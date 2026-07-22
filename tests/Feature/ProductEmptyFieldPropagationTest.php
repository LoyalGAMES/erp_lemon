<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\ProductDataExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Clearing a field in ERP must actually clear it in WooCommerce. Historically
 * two layers swallowed the emptiness: the save layer resurrected old
 * custom-label values via `?? old`, and the export layer omitted empty
 * keys/meta entirely — Woo then kept the stale value forever (the reported
 * case: a removed "PREORDER" label that never left the shop).
 */
class ProductEmptyFieldPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleared_label_price_and_tags_propagate_to_woocommerce(): void
    {
        Bus::fake();
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response([
                'id' => 123,
                'sku' => 'SKU-CLEAR',
            ]),
        ]);

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl']]],
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-CLEAR',
            'name' => 'Produkt z etykietą',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'custom_label' => ['pl' => 'PREORDER', 'en' => 'PREORDER'],
                    'tags' => ['stary-tag'],
                    'prices' => ['retail_price_pln' => 100],
                    'inventory' => ['low_stock_amount' => 3],
                ],
            ],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'SKU-CLEAR',
            'stock_sync_enabled' => true,
        ]);

        // The operator clears the label, tags, price and low-stock threshold.
        $this->put(route('products.update', $product), [
            'sku' => 'SKU-CLEAR',
            'name' => 'Produkt z etykietą',
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => '1',
            'publication_status' => 'publish',
            'product_type' => 'simple',
            'custom_label_pl' => '',
            'custom_label_en' => '',
            'tags' => '',
            'retail_price_pln' => '',
            'low_stock_amount' => '',
        ])->assertRedirect(route('products.edit', $product));

        $master = $product->refresh()->masterData();
        $this->assertNull(data_get($master, 'custom_label.pl'));
        $this->assertNull(data_get($master, 'custom_label.en'));
        $this->assertSame([], (array) data_get($master, 'tags'));
        $this->assertNull(data_get($master, 'prices.retail_price_pln'));

        $job = Bus::dispatchedAfterResponse(ExportWooCommerceProductDataJob::class)->sole();
        $job->handle(app(ProductDataExportService::class));

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PUT' || $request->url() !== 'https://shop.test/wp-json/wc/v3/products/123') {
                return false;
            }

            $meta = collect($request['meta_data'])->pluck('value', 'key');

            return $request['regular_price'] === ''
                && $request['low_stock_amount'] === ''
                && $meta['_lemon_product_label_text'] === ''
                && $meta['_sempre_erp_tags'] === '';
        });
    }

    public function test_bulk_edit_can_reset_label_colors(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-COLOR',
            'name' => 'Produkt z kolorem etykiety',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'custom_label' => ['pl' => 'SALE', 'bg_color' => '#ff0000', 'text_color' => '#ffffff'],
                ],
            ],
        ]);

        $this->post(route('products.bulk.update'), [
            'selection_mode' => 'selected',
            'product_ids' => [$product->id],
            'apply' => [
                'custom_label_bg_color' => '1',
                'custom_label_text_color' => '1',
            ],
            'changes' => [
                'custom_label_bg_color' => '',
                'custom_label_text_color' => '',
            ],
        ])->assertSessionMissing('errors');

        $master = $product->refresh()->masterData();
        $this->assertNull(data_get($master, 'custom_label.bg_color'));
        $this->assertNull(data_get($master, 'custom_label.text_color'));
    }
}
