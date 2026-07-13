<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerAccountClaim;
use App\Models\CustomerExternalAccount;
use App\Models\ExternalOrder;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCommerceCustomerSyncService;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

final class CustomerAccountClaimService
{
    private const DEFAULT_TTL_DAYS = 14;

    /** @var list<string> */
    private const BILLING_FIELDS = [
        'first_name',
        'last_name',
        'company',
        'address_1',
        'address_2',
        'city',
        'state',
        'postcode',
        'country',
        'email',
        'phone',
    ];

    /** @var list<string> */
    private const SHIPPING_FIELDS = [
        'first_name',
        'last_name',
        'company',
        'address_1',
        'address_2',
        'city',
        'state',
        'postcode',
        'country',
        'phone',
    ];

    public function __construct(
        private readonly WooCommerceClient $wooCommerce,
        private readonly WooCommerceCustomerSyncService $customerSync,
    ) {}

    public function createOrRefresh(
        Customer $customer,
        ExternalOrder $order,
        WordpressIntegration $integration,
        ?CarbonInterface $expiresAt = null,
    ): CustomerAccountClaim {
        $email = $this->orderEmail($order);

        if ($email === '') {
            throw CustomerAccountClaimException::unavailable('A guest order without a billing email cannot be claimed.');
        }

        if ($order->customer_id !== null && (int) $order->customer_id !== (int) $customer->getKey()) {
            throw CustomerAccountClaimException::unavailable('The order belongs to a different local customer.');
        }

        if ($order->wordpress_integration_id !== null
            && (int) $order->wordpress_integration_id !== (int) $integration->getKey()) {
            throw CustomerAccountClaimException::unavailable('The order belongs to a different WordPress integration.');
        }

        if ((int) $order->sales_channel_id !== (int) $integration->sales_channel_id) {
            throw CustomerAccountClaimException::unavailable('The order belongs to a different sales channel.');
        }

        if ((int) data_get($order->raw_payload, 'customer_id', 0) > 0) {
            throw CustomerAccountClaimException::unavailable('The local order is already assigned to a WooCommerce customer.');
        }

        $expiresAt ??= now()->addDays((int) config(
            'services.woocommerce.customer_claim_ttl_days',
            self::DEFAULT_TTL_DAYS,
        ));

        if ($expiresAt->lessThanOrEqualTo(now())) {
            throw CustomerAccountClaimException::expired();
        }

        return DB::transaction(function () use ($customer, $order, $integration, $email, $expiresAt): CustomerAccountClaim {
            $lockedOrder = ExternalOrder::query()->lockForUpdate()->find($order->getKey());

            if (! $lockedOrder instanceof ExternalOrder) {
                throw CustomerAccountClaimException::unavailable('The order no longer exists.');
            }

            $claim = CustomerAccountClaim::query()
                ->where('external_order_id', $lockedOrder->getKey())
                ->lockForUpdate()
                ->first();

            if ($claim?->claimed_at !== null) {
                return $claim;
            }

            $account = $lockedOrder->customerExternalAccount;

            if (! $account instanceof CustomerExternalAccount) {
                $account = CustomerExternalAccount::query()
                    ->where('customer_id', $customer->getKey())
                    ->where('wordpress_integration_id', $integration->getKey())
                    ->first();
            }

            if ($account instanceof CustomerExternalAccount
                && ((int) $account->customer_id !== (int) $customer->getKey()
                    || (int) $account->wordpress_integration_id !== (int) $integration->getKey())) {
                throw CustomerAccountClaimException::unavailable('The order points to a different external customer account.');
            }

            $emailHash = $this->emailHash($email);
            $mustRotateLink = ! $claim instanceof CustomerAccountClaim
                || $claim->expires_at->isPast()
                || ! hash_equals((string) $claim->email_hash, $emailHash)
                || (int) $claim->customer_id !== (int) $customer->getKey()
                || (int) $claim->wordpress_integration_id !== (int) $integration->getKey();

            $claim ??= new CustomerAccountClaim;
            $claim->fill([
                'uuid' => $mustRotateLink ? (string) Str::uuid() : $claim->uuid,
                'customer_id' => $customer->getKey(),
                'customer_external_account_id' => $account?->getKey(),
                'external_order_id' => $lockedOrder->getKey(),
                'wordpress_integration_id' => $integration->getKey(),
                'email_hash' => $emailHash,
                'status' => 'pending',
                'expires_at' => $mustRotateLink ? $expiresAt : $claim->expires_at,
                'claimed_at' => null,
                'external_customer_id' => null,
                'last_error' => null,
            ]);
            $claim->save();

            return $claim->fresh(['customer', 'customerExternalAccount', 'externalOrder', 'integration']);
        });
    }

