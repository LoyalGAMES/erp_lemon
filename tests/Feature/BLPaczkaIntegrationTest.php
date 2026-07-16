<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Services\Packing\PackingTaskService;
use App\Services\Shipping\CourierPickupTrackingService;
use App\Services\Shipping\ShippingLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BLPaczkaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_blpaczka_label_is_fetched_by_order_meta(): void
    {
        Http::fake([
            '*/api/getWaybill.json' => Http::response([
                'success' => true,
                'data' => [[
                    'filename' => 'etykieta-dpd.pdf',
                    'mime' => 'application/pdf',
                    'content' => base64_encode('%PDF-1.4 blpaczka-label'),
                ]],
            ], 200),
            '*/api/getOrderDetails.json' => Http::response([
                'success' => true,
                'data' => ['Order' => ['waybill_number' => '0000123456789Q']],
            ], 200),
        ]);

        $order = $this->createOrderWithBLPaczkaMeta();
        $account = $this->createBLPaczkaAccount();

        $label = app(ShippingLabelService::class)->generateForOrder($order);

        $this->assertSame('blpaczka', $label->provider);
        $this->assertSame('445566', $label->label_number);
        $this->assertSame('0000123456789Q', $label->tracking_number);
        $this->assertSame($account->id, $label->courier_account_id);
        $this->assertTrue((bool) data_get($label->response_payload, 'reused_existing_shipment'));

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'getWaybill.json')) {
                return true;
            }

            return data_get($request->data(), 'auth.login') === 'sklep@sempre.test'
                && data_get($request->data(), 'auth.api_key') === 'klucz-blp'
                && data_get($request->data(), 'Order.id') === 445566
                && data_get($request->data(), 'Order.printer_type') === 'LBL';
        });
    }

    public function test_blpaczka_order_without_erp_account_gives_actionable_error(): void
    {
        $order = $this->createOrderWithBLPaczkaMeta();

        $this->expectExceptionMessage('nie ma konta BLPaczka');

        app(ShippingLabelService::class)->generateForOrder($order);
    }

    public function test_tracking_marks_blpaczka_parcel_picked_up_from_keywords(): void
    {
        $order = $this->createOrderWithBLPaczkaMeta();
        $account = $this->createBLPaczkaAccount();

        app(PackingTaskService::class)->syncReadyOrders();
        PackingTask::query()
            ->where('external_order_id', $order->id)
            ->update(['status' => 'packed', 'packed_at' => now()]);

        ShippingLabel::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'courier_account_id' => $account->id,
            'status' => 'generated',
            'provider' => 'blpaczka',
            'label_number' => '445566',
            'disk' => 'local',
            'path' => 'shipping-labels/blp.pdf',
            'generated_at' => now(),
        ]);

        Http::fake([
            '*/api/getWaybillTracking.json' => Http::response([
                'success' => true,
                'data' => ['Tracking' => [
                    ['status' => 'Zarejestrowano dane przesyłki', 'date' => now()->subHours(3)->toDateTimeString()],
                    ['status' => 'Przesyłka odebrana od nadawcy', 'date' => now()->subHour()->toDateTimeString()],
                ]],
            ], 200),
        ]);

        $result = app(CourierPickupTrackingService::class)->trackPackedOrders();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['picked_up']);
        $this->assertTrue(
            PackingTask::query()
                ->where('external_order_id', $order->id)
                ->get()
                ->every(fn (PackingTask $task): bool => $task->status === 'shipped'),
        );
        $this->assertSame(
            'blpaczka_tracking',
            data_get(PackingTask::query()->where('external_order_id', $order->id)->first()->metadata, 'courier_pickup.source'),
        );
    }

    public function test_tracking_leaves_blpaczka_parcel_waiting_before_pickup(): void
    {
        $order = $this->createOrderWithBLPaczkaMeta();
        $account = $this->createBLPaczkaAccount();

        app(PackingTaskService::class)->syncReadyOrders();
        PackingTask::query()
            ->where('external_order_id', $order->id)
            ->update(['status' => 'packed', 'packed_at' => now()]);

        ShippingLabel::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'courier_account_id' => $account->id,
            'status' => 'generated',
            'provider' => 'blpaczka',
            'label_number' => '445566',
            'disk' => 'local',
            'path' => 'shipping-labels/blp2.pdf',
            'generated_at' => now(),
        ]);

        Http::fake([
            '*/api/getWaybillTracking.json' => Http::response([
                'success' => true,
                'data' => ['Tracking' => [
                    ['status' => 'Zarejestrowano dane przesyłki', 'date' => now()->toDateTimeString()],
                ]],
            ], 200),
        ]);

        $result = app(CourierPickupTrackingService::class)->trackPackedOrders();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['picked_up']);
    }

    public function test_blpaczka_account_can_be_added_in_settings(): void
    {
        $this->post(route('settings.shipping.accounts.store'), [
            'provider' => 'blpaczka',
            'name' => 'BLPaczka Sempre',
            'code' => 'blp',
            'organization_id' => 'sklep@sempre.test',
            'api_token' => 'klucz-blp',
            'default_parcel_template' => 'small',
            'sending_method' => 'dispatch_order',
            'is_default' => 1,
        ])->assertRedirect()->assertSessionHas('status');

        $account = CourierAccount::query()->firstOrFail();

        $this->assertSame('blpaczka', $account->provider);
        $this->assertSame('klucz-blp', $account->apiToken());

        $this->get(route('settings.shipping'))
            ->assertOk()
            ->assertSee('BLPaczka Sempre')
            ->assertSee('BLPaczka');
    }

    public function test_new_blpaczka_shipment_is_created_with_courier_matched_from_cart_method(): void
    {
        Http::fake([
            '*/api/getValuation.json' => Http::response([
                'success' => true,
                'data' => ['results' => [
                    ['Courier' => ['name' => 'GLS', 'courier_code' => 'gls'], 'Price' => ['value' => '11.99']],
                    ['Courier' => ['name' => 'Kurier DPD', 'courier_code' => 'dpd_classic'], 'Price' => ['value' => '14.50']],
                ]],
            ], 200),
            '*/api/createOrderV2.json' => Http::response([
                'success' => true,
                'data' => ['blpaczka_order_id' => 778899],
            ], 200),
            '*/api/getWaybill.json' => Http::response([
                'success' => true,
                'data' => [[
                    'filename' => 'dpd.pdf',
                    'mime' => 'application/pdf',
                    'content' => base64_encode('%PDF-1.4 created-label'),
                ]],
            ], 200),
            '*/api/getOrderDetails.json' => Http::response([
                'success' => true,
                'data' => ['Order' => ['waybill_number' => '9988776655']],
            ], 200),
        ]);

        $order = $this->createOrderWithBLPaczkaMeta(withPluginShipment: false);
        $account = $this->createBLPaczkaAccount(withSenderAndParcel: true);

        $label = app(ShippingLabelService::class)->generateForOrder($order, $account);

        $this->assertSame('blpaczka', $label->provider);
        $this->assertSame('778899', $label->label_number);
        $this->assertSame('9988776655', $label->tracking_number);
        $this->assertFalse((bool) data_get($label->response_payload, 'reused_existing_shipment'));
        $this->assertSame('dpd_classic', data_get($label->response_payload, 'blpaczka.courier_code'));

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'createOrderV2.json')) {
                return true;
            }

            return data_get($request->data(), 'CourierSearch.courier_code') === 'dpd_classic'
                && data_get($request->data(), 'Cart.0.Order.name') === 'Sempre Sp. z o.o.'
                && data_get($request->data(), 'Cart.0.Order.taker_street') === 'ul. Krzywa'
                && data_get($request->data(), 'Cart.0.Order.taker_house_no') === '2'
                && data_get($request->data(), 'Cart.0.Order.taker_city') === 'Kraków'
                && data_get($request->data(), 'CartOrder.payment') === 'bank'
                && data_get($request->data(), 'CourierSearch.weight') === 2.0;
        });
    }

    public function test_new_blpaczka_shipment_falls_back_to_cheapest_offer(): void
    {
        Http::fake([
            '*/api/getValuation.json' => Http::response([
                'success' => true,
                'data' => ['results' => [
                    ['Courier' => ['name' => 'UPS Standard', 'courier_code' => 'ups'], 'Price' => ['value' => '19.99']],
                    ['Courier' => ['name' => 'GLS', 'courier_code' => 'gls'], 'Price' => ['value' => '11.99']],
                ]],
            ], 200),
            '*/api/createOrderV2.json' => Http::response([
                'success' => true,
                'data' => ['blpaczka_order_id' => 111222],
            ], 200),
            '*/api/getWaybill.json' => Http::response([
                'success' => true,
                'data' => [[
                    'filename' => 'gls.pdf',
                    'mime' => 'application/pdf',
                    'content' => base64_encode('%PDF-1.4 gls-label'),
                ]],
            ], 200),
            '*/api/getOrderDetails.json' => Http::response(['success' => true, 'data' => ['Order' => []]], 200),
        ]);

        $order = $this->createOrderWithBLPaczkaMeta(withPluginShipment: false, methodTitle: 'Kurier standardowy');
        $this->createBLPaczkaAccount(withSenderAndParcel: true);

        $label = app(ShippingLabelService::class)->generateForOrder($order);

        $this->assertSame('gls', data_get($label->response_payload, 'blpaczka.courier_code'));
    }

    public function test_new_blpaczka_cod_shipment_sends_uptake_and_cover_to_valuation_and_order(): void
    {
        Http::fake([
            '*/api/getValuation.json' => Http::response([
                'success' => true,
                'data' => ['results' => [
                    ['Courier' => ['name' => 'Kurier DPD', 'courier_code' => 'dpd_classic'], 'Price' => ['value' => '16.50']],
                ]],
            ]),
            '*/api/createOrderV2.json' => Http::response(['success' => true, 'data' => ['blpaczka_order_id' => 778900]]),
            '*/api/getWaybill.json' => Http::response(['success' => true, 'data' => [[
                'filename' => 'dpd-cod.pdf',
                'mime' => 'application/pdf',
                'content' => base64_encode('%PDF-1.4 cod-label'),
            ]]]),
            '*/api/getOrderDetails.json' => Http::response(['success' => true, 'data' => ['Order' => ['waybill_number' => 'COD9988']]]),
        ]);

        $order = $this->createOrderWithBLPaczkaMeta(withPluginShipment: false);
        $order->update(['raw_payload' => array_replace($order->raw_payload, [
            'payment_method' => 'cod',
            'payment_method_title' => 'Płatność przy odbiorze',
        ])]);

        app(ShippingLabelService::class)->generateForOrder($order->fresh(), $this->createBLPaczkaAccount(withSenderAndParcel: true));

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'getValuation.json') && ! str_contains($request->url(), 'createOrderV2.json')) {
                return true;
            }

            return (float) data_get($request->data(), 'CourierSearch.uptake') === 250.0
                && (float) data_get($request->data(), 'CourierSearch.cover') === 250.0;
        });
    }

    public function test_new_blpaczka_shipment_requires_sender_and_parcel_config(): void
    {
        $order = $this->createOrderWithBLPaczkaMeta(withPluginShipment: false);
        $account = $this->createBLPaczkaAccount(withSenderAndParcel: false);

        $this->expectExceptionMessage('danych nadawcy BLPaczka');

        app(ShippingLabelService::class)->generateForOrder($order, $account);
    }

    private function createOrderWithBLPaczkaMeta(bool $withPluginShipment = true, string $methodTitle = 'Kurier DPD (BLPaczka)'): ExternalOrder
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-BLP',
            'name' => 'Sukienka BLP',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9324',
            'external_number' => '9324',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 250,
            'billing_data' => ['email' => 'k@example.test', 'first_name' => 'Jan', 'last_name' => 'Klient'],
            'shipping_data' => [
                'first_name' => 'Jan',
                'last_name' => 'Klient',
                'address_1' => 'ul. Krzywa 2',
                'postcode' => '30-002',
                'city' => 'Kraków',
                'country' => 'PL',
                'phone' => '500600700',
            ],
            'raw_payload' => [
                'shipping_lines' => [['method_title' => $methodTitle]],
                'meta_data' => $withPluginShipment
                    ? [['key' => 'BLPACZKA_blpaczka_order_id', 'value' => '445566']]
                    : [],
            ],
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => '41',
            'sku' => 'SKU-BLP',
            'name' => 'Sukienka BLP',
            'quantity' => 1,
            'unit_gross_price' => 250,
        ]);

        return $order;
    }

    private function createBLPaczkaAccount(bool $withSenderAndParcel = false): CourierAccount
    {
        $account = new CourierAccount([
            'provider' => 'blpaczka',
            'code' => 'blp',
            'name' => 'BLPaczka Sempre',
            'organization_id' => 'sklep@sempre.test',
            'default_parcel_template' => 'small',
            'sending_method' => 'dispatch_order',
            'is_default' => true,
            'is_active' => true,
            'metadata' => $withSenderAndParcel ? [
                'sender' => [
                    'name' => 'Sempre Sp. z o.o.',
                    'street' => 'Magazynowa',
                    'house_no' => '5',
                    'postal' => '30-001',
                    'city' => 'Kraków',
                    'phone' => '48123456789',
                    'email' => 'magazyn@sempre.test',
                ],
                'parcel' => ['weight' => 2, 'side_x' => 40, 'side_y' => 30, 'side_z' => 15],
                'payment' => 'bank',
            ] : null,
        ]);
        $account->setApiToken('klucz-blp');
        $account->save();

        return $account;
    }
}
