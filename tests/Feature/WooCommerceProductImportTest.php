<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
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
        ]);

        $stats = app(WooCommerceImportService::class)->importProducts($integration);

        $parent = Product::query()->where('sku', 'DUP-SKU')->firstOrFail();
        $variant = Product::query()->where('sku', 'WC-B2C-VARIANT-888')->firstOrFail();

        $this->assertSame(1, $stats['duplicate_sku_items']);
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
        $this->assertSame('A-01-02', data_get($product->attributes, 'master.stock.location'));
        $this->assertNull(data_get($product->attributes, 'master.stock.quantity'));
        $this->assertEquals(299.0, data_get($product->attributes, 'master.prices.sale_price_pln'));
        $this->assertSame('Rozmiar', data_get($product->attributes, 'master.parameters.0.name'));
        $this->assertTrue(data_get($product->attributes, 'master.parameters.0.variation'));
        $this->assertSame([111], data_get($product->attributes, 'master.related_products.upsell_ids'));
        $this->assertSame('https://shop.test/aura.jpg', $product->imageUrl());
        $this->assertSame('5901234123457', data_get($product->attributes, 'woocommerce_global_unique_id'));
        $this->assertSame('<p>Polski opis</p>', data_get($product->attributes, 'woocommerce_description'));
    }
}
