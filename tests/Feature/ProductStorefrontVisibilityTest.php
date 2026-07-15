<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportStockToWooCommerceJob;
use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockSyncQueueItem;
use App\Models\Warehouse;
use App\Models\WordpressIntegration;
use App\Services\Products\ProductStorefrontVisibilityService;
use App\Services\WooCommerce\ProductDataExportService;
use App\Services\WooCommerce\StockSyncExportService;
use App\Services\WooCommerce\WooCommerceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductStorefrontVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_hide_reveal_and_manually_release_storefront_stock(): void
    {
        Bus::fake();
        [$channel, $integration, $warehouse] = $this->channelWithWarehouseRoute();
        $product = $this->product('SKU-HIDE', [
            'master' => [
                'source' => 'erp',
                'publication_status' => 'publish',
                'catalog_visibility' => 'catalog',
                'category_ids' => [91],
                'related_products' => [
                    'upsell_skus' => ['SKU-UP'],
                    'cross_sell_skus' => ['SKU-CROSS'],
                ],
            ],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 7,
            'quantity_reserved' => 2,
            'quantity_available' => 5,
        ]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Ukryj produkt');

        $this->from(route('products.index'))
            ->post(route('products.storefront.hide', $product))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('status');

        $product->refresh();
        $this->assertTrue($product->isStorefrontHidden());
        $this->assertTrue($product->requiresStockVerification());
        $this->assertSame('visible', $product->storefront_restore_visibility);
        $this->assertSame('hidden', data_get($product->masterData(), 'catalog_visibility'));
        $this->assertSame([91], data_get($product->masterData(), 'category_ids'));
        $this->assertSame(['SKU-UP'], data_get($product->masterData(), 'related_products.upsell_skus'));
        $this->assertTrue($product->is_active);
        $this->assertSame('7.0000', (string) $product->stockBalances()->firstOrFail()->quantity_on_hand);
        Bus::assertDispatchedAfterResponse(ExportWooCommerceProductDataJob::class, 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.storefront_hidden']);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Odkryj produkt')
            ->assertSee('Ukryty w sklepie');

        $this->from(route('products.index'))
            ->post(route('products.storefront.reveal', $product))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('status');

        $product->refresh();
        $this->assertFalse($product->isStorefrontHidden());
        $this->assertTrue($product->requiresStockVerification());
        $this->assertSame('visible', $product->storefront_restore_visibility);
        $this->assertSame('visible', data_get($product->masterData(), 'catalog_visibility'));
        $this->assertSame('7.0000', (string) $product->stockBalances()->firstOrFail()->quantity_on_hand);
        Bus::assertDispatchedAfterResponse(ExportWooCommerceProductDataJob::class, 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.storefront_revealed']);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Ukryj produkt')
            ->assertDontSee('Odkryj produkt')
            ->assertSee('Potwierdź stan')
            ->assertSee('Stan w sklepie: 0 — do weryfikacji');

        Http::fake(function ($request) use ($product) {
            if (
                $request->method() === 'GET'
                && str_ends_with(
                    $request->url(),
                    '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities',
                )
            ) {
                return Http::response([
                    'available' => true,
                    'plugin_version' => '0.5.3',
                    'languages' => ['pl', 'en'],
                    'attribute_term_translation_link_available' => true,
                ]);
            }

            if (
                $request->method() === 'PUT'
                && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123'
            ) {
                return Http::response([
                    'id' => 123,
                    'sku' => $product->sku,
                ]);
            }

            if (
                $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://shop.test/wp-json/wc/v3/products?')
            ) {
                return Http::response([]);
            }

            return Http::response([], 404);
        });
        $revealJob = Bus::dispatchedAfterResponse(ExportWooCommerceProductDataJob::class)->last();
        $revealJob->handle(app(ProductDataExportService::class));
        $product->refresh();
        $this->assertNull($product->storefront_restore_visibility);
        $this->assertTrue($product->requiresStockVerification());
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123'
            && $request['catalog_visibility'] === 'visible'
            && $request['stock_quantity'] === 0);

        $this->from(route('products.index'))
            ->post(route('products.storefront.verify-stock', $product))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('status');

        $product->refresh();
        $this->assertFalse($product->requiresStockVerification());
        $this->assertSame('7.0000', (string) $product->stockBalances()->firstOrFail()->quantity_on_hand);
        $this->assertDatabaseHas('stock_sync_queue_items', [
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'quantity_to_push' => 5,
        ]);
        Bus::assertDispatched(ExportStockToWooCommerceJob::class);
        Bus::assertDispatchedAfterResponse(ExportWooCommerceProductDataJob::class, 2);
        $this->assertSame(1, AuditLog::query()->where('action', 'product.storefront_stock_verified')->count());
        $this->assertSame($integration->id, WordpressIntegration::query()->firstOrFail()->id);
    }

    public function test_hiding_a_variant_hides_and_holds_the_whole_family(): void
    {
        Bus::fake();
        $channel = $this->channel();
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
            'settings' => [
                'product_import' => ['languages' => ['pl']],
                'product_export' => ['languages' => ['pl']],
            ],
        ]);
        $parent = $this->product('SKU-FAMILY', [
            'master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'catalog_visibility' => 'search',
            ],
        ]);
        $variantA = $this->product('SKU-FAMILY-S', [
            'master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'parameters' => [
                    ['name' => 'Rozmiar', 'value' => 'S', 'variation' => true],
                ],
            ],
        ]);
        $variantB = $this->product('SKU-FAMILY-M', [
            'master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'parameters' => [
                    ['name' => 'Rozmiar', 'value' => 'M', 'variation' => true],
                ],
            ],
        ], [
            'is_active' => false,
        ]);

        foreach ([$variantA, $variantB] as $index => $variant) {
            if ($index > 0) {
                ProductRelation::query()->create([
                    'parent_product_id' => $parent->id,
                    'child_product_id' => $variant->id,
                    'relation_type' => 'variant',
                    'sort_order' => ($index + 1) * 10,
                ]);
            }

            ProductChannelMapping::query()->create([
                'product_id' => $variant->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => '900',
                'external_variation_id' => (string) (901 + $index),
                'external_sku' => $variant->sku,
                'stock_sync_enabled' => true,
            ]);
        }

        $result = app(ProductStorefrontVisibilityService::class)->hide($variantA);

        $this->assertTrue($result['root']->is($parent));
        foreach ([$parent, $variantA, $variantB] as $member) {
            $member->refresh();
            $this->assertTrue($member->isStorefrontHidden());
            $this->assertTrue($member->requiresStockVerification());
        }
        $this->assertSame('hidden', data_get($parent->masterData(), 'catalog_visibility'));
        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '900',
            'external_variation_id' => null,
        ]);
        $this->assertDatabaseHas('product_relations', [
            'parent_product_id' => $parent->id,
            'child_product_id' => $variantA->id,
            'relation_type' => 'variant',
        ]);
        Bus::assertDispatchedAfterResponse(ExportWooCommerceProductDataJob::class, 1);

        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/attributes?*' => Http::response([[
                'id' => 70,
                'name' => 'Rozmiar',
                'slug' => 'pa_rozmiar',
            ]]),
            'https://shop.test/wp-json/wc/v3/products/attributes/70/terms?*' => Http::response([
                ['id' => 701, 'name' => 'S', 'slug' => 's-pl'],
                ['id' => 702, 'name' => 'M', 'slug' => 'm-pl'],
            ]),
            'https://shop.test/wp-json/wc/v3/products/900' => Http::response([
                'id' => 900,
                'sku' => $parent->sku,
            ]),
            'https://shop.test/wp-json/wc/v3/products/900/variations/901' => Http::response([
                'id' => 901,
                'sku' => $variantA->sku,
            ]),
            'https://shop.test/wp-json/wc/v3/products/900/variations/902' => Http::response([
                'id' => 902,
                'sku' => $variantB->sku,
            ]),
        ]);
        $hideJob = Bus::dispatchedAfterResponse(ExportWooCommerceProductDataJob::class)->sole();
        $hideJob->handle(app(ProductDataExportService::class));

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/900'
            && $request['status'] === 'publish'
            && $request['catalog_visibility'] === 'hidden'
            && $request['manage_stock'] === false
            && $request['default_attributes'] === []
            && ! array_key_exists('stock_quantity', $request->data())
            && ! array_key_exists('stock_status', $request->data()));
        foreach ([901, 902] as $variationId) {
            Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
                && $request->url() === "https://shop.test/wp-json/wc/v3/products/900/variations/{$variationId}"
                && $request['manage_stock'] === true
                && $request['stock_quantity'] === 0
                && $request['stock_status'] === 'outofstock'
                && $request['backorders'] === 'no');
        }

        app(ProductStorefrontVisibilityService::class)->reveal($variantB);

        foreach ([$parent, $variantA, $variantB] as $member) {
            $member->refresh();
            $this->assertFalse($member->isStorefrontHidden());
            $this->assertTrue($member->requiresStockVerification());
        }
        $this->assertSame('visible', data_get($parent->masterData(), 'catalog_visibility'));
    }

    public function test_product_export_keeps_publication_and_relationships_but_forces_hidden_zero_stock(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response([
                'id' => 123,
                'sku' => 'SKU-EXPORT-HIDDEN',
            ]),
        ]);
        $channel = $this->channel();
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
            'settings' => [
                'product_import' => ['languages' => ['pl']],
                'product_export' => ['languages' => ['pl']],
            ],
        ]);
        $category = ProductCategory::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '44',
            'name' => 'Koszule',
            'slug' => 'koszule',
            'path' => 'Koszule',
        ]);
        $product = $this->product('SKU-EXPORT-HIDDEN', [
            'master' => [
                'source' => 'erp',
                'publication_status' => 'publish',
                'catalog_visibility' => 'catalog',
                'category_ids' => [$category->id],
                'inventory' => ['manage_stock' => false, 'backorders' => 'yes'],
                'content' => ['pl' => ['name' => 'Ukrywany produkt']],
                'related_products' => [
                    'upsell_skus' => ['SKU-UPSELL'],
                    'cross_sell_skus' => ['SKU-CROSS'],
                ],
            ],
        ], [
            'storefront_hidden_at' => now(),
            'stock_verification_required_at' => now(),
        ]);
        $upsell = $this->product('SKU-UPSELL');
        $crossSell = $this->product('SKU-CROSS');

        foreach ([[$product, '123'], [$upsell, '777'], [$crossSell, '778']] as [$mappedProduct, $externalId]) {
            ProductChannelMapping::query()->create([
                'product_id' => $mappedProduct->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => $externalId,
                'external_sku' => $mappedProduct->sku,
                'stock_sync_enabled' => true,
            ]);
        }

        app(ProductDataExportService::class)->export($product);

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PUT' || $request->url() !== 'https://shop.test/wp-json/wc/v3/products/123') {
                return false;
            }

            $meta = collect($request['meta_data'])->pluck('value', 'key');

            return $request['status'] === 'publish'
                && $request['catalog_visibility'] === 'hidden'
                && $request['manage_stock'] === true
                && $request['stock_quantity'] === 0
                && $request['stock_status'] === 'outofstock'
                && $request['backorders'] === 'no'
                && $request['categories'] === [['id' => 44]]
                && $request['upsell_ids'] === [777]
                && $request['cross_sell_ids'] === [778]
                && $meta->get('_sempre_erp_storefront_hidden') === '1'
                && $meta->get('_sempre_erp_stock_verification_required') === '1';
        });
    }

    public function test_stock_queue_cannot_restore_positive_stock_while_verification_is_required(): void
    {
        Http::fake(fn ($request) => Http::response([
            'id' => str_ends_with($request->url(), '/124') ? 124 : 123,
            'stock_quantity' => $request['stock_quantity'],
            'stock_status' => $request['stock_status'],
        ]));
        $channel = $this->channel();
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $product = $this->product('SKU-STOCK-HOLD', [], [
            'stock_verification_required_at' => now(),
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        ProductChannelAlias::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '124',
            'external_sku' => $product->sku,
            'language' => 'en',
        ]);
        $item = StockSyncQueueItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'status' => 'pending',
            'quantity_to_push' => 8.7,
            'available_at' => now(),
        ]);

        app(StockSyncExportService::class)->export($item);

        $item->refresh();
        $this->assertSame('success', $item->status);
        $this->assertSame('0', (string) $item->metadata['woocommerce_stock_quantity']);
        $this->assertTrue($item->metadata['storefront_stock_forced_zero']);
        $this->assertCount(2, Http::recorded());
        Http::assertSent(fn ($request): bool => $request['stock_quantity'] === 0
            && $request['stock_status'] === 'outofstock');
        Http::assertNotSent(fn ($request): bool => $request['stock_quantity'] > 0);
    }

    public function test_manual_stock_release_without_a_stock_route_marks_product_export_pending(): void
    {
        Bus::fake();
        $channel = $this->channel();
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => false,
            'settings' => [
                'product_import' => ['languages' => ['pl']],
                'product_export' => ['languages' => ['pl']],
            ],
        ]);
        $product = $this->product('SKU-VERIFY-FALLBACK', [
            'master' => [
                'source' => 'erp',
                'inventory' => ['manage_stock' => false],
            ],
        ], [
            'stock_verification_required_at' => now(),
        ]);
        $mapping = ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '8123',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);

        $result = app(ProductStorefrontVisibilityService::class)->verifyStock($product);

        $this->assertTrue($result['changed']);
        $this->assertSame(0, $result['queued']);
        $this->assertFalse($product->fresh()->requiresStockVerification());
        $this->assertSame('visible', $product->fresh()->storefront_restore_visibility);
        $this->assertNotNull(data_get($mapping->fresh()->metadata, 'product_data_export.pending_token'));
        $this->assertTrue(data_get($mapping->fresh()->metadata, 'product_data_export.stock_release_pending'));
        Bus::assertDispatched(ExportWooCommerceProductDataJob::class, 2);
        Bus::assertDispatchedAfterResponse(ExportWooCommerceProductDataJob::class, 1);

        $job = Bus::dispatchedAfterResponse(ExportWooCommerceProductDataJob::class)->sole();
        $job->failed(new \RuntimeException('Testowa awaria eksportu'));

        $this->assertNull(data_get($mapping->fresh()->metadata, 'product_data_export.pending_token'));
        $this->assertTrue(data_get($mapping->fresh()->metadata, 'product_data_export.stock_release_pending'));
        $this->assertSame('visible', $product->fresh()->storefront_restore_visibility);

        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/8123' => Http::response([
                'id' => 8123,
                'sku' => $product->sku,
            ]),
        ]);

        $this->post(route('products.woocommerce.export', $product))
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/8123'
            && $request['manage_stock'] === false
            && $request['stock_status'] === 'instock'
            && ! array_key_exists('stock_quantity', $request->data()));
        $this->assertNull(data_get($mapping->fresh()->metadata, 'product_data_export.stock_release_pending'));
        $this->assertNull($product->fresh()->storefront_restore_visibility);
    }

    public function test_variant_stock_import_observes_the_parent_release_guard(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (str_contains($url, '/products/900/variations')) {
                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response([[
                        'id' => 901,
                        'sku' => 'SKU-GUARDED-S',
                        'name' => 'S',
                        'manage_stock' => true,
                        'stock_quantity' => 0,
                        'stock_status' => 'outofstock',
                        'attributes' => [
                            ['name' => 'Rozmiar', 'option' => 'S'],
                        ],
                    ]])
                    : Http::response([]);
            }

            if (str_contains($url, '/products')) {
                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response([[
                        'id' => 900,
                        'sku' => 'SKU-GUARDED',
                        'name' => 'Rodzina chroniona',
                        'type' => 'variable',
                        'status' => 'publish',
                    ]])
                    : Http::response([]);
            }

            return Http::response([], 404);
        });
        $channel = $this->channel();
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => false,
            'settings' => [
                'product_import' => ['languages' => ['pl']],
                'product_export' => ['languages' => ['pl']],
            ],
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'M-GUARD',
            'name' => 'Magazyn chroniony',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $warehouse->routes()->create([
            'sales_channel_id' => $channel->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 100,
        ]);
        $parent = $this->product('SKU-GUARDED', [
            'master' => ['source' => 'erp', 'product_type' => 'variable'],
        ]);
        $variant = $this->product('SKU-GUARDED-S', [
            'master' => ['source' => 'erp', 'product_type' => 'variation'],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '900',
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'product_data_export' => ['stock_release_pending' => true],
            ],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '900',
            'external_variation_id' => '901',
            'external_sku' => $variant->sku,
            'stock_sync_enabled' => true,
        ]);
        $balance = StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $variant->id,
            'quantity_on_hand' => 7,
            'quantity_reserved' => 0,
            'quantity_available' => 7,
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $this->assertSame(0, $stats['stock_updated']);
        $this->assertSame(1, $stats['stock_skipped_pending_export']);
        $this->assertSame('7.0000', (string) $balance->fresh()->quantity_on_hand);
    }

    public function test_woocommerce_import_cannot_replace_erp_stock_while_storefront_hold_is_active(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (str_contains($url, '/products')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response([[
                        'id' => 7100,
                        'sku' => 'SKU-IMPORT-HOLD',
                        'name' => 'Towar chroniony przed importem stanu',
                        'type' => 'simple',
                        'status' => 'publish',
                        'catalog_visibility' => 'hidden',
                        'manage_stock' => true,
                        'stock_quantity' => 0,
                        'stock_status' => 'outofstock',
                    ]])
                    : Http::response([]);
            }

            return Http::response([], 404);
        });
        $channel = $this->channel();
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
            'settings' => [
                'product_import' => ['languages' => ['pl']],
                'product_export' => ['languages' => ['pl']],
            ],
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $warehouse->routes()->create([
            'sales_channel_id' => $channel->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 100,
        ]);
        $product = $this->product('SKU-IMPORT-HOLD', [
            'master' => [
                'source' => 'woocommerce_import',
                'catalog_visibility' => 'visible',
            ],
        ], [
            'stock_verification_required_at' => now(),
            'storefront_restore_visibility' => 'visible',
        ]);
        $mapping = ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '7100',
            'external_variation_id' => null,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        $balance = StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 7,
            'quantity_reserved' => 0,
            'quantity_available' => 7,
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $this->assertSame(0, $stats['stock_updated']);
        $this->assertSame(1, $stats['stock_skipped_storefront_hold']);
        $this->assertSame('7.0000', (string) $balance->fresh()->quantity_on_hand);
        $product->refresh();
        $this->assertTrue($product->requiresStockVerification());
        $this->assertSame('visible', data_get($product->masterData(), 'catalog_visibility'));
        $this->assertSame('visible', $product->storefront_restore_visibility);

        $product->forceFill([
            'stock_verification_required_at' => null,
            'storefront_restore_visibility' => 'visible',
        ])->save();
        $mappingMetadata = (array) $mapping->metadata;
        data_set($mappingMetadata, 'product_data_export.stock_release_pending', true);
        $mapping->forceFill(['metadata' => $mappingMetadata])->save();

        $pendingStats = app(WooCommerceImportService::class)->importProducts($integration);

        $this->assertSame(0, $pendingStats['stock_updated']);
        $this->assertSame(1, $pendingStats['stock_skipped_pending_export']);
        $this->assertSame('7.0000', (string) $balance->fresh()->quantity_on_hand);
        $this->assertSame('visible', data_get($product->fresh()->masterData(), 'catalog_visibility'));
    }

    /**
     * @return array{0:SalesChannel,1:WordpressIntegration,2:Warehouse}
     */
    private function channelWithWarehouseRoute(): array
    {
        $channel = $this->channel();
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $warehouse->routes()->create([
            'sales_channel_id' => $channel->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 100,
        ]);

        return [$channel, $integration, $warehouse];
    }

    private function channel(): SalesChannel
    {
        return SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $extra
     */
    private function product(string $sku, array $attributes = [], array $extra = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => $sku,
            'name' => 'Produkt '.$sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => $attributes,
        ], $extra));
    }
}
