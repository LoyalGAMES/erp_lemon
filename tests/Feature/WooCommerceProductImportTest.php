<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\StockBalance;
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
                        'name' => '36',
                        'manage_stock' => true,
                        'stock_quantity' => 4,
                        'stock_status' => 'instock',
                        'attributes' => [
                            ['name' => 'Rozmiar', 'option' => '36'],
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

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $parent = Product::query()->where('sku', 'WC-B2C-PARENT-777')->firstOrFail();
        $product = Product::query()->where('sku', 'BLS29K1TRMI')->firstOrFail();

        $this->assertSame('Koszula VIVIEN Biala', $parent->name);
        $this->assertSame('variable', data_get($parent->attributes, 'woocommerce_type'));
        $this->assertSame('2026-07-08T14:20', data_get($parent->attributes, 'master.publication_date'));
        $this->assertSame(2, Product::query()->count());
        $this->assertSame('Koszula VIVIEN Biala - 36', $product->name);
        $this->assertSame('https://shop.test/wp-content/uploads/koszula-vivien.jpg', $product->imageUrl());
        $this->assertSame('https://shop.test/produkt/koszula-vivien-biala', $product->externalProductUrl());
        $this->assertSame('https://shop.test/wp-content/uploads/koszula-vivien.jpg', $product->attributes['woocommerce_parent_image']['src']);
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

    public function test_import_keeps_duplicate_parent_and_variation_skus_as_separate_mappings(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (str_contains($url, '/products/777/variations')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response([[
                        'id' => 888,
                        'sku' => 'DUP-SKU',
                        'name' => 'M',
                        'attributes' => [['name' => 'Rozmiar', 'option' => 'M']],
                    ]])
                    : Http::response([]);
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
        $this->assertTrue($parent->variantChildren()->whereKey($variant->id)->exists());
    }

    public function test_import_filters_polylang_twins_when_woocommerce_ignores_the_requested_language(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_contains($url, '/products/categories')) {
                return (int) ($query['page'] ?? 1) === 1
                    ? Http::response([
                        ['id' => 10, 'name' => 'Koszule', 'lang' => 'pl', 'translations' => ['pl' => 10, 'en' => 11]],
                        ['id' => 11, 'name' => 'Shirts', 'lang' => 'en', 'translations' => ['pl' => 10, 'en' => 11]],
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
                            ['name' => 'Rozmiar', 'options' => ['One size'], 'variation' => true],
                            ['name' => 'Skład', 'options' => ['Bawełna'], 'variation' => false],
                        ],
                        'meta_data' => [
                            ['key' => '_warehouse_location', 'value' => 'A-01-02'],
                            ['key' => '_lemon_product_label_text', 'value' => $language === 'en' ? 'New' : 'Nowość'],
                            ['key' => '_lemon_product_label_bg_color', 'value' => '#112233'],
                            ['key' => '_lemon_product_label_text_color', 'value' => '#ffffff'],
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

        app(WooCommerceImportService::class)->importProducts($integration);

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
        $this->assertDatabaseHas('product_parameter_definitions', ['name' => 'Rozmiar', 'is_variant' => true]);
        $this->assertDatabaseHas('product_parameter_definitions', ['name' => 'Skład', 'is_variant' => false]);
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
}
