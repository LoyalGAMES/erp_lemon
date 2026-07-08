<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
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
                && data_get($request->data(), 'Order.id') === 445566;
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

        app(\App\Services\Packing\PackingTaskService::class)->syncReadyOrders();
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

        app(\App\Services\Packing\PackingTaskService::class)->syncReadyOrders();
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

    private function createOrderWithBLPaczkaMeta(): ExternalOrder
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
            'shipping_data' => ['first_name' => 'Jan', 'last_name' => 'Klient'],
            'raw_payload' => [
                'shipping_lines' => [['method_title' => 'Kurier DPD (BLPaczka)']],
                'meta_data' => [
                    ['key' => 'BLPACZKA_blpaczka_order_id', 'value' => '445566'],
                ],
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

    private function createBLPaczkaAccount(): CourierAccount
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
        ]);
        $account->setApiToken('klucz-blp');
        $account->save();

        return $account;
    }
}
