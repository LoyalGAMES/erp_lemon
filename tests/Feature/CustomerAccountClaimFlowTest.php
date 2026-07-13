<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAccountClaim;
use App\Models\CustomerExternalAccount;
use App\Models\ExternalOrder;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\Customers\CustomerAccountClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CustomerAccountClaimFlowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $authenticateByDefault = false;

    public function test_signed_get_does_not_consume_the_claim_or_contact_woocommerce(): void
    {
        Http::fake();
        ['claim' => $claim, 'url' => $url] = $this->fixture();

        $response = $this->get($url);

        $response
            ->assertOk()
            ->assertSee('Załóż konto i zachowaj zamówienie')
            ->assertSee('G-501')
            ->assertSee('k***@example.test')
            ->assertDontSee('klient@example.test')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache');

        $this->assertNull($claim->fresh()->claimed_at);
        $this->assertSame('pending', $claim->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_new_account_is_created_and_only_the_exact_guest_order_is_assigned(): void
    {
        Mail::fake();
        $email = 'klient@example.test';
        $password = 'Bezpieczne-Haslo-123';
        $profile = $this->remoteCustomer(77, $email);
        $remoteOrder = $this->remoteOrder(501, $email, 0);

        Http::fake(function (Request $request) use ($email, $password, $profile, $remoteOrder) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/orders/501')) {
                return Http::response($remoteOrder);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/customers?')) {
                $this->assertSame($email, $request['email']);

                return Http::response([]);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/customers')) {
                $this->assertSame($email, $request['email']);
                $this->assertSame($password, $request['password']);

                return Http::response($profile, 201);
            }

            if ($request->method() === 'PUT' && str_ends_with($request->url(), '/orders/501')) {
                $this->assertSame(77, $request['customer_id']);

                return Http::response(array_replace($remoteOrder, ['customer_id' => 77]));
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        ['claim' => $claim, 'order' => $order, 'customer' => $customer, 'url' => $url] = $this->fixture();

        $this->get($url)->assertOk();
        $this->post($url, [
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertRedirect($url);

        $claim->refresh();
        $order->refresh();
        $customer->refresh();

        $this->assertNotNull($claim->claimed_at);
        $this->assertSame('claimed', $claim->status);
        $this->assertSame('77', $claim->external_customer_id);
        $this->assertTrue((bool) data_get($claim->metadata, 'created_account'));
        $this->assertSame(77, (int) data_get($order->raw_payload, 'customer_id'));
        $this->assertSame('claim_link', $order->customer_match_method);
        $this->assertSame('registered', $customer->account_status);
        $this->assertTrue($order->customerExternalAccount->is_registered);
        $this->assertSame('77', $order->customerExternalAccount->external_customer_id);
        $this->assertStringNotContainsString($password, json_encode([
            $claim->toArray(),
            $order->toArray(),
            $customer->toArray(),
            $order->customerExternalAccount->toArray(),
        ], JSON_THROW_ON_ERROR));

        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/orders/502'));

        $this->get($url)
            ->assertOk()
            ->assertSee('Konto zostało utworzone');

        // A repeated POST is idempotent and never calls WooCommerce again.
        $this->post($url, [
            'password' => 'Inne-Haslo-999',
            'password_confirmation' => 'Inne-Haslo-999',
        ])->assertRedirect($url);
        Http::assertSentCount(4);
    }

    public function test_guest_order_can_be_linked_to_an_existing_account_without_password_reset(): void
    {
        Mail::fake();
        $email = 'klient@example.test';
        $profile = $this->remoteCustomer(91, $email);
        $remoteOrder = $this->remoteOrder(501, $email, 0);

        Http::fake(function (Request $request) use ($profile, $remoteOrder) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/orders/501')) {
                return Http::response($remoteOrder);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/customers?')) {
                return Http::response([$profile]);
            }

            if ($request->method() === 'PUT' && str_ends_with($request->url(), '/orders/501')) {
                return Http::response(array_replace($remoteOrder, ['customer_id' => 91]));
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        ['claim' => $claim, 'order' => $order, 'account' => $account, 'url' => $url] = $this->fixture(
            registeredAccount: true,
            externalCustomerId: '91',
        );

        $this->post($url)->assertRedirect($url);

        $this->assertNotNull($claim->fresh()->claimed_at);
        $this->assertFalse((bool) data_get($claim->fresh()->metadata, 'created_account'));
        $this->assertSame(91, (int) data_get($order->fresh()->raw_payload, 'customer_id'));
        $this->assertSame($account->id, $order->fresh()->customer_external_account_id);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/customers'));
        Http::assertSentCount(3);

        $this->get($url)
            ->assertOk()
            ->assertSee('nie zmieniliśmy jego hasła');
    }

    public function test_tampered_or_expired_signature_is_rejected_without_consuming_claim(): void
    {
        Http::fake();
        ['claim' => $claim, 'url' => $url] = $this->fixture();

        $this->get($url.'&tampered=1')
            ->assertForbidden()
            ->assertSee('nieprawidłowy albo wygasł')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $this->travel(15)->days();

        $this->get($url)
            ->assertForbidden()
            ->assertSee('nieprawidłowy albo wygasł');

        $this->assertNull($claim->fresh()->claimed_at);
        Http::assertNothingSent();
    }

    public function test_remote_billing_email_change_is_rejected_before_customer_creation(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/501' => Http::response(
                $this->remoteOrder(501, 'inna-osoba@example.test', 0),
            ),
        ]);
        ['claim' => $claim, 'url' => $url] = $this->fixture();

        $this->post($url, [
            'password' => 'Bezpieczne-Haslo-123',
            'password_confirmation' => 'Bezpieczne-Haslo-123',
        ])->assertStatus(409);

        $this->assertNull($claim->fresh()->claimed_at);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['POST', 'PUT'], true));
    }

    public function test_order_assigned_to_a_different_remote_customer_is_never_overwritten(): void
    {
        $remoteOrder = $this->remoteOrder(501, 'klient@example.test', 999);
        $differentCustomer = $this->remoteCustomer(999, 'inna-osoba@example.test');

        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/501' => Http::response($remoteOrder),
            'https://shop.test/wp-json/wc/v3/customers/999' => Http::response($differentCustomer),
        ]);
        ['claim' => $claim, 'url' => $url] = $this->fixture();

        $this->post($url)->assertStatus(409);

        $this->assertNull($claim->fresh()->claimed_at);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_retry_reconciles_a_previous_successful_remote_assignment_with_the_same_email(): void
    {
        Mail::fake();
        $email = 'klient@example.test';
        $remoteOrder = $this->remoteOrder(501, $email, 88);
        $profile = $this->remoteCustomer(88, $email);

        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/501' => Http::response($remoteOrder),
            'https://shop.test/wp-json/wc/v3/customers/88' => Http::response($profile),
        ]);
        ['claim' => $claim, 'order' => $order, 'url' => $url] = $this->fixture();

        $this->post($url)->assertRedirect($url);

        $this->assertNotNull($claim->fresh()->claimed_at);
        $this->assertSame('88', $claim->fresh()->external_customer_id);
        $this->assertSame(88, (int) data_get($order->fresh()->raw_payload, 'customer_id'));
        $this->assertTrue($order->fresh()->customerExternalAccount->is_registered);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_claim_is_not_consumed_when_remote_order_update_fails(): void
    {
        $email = 'klient@example.test';
        $remoteOrder = $this->remoteOrder(501, $email, 0);
        $profile = $this->remoteCustomer(66, $email);

        Http::fake(function (Request $request) use ($remoteOrder, $profile) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/orders/501')) {
                return Http::response($remoteOrder);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/customers?')) {
                return Http::response([$profile]);
            }

            if ($request->method() === 'PUT' && str_ends_with($request->url(), '/orders/501')) {
                return Http::response(['message' => 'Temporary WooCommerce failure'], 503);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });
        ['claim' => $claim, 'order' => $order, 'account' => $account, 'url' => $url] = $this->fixture();

        $this->post($url)->assertRedirect($url);

        $this->assertNull($claim->fresh()->claimed_at);
        $this->assertSame('pending', $claim->fresh()->status);
        $this->assertSame(0, (int) data_get($order->fresh()->raw_payload, 'customer_id'));
        $this->assertFalse($account->fresh()->is_registered);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/orders/501'));
    }

    public function test_password_validation_never_flashes_password_values(): void
    {
        Http::fake();
        ['claim' => $claim, 'url' => $url] = $this->fixture();

        $response = $this->from($url)->post($url, [
            'password' => 'Sekretne-Haslo-123',
            'password_confirmation' => 'Niepasujace-Haslo-456',
        ]);

        $response
            ->assertRedirect($url)
            ->assertSessionHasErrors('password');
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
        $this->assertNull($claim->fresh()->claimed_at);
        Http::assertNothingSent();
    }

    public function test_missing_new_account_password_redirects_to_the_same_signed_url(): void
    {
        $remoteOrder = $this->remoteOrder(501, 'klient@example.test', 0);

        Http::fake(function (Request $request) use ($remoteOrder) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/orders/501')) {
                return Http::response($remoteOrder);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/customers?')) {
                return Http::response([]);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });
        ['claim' => $claim, 'url' => $url] = $this->fixture();

        $this->post($url)
            ->assertRedirect($url)
            ->assertSessionHasErrors(['password' => 'Ustaw hasło, aby utworzyć nowe konto.']);

        $this->assertNull($claim->fresh()->claimed_at);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringContainsString('signature=', $url);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['POST', 'PUT'], true));
    }

    public function test_claim_email_uses_keyed_hmac_instead_of_plain_sha256(): void
    {
        ['claim' => $claim] = $this->fixture();
        $plainHash = hash('sha256', 'klient@example.test');
        $expectedHmac = hash_hmac('sha256', 'klient@example.test', (string) config('app.key'));

        $this->assertNotSame($plainHash, $claim->email_hash);
        $this->assertSame($expectedHmac, $claim->email_hash);
    }

    public function test_completion_locks_order_before_claim(): void
    {
        $email = 'klient@example.test';
        $profile = $this->remoteCustomer(91, $email);
        $remoteOrder = $this->remoteOrder(501, $email, 0);

        Http::fake(function (Request $request) use ($profile, $remoteOrder) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/orders/501')) {
                return Http::response($remoteOrder);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/customers?')) {
                return Http::response([$profile]);
            }

            if ($request->method() === 'PUT' && str_ends_with($request->url(), '/orders/501')) {
                return Http::response(array_replace($remoteOrder, ['customer_id' => 91]));
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });
        ['claim' => $claim] = $this->fixture(
            registeredAccount: true,
            externalCustomerId: '91',
        );
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $sql = mb_strtolower((string) $query->sql);

            if (str_contains($sql, 'external_orders') || str_contains($sql, 'customer_account_claims')) {
                $queries[] = $sql;
            }
        });

        app(CustomerAccountClaimService::class)->complete($claim, null);

        $this->assertStringContainsString('external_orders', $queries[0] ?? '');
        $this->assertStringContainsString('customer_account_claims', $queries[1] ?? '');
    }

    /**
     * @return array{customer:Customer,account:CustomerExternalAccount,order:ExternalOrder,integration:WordpressIntegration,claim:CustomerAccountClaim,url:string}
     */
    private function fixture(bool $registeredAccount = false, ?string $externalCustomerId = null): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'CLAIM-'.strtoupper(fake()->unique()->bothify('??##')),
            'name' => 'Sklep claim test',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo claim test',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
        ]);
        $customer = Customer::query()->create([
            'email' => 'klient@example.test',
            'email_normalized' => 'klient@example.test',
            'first_name' => 'Klara',
            'last_name' => 'Kowalska',
            'display_name' => 'Klara Kowalska',
            'account_status' => $registeredAccount ? 'registered' : 'guest',
            'billing_data' => [
                'email' => 'klient@example.test',
                'first_name' => 'Klara',
                'last_name' => 'Kowalska',
            ],
        ]);
        $account = CustomerExternalAccount::query()->create([
            'customer_id' => $customer->id,
            'wordpress_integration_id' => $integration->id,
            'external_customer_id' => $externalCustomerId,
            'email' => 'klient@example.test',
            'email_normalized' => 'klient@example.test',
            'first_name' => 'Klara',
            'last_name' => 'Kowalska',
            'display_name' => 'Klara Kowalska',
            'is_registered' => $registeredAccount,
            'billing_data' => [
                'email' => 'klient@example.test',
                'first_name' => 'Klara',
                'last_name' => 'Kowalska',
            ],
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'customer_id' => $customer->id,
            'customer_external_account_id' => $account->id,
            'wordpress_integration_id' => $integration->id,
            'customer_match_method' => 'guest_email',
            'external_id' => '501',
            'external_number' => 'G-501',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 149.90,
            'billing_data' => [
                'email' => 'klient@example.test',
                'first_name' => 'Klara',
                'last_name' => 'Kowalska',
                'address_1' => 'Testowa 1',
                'city' => 'Warszawa',
                'postcode' => '00-001',
                'country' => 'PL',
                'phone' => '500600700',
            ],
            'shipping_data' => [
                'first_name' => 'Klara',
                'last_name' => 'Kowalska',
                'address_1' => 'Testowa 1',
                'city' => 'Warszawa',
                'postcode' => '00-001',
                'country' => 'PL',
            ],
            'raw_payload' => [
                'id' => 501,
                'number' => 'G-501',
                'customer_id' => 0,
            ],
            'external_created_at' => now()->subHour(),
            'external_updated_at' => now()->subHour(),
        ]);

        $service = app(CustomerAccountClaimService::class);
        $claim = $service->createOrRefresh($customer, $order, $integration);

        return compact('customer', 'account', 'order', 'integration', 'claim') + [
            'url' => $service->signedUrl($claim),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteOrder(int $id, string $email, int $customerId): array
    {
        return [
            'id' => $id,
            'number' => 'G-'.$id,
            'customer_id' => $customerId,
            'billing' => [
                'email' => $email,
                'first_name' => 'Klara',
                'last_name' => 'Kowalska',
                'address_1' => 'Testowa 1',
                'city' => 'Warszawa',
                'postcode' => '00-001',
                'country' => 'PL',
                'phone' => '500600700',
            ],
            'shipping' => [
                'first_name' => 'Klara',
                'last_name' => 'Kowalska',
                'address_1' => 'Testowa 1',
                'city' => 'Warszawa',
                'postcode' => '00-001',
                'country' => 'PL',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteCustomer(int $id, string $email): array
    {
        return [
            'id' => $id,
            'email' => $email,
            'username' => 'klient',
            'first_name' => 'Klara',
            'last_name' => 'Kowalska',
            'display_name' => 'Klara Kowalska',
            'role' => 'customer',
            'date_created' => now()->toIso8601String(),
            'orders_count' => 0,
            'total_spent' => '0.00',
            'billing' => [
                'email' => $email,
                'first_name' => 'Klara',
                'last_name' => 'Kowalska',
                'phone' => '500600700',
            ],
            'shipping' => [
                'first_name' => 'Klara',
                'last_name' => 'Kowalska',
            ],
        ];
    }
}
