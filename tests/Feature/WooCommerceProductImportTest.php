<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\StockSyncQueueItem;
use App\Models\Warehouse;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WooCommerceProductImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_uses_parent_product_name_for_variations_and_imports_stock(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/products/categories')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ((int) ($query['page'] ?? 1) > 1) {
                    return Http::response([]);
                }

                return Http::response([
                    [
                        'id' => 15,
                        'name' => 'Koszule',
                        'slug' => 'koszule',
                        'path' => 'Odzież > Koszule',
                        'description' => 'Opis kategorii z WooCommerce',
                        'count' => 3,
                    ],
                ]);
            }

            if (str_contains($url, '/products/777/variations')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ((int) ($query['page'] ?? 1) > 1) {
                    return Http::response([]);
                }

                return Http::response([
                    [
                        'id' => 888,
                        'sku' => 'BLS29K1TRMI',
                        'name' => 'm',
                        'manage_stock' => true,
                        'stock_quantity' => 4,
                        'stock_status' => 'instock',
                        'menu_order' => 37,
                        'meta_data' => [
                            ['key' => 'lemon_preorder', 'value' => 'no'],
                        ],
                        'attributes' => [
                            ['name' => 'Kolor', 'option' => 'Czarny'],
                            ['name' => 'Rozmiar', 'option' => 'm'],
                        ],
                    ],
                ]);
            }

            if (str_contains($url, '/products')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ((int) ($query['page'] ?? 1) === 1) {
                    return Http::response([
                        [
                            'id' => 777,
                            'sku' => '',
                            'name' => 'Koszula VIVIEN Biala',
                            'type' => 'variable',
                            'status' => 'publish',
                            'date_created' => '2026-07-08T14:20:00',
                            'permalink' => 'https://shop.test/produkt/koszula-vivien-biala',
                            'meta_data' => [
                                ['key' => 'lemon_shipping_days', 'value' => '11'],
                                ['key' => 'lemon_shipping_text', 'value' => 'Planowana wysyłka: {date}'],
                                ['key' => 'lemon_preorder', 'value' => 'yes'],
                            ],
                            'attributes' => [
                                [
                                    'name' => 'Kolor',
                                    'variation' => true,
                                    'options' => ['Czarny'],
                                ],
                                [
                                    'name' => 'Rozmiar',
                                    'variation' => true,
                                    'options' => ['s', 'm'],
                                ],
                            ],
                            'images' => [
                                [
                                    'id' => 1001,
                                    'src' => 'https://shop.test/wp-content/uploads/koszula-vivien.jpg',
                                    'name' => 'Koszula VIVIEN',
                                    'alt' => 'Koszula VIVIEN Biala',
                                ],
                            ],
                        ],
                    ]);
                }

                return Http::response([]);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S', 's'],
            'values_en' => ['S', ''],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 100,
            'metadata' => [],
        ]);
        Product::query()->create([
            'sku' => 'WC-B2C-PARENT-777',
            'name' => 'Stary rodzic',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'woocommerce_import',
                'variant_attribute' => 'System',
            ]],
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $parent = Product::query()->where('sku', 'WC-B2C-PARENT-777')->firstOrFail();
        $product = Product::query()->where('sku', 'BLS29K1TRMI')->firstOrFail();

        $this->assertSame('Koszula VIVIEN Biala', $parent->name);
        $this->assertSame('variable', data_get($parent->attributes, 'woocommerce_type'));
        $this->assertSame('Rozmiar', data_get($parent->attributes, 'master.variant_attribute'));
        $this->assertSame('S | M', collect(data_get($parent->attributes, 'master.parameters'))
            ->firstWhere('name', 'Rozmiar')['value']);
        $this->assertSame('2026-07-08T14:20', data_get($parent->attributes, 'master.publication_date'));
        $this->assertSame(11, data_get($parent->attributes, 'master.shipping.days'));
        $this->assertSame('Planowana wysyłka: {date}', data_get($parent->attributes, 'master.shipping.text'));
        $this->assertTrue(data_get($parent->attributes, 'master.shipping.preorder'));
        $this->assertSame(2, Product::query()->count());
        $this->assertSame('Koszula VIVIEN Biala - M', $product->name);
        $this->assertSame('Rozmiar', data_get($product->attributes, 'master.variant_attribute'));
        $this->assertSame('M', collect(data_get($product->attributes, 'master.parameters'))
            ->firstWhere('name', 'Rozmiar')['value']);
        $this->assertSame(['S', 'M'], ProductParameterDefinition::query()
            ->where('name', 'Rozmiar')
            ->firstOrFail()
            ->values);
        $this->assertSame([], data_get($product->attributes, 'master.media'));
        $this->assertSame(11, data_get($product->attributes, 'master.shipping.days'));
        $this->assertSame('Planowana wysyłka: {date}', data_get($product->attributes, 'master.shipping.text'));
        $this->assertFalse(data_get($product->attributes, 'master.shipping.preorder'));
        $this->assertSame('https://shop.test/wp-content/uploads/koszula-vivien.jpg', $product->imageUrl());
        $this->assertSame('https://shop.test/produkt/koszula-vivien-biala', $product->externalProductUrl());
        $this->assertSame('https://shop.test/wp-content/uploads/koszula-vivien.jpg', $product->attributes['woocommerce_parent_image']['src']);
        $this->assertSame(37, (int) $parent->variantChildren()->firstOrFail()->pivot->sort_order);
        $this->assertSame(1, $stats['stock_updated']);
        $this->assertSame(1, ProductChannelMapping::query()->where('external_variation_id', '888')->count());
        $this->assertDatabaseHas('product_categories', [
            'sales_channel_id' => $channel->id,
            'external_id' => '15',
            'name' => 'Koszule',
            'description' => 'Opis kategorii z WooCommerce',
        ]);

        $warehouse = Warehouse::query()->where('code', 'WC_B2C')->firstOrFail();
        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('4.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('0.0000', (string) $balance->quantity_reserved);
        $this->assertSame('4.0000', (string) $balance->quantity_available);
    }

    public function test_import_only_replaces_legacy_generic_variant_metadata_with_an_unambiguous_size_axis(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_contains($path, '/products/categories')) {
                return Http::response([]);
            }

            if (preg_match('#/products/(\d+)/variations$#', $path, $matches) === 1) {
                if ((int) ($query['page'] ?? 1) !== 1 || (int) $matches[1] !== 8101) {
                    return Http::response([]);
                }

                return Http::response([[
                    'id' => 8111,
                    'sku' => 'LEGACY-SIZE-MATCH-SM',
                    'name' => 'stary wariant',
                    'status' => 'publish',
                    'meta_data' => [[
                        'key' => '_sempre_erp_variant_attribute',
                        'value' => 'variant',
                    ]],
                    'attributes' => [
                        ['name' => 'wariant', 'option' => 's-m'],
                        ['name' => 'Rozmiar', 'option' => 'S/M'],
                        ['name' => 'Color', 'option' => 'Black'],
                    ],
                ]]);
            }

            if ($path === '/wp-json/wc/v3/products') {
                if ((int) ($query['page'] ?? 1) !== 1) {
                    return Http::response([]);
                }

                return Http::response([
                    [
                        'id' => 8101,
                        'sku' => 'LEGACY-SIZE-MATCH',
                        'name' => 'Rodzina z tymi samymi opcjami',
                        'type' => 'variable',
                        'status' => 'publish',
                        'meta_data' => [[
                            'key' => '_sempre_erp_variant_attribute',
                            'value' => 'BLVariant',
                        ]],
                        'attributes' => [
                            ['name' => 'wariant', 'variation' => true, 'options' => ['M/L', 'S/M']],
                            ['name' => 'Rozmiar', 'variation' => true, 'options' => ['s / m', 'm / l']],
                            ['name' => 'Color', 'variation' => true, 'options' => ['Black', 'White']],
                        ],
                    ],
                    [
                        'id' => 8102,
                        'sku' => 'LEGACY-SOLE-SIZE',
                        'name' => 'Rodzina z jedyną osią rozmiaru',
                        'type' => 'variable',
                        'status' => 'publish',
                        'meta_data' => [[
                            'key' => '_sempre_erp_variant_attribute',
                            'value' => 'wariant',
                        ]],
                        'attributes' => [
                            ['name' => 'Size', 'variation' => true, 'options' => ['S/M']],
                            ['name' => 'Color', 'variation' => false, 'options' => ['Black']],
                        ],
                    ],
                    [
                        'id' => 8103,
                        'sku' => 'LEGACY-COLOR-ONLY',
                        'name' => 'Rodzina kolorystyczna',
                        'type' => 'variable',
                        'status' => 'publish',
                        'meta_data' => [[
                            'key' => '_sempre_erp_variant_attribute',
                            'value' => 'variant',
                        ]],
                        'attributes' => [
                            ['name' => 'Color', 'variation' => true, 'options' => ['Black', 'White']],
                        ],
                    ],
                    [
                        'id' => 8104,
                        'sku' => 'LEGACY-AMBIGUOUS',
                        'name' => 'Rodzina niejednoznaczna',
                        'type' => 'variable',
                        'status' => 'publish',
                        'meta_data' => [[
                            'key' => '_sempre_erp_variant_attribute',
                            'value' => 'BLVariant',
                        ]],
                        'attributes' => [
                            ['name' => 'wariant', 'variation' => true, 'options' => ['S', 'M']],
                            ['name' => 'Rozmiar', 'variation' => true, 'options' => ['S', 'M']],
                            ['name' => 'Color', 'variation' => true, 'options' => ['S', 'M']],
                        ],
                    ],
                    [
                        'id' => 8105,
                        'sku' => 'LEGACY-MISMATCH',
                        'name' => 'Rodzina z różnymi opcjami',
                        'type' => 'variable',
                        'status' => 'publish',
                        'meta_data' => [[
                            'key' => '_sempre_erp_variant_attribute',
                            'value' => 'wariant',
                        ]],
                        'attributes' => [
                            ['name' => 'wariant', 'variation' => true, 'options' => ['S', 'M']],
                            ['name' => 'Rozmiar', 'variation' => true, 'options' => ['S/M', 'M/L']],
                        ],
                    ],
                    [
                        'id' => 8106,
                        'sku' => 'LEGACY-GENERIC-SIZE-MATCH',
                        'name' => 'Rodzina z jednym konkretnym odpowiednikiem',
                        'type' => 'variable',
                        'status' => 'publish',
                        'meta_data' => [[
                            'key' => '_sempre_erp_variant_attribute',
                            'value' => 'BLVariant',
                        ]],
                        'attributes' => [
                            ['name' => 'wariant', 'variation' => true, 'options' => ['M/L', 'S/M']],
                            ['name' => 'Rozmiar', 'variation' => true, 'options' => ['s / m', 'm / l']],
                        ],
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C-LEGACY-AXIS',
            'name' => 'Sklep B2C legacy axis',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo legacy axis',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);

        app(WooCommerceImportService::class)->importProducts($integration);

        $this->assertSame('BLVariant', data_get(
            Product::query()->where('sku', 'LEGACY-SIZE-MATCH')->firstOrFail()->masterData(),
            'variant_attribute',
        ));
        $this->assertSame('variant', data_get(
            Product::query()->where('sku', 'LEGACY-SIZE-MATCH-SM')->firstOrFail()->masterData(),
            'variant_attribute',
        ));
        $this->assertSame('Rozmiar', data_get(
            Product::query()->where('sku', 'LEGACY-SOLE-SIZE')->firstOrFail()->masterData(),
            'variant_attribute',
        ));
        $this->assertSame('variant', data_get(
            Product::query()->where('sku', 'LEGACY-COLOR-ONLY')->firstOrFail()->masterData(),
            'variant_attribute',
        ));
        $this->assertSame('BLVariant', data_get(
            Product::query()->where('sku', 'LEGACY-AMBIGUOUS')->firstOrFail()->masterData(),
            'variant_attribute',
        ));
        $this->assertSame('wariant', data_get(
            Product::query()->where('sku', 'LEGACY-MISMATCH')->firstOrFail()->masterData(),
            'variant_attribute',
        ));
        $this->assertSame('Rozmiar', data_get(
            Product::query()->where('sku', 'LEGACY-GENERIC-SIZE-MATCH')->firstOrFail()->masterData(),
            'variant_attribute',
        ));
    }

    public function test_imported_woo_stock_is_available_quantity_and_preserves_active_reservations_in_on_hand(): void
    {
        $remoteStock = 0;

        Http::fake(function ($request) use (&$remoteStock) {
            $url = $request->url();

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (str_contains($url, '/products')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response([[
                        'id' => 7001,
                        'sku' => 'SKU-RESERVED-STOCK',
                        'name' => 'Towar z rezerwacją',
                        'type' => 'simple',
                        'status' => 'publish',
                        'manage_stock' => true,
                        'stock_quantity' => $remoteStock,
                        'stock_status' => $remoteStock > 0 ? 'instock' : 'outofstock',
                    ]])
                    : Http::response([]);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
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
        $product = Product::query()->create([
            'sku' => 'SKU-RESERVED-STOCK',
            'name' => 'Towar z rezerwacją',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '7001',
            'external_variation_id' => null,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 2,
            'quantity_reserved' => 2,
            'quantity_available' => 0,
        ]);
        StockReservation::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_order_id' => 'ORDER-1',
            'quantity' => 2,
            'status' => 'active',
            'reserved_at' => now(),
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $balance = $product->stockBalances()->where('warehouse_id', $warehouse->id)->firstOrFail();

        $this->assertSame(1, $stats['stock_updated']);
        $this->assertSame(0, $stats['stock_skipped_waiting_reservations']);

        $this->assertSame('2.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('2.0000', (string) $balance->quantity_reserved);
        $this->assertSame('0.0000', (string) $balance->quantity_available);

        $remoteStock = 2;
        app(WooCommerceImportService::class)->importProducts($integration);
        $balance->refresh();

        $this->assertSame('4.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('2.0000', (string) $balance->quantity_reserved);
        $this->assertSame('2.0000', (string) $balance->quantity_available);
    }

    public function test_imported_woo_stock_promotes_waiting_reservation_and_reconstructs_physical_stock(): void
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
                        'id' => 7002,
                        'sku' => 'SKU-WAITING-STOCK',
                        'name' => 'Towar z oczekującą rezerwacją',
                        'type' => 'simple',
                        'status' => 'publish',
                        'manage_stock' => true,
                        'stock_quantity' => 2,
                        'stock_status' => 'instock',
                    ]])
                    : Http::response([]);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
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
        $product = Product::query()->create([
            'sku' => 'SKU-WAITING-STOCK',
            'name' => 'Towar z oczekującą rezerwacją',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '7002',
            'external_variation_id' => null,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
            'quantity_available' => 0,
        ]);
        $reservation = StockReservation::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_order_id' => 'ORDER-WAITING-1',
            'quantity' => 2,
            'status' => 'waiting',
            'reserved_at' => now(),
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $balance = $product->stockBalances()->where('warehouse_id', $warehouse->id)->firstOrFail();
        $this->assertSame(1, $stats['stock_updated']);
        $this->assertSame(0, $stats['stock_skipped_waiting_reservations']);
        $this->assertSame('4.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('2.0000', (string) $balance->quantity_reserved);
        $this->assertSame('2.0000', (string) $balance->quantity_available);
        $this->assertSame('active', $reservation->fresh()->status);
    }

    public function test_imported_woo_stock_does_not_override_balance_while_export_is_pending(): void
    {
        $scenario = $this->stockImportSkipScenario();
        $queueItem = StockSyncQueueItem::query()->create([
            'warehouse_id' => $scenario['warehouse']->id,
            'product_id' => $scenario['product']->id,
            'sales_channel_id' => $scenario['channel']->id,
            'status' => 'pending',
            'quantity_to_push' => 7,
            'available_at' => now(),
            'metadata' => ['reason' => 'test_pending_export'],
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($scenario['integration']);
        $balance = $scenario['balance']->fresh();

        $this->assertSame(0, $stats['stock_updated']);
        $this->assertSame(1, $stats['stock_skipped_pending_export']);
        $this->assertSame(0, $stats['stock_skipped_ambiguous_routes']);
        $this->assertSame('7.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('0.0000', (string) $balance->quantity_reserved);
        $this->assertSame('7.0000', (string) $balance->quantity_available);

        $queueItem->update([
            'status' => 'success',
            'processed_at' => now(),
            'metadata' => ['reason' => 'test_recovered_export'],
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($scenario['integration']);
        $balance->refresh();

        $this->assertSame(1, $stats['stock_updated']);
        $this->assertSame(0, $stats['stock_skipped_pending_export']);
        $this->assertSame('2.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('0.0000', (string) $balance->quantity_reserved);
        $this->assertSame('2.0000', (string) $balance->quantity_available);
    }

    public function test_imported_woo_stock_does_not_override_balance_after_latest_export_failed(): void
    {
        $scenario = $this->stockImportSkipScenario();
        $queueItem = StockSyncQueueItem::query()->create([
            'warehouse_id' => $scenario['warehouse']->id,
            'product_id' => $scenario['product']->id,
            'sales_channel_id' => $scenario['channel']->id,
            'status' => 'failed',
            'quantity_to_push' => 7,
            'available_at' => now(),
            'processed_at' => now(),
            'last_error' => 'Testowy błąd eksportu',
            'metadata' => ['reason' => 'test_failed_export'],
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($scenario['integration']);
        $balance = $scenario['balance']->fresh();

        $this->assertSame(0, $stats['stock_updated']);
        $this->assertSame(1, $stats['stock_skipped_pending_export']);
        $this->assertSame('7.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('0.0000', (string) $balance->quantity_reserved);
        $this->assertSame('7.0000', (string) $balance->quantity_available);

        $queueItem->update([
            'status' => 'success',
            'processed_at' => now(),
            'last_error' => null,
            'metadata' => ['reason' => 'test_recovered_export'],
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($scenario['integration']);
        $balance->refresh();

        $this->assertSame(1, $stats['stock_updated']);
        $this->assertSame(0, $stats['stock_skipped_pending_export']);
        $this->assertSame('2.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('0.0000', (string) $balance->quantity_reserved);
        $this->assertSame('2.0000', (string) $balance->quantity_available);
    }

    public function test_imported_woo_stock_does_not_override_balance_for_buffered_route(): void
    {
        $scenario = $this->stockImportSkipScenario(stockBuffer: 1);

        $stats = app(WooCommerceImportService::class)->importProducts($scenario['integration']);
        $balance = $scenario['balance']->fresh();

        $this->assertSame(0, $stats['stock_updated']);
        $this->assertSame(1, $stats['stock_skipped_ambiguous_routes']);
        $this->assertSame(0, $stats['stock_skipped_pending_export']);
        $this->assertSame('7.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('0.0000', (string) $balance->quantity_reserved);
        $this->assertSame('7.0000', (string) $balance->quantity_available);
    }

    public function test_imported_woo_stock_does_not_override_balance_for_multiple_push_routes(): void
    {
        $scenario = $this->stockImportSkipScenario(secondPushRoute: true);

        $stats = app(WooCommerceImportService::class)->importProducts($scenario['integration']);
        $balance = $scenario['balance']->fresh();

        $this->assertSame(0, $stats['stock_updated']);
        $this->assertSame(1, $stats['stock_skipped_ambiguous_routes']);
        $this->assertSame(0, $stats['stock_skipped_pending_export']);
        $this->assertSame('7.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('0.0000', (string) $balance->quantity_reserved);
        $this->assertSame('7.0000', (string) $balance->quantity_available);
    }

    public function test_import_keeps_duplicate_parent_and_variation_skus_as_separate_mappings(): void
    {
        $variationHasImage = true;

        Http::fake(function ($request) use (&$variationHasImage) {
            $url = $request->url();

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (str_contains($url, '/products/777/variations')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ((int) ($query['page'] ?? 1) !== 1) {
                    return Http::response([]);
                }

                $variation = [
                    'id' => 888,
                    'sku' => 'DUP-SKU',
                    'name' => 'M',
                    'attributes' => [['name' => 'Rozmiar', 'option' => 'M']],
                ];

                if ($variationHasImage) {
                    $variation['image'] = [
                        'id' => 2002,
                        'src' => 'https://shop.test/wp-content/uploads/variant-m.jpg',
                        'alt' => 'Wariant M',
                    ];
                }

                return Http::response([$variation]);
            }

            if (str_contains($url, '/products')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response([[
                        'id' => 777,
                        'sku' => 'DUP-SKU',
                        'name' => 'Produkt główny',
                        'type' => 'variable',
                        'status' => 'publish',
                    ]])
                    : Http::response([]);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $parent = Product::query()->where('sku', 'DUP-SKU')->firstOrFail();
        $variant = Product::query()->where('sku', 'WC-B2C-VARIANT-888')->firstOrFail();

        $this->assertSame(0, $stats['duplicate_sku_items']);
        $this->assertSame(1, $stats['duplicate_sku_resolved']);
        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '777',
            'external_variation_id' => null,
        ]);
        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '777',
            'external_variation_id' => '888',
        ]);
        $this->assertSame('https://shop.test/wp-content/uploads/variant-m.jpg', data_get($variant->attributes, 'master.media.0.src'));
        $this->assertSame('https://shop.test/wp-content/uploads/variant-m.jpg', $variant->imageUrl());
        $this->assertTrue($parent->variantChildren()->whereKey($variant->id)->exists());

        $variationHasImage = false;
        app(WooCommerceImportService::class)->importProducts($integration);

        $this->assertSame([], data_get($variant->fresh()->attributes, 'master.media'));
    }

    public function test_import_filters_polylang_twins_when_woocommerce_ignores_the_requested_language(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_contains($url, '/products/categories')) {
                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response([
                        [
                            'id' => 10,
                            'name' => 'Koszule',
                            'lemon_erp_catalog_contract' => 1,
                            'lemon_erp_language' => 'pl',
                            'lemon_erp_translations' => ['pl' => 10, 'en' => 11],
                            'lemon_erp_translation_group' => 'category:10|11',
                        ],
                        [
                            'id' => 11,
                            'name' => 'Shirts',
                            'lemon_erp_catalog_contract' => 1,
                            'lemon_erp_language' => 'en',
                            'lemon_erp_translations' => ['pl' => 10, 'en' => 11],
                            'lemon_erp_translation_group' => 'category:10|11',
                        ],
                    ])
                    : Http::response([]);
            }

            if (str_contains($url, '/products')) {
                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response([
                        [
                            'id' => 100,
                            'sku' => 'POLYLANG-SKU',
                            'name' => 'Koszula AURA',
                            'type' => 'simple',
                            'status' => 'publish',
                            'lang' => 'pl',
                            'translations' => ['pl' => 100, 'en' => 101],
                            'categories' => [['id' => 10, 'name' => 'Koszule']],
                        ],
                        [
                            'id' => 101,
                            'sku' => 'POLYLANG-SKU',
                            'name' => 'AURA Shirt',
                            'type' => 'simple',
                            'status' => 'publish',
                            'lang' => 'en',
                            'translations' => ['pl' => 100, 'en' => 101],
                            'categories' => [['id' => 11, 'name' => 'Shirts']],
                        ],
                    ])
                    : Http::response([]);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_import' => ['languages' => ['pl', 'en']]],
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $product = Product::query()->where('sku', 'POLYLANG-SKU')->firstOrFail();

        $this->assertSame(1, $stats['source_items']);
        $this->assertSame(1, $stats['source_products']);
        $this->assertSame(0, $stats['duplicate_sku_items']);
        $this->assertSame(1, $stats['created']);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, ProductChannelMapping::query()->count());
        $this->assertSame(1, ProductChannelAlias::query()->count());
        $this->assertSame(1, $stats['translation_aliases_mapped']);
        $this->assertSame('101', data_get($product->attributes, 'woocommerce_translations.en.product_id'));
        $this->assertSame(1, ProductCategory::query()->count());
        $this->assertSame('11', data_get(ProductCategory::query()->firstOrFail()->metadata, 'woocommerce_ids.en'));
    }

    public function test_catalog_contract_merges_real_polylang_pair_even_when_translation_has_no_sku(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (! str_contains($url, '/products') || (int) ($query['page'] ?? 1) > 1) {
                return Http::response([]);
            }

            return Http::response([
                [
                    'id' => 700143,
                    'sku' => 'BLS6A4FE375DAA5D',
                    'name' => 'Koszula AVA Kremowo - różowa',
                    'type' => 'simple',
                    'status' => 'publish',
                    'stock_quantity' => 2,
                    'lemon_erp_catalog_contract' => 1,
                    'lemon_erp_language' => 'pl',
                    'lemon_erp_translations' => ['pl' => 700143, 'en' => 750099],
                    'lemon_erp_translation_group' => 'product:700143|750099',
                ],
                [
                    'id' => 750099,
                    'sku' => '',
                    'name' => 'AVA Cream and Pink Shirt',
                    'type' => 'simple',
                    'status' => 'publish',
                    'stock_quantity' => 2,
                    'lemon_erp_catalog_contract' => 1,
                    'lemon_erp_language' => 'en',
                    'lemon_erp_translations' => ['pl' => 700143, 'en' => 750099],
                    'lemon_erp_translation_group' => 'product:700143|750099',
                ],
            ]);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_import' => ['languages' => ['pl', 'en']]],
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $product = Product::query()->where('sku', 'BLS6A4FE375DAA5D')->firstOrFail();
        $this->assertSame(1, Product::query()->where('is_translation', false)->count());
        $this->assertSame(1, ProductChannelMapping::query()->count());
        $this->assertSame(1, ProductChannelAlias::query()->count());
        $this->assertSame('AVA Cream and Pink Shirt', data_get($product->attributes, 'master.content.en.name'));
        $this->assertSame('750099', data_get($product->attributes, 'woocommerce_translations.en.product_id'));
        $this->assertSame(1, $stats['source_items']);
        $this->assertSame(0, $stats['duplicate_sku_items']);
    }

    public function test_multilingual_import_without_catalog_contract_stops_before_creating_duplicates(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (! str_contains($url, '/products') || (int) ($query['page'] ?? 1) > 1) {
                return Http::response([]);
            }

            return Http::response([
                ['id' => 700143, 'sku' => 'BLS6A4FE375DAA5D', 'name' => 'Koszula AVA', 'type' => 'simple'],
                ['id' => 750099, 'sku' => '', 'name' => 'AVA Shirt', 'type' => 'simple'],
            ]);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_import' => ['languages' => ['pl', 'en']]],
        ]);

        try {
            app(WooCommerceImportService::class)->importProducts($integration);
            $this->fail('Import bez kontraktu katalogowego powinien zostać zatrzymany.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Zaktualizuj i aktywuj wtyczkę', $exception->getMessage());
            $this->assertStringContainsString('0.2.0', $exception->getMessage());
        }

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, ProductChannelMapping::query()->count());
        $this->assertSame(0, ProductChannelAlias::query()->count());
    }

    public function test_import_reclassifies_a_legacy_polylang_twin_after_identifying_its_translation(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (str_contains($url, '/products')) {
                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response([
                        [
                            'id' => 100,
                            'sku' => 'POLYLANG-SKU',
                            'name' => 'Koszula AURA',
                            'type' => 'simple',
                            'status' => 'publish',
                            'lang' => 'pl',
                            'translations' => ['pl' => 100, 'en' => 101],
                        ],
                        [
                            'id' => 101,
                            'sku' => 'POLYLANG-SKU',
                            'name' => 'AURA Shirt',
                            'type' => 'simple',
                            'status' => 'publish',
                            'lang' => 'en',
                            'translations' => ['pl' => 100, 'en' => 101],
                        ],
                    ])
                    : Http::response([]);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_import' => ['languages' => ['pl', 'en']]],
        ]);
        $primary = Product::query()->create([
            'sku' => 'POLYLANG-SKU',
            'name' => 'Koszula AURA',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $legacyTranslation = Product::query()->create([
            'sku' => 'WC-B2C-PARENT-101',
            'name' => 'AURA Shirt',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $primary->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '100',
            'external_sku' => 'POLYLANG-SKU',
            'stock_sync_enabled' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $legacyTranslation->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '101',
            'external_sku' => 'POLYLANG-SKU',
            'stock_sync_enabled' => true,
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $this->assertSame(1, $stats['source_items']);
        $this->assertSame(0, $stats['duplicate_sku_items']);
        $this->assertSame(1, $stats['translation_products_merged']);
        $this->assertTrue($legacyTranslation->fresh()->is_translation);
        $this->assertFalse($legacyTranslation->fresh()->is_active);
        $this->assertSame($primary->id, data_get($legacyTranslation->fresh()->attributes, 'master.merge.canonical_product_id'));
        $this->assertDatabaseHas('product_channel_aliases', [
            'product_id' => $primary->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '101',
            'language' => 'en',
        ]);
        $this->assertDatabaseMissing('product_channel_mappings', [
            'product_id' => $legacyTranslation->id,
            'external_product_id' => '101',
        ]);
        $this->assertSame('101', data_get($primary->fresh()->attributes, 'woocommerce_translations.en.product_id'));
    }

    public function test_import_reads_all_product_pages(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (str_contains($url, '/products')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                if ($page <= 7) {
                    return Http::response([
                        [
                            'id' => 1000 + $page,
                            'sku' => 'SKU-PAGE-'.$page,
                            'name' => 'Produkt strona '.$page,
                            'type' => 'simple',
                            'status' => 'publish',
                        ],
                    ]);
                }

                return Http::response([]);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $this->assertSame(7, $stats['created']);
        $this->assertSame(7, Product::query()->count());
        $this->assertNotNull(Product::query()->where('sku', 'SKU-PAGE-7')->first());
    }

    public function test_import_does_not_overwrite_product_owned_by_erp(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/products')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ((int) ($query['page'] ?? 1) === 1) {
                    return Http::response([
                        [
                            'id' => 900,
                            'sku' => 'ERP-SKU-1',
                            'name' => 'Nazwa z Woo',
                            'type' => 'simple',
                            'status' => 'publish',
                            'regular_price' => '999.00',
                            'stock_quantity' => 7,
                            'stock_status' => 'instock',
                            'images' => [
                                ['id' => 10, 'src' => 'https://shop.test/woo.jpg'],
                            ],
                        ],
                    ]);
                }

                return Http::response([]);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);

        $product = Product::query()->create([
            'sku' => 'ERP-SKU-1',
            'name' => 'Nazwa ERP',
            'unit' => 'szt',
            'vat_rate' => 8,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'category' => 'Koszule',
                    'prices' => [
                        'retail_price_pln' => 369.00,
                    ],
                    'content' => [
                        'pl' => [
                            'description' => '<p>Opis ERP</p>',
                        ],
                    ],
                ],
            ],
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $product->refresh();
        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame('Nazwa ERP', $product->name);
        $this->assertSame('8.00', (string) $product->vat_rate);
        $this->assertSame('erp', data_get($product->attributes, 'master.source'));
        $this->assertEquals(369.00, data_get($product->attributes, 'master.prices.retail_price_pln'));
        $this->assertSame('<p>Opis ERP</p>', data_get($product->attributes, 'master.content.pl.description'));
        $this->assertSame('publish', data_get($product->attributes, 'woocommerce_status'));
        $this->assertSame('https://shop.test/woo.jpg', $product->imageUrl());

        $warehouse = Warehouse::query()->where('code', 'WC_B2C')->firstOrFail();
        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('7.0000', (string) $balance->quantity_on_hand);
    }

    public function test_import_stores_full_product_master_data_and_translations(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (str_contains($url, '/products')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ((int) ($query['page'] ?? 1) > 1) {
                    return Http::response([]);
                }

                $language = (string) ($query['lang'] ?? 'pl');

                return Http::response([
                    [
                        'id' => $language === 'en' ? 901 : 900,
                        'sku' => 'FULL-WOO-1',
                        'name' => $language === 'en' ? 'AURA Shirt' : 'Koszula AURA',
                        'type' => 'simple',
                        'status' => 'publish',
                        'catalog_visibility' => 'visible',
                        'regular_price' => '369.00',
                        'sale_price' => '299.00',
                        'stock_quantity' => 5,
                        'stock_status' => 'instock',
                        'manage_stock' => true,
                        'backorders' => 'notify',
                        'low_stock_amount' => 2,
                        'sold_individually' => true,
                        'global_unique_id' => '5901234123457',
                        'weight' => '0.3',
                        'dimensions' => [
                            'height' => '2',
                            'width' => '30',
                            'length' => '40',
                        ],
                        'description' => $language === 'en' ? '<p>English description</p>' : '<p>Polski opis</p>',
                        'short_description' => $language === 'en' ? '<p>Short EN</p>' : '<p>Krótki PL</p>',
                        'categories' => [
                            ['id' => 10, 'name' => 'Koszule'],
                        ],
                        'tags' => [
                            ['id' => 11, 'name' => 'Nowość'],
                        ],
                        'attributes' => [
                            [
                                'id' => 7,
                                'name' => $language === 'en' ? 'Size' : 'Rozmiar',
                                'options' => [$language === 'en' ? 'One size' : 'Uniwersalny'],
                                'variation' => true,
                            ],
                            [
                                'id' => 8,
                                'name' => $language === 'en' ? 'Composition' : 'Skład',
                                'options' => [$language === 'en' ? 'Cotton' : 'Bawełna'],
                                'variation' => false,
                            ],
                        ],
                        'meta_data' => [
                            ['key' => '_warehouse_location', 'value' => 'A-01-02'],
                            ['key' => '_lemon_product_label_text', 'value' => $language === 'en' ? 'New' : 'Nowość'],
                            ['key' => '_lemon_product_label_bg_color', 'value' => '#112233'],
                            ['key' => '_lemon_product_label_text_color', 'value' => '#ffffff'],
                            ['key' => 'lemon_shipping_days', 'value' => '7'],
                            ['key' => 'lemon_shipping_text', 'value' => $language === 'en'
                                ? 'Planned shipping: {date}'
                                : 'Wysyłka: {date}'],
                            ['key' => 'lemon_preorder', 'value' => 'yes'],
                        ],
                        'upsell_ids' => [111],
                        'cross_sell_ids' => [222],
                        'images' => [
                            ['id' => 77, 'src' => 'https://shop.test/aura.jpg', 'alt' => 'Koszula AURA'],
                        ],
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $product = Product::query()->where('sku', 'FULL-WOO-1')->firstOrFail();

        $this->assertSame('Koszula AURA', $product->name);
        $this->assertSame('5901234123457', $product->ean);
        $this->assertSame('0.3000', (string) $product->weight_kg);
        $this->assertSame('<p>Polski opis</p>', data_get($product->attributes, 'master.content.pl.description'));
        $this->assertSame('<p>English description</p>', data_get($product->attributes, 'master.content.en.description'));
        $this->assertSame('<p>Krótki PL</p>', data_get($product->attributes, 'master.content.pl.additional_description'));
        $this->assertSame('Koszule', data_get($product->attributes, 'master.category'));
        $this->assertSame(['Koszule'], data_get($product->attributes, 'master.categories'));
        $this->assertNotEmpty(data_get($product->attributes, 'master.category_ids'));
        $this->assertSame('A-01-02', data_get($product->attributes, 'master.stock.location'));
        $this->assertTrue(data_get($product->attributes, 'master.inventory.manage_stock'));
        $this->assertSame('notify', data_get($product->attributes, 'master.inventory.backorders'));
        $this->assertEquals(2.0, data_get($product->attributes, 'master.inventory.low_stock_amount'));
        $this->assertTrue(data_get($product->attributes, 'master.inventory.sold_individually'));
        $this->assertSame('Nowość', data_get($product->attributes, 'master.custom_label.pl'));
        $this->assertSame('New', data_get($product->attributes, 'master.custom_label.en'));
        $this->assertSame(7, data_get($product->attributes, 'master.shipping.days'));
        $this->assertSame('Wysyłka: {date}', data_get($product->attributes, 'master.shipping.text'));
        $this->assertSame('Planned shipping: {date}', data_get($product->attributes, 'master.shipping.text_en'));
        $this->assertTrue(data_get($product->attributes, 'master.shipping.preorder'));
        $this->assertNull(data_get($product->attributes, 'master.stock.quantity'));
        $this->assertEquals(299.0, data_get($product->attributes, 'master.prices.sale_price_pln'));
        $this->assertSame('Rozmiar', data_get($product->attributes, 'master.parameters.0.name'));
        $this->assertTrue(data_get($product->attributes, 'master.parameters.0.variation'));
        $this->assertSame([111], data_get($product->attributes, 'master.related_products.upsell_ids'));
        $this->assertSame('https://shop.test/aura.jpg', $product->imageUrl());
        $this->assertSame('5901234123457', data_get($product->attributes, 'woocommerce_global_unique_id'));
        $this->assertSame('<p>Polski opis</p>', data_get($product->attributes, 'woocommerce_description'));
        $this->assertSame('901', data_get($product->attributes, 'woocommerce_translations.en.product_id'));
        $this->assertSame('FULL-WOO-1', data_get($product->attributes, 'woocommerce_translations.en.sku'));
        $this->assertSame(2, $stats['parameter_definitions_localized']);
        $this->assertSame(0, $stats['parameter_definitions_merged']);
        $this->assertDatabaseHas('product_parameter_definitions', [
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'is_variant' => true,
        ]);
        $this->assertDatabaseHas('product_parameter_definitions', [
            'name' => 'Skład',
            'name_en' => 'Composition',
            'is_variant' => false,
        ]);
        $this->assertSame(
            ['One size'],
            ProductParameterDefinition::query()->where('name', 'Rozmiar')->firstOrFail()->values_en,
        );
        $this->assertSame(
            ['Cotton'],
            ProductParameterDefinition::query()->where('name', 'Skład')->firstOrFail()->values_en,
        );
    }

    public function test_import_marks_a_duplicate_ean_for_manual_review_without_stopping_sync(): void
    {
        $ean = '5906065008508';
        $owner = Product::query()->create([
            'sku' => 'EXISTING-EAN-OWNER',
            'name' => 'Produkt z istniejącym EAN',
            'ean' => $ean,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $conflictedProduct = Product::query()->create([
            'sku' => 'M700036',
            'name' => 'Marynarka TIFFANY Off White',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'woocommerce_import',
                    'identifier_conflict' => [
                        'type' => 'duplicated_ean',
                        'previous_ean' => $ean,
                        'resolution' => 'cleared_for_manual_review',
                    ],
                ],
            ],
        ]);

        Http::fake(function ($request) use ($ean) {
            $url = $request->url();

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (str_contains($url, '/products')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response([[
                        'id' => 700036,
                        'sku' => 'M700036',
                        'name' => 'Marynarka TIFFANY Off White',
                        'type' => 'simple',
                        'status' => 'publish',
                        'global_unique_id' => $ean,
                        'stock_quantity' => 0,
                    ]])
                    : Http::response([]);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $conflictedProduct->refresh();

        $this->assertSame(1, $stats['duplicate_ean_items']);
        $this->assertNull($conflictedProduct->ean);
        $this->assertSame($ean, data_get($conflictedProduct->attributes, 'woocommerce_ean'));
        $this->assertNull(data_get($conflictedProduct->attributes, 'master.ean'));
        $this->assertSame('duplicated_ean', data_get($conflictedProduct->attributes, 'master.identifier_conflict.type'));
        $this->assertSame($ean, data_get($conflictedProduct->attributes, 'master.identifier_conflict.previous_ean'));
        $this->assertSame($owner->id, data_get($conflictedProduct->attributes, 'master.identifier_conflict.conflicting_product_id'));
        $this->assertSame('EXISTING-EAN-OWNER', data_get($conflictedProduct->attributes, 'master.identifier_conflict.conflicting_product_sku'));
        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $conflictedProduct->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '700036',
            'external_variation_id' => null,
        ]);
    }

    public function test_import_reclaims_an_ean_from_a_polylang_translation(): void
    {
        $ean = '5906065008508';
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => [
                'product_import' => ['languages' => ['pl', 'en']],
            ],
        ]);
        $polishProduct = Product::query()->create([
            'sku' => 'M700036',
            'name' => 'Marynarka TIFFANY Off White',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => ['source' => 'woocommerce_import'],
                'woocommerce_translations' => [
                    'en' => ['product_id' => '700037'],
                ],
            ],
        ]);
        $englishTranslation = Product::query()->create([
            'sku' => 'WC-B2C-PARENT-700037',
            'name' => 'TIFFANY Off White Blazer',
            'ean' => $ean,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'is_translation' => true,
            'attributes' => [
                'master' => [
                    'source' => 'woocommerce_import',
                    'ean' => $ean,
                ],
            ],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $englishTranslation->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '700037',
            'external_variation_id' => null,
            'external_sku' => 'M700036',
            'stock_sync_enabled' => true,
        ]);

        Http::fake(function ($request) use ($ean) {
            $url = $request->url();

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (str_contains($url, '/products')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ((int) ($query['page'] ?? 1) > 1) {
                    return Http::response([]);
                }

                return Http::response([[
                    'id' => ($query['lang'] ?? 'pl') === 'en' ? 700037 : 700036,
                    'sku' => 'M700036',
                    'name' => ($query['lang'] ?? 'pl') === 'en'
                        ? 'TIFFANY Off White Blazer'
                        : 'Marynarka TIFFANY Off White',
                    'type' => 'simple',
                    'status' => 'publish',
                    'global_unique_id' => $ean,
                ]]);
            }

            return Http::response([], 404);
        });

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $polishProduct->refresh();
        $englishTranslation->refresh();

        $this->assertSame(1, $stats['translation_eans_reclaimed']);
        $this->assertSame($ean, $polishProduct->ean);
        $this->assertSame($ean, data_get($polishProduct->attributes, 'master.ean'));
        $this->assertNull($englishTranslation->ean);
        $this->assertNull(data_get($englishTranslation->attributes, 'master.ean'));
        $this->assertSame(
            'translation_ean_reassigned',
            data_get($englishTranslation->attributes, 'master.identifier_conflict.type'),
        );
        $this->assertSame(
            'assigned_to_primary_product',
            data_get($englishTranslation->attributes, 'master.identifier_conflict.resolution'),
        );
    }

    public function test_catalog_import_skips_a_routing_only_alias_without_breaking_order_line_routing(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (str_contains($url, '/products')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ((int) ($query['page'] ?? 1) > 1) {
                    return Http::response([]);
                }

                return Http::response([[
                    'id' => 999,
                    'sku' => 'SKU-CANONICAL',
                    'name' => 'Historyczny wpis Woo',
                    'type' => 'simple',
                    'status' => 'publish',
                ]]);
            }

            return Http::response([]);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C-ROUTING-ONLY',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-CANONICAL',
            'name' => 'Produkt kanoniczny',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $mapping = ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        ProductChannelAlias::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '999',
            'external_sku' => $product->sku,
            'language' => 'en',
            'metadata' => [
                'maintenance' => [
                    'woo_owned_variant_axis_repair' => [
                        'routing_only' => true,
                    ],
                ],
            ],
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $this->assertSame(1, $stats['source_items']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(1, $stats['skipped_routing_only_alias']);
        $this->assertSame('123', (string) $mapping->fresh()->external_product_id);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_channel_mappings', 1);

        $orderResolver = new \ReflectionMethod(
            WooCommerceImportService::class,
            'productForOrderLine',
        );
        $orderResolver->setAccessible(true);
        $resolved = $orderResolver->invoke(
            app(WooCommerceImportService::class),
            $integration,
            ['product_id' => 999, 'variation_id' => 0],
            '',
        );
        $this->assertSame($product->id, $resolved?->id);
    }

    /**
     * @return array{integration:WordpressIntegration,channel:SalesChannel,warehouse:Warehouse,product:Product,balance:StockBalance}
     */
    private function stockImportSkipScenario(float $stockBuffer = 0, bool $secondPushRoute = false): array
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
                        'sku' => 'SKU-STOCK-SKIP',
                        'name' => 'Towar chroniony przed importem stanu',
                        'type' => 'simple',
                        'status' => 'publish',
                        'manage_stock' => true,
                        'stock_quantity' => 2,
                        'stock_status' => 'instock',
                    ]])
                    : Http::response([]);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $warehouse->routes()->create([
            'sales_channel_id' => $channel->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => $stockBuffer,
            'priority' => 100,
        ]);

        if ($secondPushRoute) {
            $secondaryWarehouse = Warehouse::query()->create([
                'code' => 'M2',
                'name' => 'Magazyn dodatkowy',
                'type' => 'physical',
                'is_active' => true,
            ]);
            $secondaryWarehouse->routes()->create([
                'sales_channel_id' => $channel->id,
                'push_stock' => true,
                'allocation_strategy' => 'warehouse_balance',
                'stock_buffer' => 0,
                'priority' => 200,
            ]);
        }

        $product = Product::query()->create([
            'sku' => 'SKU-STOCK-SKIP',
            'name' => 'Towar chroniony przed importem stanu',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
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

        return [
            'integration' => $integration,
            'channel' => $channel,
            'warehouse' => $warehouse,
            'product' => $product,
            'balance' => $balance,
        ];
    }
}
