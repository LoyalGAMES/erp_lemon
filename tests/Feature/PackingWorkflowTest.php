<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerMessage;
use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\OrderCancellation;
use App\Models\PackingTask;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Models\WordpressIntegration;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Orders\OrderCancellationService;
use App\Services\Packing\PackingTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PackingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_order_creates_packing_task_and_scanner_marks_it_picked(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-PACK',
            'ean' => '5901234567890',
            'name' => 'Koszula VIVIEN Biała - M',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'woocommerce_variation_attributes' => [
                    ['name' => 'Rozmiar', 'option' => 'M'],
                ],
            ],
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '501',
            'external_number' => '501',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 199,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'email' => 'anna@example.test',
                'phone' => '+48123123123',
                'address_1' => 'ul. Faktury 10',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'shipping_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'address_1' => 'ul. Magazynowa 5',
                'postcode' => '30-001',
                'city' => 'Kraków',
                'country' => 'PL',
            ],
            'raw_payload' => [
                'customer_note' => 'Proszę zapakować na prezent.',
                'payment_method_title' => 'Przelewy24',
                'shipping_lines' => [
                    ['method_title' => 'DPD Pickup'],
                ],
                'erp_imported_order_notes' => [
                    ['note' => 'Notatka z WooCommerce', 'author' => 'admin'],
                ],
            ],
            'external_created_at' => now()->subDay(),
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => '9001',
            'sku' => $product->sku,
            'name' => 'Koszula VIVIEN Biała',
            'quantity' => 2,
            'raw_payload' => [
                'meta_data' => [
                    ['display_key' => 'Rozmiar', 'display_value' => 'M'],
                ],
            ],
        ]);

        $this->get(route('packing.index', ['view' => 'collect']))
            ->assertOk()
            ->assertSee('Kompletacja')
            ->assertSee('Koszula VIVIEN Biała - M')
            ->assertSee('Rozmiar')
            ->assertSee('DPD Pickup');

        $task = PackingTask::query()->firstOrFail();
        $this->assertSame('open', $task->status);
        $this->assertSame('2.0000', (string) $task->quantity_required);
        $this->assertSame('M', $task->size_label);

        $this->post(route('packing.scan'), ['code' => 'SKU-PACK'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $task->refresh();
        $this->assertSame('open', $task->status);
        $this->assertSame('1.0000', (string) $task->quantity_picked);

        $this->post(route('packing.scan'), ['code' => '5901234567890'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $task->refresh();
        $this->assertSame('picked', $task->status);
        $this->assertSame('2.0000', (string) $task->quantity_picked);
        $this->assertNotNull($task->picked_at);

        $this->get(route('packing.index', ['view' => 'pack']))
            ->assertOk()
            ->assertSee('Zamówienie 501')
            ->assertSee('Wybierz gabaryt paczki')
            ->assertSee('Uwagi z WooCommerce')
            ->assertSee('Notatka z WooCommerce')
            ->assertSee('Proszę zapakować na prezent.')
            ->assertSee('Dane wysyłki i płatności')
            ->assertSee('Przelewy24')
            ->assertSee('anna@example.test')
            ->assertSee('ul. Magazynowa 5')
            ->assertSee('199,00 PLN');

        $this->post(route('packing.orders.label', $order), ['parcel_template' => 'small'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->post(route('packing.tasks.pack', $task))
            ->assertRedirect()
            ->assertSessionHas('status');

        $task->refresh();
        $this->assertSame('packed', $task->status);
        $this->assertNotNull($task->packed_at);
    }

    public function test_primary_packing_views_render_fixed_mobile_workflow_navigation(): void
    {
        foreach (['collect', 'pack', 'waiting'] as $activeView) {
            $response = $this->get(route('packing.index', ['view' => $activeView]))
                ->assertOk()
                ->assertSee('data-packing-mobile-navigation', false)
                ->assertSee('data-packing-mobile-view="collect"', false)
                ->assertSee('data-packing-mobile-view="pack"', false)
                ->assertSee('data-packing-mobile-view="waiting"', false);

            $html = $response->getContent();

            $this->assertSame(3, substr_count($html, 'data-packing-mobile-view='));
            $this->assertStringNotContainsString('data-packing-mobile-view="shipped"', $html);
            $this->assertStringContainsString('.packing-mobile-workflow-nav { position: fixed;', $html);
            $this->assertMatchesRegularExpression(
                '/class="packing-mobile-workflow-link active"[^>]*data-packing-mobile-view="'.preg_quote($activeView, '/').'"[^>]*aria-current="page"/s',
                $html,
            );
        }
    }

    public function test_product_image_modal_and_line_image_fallback_are_available_through_all_primary_packing_stages(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-IMAGES',
            'name' => 'Sklep ze zdjęciami',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'packing-image-1',
            'external_number' => 'IMG-1',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 149,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Zdjęciowa',
            ],
            'raw_payload' => [
                'shipping_lines' => [
                    ['method_title' => 'DPD'],
                ],
            ],
            'external_created_at' => now(),
        ]);
        $imageUrl = 'https://cdn.example.test/products/sukienka-luna.jpg';
        $productName = 'Sukienka LUNA Granatowa';

        $order->lines()->create([
            'external_line_id' => 'packing-image-line-1',
            'sku' => 'SKU-IMG-1',
            'name' => $productName,
            'quantity' => 2,
            'raw_payload' => [
                'image' => [
                    'src' => 'javascript:alert(1)',
                    'url' => $imageUrl,
                ],
                'meta_data' => [
                    ['display_key' => 'Rozmiar', 'display_value' => 'M'],
                ],
            ],
        ]);

        $this->get(route('packing.index', ['view' => 'collect']))
            ->assertOk()
            ->assertSee($productName)
            ->assertSee('data-packing-image-preview="'.$imageUrl.'"', false)
            ->assertSee('src="/products/image-thumbnail?src=', false)
            ->assertSee('data-packing-image-modal', false)
            ->assertSee('role="dialog"', false)
            ->assertDontSee('javascript:alert(1)', false);

        $task = PackingTask::query()->firstOrFail();
        $task->update([
            'quantity_picked' => 2,
            'status' => 'picked',
            'picked_at' => now(),
        ]);

        $this->get(route('packing.index', ['view' => 'pack']))
            ->assertOk()
            ->assertSee($productName)
            ->assertSee('data-packing-image-preview="'.$imageUrl.'"', false)
            ->assertSee('src="/products/image-thumbnail?src=', false)
            ->assertDontSee('javascript:alert(1)', false);

        $task->update([
            'status' => 'packed',
            'packed_at' => now(),
        ]);

        $this->get(route('packing.index', ['view' => 'waiting']))
            ->assertOk()
            ->assertSee('Zamówienie IMG-1')
            ->assertSee($productName)
            ->assertSee('SKU-IMG-1')
            ->assertSee('data-packing-image-preview="'.$imageUrl.'"', false)
            ->assertSee('src="/products/image-thumbnail?src=', false)
            ->assertDontSee('javascript:alert(1)', false);

        $this->get(route('packing.index', ['view' => 'history', 'date' => now()->toDateString()]))
            ->assertOk()
            ->assertSee($productName)
            ->assertSee('data-packing-image-preview="'.$imageUrl.'"', false)
            ->assertSee('src="/products/image-thumbnail?src=', false)
            ->assertDontSee('javascript:alert(1)', false);

        $task->update([
            'status' => 'problem',
            'metadata' => [
                'packing_problem' => [
                    'reason' => 'Kontrola zdjęcia produktu',
                    'reported_at' => now()->toISOString(),
                ],
            ],
        ]);

        $this->get(route('packing.index', ['view' => 'problems']))
            ->assertOk()
            ->assertSee('Kontrola zdjęcia produktu')
            ->assertSee($productName)
            ->assertSee('data-packing-image-preview="'.$imageUrl.'"', false)
            ->assertSee('src="/products/image-thumbnail?src=', false)
            ->assertDontSee('javascript:alert(1)', false);

        $task->update(['status' => 'shipped']);

        $this->get(route('packing.index', ['view' => 'shipped']))
            ->assertOk()
            ->assertSee('Zamówienie IMG-1')
            ->assertSee($productName)
            ->assertSee('data-packing-image-preview="'.$imageUrl.'"', false)
            ->assertSee('src="/products/image-thumbnail?src=', false)
            ->assertDontSee('javascript:alert(1)', false);
    }

    public function test_collect_view_falls_back_to_size_from_product_name(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'BLS6A106E8F662FB',
            'name' => 'Garnitur AMELIA Tenis Butter Cream - S/M',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9437',
            'external_number' => '9437',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 399,
            'raw_payload' => [
                'shipping_lines' => [
                    ['method_title' => 'InPost Kurier Standard'],
                ],
            ],
            'external_created_at' => now(),
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'name-size-1',
            'sku' => $product->sku,
            'name' => 'Garnitur AMELIA Tenis Butter Cream - S/M',
            'quantity' => 1,
            'raw_payload' => [
                'meta_data' => [],
            ],
        ]);

        $this->get(route('packing.index', ['view' => 'collect']))
            ->assertOk()
            ->assertSee('Garnitur AMELIA Tenis Butter Cream - S/M')
            ->assertSee('Rozmiar <strong>S/M</strong>', false)
            ->assertDontSee('Rozmiar <strong>-</strong>', false);

        $task = PackingTask::query()->firstOrFail();
        $this->assertSame('S/M', $task->size_label);
    }

    public function test_operator_can_pick_without_scanner_and_pack_whole_order(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-MANUAL',
            'name' => 'Komplet ARIEL Różowy',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'stock' => [
                        'location' => 'A-01-03',
                    ],
                    'media' => [
                        ['src' => 'https://cdn.example.test/ariel.jpg', 'alt' => 'Komplet ARIEL'],
                    ],
                ],
            ],
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '601',
            'external_number' => '601',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 369,
            'billing_data' => [
                'first_name' => 'Maria',
                'last_name' => 'Nowak',
                'email' => 'maria@example.test',
            ],
            'shipping_data' => [
                'first_name' => 'Maria',
                'last_name' => 'Nowak',
                'address_1' => 'ul. Szybka 3',
                'postcode' => '61-001',
                'city' => 'Poznań',
                'country' => 'PL',
            ],
            'raw_payload' => [
                'shipping_lines' => [
                    ['method_title' => 'InPost Kurier'],
                ],
            ],
            'external_created_at' => now()->subHours(3),
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'manual-1',
            'sku' => $product->sku,
            'name' => 'Komplet ARIEL Różowy',
            'quantity' => 1,
            'raw_payload' => [
                'meta_data' => [
                    ['display_key' => 'Rozmiar', 'display_value' => 'M'],
                ],
            ],
        ]);

        $this->post(route('packing.mode'), ['mode' => 'manual'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->get(route('packing.index', ['view' => 'collect']))
            ->assertOk()
            ->assertSee('Kompletacja')
            ->assertSee('Komplet ARIEL Różowy')
            ->assertSee('Lok. A-01-03')
            ->assertSee('Zebrane')
            ->assertDontSee('Skaner SKU/EAN');

        $task = PackingTask::query()->firstOrFail();
        $this->assertSame('A-01-03', data_get($task->metadata, 'warehouse_location'));

        $this->postJson(route('packing.groups.pick'), ['task_ids' => [$task->id]])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('action', 'collect.picked')
            ->assertJsonPath('ui.remove_submitted_card', true);

        $task->refresh();
        $this->assertSame('picked', $task->status);
        $this->assertSame('1.0000', (string) $task->quantity_picked);

        $this->get(route('packing.index', ['view' => 'pack']))
            ->assertOk()
            ->assertSee('Pakowanie')
            ->assertSee('Zamówienie 601')
            ->assertSee('Wybierz gabaryt paczki');

        $this->post(route('packing.orders.pack', $order))
            ->assertRedirect()
            ->assertSessionHas('status');

        $task->refresh();
        $this->assertSame('packed', $task->status);
        $this->assertNotNull($task->packed_at);
    }

    public function test_packing_home_collect_and_pack_views_are_separated(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-CLEAN',
            'name' => 'Sukienka AURELIA Fango',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '611',
            'external_number' => '611',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 299,
            'raw_payload' => [
                'shipping_lines' => [
                    ['method_title' => 'DPD'],
                ],
            ],
            'external_created_at' => now(),
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'clean-1',
            'sku' => $product->sku,
            'name' => 'Sukienka AURELIA Fango',
            'quantity' => 1,
            'raw_payload' => [
                'meta_data' => [
                    ['display_key' => 'Rozmiar', 'display_value' => 'M'],
                ],
            ],
        ]);

        $this->get(route('packing.index'))
            ->assertOk()
            ->assertSee('Ustawienia')
            ->assertSee('Ustawienia pracy')
            ->assertSee('data-packing-settings-open', false)
            ->assertSee('data-packing-settings-overlay', false)
            ->assertSee('Do zebrania')
            ->assertSee(route('packing.index', ['view' => 'collect']), false)
            ->assertSee(route('packing.index', ['view' => 'pack']), false)
            ->assertSee(route('packing.index', ['view' => 'waiting']), false)
            ->assertSee(route('packing.index', ['view' => 'shipped']), false)
            ->assertSee(route('packing.index', ['view' => 'problems']), false)
            ->assertDontSee('Sukienka AURELIA Fango');

        $this->get(route('packing.index', ['view' => 'collect']))
            ->assertOk()
            ->assertSee('Sukienka AURELIA Fango')
            ->assertSee('Rozmiar')
            ->assertSee('Historia kompletacji')
            ->assertDontSee('Ustawienia sposobu pracy')
            ->assertDontSee('Spakowane dzisiaj')
            ->assertDontSee('Wybierz etap pracy');

        $task = PackingTask::query()->firstOrFail();

        $this->post(route('packing.groups.pick'), ['task_ids' => [$task->id]])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->get(route('packing.index', ['view' => 'pack']))
            ->assertOk()
            ->assertSee('Zamówienie 611')
            ->assertSee(route('orders.show', $task->external_order_id), false)
            ->assertSee('Oczekuje na kuriera')
            ->assertDontSee('Spakowane dzisiaj')
            ->assertDontSee('Ustawienia sposobu pracy');
    }

    public function test_collect_view_keeps_all_open_products_from_one_order_together_with_customer_name(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $firstProduct = Product::query()->create([
            'sku' => 'SKU-COLLECT-ONE',
            'name' => 'Sukienka LENA',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $secondProduct = Product::query()->create([
            'sku' => 'SKU-COLLECT-TWO',
            'name' => 'Buty VIKI',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '613',
            'external_number' => '613',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 419,
            'shipping_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
            ],
            'raw_payload' => [
                'shipping_lines' => [['method_title' => 'InPost']],
            ],
            'external_created_at' => now(),
        ]);
        $order->lines()->createMany([
            [
                'product_id' => $firstProduct->id,
                'external_line_id' => 'collect-1',
                'sku' => $firstProduct->sku,
                'name' => $firstProduct->name,
                'quantity' => 1,
            ],
            [
                'product_id' => $secondProduct->id,
                'external_line_id' => 'collect-2',
                'sku' => $secondProduct->sku,
                'name' => $secondProduct->name,
                'quantity' => 2,
            ],
        ]);

        $response = $this->get(route('packing.index', [
            'view' => 'collect',
            'segment' => 'all',
        ]));

        $response
            ->assertOk()
            ->assertSee('Zamówienie 613')
            ->assertSee('Odbiorca: Anna Kowalska')
            ->assertSee('Sukienka LENA')
            ->assertSee('Buty VIKI')
            ->assertSee('3 szt.');

        $this->assertSame(1, substr_count($response->getContent(), 'Zamówienie 613'));
    }

    public function test_packed_order_with_manually_selected_label_generates_wz_invoice_status_and_courier_queue(): void
    {
        Storage::fake('local');

        Http::fake(function ($request) {
            $url = $request->url();

            if ($url === 'https://shop.test/wp-json/ship/v1/orders/801/label') {
                return Http::response(
                    '%PDF-1.4 generated label',
                    200,
                    [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'attachment; filename="label-801.pdf"',
                    ],
                );
            }

            if ($url === 'https://shop.test/wp-json/wp/v2/media') {
                return Http::response([
                    'id' => 8101,
                    'source_url' => 'https://shop.test/wp-content/uploads/2026/06/fv-801.pdf',
                ]);
            }

            if ($url === 'https://shop.test/wp-json/wc/v3/orders/801/notes') {
                return Http::response(['id' => 8201]);
            }

            if ($url === 'https://shop.test/wp-json/wc/v3/orders/801') {
                $data = $request->data();

                return Http::response([
                    'id' => 801,
                    'status' => $data['status'] ?? 'processing',
                ]);
            }

            return Http::response([], 404);
        });

        app(InvoiceSettingsService::class)->updateSellerData([
            'name' => 'Sempre Love sp. z o.o.',
            'tax_id' => '5261040828',
            'address_1' => 'Testowa 1',
            'postcode' => '00-001',
            'city' => 'Warszawa',
            'country' => 'PL',
            'email' => 'biuro@example.test',
            'phone' => '+48123123123',
            'bank_account' => 'PL00111122223333444455556666',
        ]);
        app(InvoiceSettingsService::class)->updateNumberingData([
            'sales_prefix' => 'FV/ERP',
            'correction_prefix' => 'FK/ERP',
            'padding' => 5,
            'payment_due_days' => 7,
        ]);

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre WooCommerce',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'wp_api_username' => 'erp',
            'wp_api_password_encrypted' => Crypt::encryptString('app-password'),
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
                'order_statuses' => [
                    'ready_to_ship' => 'ready-to-ship',
                    'shipped' => 'completed',
                ],
            ],
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
            'type' => 'internal',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-AUTO',
            'name' => 'Koszula AURA Czarno-ecru',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 3,
            'quantity_reserved' => 1,
            'quantity_available' => 2,
            'recalculated_at' => now(),
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '801',
            'external_number' => '801',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 123,
            'billing_data' => [
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
                'email' => 'jan@example.test',
            ],
            'shipping_data' => [
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'address_1' => 'Magazynowa 8',
                'postcode' => '00-003',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'raw_payload' => [
                'payment_method_title' => 'Przelew online',
                'shipping_lines' => [
                    ['method_title' => 'DPD'],
                ],
            ],
            'external_created_at' => now()->subHour(),
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'auto-1',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
            'unit_net_price' => 100,
            'unit_gross_price' => 123,
            'raw_payload' => [
                'total' => '100.00',
                'total_tax' => '23.00',
                'meta_data' => [
                    ['display_key' => 'Rozmiar', 'display_value' => 'M'],
                ],
            ],
        ]);

        StockReservation::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->external_id,
            'quantity' => 1,
            'status' => 'active',
            'reserved_at' => now(),
        ]);

        $this->get(route('packing.index', ['view' => 'collect']))->assertOk();
        $task = PackingTask::query()->firstOrFail();

        $this->post(route('packing.groups.pick'), ['task_ids' => [$task->id]])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->post(route('packing.orders.label', $order), ['parcel_template' => 'small'])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'gabaryt A'));

        $this->post(route('packing.orders.pack', $order))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Spakowano zamówienie 801')
                && str_contains($message, 'oczekujących na kuriera'));

        $task->refresh();
        $order->refresh();

        $this->assertSame('packed', $task->status);
        $this->assertSame('ready-to-ship', $order->status);
        $this->assertSame(1, ShippingLabel::query()->count());
        $this->assertSame(1, Invoice::query()->count());

        $document = WarehouseDocument::query()->where('type', 'WZ')->firstOrFail();
        $this->assertSame('posted', $document->status);
        $this->assertSame('released', StockReservation::query()->firstOrFail()->status);
        $this->assertSame('2.0000', (string) StockBalance::query()->firstOrFail()->quantity_on_hand);

        $this->get(route('packing.index', ['view' => 'waiting']))
            ->assertOk()
            ->assertSee('Oczekuje na kuriera')
            ->assertSee('DPD')
            ->assertDontSee('>Odebrano<', false);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/ship/v1/orders/801/label'
            && data_get($request->data(), 'parcel_template') === 'small');

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->method() === 'PUT'
                && $request->url() === 'https://shop.test/wp-json/wc/v3/orders/801'
                && ($data['status'] ?? null) === 'ready-to-ship';
        });

        $this->post(route('packing.couriers.pickup'), [
            'courier' => 'DPD',
            'order_ids' => [$order->id],
            'pickup_token' => hash_hmac('sha256', 'DPD|'.$order->id, (string) config('app.key')),
        ])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Oznaczono odbiór kuriera DPD')
                && str_contains($message, 'W ERP zamówienia przeniesiono do wysłanych'));

        $task->refresh();
        $order->refresh();

        $this->assertSame('shipped', $task->status);
        $this->assertSame('completed', $order->status);

        $this->get(route('packing.index', ['view' => 'shipped']))
            ->assertOk()
            ->assertSee('Wysłane')
            ->assertSee('Zamówienie 801')
            ->assertDontSee('>Odebrano<', false);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->method() === 'PUT'
                && $request->url() === 'https://shop.test/wp-json/wc/v3/orders/801'
                && ($data['status'] ?? null) === 'completed';
        });
    }

    public function test_operator_can_undo_packed_order_before_courier_pickup(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/901' => Http::response(['status' => 'processing'], 200),
        ]);

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre WooCommerce',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
            'invoice_upload_enabled' => true,
            'settings' => [
                'order_statuses' => [
                    'ready_to_ship' => 'ready-to-ship',
                    'shipped' => 'completed',
                    'packing_rollback' => 'processing',
                ],
            ],
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-UNDO',
            'name' => 'Sukienka AURELIA Fango',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '901',
            'external_number' => '901',
            'status' => 'ready-to-ship',
            'currency' => 'PLN',
            'total_gross' => 289,
            'raw_payload' => [
                'shipping_lines' => [
                    ['method_title' => 'DPD'],
                ],
            ],
            'external_created_at' => now()->subMinutes(40),
        ]);

        $line = $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'undo-1',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
        ]);

        $task = PackingTask::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->id,
            'external_order_line_id' => $line->id,
            'product_id' => $product->id,
            'external_line_id' => 'undo-1',
            'order_number' => '901',
            'customer_name' => 'Anna Kowalska',
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity_required' => 1,
            'quantity_picked' => 1,
            'status' => 'packed',
            'courier' => 'DPD',
            'size_label' => 'M',
            'order_date' => now()->subMinutes(40),
            'picked_at' => now()->subMinutes(30),
            'packed_at' => now()->subMinutes(10),
            'metadata' => [
                'packing_completion' => [
                    'label_id' => 10,
                    'invoice_id' => 11,
                    'completed_at' => now()->subMinutes(10)->toISOString(),
                ],
            ],
        ]);

        $this->get(route('packing.index', ['view' => 'waiting']))
            ->assertOk()
            ->assertSee('Oczekuje na kuriera')
            ->assertSee('Zamówienie 901')
            ->assertSee('Cofnij');

        $this->post(route('packing.orders.unpack', $order), [
            'reason' => 'Błąd pakowania',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Cofnięto pakowanie zamówienia 901')
                && str_contains($message, 'WZ, faktura i etykieta pozostają w historii'));

        $task->refresh();
        $order->refresh();

        $this->assertSame('picked', $task->status);
        $this->assertNull($task->packed_at);
        $this->assertSame('Błąd pakowania', data_get($task->metadata, 'packing_rollback.reason'));
        $this->assertSame(10, data_get($task->metadata, 'packing_rollback.previous_packing_completion.label_id'));
        $this->assertSame('processing', $order->status);

        $this->get(route('packing.index', ['view' => 'pack']))
            ->assertOk()
            ->assertSee('Zamówienie 901')
            ->assertSee('Wybierz gabaryt paczki');

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->method() === 'PUT'
                && $request->url() === 'https://shop.test/wp-json/wc/v3/orders/901'
                && ($data['status'] ?? null) === 'processing';
        });
    }

    public function test_operator_can_filter_packing_history_by_date(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-HISTORY',
            'name' => 'Komplet AMORA Kremowy',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $todayOrder = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '911',
            'external_number' => '911',
            'status' => 'ready-to-ship',
            'currency' => 'PLN',
            'total_gross' => 819,
        ]);

        $oldOrder = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '912',
            'external_number' => '912',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 819,
        ]);

        PackingTask::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $todayOrder->id,
            'product_id' => $product->id,
            'external_line_id' => 'history-1',
            'order_number' => '911',
            'customer_name' => 'Maria Nowak',
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity_required' => 1,
            'quantity_picked' => 1,
            'status' => 'packed',
            'courier' => 'InPost',
            'size_label' => 'M/L',
            'order_date' => now()->subDay(),
            'picked_at' => now()->subHours(2),
            'packed_at' => now()->setTime(9, 15),
        ]);

        PackingTask::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $oldOrder->id,
            'product_id' => $product->id,
            'external_line_id' => 'history-2',
            'order_number' => '912',
            'customer_name' => 'Jan Nowak',
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity_required' => 1,
            'quantity_picked' => 1,
            'status' => 'shipped',
            'courier' => 'DPD',
            'size_label' => 'S/M',
            'order_date' => now()->subDays(3),
            'picked_at' => now()->subDays(2),
            'packed_at' => now()->subDay()->setTime(11, 0),
            'metadata' => [
                'courier_pickup' => [
                    'picked_up_at' => now()->subDay()->setTime(16, 0)->toISOString(),
                ],
            ],
        ]);

        $this->get(route('packing.index', ['view' => 'history', 'date' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('Historia pakowania')
            ->assertSee('Zamówienie 911')
            ->assertSee('Komplet AMORA Kremowy')
            ->assertSee('Cofnij pakowanie')
            ->assertDontSee('Zamówienie 912');
    }

    public function test_pick_group_problem_requires_reason_and_cancelled_problem_remains_read_only(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/701*' => Http::response([
                'id' => 701,
                'number' => '701',
                'status' => 'cancelled',
            ], 200),
        ]);

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre WooCommerce',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-PROBLEM',
            'name' => 'Koszula AURA Czarno-ecru',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '701',
            'external_number' => '701',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 149,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'email' => 'anna@example.test',
            ],
            'raw_payload' => [
                'shipping_lines' => [
                    ['method_title' => 'DPD'],
                ],
            ],
            'external_created_at' => now()->subHour(),
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'problem-1',
            'sku' => $product->sku,
            'name' => 'Koszula AURA Czarno-ecru',
            'quantity' => 1,
        ]);

        $this->get(route('packing.index', ['view' => 'collect']))->assertOk();

        $task = PackingTask::query()->firstOrFail();

        $this->post(route('packing.groups.problem'), [
            'task_ids' => [$task->id],
        ])
            ->assertRedirect()
            ->assertSessionHasErrors(['reason', 'restore_stock']);

        $this->assertSame('open', $task->fresh()->status);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(0, CustomerMessage::query()->count());

        $this->postJson(route('packing.groups.problem'), [
            'task_ids' => [$task->id],
            'reason' => 'Brak produktu na półce',
            'restore_stock' => '1',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('action', 'collect.problem')
            ->assertJsonPath('ui.destination', 'problems');

        $task->refresh();
        $order->refresh();
        $this->assertSame('problem', $task->status);
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('Brak produktu na półce', data_get($task->metadata, 'packing_problem.reason'));

        $message = CustomerMessage::query()->sole();
        $this->assertSame($order->id, $message->external_order_id);
        $this->assertSame('order_cancelled_problem', $message->trigger);
        $this->assertSame('Brak produktu na półce', data_get($message->metadata, 'problem_note'));
        $this->assertStringContainsString('Brak produktu na półce', $message->renderedBody());

        app(PackingTaskService::class)->syncReadyOrders();

        $this->assertSame('problem', $task->fresh()->status);

        $response = $this->get(route('packing.index', ['view' => 'problems']));

        $response
            ->assertOk()
            ->assertSee('Do wyjaśnienia')
            ->assertSee('Brak produktu na półce')
            ->assertDontSee('Przywróć do kolejki');

        $this->assertStringContainsString('anulowan', mb_strtolower(strip_tags($response->getContent())));

        $this->post(route('packing.tasks.reopen', $task))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('problem', $task->fresh()->status);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/wp-json/wc/v3/orders/701')
            && ($request->data()['status'] ?? null) === 'cancelled');
    }

    public function test_ready_order_problem_requires_reason_and_cancels_order_with_customer_message(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/702*' => Http::response([
                'id' => 702,
                'number' => '702',
                'status' => 'cancelled',
            ], 200),
        ]);

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre WooCommerce',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-ORDER-PROBLEM',
            'name' => 'Komplet AMORA Kremowy',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '702',
            'external_number' => '702',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 819,
            'billing_data' => [
                'first_name' => 'Maria',
                'last_name' => 'Nowak',
                'email' => 'maria@example.test',
            ],
            'raw_payload' => [
                'shipping_lines' => [
                    ['method_title' => 'InPost Kurier'],
                ],
            ],
            'external_created_at' => now(),
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'problem-order-1',
            'sku' => $product->sku,
            'name' => 'Komplet AMORA Kremowy',
            'quantity' => 1,
        ]);

        $this->get(route('packing.index', ['view' => 'collect']))->assertOk();

        $task = PackingTask::query()->firstOrFail();

        $this->post(route('packing.groups.pick'), ['task_ids' => [$task->id]])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->post(route('packing.orders.problem', $order), [])
            ->assertRedirect()
            ->assertSessionHasErrors(['reason', 'restore_stock']);

        $this->assertSame('picked', $task->fresh()->status);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(0, CustomerMessage::query()->count());

        $this->post(route('packing.orders.problem', $order), [
            'reason' => 'Adres wymaga wyjaśnienia',
            'restore_stock' => '1',
        ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $task->refresh();
        $order->refresh();
        $this->assertSame('problem', $task->status);
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('Adres wymaga wyjaśnienia', data_get($task->metadata, 'packing_problem.reason'));

        $message = CustomerMessage::query()->sole();
        $this->assertSame($order->id, $message->external_order_id);
        $this->assertSame('order_cancelled_problem', $message->trigger);
        $this->assertSame('Adres wymaga wyjaśnienia', data_get($message->metadata, 'problem_note'));
        $this->assertStringContainsString('Adres wymaga wyjaśnienia', $message->renderedBody());

        $response = $this->get(route('packing.index', ['view' => 'problems']));

        $response
            ->assertOk()
            ->assertSee('Do wyjaśnienia')
            ->assertSee('Adres wymaga wyjaśnienia')
            ->assertDontSee('Przywróć do kolejki');

        $this->assertStringContainsString('anulowan', mb_strtolower(strip_tags($response->getContent())));

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/wp-json/wc/v3/orders/702')
            && ($request->data()['status'] ?? null) === 'cancelled');
    }

    public function test_paid_packing_problem_runs_one_safe_refund_and_only_problem_notification(): void
    {
        $refundPostCount = 0;
        $statusPutCount = 0;
        $wooOrder = [
            'id' => 703,
            'number' => '703',
            'status' => 'processing',
            'currency' => 'PLN',
            'total' => '349.00',
            'date_paid' => '2026-07-14T10:00:00',
            'date_paid_gmt' => '2026-07-14T08:00:00',
            'payment_method' => 'payu',
            'payment_method_title' => 'PayU',
            'transaction_id' => 'TX-PROBLEM-703',
            'refunds' => [],
        ];

        Http::fake(function (Request $request) use ($wooOrder, &$refundPostCount, &$statusPutCount) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && str_ends_with($path, '/orders/703/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'GET' && str_ends_with($path, '/orders/703')) {
                return Http::response($wooOrder);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/orders/703/refunds')) {
                $refundPostCount++;

                return Http::response([
                    'id' => 9703,
                    'amount' => '349.00',
                    'reason' => $request['reason'],
                    'refunded_payment' => true,
                ], 201);
            }

            if ($request->method() === 'PUT' && str_ends_with($path, '/orders/703')) {
                $statusPutCount++;

                return Http::response(array_replace($wooOrder, ['status' => 'cancelled']));
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C-PROBLEM-PAID',
            'name' => 'Sklep B2C problem opłacony',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre WooCommerce problem opłacony',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-PAID-PROBLEM',
            'name' => 'Koszula problem opłacony',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => '703',
            'external_number' => '703',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 349,
            'billing_data' => [
                'first_name' => 'Ewa',
                'last_name' => 'Nowak',
                'email' => 'ewa@example.test',
            ],
            'raw_payload' => $wooOrder,
            'external_created_at' => now()->subHour(),
        ]);
        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'problem-paid-1',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
        ]);

        $this->get(route('packing.index', ['view' => 'collect']))->assertOk();
        $task = PackingTask::query()->firstOrFail();

        foreach ([1, 2] as $attempt) {
            $this->postJson(route('packing.groups.problem'), [
                'task_ids' => [$task->id],
                'reason' => 'Klientka zgłosiła problem z zamówieniem',
                'restore_stock' => '1',
            ])
                ->assertOk()
                ->assertJsonPath('ok', true);
        }

        $cancellation = OrderCancellation::query()->sole();
        $payment = CustomerPayment::query()->sole();
        $task->refresh();

        $this->assertSame(1, $refundPostCount);
        $this->assertSame(1, $statusPutCount);
        $this->assertSame('completed', $cancellation->status);
        $this->assertSame('submitted', $cancellation->refund_status);
        $this->assertSame('packing_problem', data_get($cancellation->metadata, 'source'));
        $this->assertTrue((bool) data_get($cancellation->metadata, 'context.preserve_packing_problem'));
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('problem', $task->status);
        $this->assertTrue((bool) data_get($task->metadata, 'order_cancellation.preserved_as_problem'));
        $this->assertSame('Klientka zgłosiła problem z zamówieniem', data_get($task->metadata, 'packing_problem.reason'));
        $this->assertSame('outgoing', $payment->direction);
        $this->assertSame('paid', $payment->status);
        $this->assertSame('349.00', (string) $payment->amount);
        $this->assertSame(1, CustomerMessage::query()->where('trigger', 'order_cancelled_problem')->count());
        $this->assertSame(0, CustomerMessage::query()->where('trigger', 'order_cancelled')->count());
    }

    public function test_packing_problem_waiting_for_manual_shipping_does_not_claim_cancellation_or_notify_customer(): void
    {
        $wooOrder = [
            'id' => 704,
            'number' => '704',
            'status' => 'processing',
            'currency' => 'PLN',
            'total' => '199.00',
            'date_paid' => null,
            'date_paid_gmt' => null,
            'payment_method' => 'cod',
            'payment_method_title' => 'Płatność przy odbiorze',
            'transaction_id' => '',
            'refunds' => [],
        ];
        Http::fake(function (Request $request) use ($wooOrder) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && str_ends_with($path, '/orders/704/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'GET' && str_ends_with($path, '/orders/704')) {
                return Http::response($wooOrder);
            }

            if ($request->method() === 'PUT' && str_ends_with($path, '/orders/704')) {
                return Http::response(array_replace($wooOrder, ['status' => 'cancelled']));
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C-PROBLEM-SHIPPING-GATE',
            'name' => 'Sklep B2C problem z etykietą',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre WooCommerce problem shipping gate',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-PROBLEM-SHIPPING-GATE',
            'name' => 'Produkt z etykietą do ręcznego cofnięcia',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => '704',
            'external_number' => '704',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 199,
            'billing_data' => [
                'first_name' => 'Alicja',
                'last_name' => 'Nowak',
                'email' => 'alicja@example.test',
            ],
            'raw_payload' => $wooOrder,
            'external_created_at' => now()->subHour(),
        ]);
        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'problem-shipping-gate-1',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
        ]);
        ShippingLabel::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->id,
            'purpose' => 'shipment',
            'idempotency_key' => 'shipment:problem-shipping-gate:'.$order->id,
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => 'SHIP-CONFIRMED-704',
            'disk' => 'local',
            'path' => 'shipping-labels/problem-shipping-gate-704.zpl',
            'mime_type' => 'application/zpl',
            'response_payload' => ['shipment' => ['status' => 'confirmed']],
            'generated_at' => now(),
        ]);

        $this->get(route('packing.index', ['view' => 'collect']))->assertOk();
        $task = PackingTask::query()->firstOrFail();

        $response = $this->postJson(route('packing.groups.problem'), [
            'task_ids' => [$task->id],
            'reason' => 'Problem wymagający anulowania przesyłki',
            'restore_stock' => '1',
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('ok', false);
        $this->assertStringContainsString(
            'bezpiecznie wstrzymane',
            mb_strtolower((string) $response->json('message')),
        );

        $cancellation = OrderCancellation::query()->sole();
        $this->assertSame('attention_required', $cancellation->status);
        $this->assertSame('cancellation-pending', $order->fresh()->status);
        $this->assertSame('open', $task->fresh()->status);
        $this->assertSame('attention_required', $cancellation->steps()->where('step', 'shipping')->sole()->status);
        $this->assertDatabaseCount('customer_messages', 0);
        $this->assertDatabaseCount('customer_payments', 0);
        Http::assertNothingSent();

        $completed = app(OrderCancellationService::class)->confirmManualShippingCancellation(
            $order->fresh(),
            null,
            'Potwierdzono ręczne anulowanie przesyłki.',
        );

        $this->assertFalse($completed['attention_required']);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('problem', $task->fresh()->status);
        $this->assertSame(1, CustomerMessage::query()->where('trigger', 'order_cancelled_problem')->count());
        $this->assertSame(0, CustomerMessage::query()->where('trigger', 'order_cancelled')->count());
        // Refund lookup, order lookup, optional Lemon ERP stock-contract
        // capability check, and the final WooCommerce status update.
        Http::assertSentCount(4);
    }

    public function test_on_hold_order_is_not_available_for_mobile_picking(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '777',
            'external_number' => '777',
            'status' => 'on-hold',
            'currency' => 'PLN',
            'total_gross' => 99,
        ]);

        $this->get(route('packing.index', ['view' => 'collect']))
            ->assertOk()
            ->assertSee('Brak produktów do zebrania.');

        $this->assertSame(0, PackingTask::query()->count());
    }

    public function test_operator_can_generate_and_download_shipping_label_for_order(): void
    {
        Storage::fake('local');

        Http::fake([
            'https://shop.test/wp-json/ship/v1/orders/501/label' => Http::response(
                '%PDF-1.4 generated label',
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="label-501.pdf"',
                ],
            ),
        ]);

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre WooCommerce',
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

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '501',
            'external_number' => '501',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 199,
            'external_created_at' => now(),
        ]);

        $this->post(route('packing.orders.label', $order), ['parcel_template' => 'small'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $label = ShippingLabel::query()->firstOrFail();

        $this->assertSame($order->id, $label->external_order_id);
        $this->assertSame('application/pdf', $label->mime_type);
        $this->assertSame('generated', $label->status);

        Storage::disk('local')->assertExists($label->path);

        $this->get(route('packing.labels.download', $label))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/ship/v1/orders/501/label');
    }
}
