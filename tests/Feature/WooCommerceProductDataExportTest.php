<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\ProductDataExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WooCommerceProductDataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_erp_product_master_data_can_be_exported_to_mapped_woocommerce_product(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response([
                'id' => 123,
                'sku' => 'SKU-AURA',
                'name' => 'Koszula AURA Czarno-ecru',
                'regular_price' => '369.00',
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
            'stock_export_enabled' => true,
        ]);

        ProductCategory::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '44',
            'name' => 'Koszule',
            'slug' => 'koszule',
            'path' => 'Koszule',
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-AURA',
            'ean' => '5901234567890',
            'name' => 'Koszula AURA Czarno-ecru',
            'unit' => 'szt',
            'vat_rate' => 23,
            'weight_kg' => 0.4,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'catalog' => 'Domyślny',
                    'category' => 'Koszule',
                    'producer' => 'SEMPRE',
                    'catalog_visibility' => 'catalog',
                    'product_type' => 'variable',
                    'variant_attribute' => 'Rozmiar',
                    'tags' => ['koszula', 'aura'],
                    'asin' => 'ASIN-1',
                    'developed' => true,
                    'dimensions' => [
                        'height_cm' => 2,
                        'width_cm' => 30,
                        'length_cm' => 40,
                    ],
                    'prices' => [
                        'retail_price_pln' => 369.00,
                        'sale_price_pln' => 299.00,
                        'sale_price_starts_at' => '2026-06-01',
                        'sale_price_ends_at' => '2026-06-30',
                    ],
                    'stock' => [
                        'location' => 'A-01-03',
                    ],
                    'content' => [
                        'pl' => [
                            'name' => 'Koszula AURA Czarno-ecru',
                            'description' => '<p>Stylowa koszula</p>',
                            'additional_description' => '<p>Tabela rozmiarów</p>',
                        ],
                        'en' => [
                            'name' => 'AURA shirt black ecru',
                            'description' => '<p>Stylish shirt</p>',
                            'additional_description' => '<p>Size table</p>',
                        ],
                    ],
                    'related_products' => [
                        'upsell_skus' => ['SKU-UPSELL'],
                        'cross_sell_skus' => ['SKU-CROSS'],
                    ],
                    'parameters' => [
                        ['name' => 'Rozmiar', 'value' => 'One size', 'variation' => true],
                        ['name' => 'Skład', 'value' => '60% Bawełna, 40% Poliester'],
                    ],
                    'media' => [
                        [
                            'src' => '/uploads/products/1/aura.jpg',
                            'alt' => 'Koszula AURA',
                            'name' => 'aura.jpg',
                        ],
                    ],
                ],
            ],
        ]);

        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'OLD-SKU',
            'stock_sync_enabled' => true,
        ]);

        $upsell = Product::query()->create([
            'sku' => 'SKU-UPSELL',
            'name' => 'Produkt upsell',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $crossSell = Product::query()->create([
            'sku' => 'SKU-CROSS',
            'name' => 'Produkt cross-sell',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        ProductChannelMapping::query()->create([
            'product_id' => $upsell->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '777',
            'external_sku' => 'SKU-UPSELL',
            'stock_sync_enabled' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $crossSell->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '778',
            'external_sku' => 'SKU-CROSS',
            'stock_sync_enabled' => true,
        ]);

        $this->post(route('products.woocommerce.export', $product))
            ->assertRedirect()
            ->assertSessionHas('status', 'Dane produktu wysłane do WooCommerce: 1 kanałów.');

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123');

        [$request] = Http::recorded()->first();

        $this->assertSame('Koszula AURA Czarno-ecru', $request['name']);
        $this->assertSame('SKU-AURA', $request['sku']);
        $this->assertSame('369.00', $request['regular_price']);
        $this->assertSame('299.00', $request['sale_price']);
        $this->assertSame('2026-06-01', $request['date_on_sale_from']);
        $this->assertSame('2026-06-30', $request['date_on_sale_to']);
        $this->assertSame('<p>Stylowa koszula</p>', $request['description']);
        $this->assertSame('<p>Tabela rozmiarów</p>', $request['short_description']);
        $this->assertSame('catalog', $request['catalog_visibility']);
        $this->assertSame(44, $request['categories'][0]['id']);
        $this->assertSame('0.4000', $request['weight']);
        $this->assertSame('2.00', $request['dimensions']['height']);
        $this->assertSame('30.00', $request['dimensions']['width']);
        $this->assertSame('40.00', $request['dimensions']['length']);
        $this->assertStringEndsWith('/uploads/products/1/aura.jpg', $request['images'][0]['src']);
        $this->assertSame('Koszula AURA', $request['images'][0]['alt']);
        $this->assertSame('Rozmiar', $request['attributes'][0]['name']);
        $this->assertSame('One size', $request['attributes'][0]['options'][0]);
        $this->assertTrue($request['attributes'][0]['variation']);
        $this->assertSame([777], $request['upsell_ids']);
        $this->assertSame([778], $request['cross_sell_ids']);
        $this->assertTrue(collect($request['meta_data'])->contains(fn (array $meta): bool => $meta['key'] === '_sempre_erp_category' && $meta['value'] === 'Koszule'));
        $this->assertTrue(collect($request['meta_data'])->contains(fn (array $meta): bool => $meta['key'] === '_sempre_erp_ean' && $meta['value'] === '5901234567890'));
        $this->assertTrue(collect($request['meta_data'])->contains(fn (array $meta): bool => $meta['key'] === '_sempre_erp_name_en' && $meta['value'] === 'AURA shirt black ecru'));
        $this->assertTrue(collect($request['meta_data'])->contains(fn (array $meta): bool => $meta['key'] === '_sempre_erp_upsell_skus' && $meta['value'] === 'SKU-UPSELL'));

        $mapping = ProductChannelMapping::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('SKU-AURA', $mapping->external_sku);
        $this->assertSame('success', data_get($mapping->metadata, 'last_product_export_status'));
        $this->assertNotNull(data_get($mapping->metadata, 'last_product_export_at'));

        $this->assertSame(1, IntegrationSyncLog::query()->where('operation', 'export_product_data')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'product.woocommerce_exported')->count());
    }

    public function test_product_export_requires_channel_mapping(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-NO-MAP',
            'name' => 'Produkt bez mapowania',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->post(route('products.woocommerce.export', $product))
            ->assertRedirect()
            ->assertSessionHas('error', 'Produkt nie ma mapowania do żadnego kanału WooCommerce.');

        $this->assertSame(1, AuditLog::query()->where('action', 'product.woocommerce_export_failed')->count());
    }

    public function test_export_preserves_remote_sku_when_it_is_known_to_be_duplicate(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response([
                'id' => 123,
                'sku' => 'DUP-SKU',
                'name' => 'Produkt główny',
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
        ]);
        $parent = Product::query()->create([
            'sku' => 'WC-B2C-PARENT-123',
            'name' => 'Produkt główny',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => ['source' => 'erp']],
        ]);
        $variant = Product::query()->create([
            'sku' => 'DUP-SKU',
            'name' => 'Wariant',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'DUP-SKU',
            'stock_sync_enabled' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_variation_id' => '456',
            'external_sku' => 'DUP-SKU',
            'stock_sync_enabled' => true,
        ]);

        app(ProductDataExportService::class)->export($parent);

        [$request] = Http::recorded()->first();
        $this->assertArrayNotHasKey('sku', $request->data());
        $this->assertSame('preserved_remote_duplicate', data_get(
            ProductChannelMapping::query()->where('product_id', $parent->id)->firstOrFail()->metadata,
            'last_product_export_sku_status',
        ));
    }

    public function test_export_sends_sku_shared_with_polylang_translation(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response([
                'id' => 123,
                'sku' => 'POLYLANG-SKU',
                'name' => 'Polish product',
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
        ]);
        $product = Product::query()->create([
            'sku' => 'POLYLANG-SKU',
            'name' => 'Polski produkt',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => ['source' => 'erp'],
                'woocommerce_translations' => [
                    'en' => [
                        'product_id' => '124',
                        'variation_id' => null,
                        'sku' => 'POLYLANG-SKU',
                    ],
                ],
            ],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'POLYLANG-SKU',
            'stock_sync_enabled' => true,
        ]);

        app(ProductDataExportService::class)->export($product);

        [$request] = Http::recorded()->first();
        $this->assertSame('POLYLANG-SKU', $request['sku']);
    }

    public function test_export_sends_sku_shared_with_variation_of_same_woocommerce_parent(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response([
                'id' => 123,
                'sku' => 'FAMILY-SKU',
                'name' => 'Produkt główny',
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
        ]);
        $parent = Product::query()->create([
            'sku' => 'FAMILY-SKU',
            'name' => 'Produkt główny',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => ['source' => 'erp']],
        ]);
        $variant = Product::query()->create([
            'sku' => 'VARIANT-ERP-SKU',
            'name' => 'Wariant',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'FAMILY-SKU',
            'stock_sync_enabled' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_variation_id' => '456',
            'external_sku' => 'FAMILY-SKU',
            'stock_sync_enabled' => true,
        ]);

        app(ProductDataExportService::class)->export($parent);

        [$request] = Http::recorded()->first();
        $this->assertSame('FAMILY-SKU', $request['sku']);
    }

    public function test_product_publication_date_exports_to_woocommerce_and_polylang_translations(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if ($request->method() === 'PUT' && $url === 'https://shop.test/wp-json/wc/v3/products/123') {
                return Http::response([
                    'id' => 123,
                    'sku' => 'SKU-DATE',
                    'name' => 'Produkt z datą',
                ]);
            }

            if ($request->method() === 'GET' && str_starts_with($url, 'https://shop.test/wp-json/wc/v3/products')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return match ($query['lang'] ?? null) {
                    'pl' => Http::response([
                        ['id' => 123, 'sku' => 'SKU-DATE'],
                    ]),
                    'en' => Http::response([
                        ['id' => 124, 'sku' => 'SKU-DATE'],
                    ]),
                    default => Http::response([]),
                };
            }

            if ($request->method() === 'PUT' && $url === 'https://shop.test/wp-json/wc/v3/products/124') {
                return Http::response([
                    'id' => 124,
                    'sku' => 'SKU-DATE',
                    'date_created' => $request['date_created'],
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

        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
            'settings' => [
                'product_import' => [
                    'languages' => ['pl', 'en'],
                ],
            ],
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-DATE',
            'name' => 'Produkt z datą',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'publication_status' => 'publish',
                    'publication_date' => '2026-07-15T09:30',
                    'content' => [
                        'pl' => [
                            'name' => 'Produkt z datą',
                        ],
                    ],
                ],
            ],
        ]);

        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'SKU-DATE',
            'stock_sync_enabled' => true,
        ]);

        $this->post(route('products.woocommerce.export', $product))
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123'
            && $request['date_created'] === '2026-07-15T09:30:00');

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'lang=pl'));
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'lang=en'));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124'
            && $request['date_created'] === '2026-07-15T09:30:00');
    }

    public function test_erp_product_can_be_created_in_unmapped_woocommerce_channel(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products' => Http::response([
                'id' => 555,
                'sku' => 'SKU-CREATE',
                'name' => 'Komplet ERP',
                'regular_price' => '499.00',
                'permalink' => 'https://shop.test/produkt/komplet-erp',
            ], 201),
        ]);

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'B2C Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-CREATE',
            'ean' => '5900000000001',
            'name' => 'Komplet ERP',
            'unit' => 'szt',
            'vat_rate' => 23,
            'weight_kg' => 0.7,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'category' => 'Komplety',
                    'producer' => 'SEMPRE',
                    'prices' => [
                        'retail_price_pln' => 499.00,
                    ],
                    'content' => [
                        'pl' => [
                            'name' => 'Komplet ERP',
                            'description' => '<p>Opis kompletu</p>',
                            'additional_description' => '<p>Krótki opis</p>',
                        ],
                    ],
                    'parameters' => [
                        ['name' => 'Kolor', 'value' => 'Czarny'],
                    ],
                    'media' => [
                        [
                            'src' => '/uploads/products/10/komplet.jpg',
                            'alt' => 'Komplet ERP',
                            'name' => 'komplet.jpg',
                        ],
                    ],
                ],
            ],
        ]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Utwórz produkt w kanale WooCommerce')
            ->assertSee('B2C - Sklep B2C')
            ->assertSee('Wyślij do sklepu');

        $this->post(route('products.woocommerce.create', [$product, $integration]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Produkt utworzony w WooCommerce dla kanału B2C.');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products');

        [$request] = Http::recorded()->first();
        $this->assertSame('Komplet ERP', $request['name']);
        $this->assertSame('SKU-CREATE', $request['sku']);
        $this->assertSame('499.00', $request['regular_price']);
        $this->assertSame('<p>Opis kompletu</p>', $request['description']);
        $this->assertSame('<p>Krótki opis</p>', $request['short_description']);
        $this->assertStringEndsWith('/uploads/products/10/komplet.jpg', $request['images'][0]['src']);
        $this->assertSame('Kolor', $request['attributes'][0]['name']);

        $mapping = ProductChannelMapping::query()->firstOrFail();
        $this->assertSame($product->id, $mapping->product_id);
        $this->assertSame($channel->id, $mapping->sales_channel_id);
        $this->assertSame('555', $mapping->external_product_id);
        $this->assertNull($mapping->external_variation_id);
        $this->assertSame('SKU-CREATE', $mapping->external_sku);
        $this->assertTrue($mapping->stock_sync_enabled);
        $this->assertSame('erp_product_create', data_get($mapping->metadata, 'created_via'));
        $this->assertSame('success', data_get($mapping->metadata, 'last_product_export_status'));
        $this->assertSame('https://shop.test/produkt/komplet-erp', data_get($mapping->metadata, 'woocommerce_permalink'));

        $this->assertSame(1, IntegrationSyncLog::query()->where('operation', 'create_product')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'product.woocommerce_created')->count());
    }

    public function test_new_bilingual_product_creates_and_links_polylang_translation(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc/v3/products') {
                return Http::response(['id' => 555, 'sku' => 'SKU-BILINGUAL'], 201);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc/v3/products?lang=en') {
                return Http::response(['id' => 556, 'sku' => ''], 201);
            }

            if ($request->method() === 'PUT' && $request->url() === 'https://shop.test/wp-json/wc/v3/products/556') {
                return Http::response(['id' => 556, 'sku' => $request['sku']]);
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
        $product = Product::query()->create([
            'sku' => 'SKU-BILINGUAL',
            'name' => 'Produkt polski',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'content' => [
                    'pl' => ['name' => 'Produkt polski'],
                    'en' => ['name' => 'English product'],
                ],
                'prices' => ['retail_price_pln' => 129.99],
            ]],
        ]);

        $result = app(ProductDataExportService::class)->create($product, $integration);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products?lang=en'
            && $request['name'] === 'English product'
            && ! isset($request['sku'])
            && $request['translations'] === ['pl' => 555]);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/556'
            && $request['sku'] === 'SKU-BILINGUAL');
        $this->assertSame('556', data_get($product->fresh()->attributes, 'woocommerce_translations.en.product_id'));
        $this->assertSame('SKU-BILINGUAL', data_get($product->fresh()->attributes, 'woocommerce_translations.en.sku'));
        $this->assertCount(1, $result['translation_responses']);
    }

    public function test_erp_variable_product_creates_parent_and_variants_in_woocommerce(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc/v3/products') {
                return Http::response([
                    'id' => 700,
                    'sku' => 'SET-AMORA',
                    'name' => 'Komplet AMORA',
                    'permalink' => 'https://shop.test/produkt/komplet-amora',
                ], 201);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc/v3/products/700/variations') {
                return Http::response([
                    'id' => $request['sku'] === 'SET-AMORA-S' ? 701 : 702,
                    'sku' => $request['sku'],
                    'regular_price' => $request['regular_price'],
                ], 201);
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
            'name' => 'B2C Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
        ]);

        $parent = Product::query()->create([
            'sku' => 'SET-AMORA',
            'name' => 'Komplet AMORA',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variable',
                    'variant_attribute' => 'Rozmiar',
                    'prices' => ['retail_price_pln' => 819.00],
                    'content' => [
                        'pl' => [
                            'name' => 'Komplet AMORA',
                            'description' => '<p>Opis</p>',
                        ],
                    ],
                    'parameters' => [
                        ['name' => 'Kolor', 'value' => 'Kremowy'],
                    ],
                ],
            ],
        ]);
        $variantS = $this->createVariantProduct('SET-AMORA-S', 'S', 819.00);
        $variantM = $this->createVariantProduct('SET-AMORA-M', 'M', 829.00);

        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variantS->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variantM->id,
            'relation_type' => 'variant',
            'sort_order' => 20,
        ]);

        $this->post(route('products.woocommerce.create', [$parent, $integration]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Produkt utworzony w WooCommerce dla kanału B2C razem z 2 wariantami.');

        $requests = Http::recorded()->map(fn (array $record) => $record[0])->values();
        $parentRequest = $requests->first(fn ($request) => $request->url() === 'https://shop.test/wp-json/wc/v3/products');
        $variationRequests = $requests->filter(fn ($request) => $request->url() === 'https://shop.test/wp-json/wc/v3/products/700/variations')->values();

        $this->assertSame('variable', $parentRequest['type']);
        $this->assertSame('Rozmiar', $parentRequest['attributes'][1]['name']);
        $this->assertSame(['S', 'M'], $parentRequest['attributes'][1]['options']);
        $this->assertTrue($parentRequest['attributes'][1]['variation']);
        $this->assertSame(2, $variationRequests->count());
        $this->assertSame('SET-AMORA-S', $variationRequests[0]['sku']);
        $this->assertSame('S', $variationRequests[0]['attributes'][0]['option']);
        $this->assertSame('819.00', $variationRequests[0]['regular_price']);
        $this->assertSame('SET-AMORA-M', $variationRequests[1]['sku']);
        $this->assertSame('M', $variationRequests[1]['attributes'][0]['option']);
        $this->assertSame('829.00', $variationRequests[1]['regular_price']);

        $this->assertSame(3, ProductChannelMapping::query()->count());
        $this->assertSame('700', ProductChannelMapping::query()->where('product_id', $parent->id)->firstOrFail()->external_product_id);
        $this->assertSame('701', ProductChannelMapping::query()->where('product_id', $variantS->id)->firstOrFail()->external_variation_id);
        $this->assertSame('702', ProductChannelMapping::query()->where('product_id', $variantM->id)->firstOrFail()->external_variation_id);
        $this->assertSame(1, IntegrationSyncLog::query()->where('operation', 'create_product')->count());
        $this->assertSame(2, IntegrationSyncLog::query()->where('operation', 'create_product_variation')->count());
    }

    public function test_export_converts_existing_mapped_product_to_variable_and_creates_missing_variants(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'PUT' && $request->url() === 'https://shop.test/wp-json/wc/v3/products/321') {
                return Http::response([
                    'id' => 321,
                    'sku' => 'SET-LUNA',
                    'name' => 'Komplet LUNA',
                    'type' => 'variable',
                ]);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc/v3/products/321/variations') {
                return Http::response([
                    'id' => 322,
                    'sku' => $request['sku'],
                    'regular_price' => $request['regular_price'],
                ], 201);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'B2C Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
        ]);

        $parent = Product::query()->create([
            'sku' => 'SET-LUNA',
            'name' => 'Komplet LUNA',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variable',
                    'variant_attribute' => 'Rozmiar',
                    'prices' => [
                        'retail_price_pln' => 799.00,
                        'sale_price_pln' => 699.00,
                        'sale_price_starts_at' => '2026-08-01',
                        'sale_price_ends_at' => '2026-08-10',
                    ],
                    'content' => [
                        'pl' => [
                            'name' => 'Komplet LUNA',
                            'description' => '<p>Opis</p>',
                        ],
                    ],
                    'parameters' => [
                        ['name' => 'Kolor', 'value' => 'Czarny'],
                    ],
                ],
            ],
        ]);
        $variant = $this->createVariantProduct('SET-LUNA-S', 'S', 799.00);

        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '321',
            'external_sku' => 'SET-LUNA',
            'stock_sync_enabled' => true,
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);

        $this->post(route('products.woocommerce.export', $parent))
            ->assertRedirect()
            ->assertSessionHas('status');

        $requests = Http::recorded()->map(fn (array $record) => $record[0])->values();
        $parentRequest = $requests->first(fn ($request) => $request->url() === 'https://shop.test/wp-json/wc/v3/products/321');
        $variationRequest = $requests->first(fn ($request) => $request->url() === 'https://shop.test/wp-json/wc/v3/products/321/variations');

        $this->assertSame('variable', $parentRequest['type']);
        $this->assertSame('Rozmiar', $parentRequest['attributes'][1]['name']);
        $this->assertSame(['S'], $parentRequest['attributes'][1]['options']);
        $this->assertTrue($parentRequest['attributes'][1]['variation']);
        $this->assertSame('SET-LUNA-S', $variationRequest['sku']);
        $this->assertSame('S', $variationRequest['attributes'][0]['option']);
        $this->assertSame('799.00', $variationRequest['regular_price']);
        $this->assertSame('699.00', $variationRequest['sale_price']);
        $this->assertSame('2026-08-01', $variationRequest['date_on_sale_from']);
        $this->assertSame('2026-08-10', $variationRequest['date_on_sale_to']);

        $variantMapping = ProductChannelMapping::query()->where('product_id', $variant->id)->firstOrFail();
        $this->assertSame('321', $variantMapping->external_product_id);
        $this->assertSame('322', $variantMapping->external_variation_id);
        $this->assertSame(1, IntegrationSyncLog::query()->where('operation', 'export_product_data')->count());
        $this->assertSame(1, IntegrationSyncLog::query()->where('operation', 'create_product_variation')->count());
    }

    public function test_product_create_is_blocked_when_channel_mapping_already_exists(): void
    {
        Http::fake();

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'B2C Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-MAPPED',
            'name' => 'Produkt już w Woo',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'SKU-MAPPED',
            'stock_sync_enabled' => true,
        ]);

        $this->post(route('products.woocommerce.create', [$product, $integration]))
            ->assertRedirect()
            ->assertSessionHas('error', 'Produkt ma już mapowanie do kanału B2C.');

        Http::assertNothingSent();
        $this->assertSame(1, ProductChannelMapping::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'product.woocommerce_create_failed')->count());
    }

    public function test_export_creates_selected_erp_category_for_both_languages_and_maps_it_to_product(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/products/categories')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(['id' => ($query['lang'] ?? 'pl') === 'en' ? 60 : 50]);
            }

            if ($request->method() === 'PUT' && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123') {
                return Http::response(['id' => 123, 'sku' => 'SKU-CATEGORY']);
            }

            return Http::response([], 404);
        });
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
            'settings' => ['product_import' => ['languages' => ['pl', 'en']]],
        ]);
        $category = ProductCategory::query()->create([
            'external_id' => 'ERP-KOSZULE',
            'name' => 'Koszule',
            'slug' => 'koszule',
            'path' => 'Odzież > Koszule',
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-CATEGORY',
            'name' => 'Produkt z kategorią',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'category_ids' => [$category->id],
                'content' => ['pl' => ['name' => 'Produkt z kategorią']],
            ]],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'SKU-CATEGORY',
            'stock_sync_enabled' => true,
        ]);

        app(ProductDataExportService::class)->export($product);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/categories?lang=pl')
            && $request['name'] === 'Koszule');
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/categories?lang=en')
            && $request['name'] === 'Koszule');
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123'
            && $request['categories'] === [['id' => 50]]);

        $category->refresh();
        $this->assertSame((string) $channel->id, (string) $category->sales_channel_id);
        $this->assertSame('50', data_get($category->metadata, 'woocommerce_ids.pl'));
        $this->assertSame('60', data_get($category->metadata, 'woocommerce_ids.en'));
    }

    public function test_export_updates_complete_polish_and_english_product_data_including_theme_label(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response(['id' => 123, 'sku' => 'SKU-FULL']),
            'https://shop.test/wp-json/wc/v3/products/124' => Http::response(['id' => 124, 'sku' => 'SKU-FULL']),
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
            'settings' => ['product_import' => ['languages' => ['pl', 'en']]],
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-FULL',
            'ean' => '5901234567890',
            'name' => 'Produkt PL',
            'unit' => 'szt',
            'vat_rate' => 23,
            'weight_kg' => 0.5,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'simple',
                    'publication_status' => 'publish',
                    'publication_date' => '2026-07-15T09:30',
                    'catalog_visibility' => 'catalog',
                    'prices' => ['retail_price_pln' => 199.99, 'sale_price_pln' => 149.99],
                    'inventory' => [
                        'manage_stock' => true,
                        'backorders' => 'notify',
                        'low_stock_amount' => 3,
                        'sold_individually' => true,
                    ],
                    'custom_label' => [
                        'pl' => 'Nowość',
                        'en' => 'New',
                        'bg_color' => '#112233',
                        'text_color' => '#ffffff',
                    ],
                    'content' => [
                        'pl' => ['name' => 'Produkt PL', 'description' => '<p>Opis PL</p>', 'additional_description' => 'Krótki PL'],
                        'en' => ['name' => 'Product EN', 'description' => '<p>Description EN</p>', 'additional_description' => 'Short EN'],
                    ],
                ],
                'woocommerce_translations' => [
                    'en' => ['product_id' => '124', 'variation_id' => null, 'sku' => 'SKU-FULL'],
                ],
            ],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'SKU-FULL',
            'stock_sync_enabled' => true,
        ]);

        app(ProductDataExportService::class)->export($product);

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PUT' || $request->url() !== 'https://shop.test/wp-json/wc/v3/products/123') {
                return false;
            }

            $meta = collect($request['meta_data'])->pluck('value', 'key');

            return $request['name'] === 'Produkt PL'
                && $request['regular_price'] === '199.99'
                && $request['sale_price'] === '149.99'
                && $request['global_unique_id'] === '5901234567890'
                && $request['backorders'] === 'notify'
                && $request['low_stock_amount'] === 3
                && $request['sold_individually'] === true
                && $meta['_lemon_product_label_text'] === 'Nowość'
                && $meta['_lemon_product_label_bg_color'] === '#112233';
        });
        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PUT' || $request->url() !== 'https://shop.test/wp-json/wc/v3/products/124') {
                return false;
            }

            $meta = collect($request['meta_data'])->pluck('value', 'key');

            return $request['name'] === 'Product EN'
                && $request['description'] === '<p>Description EN</p>'
                && $request['short_description'] === 'Short EN'
                && $meta['_lemon_product_label_text'] === 'New';
        });
    }

    private function createVariantProduct(string $sku, string $size, float $price): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Komplet AMORA '.$size,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variation',
                    'prices' => ['retail_price_pln' => $price],
                    'content' => [
                        'pl' => ['name' => 'Komplet AMORA '.$size],
                    ],
                    'parameters' => [
                        ['name' => 'Rozmiar', 'value' => $size, 'variation' => true],
                    ],
                ],
            ],
        ]);
    }
}
