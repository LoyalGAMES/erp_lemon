<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderListPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_module_loads_the_latest_page_without_rendering_every_order(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        foreach (range(1, 60) as $index) {
            ExternalOrder::query()->create([
                'sales_channel_id' => $channel->id,
                'external_id' => (string) (9000 + $index),
                'external_number' => (string) (9000 + $index),
                'status' => 'processing',
                'currency' => 'PLN',
                'total_gross' => $index,
                'external_created_at' => now()->subMinutes(60 - $index),
            ]);
        }

        $response = $this->get(route('modules.show', 'orders'))
            ->assertOk()
            ->assertSee('Filtry i wyszukiwanie')
            ->assertSee('orders-mobile-filter-toggle', false)
            ->assertSee('9060')
            ->assertSee('9011')
            ->assertDontSee('9010')
            ->assertDontSee('9001');

        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*900px\)\s*\{\s*\.orders-mobile-filter-trigger/s',
            $html,
        );
        foreach (['Zamówienie', 'Klient', 'Przedmioty', 'Dostawa', 'Status', 'Kwota', 'Utworzone', 'Akcje'] as $label) {
            $this->assertStringContainsString('data-label="'.$label.'"', $html);
        }
    }

    public function test_orders_module_searches_customer_fields_and_displays_order_context(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-AMELIA-SM',
            'name' => 'Garnitur AMELIA Tenis Butter Cream - S/M',
            'vat_rate' => 23,
            'attributes' => [
                'woocommerce_image' => [
                    'src' => 'https://shop.test/uploads/amelia.jpg',
                ],
            ],
            'is_active' => true,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9710',
            'external_number' => '9710',
            'status' => 'on-hold',
            'currency' => 'PLN',
            'total_gross' => 359.99,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
                'email' => 'anna.nowak@example.test',
                'phone' => '+48 600 700 800',
            ],
            'shipping_data' => [
                'phone' => '+48 600 700 800',
            ],
            'raw_payload' => [
                'customer_note' => 'Proszę dołączyć papierową kartkę z życzeniami.',
                'shipping_lines' => [
                    ['method_title' => 'InPost Kurier Standard'],
                ],
            ],
            'external_created_at' => now(),
        ]);
        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => '1',
            'sku' => 'SKU-AMELIA-SM',
            'name' => 'Garnitur AMELIA Tenis Butter Cream - S/M',
            'quantity' => 1,
            'raw_payload' => [],
        ]);
        $courierAccount = CourierAccount::query()->create([
            'provider' => 'inpost',
            'code' => 'inpost-main',
            'name' => 'Magazyn Warszawa',
            'api_token_encrypted' => Crypt::encryptString('token'),
            'organization_id' => 'org-1',
            'is_default' => true,
            'is_active' => true,
        ]);
        ShippingLabel::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->id,
            'courier_account_id' => $courierAccount->id,
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => 'LBL-9710',
            'tracking_number' => 'TRK-9710',
            'disk' => 'local',
            'path' => 'labels/lbl-9710.pdf',
            'generated_at' => now(),
        ]);

        ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9711',
            'external_number' => '9711',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 99.99,
            'billing_data' => [
                'first_name' => 'Maria',
                'last_name' => 'Kowalska',
                'email' => 'maria@example.test',
                'phone' => '+48 111 222 333',
            ],
            'external_created_at' => now()->subMinute(),
        ]);

        $this->get(route('modules.show', 'orders'))
            ->assertOk()
            ->assertSee('Anna Nowak')
            ->assertSee('anna.nowak@example.test')
            ->assertSee('+48 600 700 800')
            ->assertSee('ID Woo: 9710')
            ->assertSee('ERP: #'.$order->id)
            ->assertSee('Garnitur AMELIA Tenis Butter Cream - S/M')
            ->assertSee('SKU-AMELIA-SM')
            ->assertSee('order-item-thumb', false)
            ->assertSee('InPost: Magazyn Warszawa')
            ->assertSee('TRK-9710')
            ->assertSee('on-hold')
            ->assertSee('Notatka klienta')
            ->assertSee('Proszę dołączyć papierową kartkę z życzeniami.')
            ->assertSee('order-customer-note', false)
            ->assertSee('359,99 PLN');

        foreach (['Anna', 'Nowak', '600700800', 'anna.nowak@example.test', 'on-hold', '9710'] as $term) {
            $this->get(route('modules.show', ['module' => 'orders', 'q' => $term]))
                ->assertOk()
                ->assertSee('9710')
                ->assertDontSee('9711');
        }

        $this->get(route('modules.show', ['module' => 'orders', 'status' => 'completed']))
            ->assertOk()
            ->assertSee('9711')
            ->assertDontSee('9710');
    }

    public function test_orders_module_handles_legacy_non_array_json_payloads(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9801',
            'external_number' => '9801',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 129.99,
            'billing_data' => 'legacy-billing',
            'shipping_data' => 'legacy-shipping',
            'raw_payload' => 'legacy-payload',
            'external_created_at' => now(),
        ]);
        $line = $order->lines()->create([
            'external_line_id' => '1',
            'sku' => 'LEGACY-SKU',
            'name' => 'Produkt z importu legacy',
            'quantity' => 1,
            'raw_payload' => 'legacy-line-payload',
        ]);

        DB::table('external_orders')
            ->whereKey($order->id)
            ->update([
                'billing_data' => json_encode('legacy-billing'),
                'shipping_data' => json_encode('legacy-shipping'),
                'raw_payload' => json_encode('legacy-payload'),
            ]);
        DB::table('external_order_lines')
            ->whereKey($line->id)
            ->update(['raw_payload' => json_encode('legacy-line-payload')]);

        $this->get(route('modules.show', 'orders'))
            ->assertOk()
            ->assertSee('Klient bez nazwy')
            ->assertSee('brak e-maila')
            ->assertSee('Produkt z importu legacy');
    }

    public function test_orders_module_filters_by_payment_and_shipping_methods(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-FILTERS',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        foreach ([
            ['number' => 'COD-INPOST', 'payment' => 'Płatność przy odbiorze', 'shipping' => 'InPost Paczkomat'],
            ['number' => 'PAYU-DPD', 'payment' => 'PayU', 'shipping' => 'Kurier DPD'],
        ] as $data) {
            ExternalOrder::query()->create([
                'sales_channel_id' => $channel->id,
                'external_id' => $data['number'],
                'external_number' => $data['number'],
                'status' => 'processing',
                'currency' => 'PLN',
                'total_gross' => 100,
                'raw_payload' => [
                    'payment_method' => $data['payment'] === 'PayU' ? 'payu' : 'cod',
                    'payment_method_title' => $data['payment'],
                    'shipping_lines' => [['method_id' => 'flat_rate', 'method_title' => $data['shipping']]],
                ],
            ]);
        }

        $this->get(route('modules.show', ['module' => 'orders']))
            ->assertOk()
            ->assertSee('Forma płatności')
            ->assertSee('Forma dostawy')
            ->assertSee('Płatność przy odbiorze')
            ->assertSee('Kurier DPD');

        $this->get(route('modules.show', ['module' => 'orders', 'payment_method' => 'Płatność przy odbiorze']))
            ->assertOk()
            ->assertSee('COD-INPOST')
            ->assertDontSee('PAYU-DPD');

        $this->get(route('modules.show', ['module' => 'orders', 'shipping_method' => 'Kurier DPD']))
            ->assertOk()
            ->assertSee('PAYU-DPD')
            ->assertDontSee('COD-INPOST');
    }

    public function test_orders_module_filters_by_date_range_and_invoice_presence(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-DATE-INVOICE',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $withInvoice = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'DATED-INVOICE',
            'external_number' => 'DATED-INVOICE',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 100,
            'external_created_at' => '2026-07-10 12:00:00',
        ]);
        $withoutInvoice = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'DATED-NO-INVOICE',
            'external_number' => 'DATED-NO-INVOICE',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 100,
            'external_created_at' => '2026-07-15 12:00:00',
        ]);
        Invoice::query()->create([
            'external_order_id' => $withInvoice->id,
            'number' => 'FV/2026/07/10',
            'type' => 'vat',
            'status' => 'issued',
            'issue_date' => '2026-07-10',
            'currency' => 'PLN',
            'seller_data' => [],
            'buyer_data' => [],
            'gross_total' => 100,
            'issued_at' => now(),
        ]);

        $this->get(route('modules.show', [
            'module' => 'orders',
            'date_from' => '2026-07-09',
            'date_to' => '2026-07-11',
        ]))
            ->assertOk()
            ->assertSee('DATED-INVOICE')
            ->assertDontSee('DATED-NO-INVOICE');

        $this->get(route('modules.show', ['module' => 'orders', 'invoice' => 'yes']))
            ->assertOk()
            ->assertSee('DATED-INVOICE')
            ->assertDontSee('DATED-NO-INVOICE');

        $this->get(route('modules.show', ['module' => 'orders', 'invoice' => 'no']))
            ->assertOk()
            ->assertSee('DATED-NO-INVOICE')
            ->assertDontSee('DATED-INVOICE');
    }
}
