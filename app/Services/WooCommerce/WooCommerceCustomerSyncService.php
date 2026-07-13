<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\Customer;
use App\Models\CustomerExternalAccount;
use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\WordpressIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class WooCommerceCustomerSyncService
{
    public function __construct(
        private readonly WooCommerceClient $client,
    ) {}

    /**
     * Import every registered WooCommerce customer, then attach historical
     * local orders which predate the normalized customer directory.
     *
     * @return array{created:int,updated:int,skipped:int,pages:int,orders_linked:int,created_customer_ids:list<int>,created_external_account_ids:list<int>}
     */
    public function importCustomers(WordpressIntegration $integration): array
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'pages' => 0,
            'orders_linked' => 0,
            'created_customer_ids' => [],
            'created_external_account_ids' => [],
        ];

        for ($page = 1; ; $page++) {
            if ($page > 10_000) {
                throw new RuntimeException('Import klientów przekroczył bezpieczny limit 1 000 000 rekordów.');
            }

            $profiles = $this->client->customersPage($integration, $page);

            if ($profiles === []) {
                break;
            }

            $stats['pages']++;

            foreach ($profiles as $profile) {
                $result = $this->syncProfile($integration, $profile, true);

                if ($result === null) {
                    $stats['skipped']++;

                    continue;
                }

                if ($result['account_created']) {
                    $stats['created']++;
                    $stats['created_customer_ids'][] = (int) $result['account']->customer_id;
                    $stats['created_external_account_ids'][] = (int) $result['account']->id;
                } else {
                    $stats['updated']++;
                }
            }
        }

        $stats['orders_linked'] = $this->backfillOrders($integration);
        $stats['created_customer_ids'] = array_values(array_unique($stats['created_customer_ids']));
        $stats['created_external_account_ids'] = array_values(array_unique($stats['created_external_account_ids']));

        return $stats;
    }

    /**
     * Synchronize one full profile returned by WooCommerce `/customers`.
     */
    public function syncCustomer(
        WordpressIntegration $integration,
        array $profile,
    ): ?CustomerExternalAccount {
        return $this->syncRegisteredCustomer($integration, $profile)['account'] ?? null;
    }

    /**
     * Synchronize one registered WooCommerce customer and expose whether the
     * account was newly created or promoted from an unambiguous guest record.
     *
     * @param  array<string, mixed>  $profile
     * @return array{account:CustomerExternalAccount,account_created:bool,customer_created:bool,match_method:string}|null
     */
    public function syncRegisteredCustomer(
        WordpressIntegration $integration,
        array $profile,
    ): ?array {
        return $this->syncProfile($integration, $profile, true);
    }

    /**
     * Resolve the customer for an order without ever matching across separate
     * WordPress integrations. E-mail matching is only used when it identifies
     * one unambiguous account inside the same WooCommerce store.
     */
    public function syncFromOrder(
        WordpressIntegration $integration,
        ExternalOrder $order,
        array $payload,
    ): ?CustomerExternalAccount {
        $billing = is_array($payload['billing'] ?? null)
            ? $payload['billing']
            : (array) $order->billing_data;
        $shipping = is_array($payload['shipping'] ?? null)
            ? $payload['shipping']
            : (array) $order->shipping_data;
        $externalCustomerId = $this->externalCustomerId($payload['customer_id'] ?? null);
        $email = $this->email($billing['email'] ?? null);

        $order->wordpress_integration_id = $integration->id;

        if ($externalCustomerId === null && $email === null) {
            $order->save();

            return $order->customerExternalAccount;
        }

        $profile = [
            'id' => $externalCustomerId,
            'email' => $email,
            'first_name' => $billing['first_name'] ?? null,
            'last_name' => $billing['last_name'] ?? null,
            'display_name' => $this->displayName($payload, $billing, $email),
            'billing' => $billing,
            'shipping' => $shipping,
            'date_created_gmt' => $payload['customer_date_created_gmt'] ?? null,
            'date_created' => $payload['customer_date_created'] ?? null,
            'erp_source' => 'order',
            'erp_order_id' => $order->id,
        ];

        $result = $this->syncProfile($integration, $profile, $externalCustomerId !== null);

        if ($result === null) {
            $order->save();

            return null;
        }

        $account = $result['account'];
        $matchMethod = $externalCustomerId === null
            ? 'guest_email'
            : $result['match_method'];

        $order->forceFill([
            'customer_id' => $account->customer_id,
            'customer_external_account_id' => $account->id,
            'wordpress_integration_id' => $integration->id,
            'customer_match_method' => $matchMethod,
        ])->save();

        CustomerMessage::query()
            ->where('external_order_id', $order->id)
            ->whereNull('customer_id')
            ->update(['customer_id' => $account->customer_id]);

        $this->refreshExternalAccountMetrics($account);
        $this->refreshCustomerMetrics($account->customer);

        return $account->fresh(['customer']);
    }

    public function refreshCustomerMetrics(Customer $customer): Customer
    {
        $customer->load('externalAccounts');
        $orders = ExternalOrder::query()->where('customer_id', $customer->id);
        $orderDates = (clone $orders)->selectRaw('MIN(external_created_at) as first_order_at, MAX(external_created_at) as last_order_at')->first();
        $accounts = $customer->externalAccounts;
        $registeredAccounts = $accounts->where('is_registered', true);
        $accountCreatedAt = $registeredAccounts
            ->pluck('account_created_at')
            ->filter()
            ->sort()
            ->first();

        $customer->forceFill([
            'account_status' => $registeredAccounts->isNotEmpty() ? 'registered' : 'guest',
            'orders_count' => (int) $accounts->sum('orders_count'),
            'total_spent' => round((float) $accounts->sum(fn (CustomerExternalAccount $account): float => (float) $account->total_spent), 2),
            'first_order_at' => $orderDates?->first_order_at,
            'last_order_at' => $orderDates?->last_order_at,
            'account_created_at' => $accountCreatedAt,
            'last_synced_at' => now(),
        ])->save();

        return $customer->fresh();
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{account:CustomerExternalAccount,account_created:bool,customer_created:bool,match_method:string}|null
     */
    private function syncProfile(
        WordpressIntegration $integration,
        array $profile,
        bool $registered,
    ): ?array {
        $externalCustomerId = $registered
            ? $this->externalCustomerId($profile['id'] ?? null)
            : null;
        $billing = is_array($profile['billing'] ?? null) ? $profile['billing'] : [];
        $shipping = is_array($profile['shipping'] ?? null) ? $profile['shipping'] : [];
        $email = $this->email($profile['email'] ?? $billing['email'] ?? null);
        $emailNormalized = $this->normalizeEmail($email);

        if ($registered && $externalCustomerId === null) {
            return null;
        }

        if (! $registered && $emailNormalized === null) {
            return null;
        }

        $result = DB::transaction(function () use (
            $integration,
            $profile,
            $registered,
            $externalCustomerId,
            $billing,
            $shipping,
            $email,
            $emailNormalized,
        ): array {
            // The e-mail index is deliberately non-unique because WooCommerce
            // can contain multiple registered ids with the same address. A row
            // lock on the integration serializes the no-match/create decision.
            WordpressIntegration::query()
                ->whereKey($integration->id)
                ->lockForUpdate()
                ->firstOrFail();

            $account = null;
            $matchMethod = $registered ? 'external_id' : 'guest_email';

            if ($externalCustomerId !== null) {
                $account = CustomerExternalAccount::query()
                    ->where('wordpress_integration_id', $integration->id)
                    ->where('external_customer_id', $externalCustomerId)
                    ->lockForUpdate()
                    ->first();
            }

            if ($account === null && $emailNormalized !== null) {
                $emailAccounts = CustomerExternalAccount::query()
                    ->where('wordpress_integration_id', $integration->id)
                    ->where('email_normalized', $emailNormalized)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $orderAccount = $this->accountAlreadyAttachedToOrder($profile, $emailAccounts);

                if ($registered) {
                    if ($this->isPromotableGuest($orderAccount)) {
                        $account = $orderAccount;
                    } else {
                        $guestAccounts = $emailAccounts
                            ->filter(fn (CustomerExternalAccount $candidate): bool => $this->isPromotableGuest($candidate));

                        if ($emailAccounts->count() === 1 && $guestAccounts->count() === 1) {
                            $account = $guestAccounts->first();
                        }
                    }

                    if ($account !== null) {
                        $matchMethod = 'email_promoted';
                    }
                } elseif ($orderAccount !== null) {
                    $account = $orderAccount;
                } else {
                    $guestAccounts = $emailAccounts
                        ->filter(fn (CustomerExternalAccount $candidate): bool => ! $candidate->is_registered);

                    if ($guestAccounts->count() === 1) {
                        $account = $guestAccounts->first();
                    } elseif ($guestAccounts->isEmpty() && $emailAccounts->count() === 1) {
                        $account = $emailAccounts->first();
                    }
                }
            }

            $customerCreated = false;

            if ($account === null) {
                $customer = new Customer;
                $customerCreated = true;
                $account = new CustomerExternalAccount([
                    'wordpress_integration_id' => $integration->id,
                ]);
            } else {
                $customer = $account->customer()->lockForUpdate()->firstOrFail();
            }

            $wasRegistered = $account->exists && $account->is_registered;
            $firstName = $this->text($profile['first_name'] ?? $billing['first_name'] ?? null);
            $lastName = $this->text($profile['last_name'] ?? $billing['last_name'] ?? null);
            $displayName = $this->displayName($profile, $billing, $email);
            $phone = $this->text($billing['phone'] ?? $shipping['phone'] ?? null);
            $accountCreatedAt = $this->accountCreatedAt($profile);
            $accountAttributes = $this->withoutNullValues([
                'external_customer_id' => $externalCustomerId,
                'email' => $email,
                'email_normalized' => $emailNormalized,
                'username' => $this->text($profile['username'] ?? null),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'display_name' => $displayName,
                'phone' => $phone,
                'role' => $this->text($profile['role'] ?? null),
                'billing_data' => $billing !== [] ? $billing : null,
                'shipping_data' => $shipping !== [] ? $shipping : null,
                'account_created_at' => $accountCreatedAt,
            ]);

            $account->fill($accountAttributes);
            $account->is_registered = $wasRegistered || $registered;
            $account->last_synced_at = now();
            $isOrderSnapshot = ($profile['erp_source'] ?? null) === 'order';

            if ($isOrderSnapshot && $wasRegistered && is_array($account->raw_payload)) {
                $account->raw_payload = array_replace(
                    (array) $account->raw_payload,
                    ['erp_latest_order_profile' => $profile],
                );
            } else {
                $account->raw_payload = $profile;
            }

            if (array_key_exists('orders_count', $profile) && is_numeric($profile['orders_count'])) {
                $account->orders_count = max(0, (int) $profile['orders_count']);
            }

            if (array_key_exists('total_spent', $profile) && is_numeric($profile['total_spent'])) {
                $account->total_spent = max(0, (float) $profile['total_spent']);
            }

            $customer->fill($this->withoutNullValues([
                'email' => $email,
                'email_normalized' => $emailNormalized,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'display_name' => $displayName,
                'phone' => $phone,
                'billing_data' => $billing !== [] ? $billing : null,
                'shipping_data' => $shipping !== [] ? $shipping : null,
                'account_created_at' => $accountCreatedAt,
            ]));
            $customer->account_status = ($wasRegistered || $registered) ? 'registered' : 'guest';
            $customer->last_synced_at = now();

            [$loyaltyBalance, $loyaltySource] = $this->loyaltyPoints($integration, $profile);

            if ($loyaltyBalance !== null) {
                $customer->loyalty_points_balance = $loyaltyBalance;
                $customer->loyalty_points_source = $loyaltySource;
            }

            $customer->save();

            $account->customer()->associate($customer);
            $account->save();

            return [
                'account' => $account,
                'account_created' => ! $wasRegistered && $registered,
                'customer_created' => $customerCreated,
                'match_method' => $matchMethod,
            ];
        }, 3);

        if (($profile['erp_source'] ?? null) !== 'order') {
            $this->refreshCustomerMetrics($result['account']->customer);
        }

        return $result;
    }

    /**
     * Prefer the account already assigned to the exact order. This is the only
     * safe tie-breaker when more than one local account shares an e-mail.
     *
     * @param  array<string, mixed>  $profile
     * @param  Collection<int, CustomerExternalAccount>  $emailAccounts
     */
    private function accountAlreadyAttachedToOrder(
        array $profile,
        Collection $emailAccounts,
    ): ?CustomerExternalAccount {
        $orderId = $profile['erp_order_id'] ?? null;

        if (! is_numeric($orderId) || (int) $orderId <= 0) {
            return null;
        }

        $accountId = ExternalOrder::query()
            ->whereKey((int) $orderId)
            ->value('customer_external_account_id');

        if (! is_numeric($accountId) || (int) $accountId <= 0) {
            return null;
        }

        $account = $emailAccounts->first(
            fn (CustomerExternalAccount $candidate): bool => (int) $candidate->id === (int) $accountId,
        );

        return $account instanceof CustomerExternalAccount ? $account : null;
    }

    private function isPromotableGuest(?CustomerExternalAccount $account): bool
    {
        return $account instanceof CustomerExternalAccount
            && ! $account->is_registered
            && $account->external_customer_id === null;
    }

    private function refreshExternalAccountMetrics(CustomerExternalAccount $account): void
    {
        $localOrders = ExternalOrder::query()
            ->where('customer_external_account_id', $account->id);
        $localCount = (int) (clone $localOrders)->count();
        $localTotal = (float) (clone $localOrders)->sum('total_gross');
        $rawPayload = (array) $account->raw_payload;
        $hasRemoteCount = array_key_exists('orders_count', $rawPayload)
            && is_numeric($rawPayload['orders_count']);
        $hasRemoteTotal = array_key_exists('total_spent', $rawPayload)
            && is_numeric($rawPayload['total_spent']);

        $account->forceFill([
            'orders_count' => $hasRemoteCount
                ? max($localCount, (int) $rawPayload['orders_count'])
                : $localCount,
            'total_spent' => $hasRemoteTotal
                ? max(0, $localTotal, (float) $rawPayload['total_spent'])
                : max(0, $localTotal),
            'last_synced_at' => now(),
        ])->save();
    }

    private function backfillOrders(WordpressIntegration $integration): int
    {
        $linked = 0;
        $canAdoptLegacyOrders = WordpressIntegration::query()
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->count() === 1;
        $query = ExternalOrder::query()
            ->where(function ($query): void {
                $query->whereNull('customer_id')
                    ->orWhereNull('customer_external_account_id')
                    ->orWhereNull('wordpress_integration_id');
            })
            ->where(function ($query) use ($integration, $canAdoptLegacyOrders): void {
                $query->where('wordpress_integration_id', $integration->id);

                if ($canAdoptLegacyOrders) {
                    $query->orWhere(function ($query) use ($integration): void {
                        $query->whereNull('wordpress_integration_id')
                            ->where('sales_channel_id', $integration->sales_channel_id);
                    });
                }
            });

        $query->chunkById(100, function ($orders) use ($integration, &$linked): void {
            foreach ($orders as $order) {
                $previousCustomerId = $order->customer_id;
                $previousAccountId = $order->customer_external_account_id;
                $account = $this->syncFromOrder($integration, $order, (array) $order->raw_payload);

                if ($account !== null && (
                    (int) $previousCustomerId !== (int) $account->customer_id
                    || (int) $previousAccountId !== (int) $account->id
                )) {
                    $linked++;
                }
            }
        });

        return $linked;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function displayName(array $payload, array $billing, ?string $email): ?string
    {
        $explicit = $this->text($payload['display_name'] ?? null);

        if ($explicit !== null) {
            return $explicit;
        }

        $fullName = trim(implode(' ', array_filter([
            $this->text($payload['first_name'] ?? $billing['first_name'] ?? null),
            $this->text($payload['last_name'] ?? $billing['last_name'] ?? null),
        ])));

        if ($fullName !== '') {
            return $fullName;
        }

        $username = $this->text($payload['username'] ?? null);

        if ($username !== null) {
            return $username;
        }

        return $email !== null ? Str::before($email, '@') : null;
    }

    private function externalCustomerId(mixed $value): ?string
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (string) (int) $value;
    }

    private function email(mixed $value): ?string
    {
        $email = $this->text($value);

        return $this->normalizeEmail($email) !== null ? $email : null;
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($email));

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false
            ? $normalized
            : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, 255, '') : null;
    }

    /**
     * WooCommerce exposes both an absolute UTC value and a store-local value.
     * Keep the resulting Carbon instance in the application's timezone before
     * Eloquent serializes it: MySQL TIMESTAMP input is interpreted in the
     * connection timezone and a bare UTC 02:xx may fall into Warsaw's DST gap.
     *
     * @param  array<string, mixed>  $profile
     */
    private function accountCreatedAt(array $profile): ?CarbonImmutable
    {
        $applicationTimezone = (string) config('app.timezone', 'UTC');
        $gmt = $this->dateTime(
            $profile['date_created_gmt'] ?? null,
            'UTC',
            $applicationTimezone,
        );

        if ($gmt !== null) {
            return $gmt;
        }

        return $this->dateTime(
            $profile['date_created'] ?? null,
            $applicationTimezone,
            $applicationTimezone,
        );
    }

    private function dateTime(
        mixed $value,
        string $sourceTimezone,
        string $targetTimezone,
    ): ?CarbonImmutable {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, $sourceTimezone)
                ->setTimezone($targetTimezone);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutNullValues(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{0:?float,1:?string}
     */
    private function loyaltyPoints(WordpressIntegration $integration, array $profile): array
    {
        $configuredKeys = collect((array) data_get(
            $integration->settings,
            'customer_import.loyalty_points_meta_keys',
            ['_wc_points_balance', 'wc_points_balance', '_ywpar_user_total_points'],
        ))
            ->map(fn (mixed $key): string => trim((string) $key))
            ->filter()
            ->unique()
            ->values();

        foreach ((array) ($profile['meta_data'] ?? []) as $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $key = trim((string) ($meta['key'] ?? ''));
            $value = $meta['value'] ?? null;

            if ($configuredKeys->contains($key) && is_numeric($value)) {
                return [(float) $value, $key];
            }
        }

        return [null, null];
    }
}
