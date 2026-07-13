<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerExternalAccount;
use App\Models\ExternalOrder;
use App\Models\SalesChannel;
use App\Models\User;
use App\Models\WordpressIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_list_shows_synced_accounts_guests_and_metrics(): void
    {
        [$registered, $guest] = $this->createCustomerDirectoryFixture();

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Klienci')
            ->assertSee('Lista klientów')
            ->assertSee('Anna Kowalska')
            ->assertSee('anna@example.test')
            ->assertSee('Jan Gość')
            ->assertSee('jan@example.test')
            ->assertSee('Zarejestrowane')
            ->assertSee('Gość')
            ->assertSee('Zamówienia klientów')
            ->assertSee(route('customers.show', $registered), false)
            ->assertSee(route('customers.show', $guest), false);
    }

    public function test_customer_list_can_search_and_filter_by_account_status_and_channel(): void
    {
        [$registered, $guest, $firstChannel, $secondChannel] = $this->createCustomerDirectoryFixture();

        $this->get(route('customers.index', ['q' => '555 100 200']))
            ->assertOk()
            ->assertSee('Anna Kowalska')
            ->assertDontSee('Jan Gość');

        $this->get(route('customers.index', ['q' => 'WOO-9002']))
            ->assertOk()
            ->assertSee('Jan Gość')
            ->assertDontSee('Anna Kowalska');

        $this->get(route('customers.index', ['status' => 'registered']))
            ->assertOk()
            ->assertSee($registered->email)
            ->assertDontSee($guest->email);

        $this->get(route('customers.index', ['channel' => $secondChannel->id]))
            ->assertOk()
            ->assertSee($guest->email)
            ->assertDontSee($registered->email)
            ->assertSee($firstChannel->name)
            ->assertSee($secondChannel->name);
    }

    public function test_customer_detail_shows_contact_addresses_external_account_and_order_history(): void
    {
        [$customer] = $this->createCustomerDirectoryFixture();

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Anna Kowalska')
            ->assertSee('anna@example.test')
            ->assertSee('555 100 200')
            ->assertSee('Kwiatowa 10')
            ->assertSee('Konto WooCommerce')
            ->assertSee('anna-k')
            ->assertSee('123')
            ->assertSee('Historia zamówień')
            ->assertSee('WOO-9001')
            ->assertSee('199,90 PLN')
            ->assertSee('125,00')
            ->assertSee(route('orders.show', ExternalOrder::query()->where('external_number', 'WOO-9001')->firstOrFail()), false);
    }

    public function test_customer_directory_is_available_to_business_roles_but_not_packers(): void
    {
        $operator = $this->createUser(User::ROLE_OPERATOR, 'operator@example.test');
        $accounting = $this->createUser(User::ROLE_ACCOUNTING, 'accounting@example.test');
        $packer = $this->createUser(User::ROLE_PACKER, 'packer@example.test');

        $this->actingAs($operator)
            ->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Klienci');

        $this->actingAs($accounting)
            ->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Klienci');

        $this->actingAs($packer)
            ->get(route('customers.index'))
            ->assertForbidden();

        $this->actingAs($packer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('customers.index'), false);
    }

    /**
     * @return array{Customer, Customer, SalesChannel, SalesChannel}
     */
    private function createCustomerDirectoryFixture(): array
    {
        [$firstChannel, $firstIntegration] = $this->createIntegration('B2C', 'Sklep Sempre', 'https://shop-one.test');
        [$secondChannel, $secondIntegration] = $this->createIntegration('OUTLET', 'Sempre Outlet', 'https://shop-two.test');

        $registered = Customer::query()->create([
            'email' => 'anna@example.test',
            'email_normalized' => 'anna@example.test',
            'first_name' => 'Anna',
            'last_name' => 'Kowalska',
            'display_name' => 'Anna K.',
            'phone' => '555 100 200',
            'account_status' => 'registered',
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'address_1' => 'Kwiatowa 10',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'shipping_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'address_1' => 'Kwiatowa 10',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'orders_count' => 1,
            'total_spent' => 199.90,
            'loyalty_points_balance' => 125,
            'loyalty_points_source' => 'woo_meta',
            'first_order_at' => '2026-07-10 10:00:00',
            'last_order_at' => '2026-07-10 10:00:00',
            'account_created_at' => '2026-07-01 08:00:00',
            'last_synced_at' => '2026-07-13 12:00:00',
        ]);

        $registeredAccount = CustomerExternalAccount::query()->create([
            'customer_id' => $registered->id,
            'wordpress_integration_id' => $firstIntegration->id,
            'external_customer_id' => '123',
            'email' => $registered->email,
            'email_normalized' => $registered->email_normalized,
            'username' => 'anna-k',
            'first_name' => 'Anna',
            'last_name' => 'Kowalska',
            'display_name' => 'Anna K.',
            'phone' => $registered->phone,
            'is_registered' => true,
            'role' => 'customer',
            'orders_count' => 1,
            'total_spent' => 199.90,
            'account_created_at' => '2026-07-01 08:00:00',
            'last_synced_at' => '2026-07-13 12:00:00',
        ]);

        ExternalOrder::query()->create([
            'sales_channel_id' => $firstChannel->id,
            'customer_id' => $registered->id,
            'customer_external_account_id' => $registeredAccount->id,
            'wordpress_integration_id' => $firstIntegration->id,
            'customer_match_method' => 'external_id',
            'external_id' => '9001',
            'external_number' => 'WOO-9001',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 199.90,
            'external_created_at' => '2026-07-10 10:00:00',
        ]);

        $guest = Customer::query()->create([
            'email' => 'jan@example.test',
            'email_normalized' => 'jan@example.test',
            'first_name' => 'Jan',
            'last_name' => 'Gość',
            'phone' => '555 300 400',
            'account_status' => 'guest',
            'orders_count' => 1,
            'total_spent' => 89.50,
            'first_order_at' => '2026-07-12 14:00:00',
            'last_order_at' => '2026-07-12 14:00:00',
            'last_synced_at' => '2026-07-13 12:00:00',
        ]);

        $guestAccount = CustomerExternalAccount::query()->create([
            'customer_id' => $guest->id,
            'wordpress_integration_id' => $secondIntegration->id,
            'email' => $guest->email,
            'email_normalized' => $guest->email_normalized,
            'first_name' => 'Jan',
            'last_name' => 'Gość',
            'phone' => $guest->phone,
            'is_registered' => false,
            'orders_count' => 1,
            'total_spent' => 89.50,
            'last_synced_at' => '2026-07-13 12:00:00',
        ]);

        ExternalOrder::query()->create([
            'sales_channel_id' => $secondChannel->id,
            'customer_id' => $guest->id,
            'customer_external_account_id' => $guestAccount->id,
            'wordpress_integration_id' => $secondIntegration->id,
            'customer_match_method' => 'guest_email',
            'external_id' => '9002',
            'external_number' => 'WOO-9002',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 89.50,
            'external_created_at' => '2026-07-12 14:00:00',
        ]);

        return [$registered, $guest, $firstChannel, $secondChannel];
    }

    /**
     * @return array{SalesChannel, WordpressIntegration}
     */
    private function createIntegration(string $code, string $name, string $baseUrl): array
    {
        $channel = SalesChannel::query()->create([
            'code' => $code,
            'name' => $name,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => $name.' WooCommerce',
            'base_url' => $baseUrl,
            'consumer_key_encrypted' => Crypt::encryptString('ck_test_'.$code),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test_'.$code),
        ]);

        return [$channel, $integration];
    }

    private function createUser(string $role, string $email): User
    {
        return User::query()->create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => 'secret-password',
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