    public function signedUrl(CustomerAccountClaim $claim): string
    {
        if ($claim->claimed_at !== null || $claim->expires_at->isPast()) {
            throw $claim->claimed_at !== null
                ? CustomerAccountClaimException::unavailable('A completed claim cannot be reissued.')
                : CustomerAccountClaimException::expired();
        }

        return URL::temporarySignedRoute(
            'customer-account-claims.show',
            $claim->expires_at,
            ['claim' => $claim],
        );
    }

    public function complete(CustomerAccountClaim $claim, ?string $password): CustomerAccountClaimResult
    {
        $claimKey = $claim->getKey();
        $orderKey = $claim->external_order_id;

        return DB::transaction(function () use ($claimKey, $orderKey, $password): CustomerAccountClaimResult {
            // Keep the lock order identical to createOrRefresh(): order first,
            // then its claim. Taking these locks in the opposite direction can
            // deadlock when an invitation is refreshed while it is completed.
            $lockedOrder = ExternalOrder::query()
                ->lockForUpdate()
                ->find($orderKey);

            if (! $lockedOrder instanceof ExternalOrder) {
                throw CustomerAccountClaimException::unavailable('The claim order no longer exists.');
            }

            $lockedClaim = CustomerAccountClaim::query()
                ->lockForUpdate()
                ->find($claimKey);

            if (! $lockedClaim instanceof CustomerAccountClaim
                || (int) $lockedClaim->external_order_id !== (int) $lockedOrder->getKey()) {
                throw CustomerAccountClaimException::unavailable('The claim no longer exists.');
            }

            $lockedClaim->load(['customer', 'customerExternalAccount', 'externalOrder', 'integration']);

            $customer = $lockedClaim->customer;
            $order = $lockedClaim->externalOrder;
            $integration = $lockedClaim->integration;

            if (! $customer instanceof Customer
                || ! $order instanceof ExternalOrder
                || ! $integration instanceof WordpressIntegration
                || (int) $order->getKey() !== (int) $lockedOrder->getKey()
                || (int) $lockedClaim->customer_id !== (int) $customer->getKey()
                || (int) $lockedClaim->wordpress_integration_id !== (int) $integration->getKey()
                || (int) $lockedOrder->customer_id !== (int) $customer->getKey()
                || (int) $lockedOrder->wordpress_integration_id !== (int) $integration->getKey()
                || (int) $lockedOrder->sales_channel_id !== (int) $integration->sales_channel_id) {
                throw CustomerAccountClaimException::unavailable('The claim relations are incomplete.');
            }

            if ($lockedClaim->claimed_at !== null) {
                return $this->completedResult($lockedClaim);
            }

            if ($lockedClaim->expires_at->isPast()) {
                throw CustomerAccountClaimException::expired();
            }

            $remoteOrder = $this->wooCommerce->order($integration, (string) $lockedOrder->external_id);
            $remoteEmail = $this->normalizeEmail((string) data_get($remoteOrder, 'billing.email', ''));

            if ($remoteEmail === '' || ! hash_equals((string) $lockedClaim->email_hash, $this->emailHash($remoteEmail))) {
                throw CustomerAccountClaimException::unavailable('The remote order billing email changed.');
            }

            $remoteOrderCustomerId = (int) data_get($remoteOrder, 'customer_id', 0);
            $localOrderCustomerId = (int) data_get($lockedOrder->raw_payload, 'customer_id', 0);
            $alreadyAssignedToVerifiedCustomer = $remoteOrderCustomerId > 0;

            if ($alreadyAssignedToVerifiedCustomer) {
                if ($localOrderCustomerId > 0 && $localOrderCustomerId !== $remoteOrderCustomerId) {
                    throw CustomerAccountClaimException::unavailable('Local and remote order customer ids differ.');
                }

                $remoteCustomer = $this->wooCommerce->customer($integration, $remoteOrderCustomerId);
                $remoteCustomerEmail = $this->normalizeEmail((string) ($remoteCustomer['email'] ?? ''));

                if ((int) ($remoteCustomer['id'] ?? 0) !== $remoteOrderCustomerId
                    || $remoteCustomerEmail === ''
                    || ! hash_equals((string) $lockedClaim->email_hash, $this->emailHash($remoteCustomerEmail))) {
                    throw CustomerAccountClaimException::unavailable('The remote order belongs to another customer.');
                }

                $createdAccount = false;
            } else {
                if ($localOrderCustomerId > 0) {
                    throw CustomerAccountClaimException::unavailable('The local order is already assigned but WooCommerce is not.');
                }

                [$remoteCustomer, $createdAccount] = $this->findOrCreateRemoteCustomer(
                    $integration,
                    $lockedOrder,
                    $remoteEmail,
                    $password,
                );
            }
            $externalCustomerId = trim((string) ($remoteCustomer['id'] ?? ''));

            if ($externalCustomerId === '') {
                throw new RuntimeException('WooCommerce returned a customer without an id.');
            }

            $assignedRemoteOrder = $remoteOrder;

            if (! $alreadyAssignedToVerifiedCustomer) {
                $updatedRemoteOrder = $this->wooCommerce->updateOrder(
                    $integration,
                    (string) $lockedOrder->external_id,
                    ['customer_id' => (int) $externalCustomerId],
                );

                if ((string) data_get($updatedRemoteOrder, 'customer_id', '') !== $externalCustomerId) {
                    throw new RuntimeException('WooCommerce did not confirm the order customer assignment.');
                }

                $assignedRemoteOrder = array_replace_recursive(
                    $remoteOrder,
                    $updatedRemoteOrder,
                    ['customer_id' => (int) $externalCustomerId],
                );
            }

            $lockedOrder->forceFill([
                'billing_data' => is_array($assignedRemoteOrder['billing'] ?? null)
                    ? $assignedRemoteOrder['billing']
                    : $lockedOrder->billing_data,
                'shipping_data' => is_array($assignedRemoteOrder['shipping'] ?? null)
                    ? $assignedRemoteOrder['shipping']
                    : $lockedOrder->shipping_data,
                'raw_payload' => array_replace_recursive(
                    (array) $lockedOrder->raw_payload,
                    $assignedRemoteOrder,
                    ['customer_id' => (int) $externalCustomerId],
                ),
            ])->save();

            $externalAccount = $this->customerSync->syncCustomer($integration, $remoteCustomer);
            $orderAccount = $this->customerSync->syncFromOrder(
                $integration,
                $lockedOrder,
                $assignedRemoteOrder,
            );

            if (! $externalAccount instanceof CustomerExternalAccount
                || ! $orderAccount instanceof CustomerExternalAccount
                || (int) $externalAccount->getKey() !== (int) $orderAccount->getKey()
                || (int) $orderAccount->customer_id !== (int) $customer->getKey()) {
                throw new RuntimeException('Local customer synchronization returned an inconsistent account.');
            }

            $lockedOrder->forceFill(['customer_match_method' => 'claim_link'])->save();

            $lockedClaim->forceFill([
                'customer_external_account_id' => $externalAccount->getKey(),
                'external_customer_id' => $externalCustomerId,
                'status' => 'claimed',
                'claimed_at' => now(),
                'last_error' => null,
                'metadata' => array_merge((array) $lockedClaim->metadata, [
                    'created_account' => $createdAccount,
                ]),
            ])->save();

            return new CustomerAccountClaimResult(
                createdAccount: $createdAccount,
                externalCustomerId: $externalCustomerId,
                customer: $orderAccount->customer()->firstOrFail(),
                order: $lockedOrder->fresh(),
                loginUrl: $this->loginUrl($integration),
            );
        });
    }

