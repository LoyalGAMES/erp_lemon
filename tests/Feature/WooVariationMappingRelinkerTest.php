<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\StockSyncQueueItem;
use App\Models\Warehouse;
use App\Models\WordpressIntegration;
use App\Jobs\ExportWooCommerceProductDataJob;
use App\Services\WooCommerce\StockSyncExportService;
use App\Services\WooCommerce\WooVariationMappingRelinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WooVariationMappingRelinkerTest extends TestCase
{
    use RefreshDatabase;

    private function channel(): SalesChannel
    {
        return SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
    }

    private function integration(SalesChannel $channel): WordpressIntegration
    {
        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
        ]);
    }

    private function product(string $sku, string $name): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
    }

    public function test_stock_export_relinks_a_deleted_variation_by_sku_and_reaches_the_new_id(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'PUT'
                && $path === '/wp-json/wc/v3/products/123/variations/456'
            ) {
                return Http::response([
                    'code' => 'woocommerce_rest_product_variation_invalid_id',
                    'message' => 'Nieprawidłowy identyfikator.',
                    'data' => ['status' => 404],
                ], 404);
            }

            if ($request->method() === 'GET'
                && $path === '/wp-json/wc/v3/products/123/variations'
            ) {
                return Http::response([[
                    'id' => 789,
                    'sku' => 'SKU-VARIANT-M',
                    'attributes' => [['id' => 70, 'option' => 'M']],
                ]]);
            }

            if ($request->method() === 'PUT'
                && $path === '/wp-json/wc/v3/products/123/variations/789'
            ) {
                return Http::response([
                    'id' => 789,
                    'sku' => 'SKU-VARIANT-M',
                    'stock_quantity' => 4,
                    'stock_status' => 'instock',
                ]);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $channel = $this->channel();
        $this->integration($channel);
        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Main',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $variant = $this->product('SKU-VARIANT-M', 'Wariant M');
        $mapping = ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_variation_id' => '456',
            'external_sku' => 'SKU-VARIANT-M',
            'stock_sync_enabled' => true,
        ]);
        $queueItem = StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'status' => 'pending',
            'quantity_to_push' => 4,
            'available_at' => now(),
        ]);

        app(StockSyncExportService::class)->export($queueItem);

        $queueItem->refresh();
        $mapping->refresh();

        $this->assertSame('success', $queueItem->status);
        $this->assertSame('789', $mapping->external_variation_id);
        $this->assertSame('relinked_by_stock_sync', data_get(
            $mapping->metadata,
            'deleted_variation_recovery.mode',
        ));
        $this->assertSame('456', data_get(
            $mapping->metadata,
            'deleted_variation_recovery.old_variation_id',
        ));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && (string) parse_url($request->url(), PHP_URL_PATH)
                === '/wp-json/wc/v3/products/123/variations/789'
            && $request['stock_quantity'] === 4);
    }

    public function test_relink_family_adopts_regenerated_variation_ids_without_touching_stock(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/321') {
                return Http::response(['id' => 321, 'sku' => 'HEROS-PARENT', 'type' => 'variable']);
            }

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/321/variations') {
                return Http::response([[
                    'id' => 401,
                    'sku' => 'BLS6A4BB2A01EA5F-36',
                    'attributes' => [['id' => 70, 'option' => '36']],
                ]]);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $channel = $this->channel();
        $integration = $this->integration($channel);
        $parent = $this->product('HEROS-PARENT', 'Klapki HEROS Beżowe');
        $variant = $this->product('BLS6A4BB2A01EA5F-36', 'Klapki HEROS Beżowe - 36');
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '321',
            'external_sku' => 'HEROS-PARENT',
            'stock_sync_enabled' => true,
        ]);
        $variantMapping = ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '321',
            'external_variation_id' => '399',
            'external_sku' => 'BLS6A4BB2A01EA5F-36',
            'stock_sync_enabled' => true,
        ]);

        $relinker = app(WooVariationMappingRelinker::class);

        // Dry run must not write anything.
        $dry = $relinker->relinkFamily($parent, $integration, (int) $channel->id, true);
        $this->assertSame(0, $dry['changed']);
        $this->assertSame('399', $variantMapping->fresh()->external_variation_id);

        $report = $relinker->relinkFamily($parent, $integration, (int) $channel->id, false);

        $this->assertSame(1, $report['changed']);
        $this->assertSame('401', $variantMapping->fresh()->external_variation_id);
        $this->assertSame('relinked_by_command', data_get(
            $variantMapping->fresh()->metadata,
            'deleted_variation_recovery.mode',
        ));
        // Relink is adopt-only: no stock or data is pushed.
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['PUT', 'POST'], true));
    }

    public function test_relink_family_recovers_a_deleted_and_recreated_parent(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/321') {
                return Http::response([
                    'code' => 'woocommerce_rest_product_invalid_id',
                    'message' => 'Nieprawidłowy identyfikator.',
                    'data' => ['status' => 404],
                ], 404);
            }

            if ($request->method() === 'GET'
                && $path === '/wp-json/wc/v3/products'
                && ($query['sku'] ?? '') === 'HEROS-PARENT'
            ) {
                return Http::response([['id' => 900, 'sku' => 'HEROS-PARENT', 'type' => 'variable']]);
            }

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/900/variations') {
                return Http::response([[
                    'id' => 401,
                    'sku' => 'BLS6A4BB2A01EA5F-36',
                    'attributes' => [['id' => 70, 'option' => '36']],
                ]]);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $channel = $this->channel();
        $integration = $this->integration($channel);
        $parent = $this->product('HEROS-PARENT', 'Klapki HEROS Beżowe');
        $variant = $this->product('BLS6A4BB2A01EA5F-36', 'Klapki HEROS Beżowe - 36');
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        $parentMapping = ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '321',
            'external_sku' => 'HEROS-PARENT',
            'stock_sync_enabled' => true,
        ]);
        $variantMapping = ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '321',
            'external_variation_id' => '399',
            'external_sku' => 'BLS6A4BB2A01EA5F-36',
            'stock_sync_enabled' => true,
        ]);

        $report = app(WooVariationMappingRelinker::class)
            ->relinkFamily($parent, $integration, (int) $channel->id, false);

        $this->assertSame('relink', $report['parent']['status']);
        $this->assertSame('900', $parentMapping->fresh()->external_product_id);
        $this->assertSame('900', data_get(
            $parentMapping->fresh()->metadata,
            'deleted_parent_recovery.new_product_id',
        ));
        $this->assertSame('900', $variantMapping->fresh()->external_product_id);
        $this->assertSame('401', $variantMapping->fresh()->external_variation_id);
    }

    public function test_relink_button_relinks_family_and_queues_export(): void
    {
        Bus::fake();
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/321') {
                return Http::response(['id' => 321, 'sku' => 'HEROS-PARENT', 'type' => 'variable']);
            }

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/321/variations') {
                return Http::response([[
                    'id' => 401,
                    'sku' => 'BLS6A4BB2A01EA5F-36',
                    'attributes' => [['id' => 70, 'option' => '36']],
                ]]);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $channel = $this->channel();
        $this->integration($channel);
        $parent = $this->product('HEROS-PARENT', 'Klapki HEROS Beżowe');
        $variant = $this->product('BLS6A4BB2A01EA5F-36', 'Klapki HEROS Beżowe - 36');
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '321',
            'external_sku' => 'HEROS-PARENT',
            'stock_sync_enabled' => true,
        ]);
        $variantMapping = ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '321',
            'external_variation_id' => '399',
            'external_sku' => 'BLS6A4BB2A01EA5F-36',
            'stock_sync_enabled' => true,
        ]);

        $this->post(route('products.woocommerce.relink', $parent))
            ->assertRedirect();

        $this->assertSame('401', $variantMapping->fresh()->external_variation_id);
        Bus::assertDispatched(ExportWooCommerceProductDataJob::class);
    }
}
