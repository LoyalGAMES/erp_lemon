<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
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

        $this->get(route('modules.show', 'orders'))
            ->assertOk()
            ->assertSee('9060')
            ->assertSee('9011')
            ->assertDontSee('9010')
            ->assertDontSee('9001');
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
}
