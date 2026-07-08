<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShipmentGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_page_shows_generate_shipment_form_and_creates_inpost_label(): void
    {
        Http::fake([
            '*/v1/organizations/111/shipments?*' => Http::response(['items' => []], 200),
            '*/v1/organizations/111/shipments' => Http::response(['id' => 'SHIP-9', 'status' => 'created'], 201),
            '*/v1/shipments/SHIP-9/label*' => Http::response('%PDF-1.4 order-label', 200, ['Content-Type' => 'application/pdf']),
            '*/v1/shipments/SHIP-9' => Http::response([
                'id' => 'SHIP-9',
                'status' => 'confirmed',
                'tracking_number' => '520000123123123123123123',
            ], 200),
        ]);

        $order = $this->createOrder();
        $account = $this->createAccount();

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Generuj przesyłkę')
            ->assertSee('InPost: Konto główne');

        $this->post(route('orders.label.generate', $order), [
            'courier_account_id' => $account->id,
        ])->assertRedirect()->assertSessionHas('status');

        $label = ShippingLabel::query()->firstOrFail();

        $this->assertSame($order->id, $label->external_order_id);
        $this->assertSame('inpost', $label->provider);
        $this->assertSame($account->id, $label->courier_account_id);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Pobierz etykietę')
            ->assertDontSee('Generuj przesyłkę</button>', false);
    }

    public function test_return_label_is_generated_with_reversed_direction_to_warehouse(): void
    {
        Http::fake([
            '*/v1/organizations/111/shipments?*' => Http::response(['items' => []], 200),
            '*/v1/organizations/111/shipments' => Http::response(['id' => 'SHIP-RET', 'status' => 'created'], 201),
            '*/v1/shipments/SHIP-RET/label*' => Http::response('%PDF-1.4 return-label', 200, ['Content-Type' => 'application/pdf']),
            '*/v1/shipments/SHIP-RET' => Http::response([
                'id' => 'SHIP-RET',
                'status' => 'confirmed',
                'tracking_number' => '520000999999999999999999',
            ], 200),
        ]);

        $returnCase = $this->createReturnCase();
        $account = $this->createAccount(withReturnAddress: true);

        $this->get(route('returns.index'))
            ->assertOk()
            ->assertSee('Otwórz kartę')
            ->assertDontSee('Generuj przesyłkę zwrotną');

        $this->get(route('returns.show', $returnCase))
            ->assertOk()
            ->assertSee('Generuj przesyłkę zwrotną');

        $this->post(route('returns.shipping-label.create', $returnCase), [
            'courier_account_id' => $account->id,
        ])->assertRedirect()->assertSessionHas('status');

        $label = ShippingLabel::query()->firstOrFail();

        $this->assertSame($returnCase->id, $label->return_case_id);
        $this->assertSame('return', data_get($label->response_payload, 'direction'));

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/shipments') || $request->method() !== 'POST') {
                return true;
            }

            return data_get($request->data(), 'receiver.company_name') === 'Sempre Magazyn'
                && data_get($request->data(), 'custom_attributes.target_point') === 'KRA010'
                && data_get($request->data(), 'custom_attributes.sending_method') === 'parcel_locker'
                && str_starts_with((string) data_get($request->data(), 'reference'), 'ZWROT ');
        });

        $this->get(route('returns.labels.download', $label))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->get(route('returns.show', $returnCase))
            ->assertOk()
            ->assertSee('Etykieta zwrotna');
    }

    public function test_automatic_label_falls_back_to_default_inpost_account_when_store_endpoint_missing(): void
    {
        Http::fake([
            '*/v1/organizations/111/shipments?*' => Http::response(['items' => []], 200),
            '*/v1/organizations/111/shipments' => Http::response(['id' => 'SHIP-FB', 'status' => 'created'], 201),
            '*/v1/shipments/SHIP-FB/label*' => Http::response('%PDF-1.4 fallback-label', 200, ['Content-Type' => 'application/pdf']),
            '*/v1/shipments/SHIP-FB' => Http::response([
                'id' => 'SHIP-FB',
                'status' => 'confirmed',
                'tracking_number' => '520000555555555555555555',
            ], 200),
        ]);

        $order = $this->createOrder();
        $order->update(['raw_payload' => ['shipping_lines' => [['method_title' => 'InPost Paczkomaty 24/7']]]]);
        $account = $this->createAccount();

        $label = app(\App\Services\Shipping\ShippingLabelService::class)->generateForOrder($order->fresh());

        $this->assertSame('inpost', $label->provider);
        $this->assertSame($account->id, $label->courier_account_id);
        $this->assertSame($order->id, $label->external_order_id);
    }

    public function test_automatic_fallback_refuses_inpost_label_for_other_courier_order(): void
    {
        $order = $this->createOrder();
        $order->update(['raw_payload' => ['shipping_lines' => [['method_title' => 'Kurier DPD (BLPaczka)']]]]);
        $this->createAccount();

        $this->expectExceptionMessage('Brak konfiguracji etykiet');

        app(\App\Services\Shipping\ShippingLabelService::class)->generateForOrder($order->fresh());

        $this->assertSame(0, ShippingLabel::query()->count());
    }

    public function test_automatic_label_without_any_configuration_gives_actionable_error(): void
    {
        $order = $this->createOrder();

        $this->expectExceptionMessage('Włącz etykiety kurierskie w Integracjach');

        app(\App\Services\Shipping\ShippingLabelService::class)->generateForOrder($order);
    }

    public function test_locker_shipment_detects_official_inpost_plugin_meta_keys(): void
    {
        Http::fake([
            '*/v1/organizations/111/shipments?*' => Http::response(['items' => []], 200),
            '*/v1/organizations/111/shipments' => Http::response(['id' => 'SHIP-LOC', 'status' => 'created'], 201),
            '*/v1/shipments/SHIP-LOC/label*' => Http::response('%PDF-1.4 locker-label', 200, ['Content-Type' => 'application/pdf']),
            '*/v1/shipments/SHIP-LOC' => Http::response(['id' => 'SHIP-LOC', 'status' => 'confirmed', 'tracking_number' => '520000777'], 200),
        ]);

        $order = $this->createOrder();
        $raw = (array) $order->raw_payload;
        $raw['shipping_lines'] = [[
            'method_title' => 'InPost Paczkomaty',
            'meta_data' => [
                ['key' => '_inpost_locker_id', 'value' => 'KRA05H'],
            ],
        ]];
        $order->update(['raw_payload' => $raw]);

        $account = $this->createAccount();

        app(\App\Services\Shipping\ShippingLabelService::class)->generateForOrder($order->fresh(), $account);

        Http::assertSent(function ($request): bool {
            if (! str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/shipments') || $request->method() !== 'POST') {
                return true;
            }

            return data_get($request->data(), 'service') === 'inpost_locker_standard'
                && data_get($request->data(), 'custom_attributes.target_point') === 'KRA05H';
        });
    }

    public function test_existing_plugin_shipment_is_reused_instead_of_creating_duplicate(): void
    {
        Http::fake([
            '*/v1/organizations/111/shipments?*' => Http::response(['items' => [
                [
                    'id' => 'SHIP-PLUGIN',
                    'status' => 'confirmed',
                    'reference' => '801',
                    'tracking_number' => '520000444444444444444444',
                ],
            ]], 200),
            '*/v1/shipments/SHIP-PLUGIN/label*' => Http::response('%PDF-1.4 plugin-label', 200, ['Content-Type' => 'application/pdf']),
            '*/v1/shipments/SHIP-PLUGIN' => Http::response([
                'id' => 'SHIP-PLUGIN',
                'status' => 'confirmed',
                'tracking_number' => '520000444444444444444444',
            ], 200),
        ]);

        $order = $this->createOrder();
        $account = $this->createAccount();

        $label = app(\App\Services\Shipping\ShippingLabelService::class)->generateForOrder($order, $account);

        $this->assertSame('SHIP-PLUGIN', $label->label_number);
        $this->assertSame('520000444444444444444444', $label->tracking_number);
        $this->assertTrue((bool) data_get($label->response_payload, 'shipment.reused_existing_shipment'));

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/shipments'));
    }

    public function test_existing_shipment_is_found_by_meta_shipment_id(): void
    {
        Http::fake([
            '*/v1/shipments/987654/label*' => Http::response('%PDF-1.4 meta-label', 200, ['Content-Type' => 'application/pdf']),
            '*/v1/shipments/987654' => Http::response([
                'id' => 987654,
                'status' => 'dispatched_by_sender',
                'tracking_number' => '520000333333333333333333',
            ], 200),
        ]);

        $order = $this->createOrder();
        $raw = (array) $order->raw_payload;
        $raw['meta_data'] = [
            ['key' => '_inpost_shipment_id', 'value' => '987654'],
        ];
        $order->update(['raw_payload' => $raw]);

        $account = $this->createAccount();

        $label = app(\App\Services\Shipping\ShippingLabelService::class)->generateForOrder($order->fresh(), $account);

        $this->assertSame('987654', $label->label_number);
        $this->assertSame('520000333333333333333333', $label->tracking_number);

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/shipments'));
    }

    public function test_return_label_requires_configured_return_address(): void
    {
        $returnCase = $this->createReturnCase();
        $account = $this->createAccount(withReturnAddress: false);

        $this->post(route('returns.shipping-label.create', $returnCase), [
            'courier_account_id' => $account->id,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, ShippingLabel::query()->count());
    }

    public function test_return_address_can_be_saved_on_courier_account(): void
    {
        $account = $this->createAccount();

        $this->put(route('settings.shipping.accounts.update', $account), [
            'name' => $account->name,
            'organization_id' => $account->organization_id,
            'default_parcel_template' => 'small',
            'sending_method' => 'dispatch_order',
            'is_default' => 1,
            'is_active' => 1,
            'return_name' => 'Sempre Magazyn',
            'return_phone' => '48123456789',
            'return_email' => 'magazyn@sempre.test',
            'return_target_point' => 'kra010',
        ])->assertRedirect()->assertSessionHas('status');

        $returnConfig = data_get($account->fresh()->metadata, 'return');

        $this->assertSame('Sempre Magazyn', $returnConfig['name']);
        $this->assertSame('KRA010', $returnConfig['target_point']);
    }

    private function createOrder(): ExternalOrder
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '801',
            'external_number' => '801',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 150,
            'billing_data' => [
                'first_name' => 'Jan',
                'last_name' => 'Klient',
                'email' => 'jan@example.test',
                'phone' => '+48111222333',
                'address_1' => 'ul. Prosta 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'shipping_data' => [
                'first_name' => 'Jan',
                'last_name' => 'Klient',
                'address_1' => 'ul. Krzywa 2',
                'postcode' => '30-002',
                'city' => 'Kraków',
                'country' => 'PL',
            ],
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-LBL',
            'name' => 'Sukienka Etykieta',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => '31',
            'sku' => 'SKU-LBL',
            'name' => 'Sukienka Etykieta',
            'quantity' => 1,
            'unit_gross_price' => 150,
        ]);

        return $order;
    }

    private function createReturnCase(): ReturnCase
    {
        Warehouse::query()->create([
            'code' => 'RET',
            'name' => 'Magazyn zwrotów',
            'type' => 'returns',
            'is_active' => true,
        ]);

        return ReturnCase::query()->create([
            'number' => 'RET/2026/000009',
            'status' => 'pending',
            'customer_email' => 'jan@example.test',
            'metadata' => ['source' => 'store_form', 'return_reference' => 'LLR-TEST-9'],
        ]);
    }

    private function createAccount(bool $withReturnAddress = false): CourierAccount
    {
        $account = new CourierAccount([
            'provider' => 'inpost',
            'code' => 'glowne',
            'name' => 'Konto główne',
            'organization_id' => '111',
            'default_parcel_template' => 'small',
            'sending_method' => 'dispatch_order',
            'is_default' => true,
            'is_active' => true,
            'metadata' => $withReturnAddress ? [
                'return' => [
                    'name' => 'Sempre Magazyn',
                    'phone' => '48123456789',
                    'email' => 'magazyn@sempre.test',
                    'target_point' => 'KRA010',
                ],
            ] : null,
        ]);
        $account->setApiToken('token-main');
        $account->save();

        return $account;
    }
}
