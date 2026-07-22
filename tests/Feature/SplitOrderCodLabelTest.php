<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\Shipping\ShippingLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class SplitOrderCodLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_cod_order_creates_new_inpost_shipment_with_only_the_split_part_amount(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/v1/shipments/987654/label*' => Http::response('^XA inherited ^XZ', 200),
            '*/v1/shipments/987654' => Http::response([
                'id' => '987654',
                'status' => 'confirmed',
                'tracking_number' => '520000000000000000987654',
            ], 200),
            '*/v1/organizations/111/shipments' => Http::response([
                'id' => 'SPLIT-SHIP-1',
                'status' => 'created',
            ], 201),
            '*/v1/shipments/SPLIT-SHIP-1/label*' => Http::response('^XA split ^XZ', 200),
            '*/v1/shipments/SPLIT-SHIP-1' => Http::response([
                'id' => 'SPLIT-SHIP-1',
                'status' => 'confirmed',
                'tracking_number' => '520000000000000000000001',
            ], 200),
        ]);

        [$root, $splitOrder] = $this->createSplitCodOrder([
            'shipping_lines' => [['method_title' => 'Kurier InPost']],
            'meta_data' => [
                ['key' => '_inpost_shipment_id', 'value' => '987654'],
            ],
        ]);
        $account = $this->createInPostAccount();

        $label = app(ShippingLabelService::class)->generateForOrder($splitOrder, $account);

        $this->assertSame('SPLIT-SHIP-1', $label->label_number);
        $this->assertSame($splitOrder->id, $label->external_order_id);
        $this->assertFalse((bool) data_get($label->response_payload, 'shipment.reused_existing_shipment'));
        $this->assertSame($splitOrder->id, data_get($label->response_payload, 'financial.order_id'));
        $this->assertEqualsWithDelta(73.42, (float) data_get($label->response_payload, 'financial.order_total'), 0.001);
        $this->assertEqualsWithDelta(73.42, (float) data_get($label->response_payload, 'financial.requested_cod_amount'), 0.001);
        $this->assertSame('PLN', data_get($label->response_payload, 'financial.currency'));
        $this->assertTrue((bool) data_get($label->response_payload, 'financial.cash_on_delivery'));
        $this->assertSame($root->id, data_get($label->response_payload, 'financial.split_family_root_order_id'));

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST'
                || ! str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/shipments')) {
                return false;
            }

            return (float) data_get($request->data(), 'cod.amount') === 73.42
                && data_get($request->data(), 'cod.currency') === 'PLN'
                && (float) data_get($request->data(), 'insurance.amount') === 73.42
                && data_get($request->data(), 'insurance.currency') === 'PLN';
        });
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/shipments/987654'));
    }

    public function test_split_cod_order_refuses_woocommerce_label_endpoint_before_sending_request(): void
    {
        Storage::fake('local');
        Http::fake();

        [, $splitOrder, $channel] = $this->createSplitCodOrder();
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo etykiety',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
            'invoice_upload_enabled' => true,
            'settings' => [
                'shipping_labels' => [
                    'enabled' => true,
                    'endpoint' => '/wp-json/ship/v1/orders/{order_id}/label',
                    'method' => 'POST',
                    'auth' => 'woocommerce',
                ],
            ],
        ]);

        try {
            app(ShippingLabelService::class)->generateForOrder($splitOrder);
            $this->fail('Etykieta COD dla podzielonego zamówienia nie powinna użyć endpointu WooCommerce.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('bezpośredniego konta InPost lub BLPaczka', $exception->getMessage());
            $this->assertStringContainsString('kwoty COD dla tej części zamówienia', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('shipping_labels', 0);
    }

    public function test_split_cod_automation_prefers_a_default_direct_account_over_the_woocommerce_endpoint(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/v1/organizations/111/shipments' => Http::response([
                'id' => 'SPLIT-AUTO-1',
                'status' => 'created',
            ], 201),
            '*/v1/shipments/SPLIT-AUTO-1/label*' => Http::response('^XA split auto ^XZ', 200),
            '*/v1/shipments/SPLIT-AUTO-1' => Http::response([
                'id' => 'SPLIT-AUTO-1',
                'status' => 'confirmed',
                'tracking_number' => '520000000000000000000002',
            ], 200),
            '*' => Http::response(['message' => 'Unexpected endpoint'], 500),
        ]);

        [, $splitOrder, $channel] = $this->createSplitCodOrder([
            'shipping_lines' => [['method_title' => 'Kurier InPost']],
        ]);
        $account = $this->createInPostAccount();
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo etykiety',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
            'settings' => [
                'shipping_labels' => [
                    'enabled' => true,
                    'endpoint' => '/wp-json/ship/v1/orders/{order_id}/label',
                    'method' => 'POST',
                    'auth' => 'woocommerce',
                ],
            ],
        ]);

        $label = app(ShippingLabelService::class)->generateForOrder($splitOrder);

        $this->assertSame($account->id, $label->courier_account_id);
        $this->assertSame('SPLIT-AUTO-1', $label->label_number);
        $this->assertEqualsWithDelta(73.42, (float) data_get($label->response_payload, 'financial.requested_cod_amount'), 0.001);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'shop.test'));
    }

    public function test_split_cod_order_creates_new_blpaczka_shipment_with_only_the_split_part_amount(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/api/getValuation.json' => Http::response([
                'success' => true,
                'data' => ['results' => [[
                    'Courier' => ['name' => 'Kurier DPD', 'courier_code' => 'dpd_classic'],
                    'Price' => ['value' => '16.50'],
                ]]],
            ], 200),
            '*/api/createOrderV2.json' => Http::response([
                'success' => true,
                'data' => ['blpaczka_order_id' => 778901],
            ], 200),
            '*/api/getWaybill.json' => Http::response([
                'success' => true,
                'data' => [[
                    'filename' => 'split-cod.pdf',
                    'mime' => 'application/pdf',
                    'content' => base64_encode('%PDF-1.4 split-cod'),
                ]],
            ], 200),
            '*/api/getOrderDetails.json' => Http::response([
                'success' => true,
                'data' => ['Order' => ['waybill_number' => 'COD-SPLIT-778901']],
            ], 200),
        ]);

        [$root, $splitOrder] = $this->createSplitCodOrder([
            'shipping_lines' => [['method_title' => 'Kurier DPD (BLPaczka)']],
            'meta_data' => [
                ['key' => 'BLPACZKA_blpaczka_order_id', 'value' => '445566'],
            ],
        ]);

        $label = app(ShippingLabelService::class)->generateForOrder(
            $splitOrder,
            $this->createBLPaczkaAccount(),
        );

        $this->assertSame('778901', $label->label_number);
        $this->assertFalse((bool) data_get($label->response_payload, 'reused_existing_shipment'));
        $this->assertEqualsWithDelta(73.42, (float) data_get($label->response_payload, 'financial.order_total'), 0.001);
        $this->assertEqualsWithDelta(73.42, (float) data_get($label->response_payload, 'financial.requested_cod_amount'), 0.001);
        $this->assertTrue((bool) data_get($label->response_payload, 'financial.cash_on_delivery'));
        $this->assertSame($root->id, data_get($label->response_payload, 'financial.split_family_root_order_id'));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'getValuation.json')
            && (float) data_get($request->data(), 'CourierSearch.uptake') === 73.42
            && (float) data_get($request->data(), 'CourierSearch.cover') === 73.42);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'createOrderV2.json')
            && (float) data_get($request->data(), 'CourierSearch.uptake') === 73.42
            && (float) data_get($request->data(), 'CourierSearch.cover') === 73.42);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'getWaybill.json')
            && (int) data_get($request->data(), 'Order.id') === 445566);
    }

    /**
     * @param  array<string, mixed>  $extraRawPayload
     * @return array{ExternalOrder,ExternalOrder,SalesChannel}
     */
    private function createSplitCodOrder(array $extraRawPayload = []): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $basePayload = [
            'payment_method' => 'cod',
            'payment_method_title' => 'Płatność przy odbiorze',
        ];

        $root = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '8801',
            'external_number' => '8801',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 176.58,
            'billing_data' => $this->customerAddress(),
            'shipping_data' => $this->customerAddress(),
            'raw_payload' => $basePayload,
        ]);

        $splitOrder = ExternalOrder::query()->create([
            'split_parent_order_id' => $root->id,
            'split_root_order_id' => $root->id,
            'sales_channel_id' => $channel->id,
            'external_id' => '8801-SPLIT-1',
            'external_number' => '8801/S1',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 73.42,
            'billing_data' => $this->customerAddress(),
            'shipping_data' => $this->customerAddress(),
            'raw_payload' => array_replace_recursive($basePayload, $extraRawPayload),
        ]);

        return [$root, $splitOrder, $channel];
    }

    /** @return array<string, string> */
    private function customerAddress(): array
    {
        return [
            'first_name' => 'Jan',
            'last_name' => 'Klient',
            'email' => 'jan@example.test',
            'phone' => '+48111222333',
            'address_1' => 'ul. Krzywa 2',
            'postcode' => '30-002',
            'city' => 'Kraków',
            'country' => 'PL',
        ];
    }

    private function createInPostAccount(): CourierAccount
    {
        $account = new CourierAccount([
            'provider' => 'inpost',
            'code' => 'split-inpost',
            'name' => 'InPost split COD',
            'organization_id' => '111',
            'default_parcel_template' => 'small',
            'sending_method' => 'dispatch_order',
            'is_default' => true,
            'is_active' => true,
        ]);
        $account->setApiToken('token-inpost');
        $account->save();

        return $account;
    }

    private function createBLPaczkaAccount(): CourierAccount
    {
        $account = new CourierAccount([
            'provider' => 'blpaczka',
            'code' => 'split-blp',
            'name' => 'BLPaczka split COD',
            'organization_id' => 'sklep@sempre.test',
            'is_default' => true,
            'is_active' => true,
            'metadata' => [
                'sender' => [
                    'name' => 'Sempre Sp. z o.o.',
                    'street' => 'Magazynowa',
                    'house_no' => '5',
                    'postal' => '30-001',
                    'city' => 'Kraków',
                    'phone' => '48123456789',
                    'email' => 'magazyn@sempre.test',
                ],
                'parcel' => [
                    'weight' => 2,
                    'side_x' => 40,
                    'side_y' => 30,
                    'side_z' => 15,
                ],
                'payment' => 'bank',
            ],
        ]);
        $account->setApiToken('token-blp');
        $account->save();

        return $account;
    }
}