    public function emailHash(string $email): string
    {
        $normalized = $this->normalizeEmail($email);

        if ($normalized === '') {
            throw CustomerAccountClaimException::unavailable('An empty email cannot be hashed for a claim.');
        }

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    public function loginUrl(WordpressIntegration $integration): string
    {
        $configured = trim((string) data_get($integration->settings, 'customer_accounts.login_url', ''));

        return $configured !== '' && $this->isSafeStoreUrl($configured, (string) $integration->base_url)
            ? $configured
            : rtrim((string) $integration->base_url, '/').'/moje-konto/';
    }

    public function storeUrl(WordpressIntegration $integration): string
    {
        return rtrim((string) $integration->base_url, '/').'/';
    }

    private function completedResult(CustomerAccountClaim $claim): CustomerAccountClaimResult
    {
        $customer = $claim->customer;
        $order = $claim->externalOrder;
        $integration = $claim->integration;

        if (! $customer instanceof Customer
            || ! $order instanceof ExternalOrder
            || ! $integration instanceof WordpressIntegration
            || blank($claim->external_customer_id)) {
            throw CustomerAccountClaimException::unavailable('The completed claim is incomplete.');
        }

        return new CustomerAccountClaimResult(
            createdAccount: (bool) data_get($claim->metadata, 'created_account', false),
            externalCustomerId: (string) $claim->external_customer_id,
            customer: $customer,
            order: $order,
            loginUrl: $this->loginUrl($integration),
        );
    }

    /**
     * @return array{0:array<string,mixed>,1:bool}
     */
    private function findOrCreateRemoteCustomer(
        WordpressIntegration $integration,
        ExternalOrder $order,
        string $email,
        ?string $password,
    ): array {
        $remoteCustomer = $this->matchingRemoteCustomer(
            $this->wooCommerce->customersByEmail($integration, $email),
            $email,
        );

        if (is_array($remoteCustomer)) {
            return [$remoteCustomer, false];
        }

        if ($password === null || $password === '') {
            throw CustomerAccountClaimException::passwordRequired();
        }

        try {
            $created = $this->wooCommerce->createCustomer($integration, $this->customerPayload($order, $email, $password));
        } catch (RuntimeException $exception) {
            // The account may have been created between the lookup and POST.
            // Re-querying by the verified email makes this race idempotent and
            // never changes an existing account's password.
            $existing = $this->matchingRemoteCustomer(
                $this->wooCommerce->customersByEmail($integration, $email),
                $email,
            );

            if (! is_array($existing)) {
                throw $exception;
            }

            return [$existing, false];
        }

        $createdEmail = $this->normalizeEmail((string) ($created['email'] ?? $email));

        if ($createdEmail !== $email) {
            throw new RuntimeException('WooCommerce returned a customer with a different email.');
        }

        return [$created, true];
    }

    /**
     * @param  list<array<string, mixed>>  $customers
     * @return array<string, mixed>|null
     */
    private function matchingRemoteCustomer(array $customers, string $email): ?array
    {
        foreach ($customers as $customer) {
            if (! is_array($customer)) {
                continue;
            }

            if ($this->normalizeEmail((string) ($customer['email'] ?? '')) === $email
                && (int) ($customer['id'] ?? 0) > 0) {
                return $customer;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload(ExternalOrder $order, string $email, string $password): array
    {
        $billing = Arr::only((array) $order->billing_data, self::BILLING_FIELDS);
        $shipping = Arr::only((array) $order->shipping_data, self::SHIPPING_FIELDS);
        $billing['email'] = $email;

        return array_filter([
            'email' => $email,
            'password' => $password,
            'first_name' => trim((string) ($billing['first_name'] ?? '')),
            'last_name' => trim((string) ($billing['last_name'] ?? '')),
            'billing' => $billing,
            'shipping' => $shipping,
        ], static fn (mixed $value, string $key): bool => $key === 'password' || $value !== '' && $value !== [], ARRAY_FILTER_USE_BOTH);
    }

    private function orderEmail(ExternalOrder $order): string
    {
        return $this->normalizeEmail((string) data_get($order->billing_data, 'email', ''));
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function isSafeStoreUrl(string $url, string $storeBaseUrl): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $storeHost = mb_strtolower((string) parse_url($storeBaseUrl, PHP_URL_HOST));

        return in_array($scheme, ['http', 'https'], true)
            && $host !== ''
            && hash_equals($storeHost, $host);
    }
}
