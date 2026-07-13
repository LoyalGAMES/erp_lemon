<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerExternalAccount;
use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCommerceCustomerSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WooCommerceCustomerSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_promoted_inside_one_integration_and_existing_messages_are_linked(): void
    {
        [$channel, $integration] = $this->integration('ONE');
        $order = $this->order($channel, '1001', 'Anna.Example@Example.test');
        $message = CustomerMessage::query()->create([
            'external_order_id' => $order->id,
            'direction' => 'outgoing',
            'type' => 'email',
            'trigger' => 'order_created',
            'status' => 'sent',
            'recipient_email' => 'Anna.Example@Example.test',
            'subject' => 'Zamówienie',
            'body' => 'Treść',
        ]);
        $service = app(WooCommerceCustomerSyncService::class);

        $guestAccount = $service->syncFromOrder($integration, $order, (array) $order->raw_payload);

        $this->assertNotNull($guestAccount);
        $this->assertFalse($guestAccount->is_registered);
        $this->assertSame('guest_email', $order->fresh()->customer_match_method);
        $this->assertSame($guestAccount->customer_id, $message->fresh()->customer_id);
        $this->assertSame(1, $guestAccount->customer->fresh()->orders_count);
        $this->assertSame('49.90', (string) $guestAccount->customer->fresh()->total_spent);

        $registeredPayload = (array) $order->raw_payload;
        $registeredPayload['customer_id'] = 42;
        $registeredAccount = $service->syncFromOrder($integration, $order->fresh(), $registeredPayload);

        $this->assertNotNull($registeredAccount);
        $this->assertSame($guestAccount->id, $registeredAccount->id);
        $this->assertSame('42', $registeredAccount->external_customer_id);
        $this->assertTrue($registeredAccount->is_registered);
        $this->assertSame('registered', $registeredAccount->customer->account_status);
        $this->assertSame('email_promoted', $order->fresh()->customer_match_method);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, CustomerExternalAccount::query()->count());
        $this->assertSame(1, $registeredAccount->customer->orders_count);
        $this->assertSame('49.90', (string) $registeredAccount->customer->total_spent);
    }

    public function test_same_email_is_never_automatically_merged_across_integrations(): void
    {
        [$channel, $firstIntegration] = $this->integration('FIRST');
        [, $secondIntegration] = $this->integration('SECOND', $channel);
        $firstOrder = $this->order($channel, '2001', 'shared@example.test');
        $secondOrder = $this->order($channel, '2002', 'shared@example.test');
        $service = app(WooCommerceCustomerSyncService::class);

        $firstAccount = $service->syncFromOrder($firstIntegration, $firstOrder, (array) $firstOrder->raw_payload);
        $secondAccount = $service->syncFromOrder($secondIntegration, $secondOrder, (array) $secondOrder->raw_payload);

        $this->assertNotNull($firstAccount);
        $this->assertNotNull($secondAccount);
        $this->assertNotSame($firstAccount->customer_id, $secondAccount->customer_id);
        $this->assertSame(2, Customer::query()->where('email_normalized', 'shared@example.test')->count());
        $this->assertSame(2, CustomerExternalAccount::query()->count());
    }

    public function test_two_registered_woocommerce_ids_with_the_same_email_remain_separate(): void
    {
        [, $integration] = $this->integration('DUPLICATE');

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ((int) ($query['page'] ?? 1) !== 1) {
                return Http::response([]);
            }

            return Http::response([
                [
                    'id' => 501,
                    'email' => 'wspolny@example.test',
                    'first_name' => 'Anna',
                    'last_name' => 'Pierwsza',
                    'role' => 'customer',
                ],
                [
                    'id' => 502,
                    'email' => 'wspolny@example.test',
                    'first_name' => 'Anna',
                    'last_name' => 'Druga',
                    'role' => 'customer',
                ],
            ]);
        });

        $stats = app(WooCommerceCustomerSyncService::class)->importCustomers($integration);

        $accounts = CustomerExternalAccount::query()
            ->where('wordpress_integration_id', $integration->id)
            ->where('email_normalized', 'wspolny@example.test')
            ->orderBy('external_customer_id')
            ->get();

        $this->assertSame(2, $stats['created']);
        $this->assertSame(0, $stats['updated']);
        $this->assertCount(2, $accounts);
        $this->assertSame(['501', '502'], $accounts->pluck('external_customer_id')->all());
        $this->assertNotSame($accounts[0]->customer_id, $accounts[1]->customer_id);
        $this->assertTrue($accounts[0]->is_registered);
        $this->assertTrue($accounts[1]->is_registered);
        $this->assertSame(2, Customer::query()->where('email_normalized', 'wspolny@example.test')->count());
    }

    public function test_guest_with_duplicate_registered_email_uses_one_dedicated_guest_account_deterministically(): void
    {
        [$channel, $integration] = $this->integration('GUEST-DUPLICATE');
        $service = app(WooCommerceCustomerSyncService::class);
        $firstRegistered = $service->syncCustomer($integration, [
            'id' => 601,
            'email' => 'wspolny@example.test',
        ]);
        $secondRegistered = $service->syncCustomer($integration, [
            'id' => 602,
            'email' => 'wspolny@example.test',
        ]);
        $firstOrder = $this->order($channel, '6001', 'wspolny@example.test');

        $guest = $service->syncFromOrder($integration, $firstOrder, (array) $firstOrder->raw_payload);
        $sameGuest = $service->syncFromOrder($integration, $firstOrder->fresh(), (array) $firstOrder->raw_payload);
        $secondOrder = $this->order($channel, '6002', 'wspolny@example.test');
        $guestForSecondOrder = $service->syncFromOrder($integration, $secondOrder, (array) $secondOrder->raw_payload);

        $this->assertNotNull($firstRegistered);
        $this->assertNotNull($secondRegistered);
        $this->assertNotNull($guest);
        $this->assertFalse($guest->is_registered);
        $this->assertNotSame($firstRegistered->id, $guest->id);
        $this->assertNotSame($secondRegistered->id, $guest->id);
        $this->assertSame($guest->id, $sameGuest?->id);
        $this->assertSame($guest->id, $guestForSecondOrder?->id);
        $this->assertSame(3, CustomerExternalAccount::query()
            ->where('wordpress_integration_id', $integration->id)
            ->where('email_normalized', 'wspolny@example.test')
            ->count());
        $this->assertSame('guest_email', $firstOrder->fresh()->customer_match_method);
        $this->assertSame('guest_email', $secondOrder->fresh()->customer_match_method);
    }

    public function test_new_registered_id_does_not_promote_guest_when_registered_accounts_share_email(): void
    {
        [$channel, $integration] = $this->integration('REGISTERED-GUEST-DUPLICATE');
        $service = app(WooCommerceCustomerSyncService::class);
        $firstRegistered = $service->syncCustomer($integration, [
            'id' => 701,
            'email' => 'wspolny@example.test',
        ]);
        $secondRegistered = $service->syncCustomer($integration, [
            'id' => 702,
            'email' => 'wspolny@example.test',
        ]);
        $order = $this->order($channel, '7001', 'wspolny@example.test');
        $guest = $service->syncFromOrder($integration, $order, (array) $order->raw_payload);

        $thirdRegistered = $service->syncCustomer($integration, [
            'id' => 703,
            'email' => 'wspolny@example.test',
        ]);

        $this->assertNotNull($firstRegistered);
        $this->assertNotNull($secondRegistered);
        $this->assertNotNull($guest);
        $this->assertNotNull($thirdRegistered);
        $this->assertFalse($guest->fresh()->is_registered);
        $this->assertNotSame($guest->id, $thirdRegistered->id);
        $this->assertSame('703', $thirdRegistered->external_customer_id);
        $this->assertTrue($thirdRegistered->is_registered);
        $this->assertSame(4, CustomerExternalAccount::query()
            ->where('wordpress_integration_id', $integration->id)
            ->where('email_normalized', 'wspolny@example.test')
            ->count());
    }

    public function test_ambiguous_guest_email_candidates_are_not_selected_arbitrarily(): void
    {
        [$channel, $integration] = $this->integration('AMBIGUOUS-GUEST');
        $candidateIds = collect([1, 2])->map(function (int $number) use ($integration): int {
            $customer = Customer::query()->create([
                'email' => 'gosc@example.test',
                'email_normalized' => 'gosc@example.test',
                'display_name' => 'Gość '.$number,
                'account_status' => 'guest',
            ]);

            return CustomerExternalAccount::query()->create([
                'customer_id' => $customer->id,
                'wordpress_integration_id' => $integration->id,
                'email' => 'gosc@example.test',
                'email_normalized' => 'gosc@example.test',
                'display_name' => 'Gość '.$number,
                'is_registered' => false,
            ])->id;
        });
        $order = $this->order($channel, '7001', 'gosc@example.test');
        $service = app(WooCommerceCustomerSyncService::class);

        $resolved = $service->syncFromOrder($integration, $order, (array) $order->raw_payload);
        $resolvedAgain = $service->syncFromOrder($integration, $order->fresh(), (array) $order->raw_payload);

        $this->assertNotNull($resolved);
        $this->assertFalse($resolved->is_registered);
        $this->assertFalse($candidateIds->contains($resolved->id));
        $this->assertSame($resolved->id, $resolvedAgain?->id);
        $this->assertSame(3, CustomerExternalAccount::query()
            ->where('wordpress_integration_id', $integration->id)
            ->where('email_normalized', 'gosc@example.test')
            ->count());
        $this->assertSame($resolved->id, $order->fresh()->customer_external_account_id);
    }

    public function test_full_import_creates_registered_profiles_and_backfills_legacy_orders(): void
    {
        [$channel, $integration] = $this->integration('IMPORT');
        $legacyOrder = $this->order($channel, '3001', 'history@example.test');
        $requests = [];

        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = $request;
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ((int) ($query['page'] ?? 1) === 1) {
                return Http::response([[
                    'id' => 77,
                    'email' => 'history@example.test',
                    'username' => 'history',
                    'first_name' => 'Anna',
                    'last_name' => 'Historia',
                    'display_name' => 'Anna Historia',
                    'role' => 'customer',
                    'orders_count' => 3,
                    'total_spent' => '199.00',
                    'date_created_gmt' => '2026-07-01T10:00:00',
                    'billing' => [
                        'email' => 'history@example.test',
                        'phone' => '+48123123123',
                    ],
                    'shipping' => [],
                    'meta_data' => [
                        ['key' => '_wc_points_balance', 'value' => '24'],
                    ],
                ]]);
            }

            return Http::response([]);
        });

        $stats = app(WooCommerceCustomerSyncService::class)->importCustomers($integration);

        $this->assertSame(1, $stats['created']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(1, $stats['pages']);
        $this->assertSame(1, $stats['orders_linked']);
        $this->assertCount(1, $stats['created_customer_ids']);
        $this->assertCount(1, $stats['created_external_account_ids']);

        $account = CustomerExternalAccount::query()->firstOrFail();
        $customer = $account->customer;
        $this->assertSame('77', $account->external_customer_id);
        $this->assertTrue($account->is_registered);
        $this->assertSame(3, $account->orders_count);
        $this->assertSame('199.00', (string) $account->total_spent);
        $this->assertSame('registered', $customer->account_status);
        $this->assertSame('24.00', (string) $customer->loyalty_points_balance);
        $this->assertSame('_wc_points_balance', $customer->loyalty_points_source);
        $this->assertSame($customer->id, $legacyOrder->fresh()->customer_id);
        $this->assertSame($account->id, $legacyOrder->fresh()->customer_external_account_id);
        $this->assertSame($integration->id, $legacyOrder->fresh()->wordpress_integration_id);

        $this->assertNotEmpty($requests);
        $firstQuery = [];
        parse_str((string) parse_url($requests[0]->url(), PHP_URL_QUERY), $firstQuery);
        $this->assertSame('customer', $firstQuery['role'] ?? null);

        $secondStats = app(WooCommerceCustomerSyncService::class)->importCustomers($integration);

        $this->assertSame(0, $secondStats['created']);
        $this->assertSame(1, $secondStats['updated']);
        $this->assertSame(0, $secondStats['orders_linked']);
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_customer_gmt_timestamp_is_normalized_before_warsaw_dst_gap_write(): void
    {
        [, $integration] = $this->integration('DST');

        $account = app(WooCommerceCustomerSyncService::class)->syncCustomer($integration, [
            'id' => 88,
            'email' => 'dst@example.test',
            'date_created' => '2026-03-29T04:15:26',
            'date_created_gmt' => '2026-03-29T02:15:26',
        ]);

        $this->assertNotNull($account);

        $account = $account->fresh();
        $customer = $account->customer->fresh();

        $this->assertSame('2026-03-29 04:15:26', $account->getRawOriginal('account_created_at'));
        $this->assertSame('2026-03-29 04:15:26', $customer->getRawOriginal('account_created_at'));
        $this->assertSame(
            '2026-03-29 02:15:26',
            $account->account_created_at?->utc()->toDateTimeString(),
        );
    }

    public function test_client_exposes_safe_customer_and_order_operations(): void
    {
        [, $integration] = $this->integration('CLIENT');

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && str_ends_with($path, '/orders/55')) {
                return Http::response(['id' => 55, 'customer_id' => 0]);
            }

            if ($request->method() === 'GET' && str_ends_with($path, '/customers/7')) {
                return Http::response(['id' => 7, 'email' => 'person@example.test']);
            }

            if ($request->method() === 'GET' && str_ends_with($path, '/customers')) {
                return Http::response([['id' => 7, 'email' => 'person@example.test']]);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/customers')) {
                return Http::response(['id' => 8, ...$request->data()], 201);
            }

            return Http::response([], 404);
        });

        $client = app(WooCommerceClient::class);

        $this->assertSame(55, $client->order($integration, 55)['id']);
        $this->assertSame(7, $client->customer($integration, 7)['id']);
        $this->assertSame(7, $client->customersByEmail($integration, 'person@example.test')[0]['id']);
        $this->assertSame(8, $client->createCustomer($integration, [
            'email' => 'new@example.test',
            'password' => 'safe-random-value',
        ])['id']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'email=person%40example.test')
            && str_contains($request->url(), 'role=all'));
    }

    public function test_customer_pagination_treats_the_wordpress_out_of_range_response_as_the_end(): void
    {
        [, $integration] = $this->integration('LASTPAGE');

        Http::fake(Http::response([
            'code' => 'rest_post_invalid_page_number',
            'message' => 'The page number requested is larger than the number of pages available.',
        ], 400));

        $this->assertSame([], app(WooCommerceClient::class)->customersPage($integration, 2));
    }

    /**
     * @return array{SalesChannel,WordpressIntegration}
     */
    private function integration(string $suffix, ?SalesChannel $channel = null): array
    {
        $channel ??= SalesChannel::query()->create([
            'code' => 'SHOP-'.$suffix,
            'name' => 'Sklep '.$suffix,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo '.$suffix,
            'base_url' => 'https://'.strtolower($suffix).'.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_'.$suffix),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_'.$suffix),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
        ]);

        return [$channel, $integration];
    }

    private function order(SalesChannel $channel, string $externalId, string $email): ExternalOrder
    {
        $billing = [
            'email' => $email,
            'first_name' => 'Anna',
            'last_name' => 'Przykład',
            'phone' => '+48111222333',
        ];

        return ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => $externalId,
            'external_number' => $externalId,
            'status' => 'pending',
            'currency' => 'PLN',
            'total_gross' => 49.90,
            'billing_data' => $billing,
            'shipping_data' => $billing,
            'raw_payload' => [
                'id' => (int) $externalId,
                'number' => $externalId,
                'customer_id' => 0,
                'billing' => $billing,
                'shipping' => $billing,
            ],
            'external_created_at' => '2026-07-13 12:00:00',
            'external_updated_at' => '2026-07-13 12:00:00',
        ]);
    }
}
