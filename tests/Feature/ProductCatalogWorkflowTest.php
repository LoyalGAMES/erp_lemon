<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Models\AuditLog;
use App\Models\WordpressIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductCatalogWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_catalog_shows_image_clean_stock_and_details_page(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        ProductCategory::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '15',
            'name' => 'Koszule',
            'slug' => 'koszule',
            'path' => 'Odzież > Koszule',
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
            'type' => 'physical',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sklep B2C',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => 'encrypted-key',
            'consumer_secret_encrypted' => 'encrypted-secret',
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
            'invoice_upload_enabled' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-PHOTO',
            'ean' => '5900000000011',
            'name' => 'Koszula VIVIEN Biała - 36',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'woocommerce_type' => 'variation',
                'woocommerce_status' => 'publish',
                'woocommerce_stock_status' => 'instock',
                'woocommerce_manage_stock' => true,
                'woocommerce_permalink' => 'https://shop.test/produkt/koszula-vivien-biala',
                'woocommerce_image' => [
                    'src' => 'https://cdn.test/koszula.jpg',
                    'alt' => 'Koszula VIVIEN Biała',
                ],
                'woocommerce_variation_attributes' => [
                    ['name' => 'Rozmiar', 'option' => '36'],
                ],
            ],
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 5,
            'quantity_reserved' => 1,
            'quantity_available' => 4,
        ]);

        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '777',
            'external_variation_id' => '888',
            'external_sku' => 'SKU-PHOTO',
            'stock_sync_enabled' => true,
        ]);

        $document = WarehouseDocument::query()->create([
            'number' => 'PZ/2026/000123',
            'type' => 'PZ',
            'status' => 'posted',
            'destination_warehouse_id' => $warehouse->id,
            'document_date' => now(),
            'posted_at' => now(),
        ]);

        $line = $document->lines()->create([
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        StockLedgerEntry::query()->create([
            'warehouse_document_id' => $document->id,
            'warehouse_document_line_id' => $line->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_change' => 5,
            'direction' => 'in',
            'posted_at' => now(),
        ]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Koszula VIVIEN Biała - 36')
            ->assertSee('data-create-tab="produkt"', false)
            ->assertSee('data-create-step="media"', false)
            ->assertSee('Dodaj zdjęcia z komputera')
            ->assertSee('https://cdn.test/koszula.jpg')
            ->assertSee('/products/image-thumbnail?src=', false)
            ->assertDontSee('src="https://cdn.test/koszula.jpg"', false)
            ->assertSee('Szczegóły')
            ->assertSee('Edytuj')
            ->assertSee('Ogółem')
            ->assertSee('Dostępne')
            ->assertDontSee('5,0000')
            ->assertDontSee('4,0000');

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('data-product-view-tab="produkt"', false)
            ->assertSee('data-product-view-panel="sprzedaz"', false)
            ->assertSee('Produkt')
            ->assertSee('Sprzedaż i magazyn')
            ->assertSee('Informacje')
            ->assertSee('Media')
            ->assertSee('Warianty')
            ->assertSee('https://cdn.test/koszula.jpg')
            ->assertSee('Stan ogólny')
            ->assertSee('Relacje i kanały')
            ->assertSee('Ostatnie ruchy magazynowe')
            ->assertSee('PZ/2026/000123')
            ->assertSee('Otwórz w sklepie')
            ->assertDontSee('5,0000');
    }

    public function test_product_catalog_has_search_filters_and_custom_pagination(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
            'type' => 'physical',
            'is_active' => true,
        ]);

        $matching = Product::query()->create([
            'sku' => 'SKU-FIND-001',
            'name' => 'Sukienka PARIS Różowa',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'category' => 'Sukienki',
                    'content' => [
                        'pl' => [
                            'description' => '<p>Opis po polsku do szybkiego wyszukania</p>',
                        ],
                    ],
                ],
                'woocommerce_status' => 'publish',
            ],
        ]);

        ProductChannelMapping::query()->create([
            'product_id' => $matching->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '501',
            'stock_sync_enabled' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $matching->id,
            'quantity_on_hand' => 4,
            'quantity_reserved' => 1,
            'quantity_available' => 3,
        ]);

        Product::query()->create([
            'sku' => 'SKU-OTHER-001',
            'name' => 'Buty zimowe',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        for ($index = 1; $index <= 32; $index++) {
            Product::query()->create([
                'sku' => sprintf('SKU-PAGE-%03d', $index),
                'name' => sprintf('Produkt paginacji %03d', $index),
                'unit' => 'szt',
                'vat_rate' => 23,
                'quantity_precision' => 0,
                'is_active' => true,
            ]);
        }

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('data-product-filters', false)
            ->assertSee('data-product-search', false)
            ->assertSee('pagination-bar', false)
            ->assertSee('Szybkie wyszukiwanie')
            ->assertSee('Magazyn')
            ->assertSee('Filtruj');

        $this->get(route('products.index', ['q' => 'Opis po polsku']))
            ->assertOk()
            ->assertSee('Sukienka PARIS Różowa')
            ->assertDontSee('Buty zimowe');

        $this->get(route('products.index', [
            'channel' => 'B2C',
            'warehouse' => $warehouse->id,
            'stock' => 'available',
            'category' => 'Sukienki',
            'status' => 'publish',
        ]))
            ->assertOk()
            ->assertSee('Sukienka PARIS Różowa')
            ->assertDontSee('Buty zimowe');
    }

    public function test_operator_can_create_product_with_stepper_and_server_media(): void
    {
        $response = $this->post(route('products.store'), [
            'sku' => 'SKU-NEW',
            'name' => 'Nowa koszula ERP',
            'ean' => '5901234567000',
            'unit' => 'szt',
            'vat_rate' => 23,
            'weight_kg' => '0.3000',
            'is_active' => '1',
            'catalog' => 'Domyślny',
            'category' => 'Koszule',
            'producer' => 'SEMPRE',
            'tags' => 'nowość, koszula',
            'asin' => 'ASIN-NEW',
            'height_cm' => '2',
            'width_cm' => '30',
            'length_cm' => '40',
            'developed' => '1',
            'retail_price_pln' => '299.00',
            'stock_quantity' => '8',
            'stock_threshold' => '2',
            'warehouse_location' => 'A-02-01',
            'description_pl' => '<p>Opis z ERP</p>',
            'short_description_en' => '<p>Short ERP</p>',
            'related_upsell_skus' => "SKU-UP-1\nSKU-UP-2",
            'catalog_visibility' => 'catalog',
            'product_type' => 'variable',
            'variant_attribute' => 'Rozmiar',
            'parameters' => [
                'name' => ['Rozmiar', 'Kolor'],
                'value' => ['M', 'Czarny'],
                'variation' => ['1', '0'],
            ],
            'new_media' => [
                UploadedFile::fake()->image('nowa-koszula.jpg', 600, 800),
            ],
            'new_media_alt' => 'Nowa koszula ERP',
            'suppliers' => [
                'name' => ['Dostawca ERP'],
                'product_code' => ['ERP-001'],
                'purchase_price_pln' => ['120.00'],
            ],
        ]);

        $product = Product::query()->where('sku', 'SKU-NEW')->firstOrFail();

        $response->assertRedirect(route('products.show', $product));
        $this->assertSame('Nowa koszula ERP', $product->name);
        $this->assertSame('erp', data_get($product->attributes, 'master.source'));
        $this->assertSame('Koszule', data_get($product->attributes, 'master.category'));
        $this->assertSame('M', data_get($product->attributes, 'master.parameters.0.value'));
        $this->assertTrue(data_get($product->attributes, 'master.parameters.0.variation'));
        $this->assertEquals(8.0, data_get($product->attributes, 'master.stock.quantity'));
        $this->assertSame('catalog', data_get($product->attributes, 'master.catalog_visibility'));
        $this->assertSame('variable', data_get($product->attributes, 'master.product_type'));
        $this->assertSame('Rozmiar', data_get($product->attributes, 'master.variant_attribute'));
        $this->assertSame(['SKU-UP-1', 'SKU-UP-2'], data_get($product->attributes, 'master.related_products.upsell_skus'));
        $this->assertSame('<p>Short ERP</p>', data_get($product->attributes, 'master.content.en.additional_description'));
        $this->assertEquals(round(299 / 4.55, 2), data_get($product->attributes, 'master.prices.price_eur'));
        $this->assertSame('Nowa koszula ERP', data_get($product->attributes, 'master.media.0.alt'));

        $mediaSrc = (string) data_get($product->attributes, 'master.media.0.src');
        $this->assertStringStartsWith('/uploads/testing-products/' . $product->id . '/', $mediaSrc);
        $this->assertFileExists(public_path(ltrim($mediaSrc, '/')));

        $thumbnailUrl = $product->thumbnailUrl(116, 144);
        $this->assertIsString($thumbnailUrl);
        $this->assertStringStartsWith('/uploads/testing-product-thumbnails/116x144/', $thumbnailUrl);

        $thumbnailPath = public_path(ltrim($thumbnailUrl, '/'));
        $this->assertFileExists($thumbnailPath);
        $this->assertLessThan(filesize(public_path(ltrim($mediaSrc, '/'))), filesize($thumbnailPath));
        $this->assertSame([116, 144], array_slice(getimagesize($thumbnailPath) ?: [], 0, 2));

        @unlink(public_path(ltrim($mediaSrc, '/')));
        File::deleteDirectory(public_path('uploads/testing-product-thumbnails'));
    }

    public function test_product_thumbnail_route_caches_remote_images(): void
    {
        $remoteImage = UploadedFile::fake()->image('remote-product.jpg', 600, 800);
        Http::fake([
            'cdn.test/*' => Http::response((string) file_get_contents($remoteImage->getRealPath()), 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-REMOTE',
            'name' => 'Produkt z CDN',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'woocommerce_image' => [
                    'src' => 'https://cdn.test/products/remote-product.jpg',
                    'alt' => 'Produkt z CDN',
                ],
            ],
        ]);

        $thumbnailUrl = $product->thumbnailUrl(116, 144);
        $this->assertIsString($thumbnailUrl);
        $this->assertStringStartsWith('/products/image-thumbnail?src=', $thumbnailUrl);

        $this->get($thumbnailUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        Http::assertSentCount(1);

        $this->get($thumbnailUrl)->assertOk();
        Http::assertSentCount(1);

        File::deleteDirectory(public_path('uploads/testing-product-thumbnails'));
    }

    public function test_operator_can_edit_product_master_data_in_erp(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-BASE',
            'name' => 'Stara nazwa',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('Edycja produktu')
            ->assertSee('Po zapisie ERP przejmuje produkt jako źródło prawdy')
            ->assertSee('data-product-tab="produkt"', false)
            ->assertSee('data-product-tab="sprzedaz"', false)
            ->assertSee('Status WooCommerce')
            ->assertSee('Wyszukaj kategorię z WooCommerce')
            ->assertSee('Dodaj zdjęcia z komputera');

        $this->post(route('products.update', $product), [
            '_method' => 'PUT',
            'sku' => 'SKU-BASE',
            'name' => 'Koszula AURA Czarno-ecru',
            'ean' => '5901234567890',
            'unit' => 'szt',
            'vat_rate' => 23,
            'weight_kg' => '0.4000',
            'is_active' => '1',
            'catalog' => 'Domyślny',
            'category' => 'Koszule',
            'producer' => 'SEMPRE',
            'tags' => 'koszula, aura',
            'asin' => 'ASIN-1',
            'height_cm' => '2',
            'width_cm' => '30',
            'length_cm' => '40',
            'developed' => '1',
            'publication_status' => 'publish',
            'catalog_visibility' => 'visible',
            'product_type' => 'variable',
            'variant_attribute' => 'Rozmiar',
            'wholesale_price_pln' => '149.00',
            'retail_price_pln' => '369.00',
            'price_eur' => '81.18',
            'price_gbp' => '70.11',
            'price_usd' => '88.56',
            'purchase_price_pln' => '100.00',
            'extra_cost_pln' => '5.00',
            'stock_quantity' => '5',
            'stock_threshold' => '3',
            'ordered_quantity' => '2',
            'warehouse_location' => 'A-01-03',
            'name_en' => 'AURA shirt black ecru',
            'description_pl' => '<p>Stylowa koszula</p>',
            'description_en' => '<p>Stylish shirt</p>',
            'short_description_pl' => '<table><tr><td>Rozmiar</td></tr></table>',
            'short_description_en' => '<p>Short description</p>',
            'related_upsell_skus' => 'SKU-UPSELL',
            'related_cross_sell_skus' => "SKU-CROSS-1\nSKU-CROSS-2",
            'parameters' => [
                'name' => ['Rozmiar', 'Skład', ''],
                'value' => ['One size', '60% Bawełna, 40% Poliester', ''],
                'variation' => ['1', '0', '0'],
            ],
            'new_media' => [
                UploadedFile::fake()->image('aura.jpg', 600, 800),
            ],
            'new_media_alt' => 'Koszula AURA',
            'suppliers' => [
                'name' => ['Dostawca A', ''],
                'product_code' => ['AURA-01', ''],
                'purchase_price_pln' => ['99.50', ''],
            ],
        ])->assertRedirect(route('products.show', $product));

        $product->refresh();
        $this->assertSame('Koszula AURA Czarno-ecru', $product->name);
        $this->assertSame('erp', data_get($product->attributes, 'master.source'));
        $this->assertSame('Koszule', data_get($product->attributes, 'master.category'));
        $this->assertSame(['koszula', 'aura'], data_get($product->attributes, 'master.tags'));
        $this->assertEquals(369.0, data_get($product->attributes, 'master.prices.retail_price_pln'));
        $this->assertEquals(round(369 / 4.55, 2), data_get($product->attributes, 'master.prices.price_eur'));
        $this->assertEquals(5.0, data_get($product->attributes, 'master.stock.quantity'));
        $this->assertSame('variable', data_get($product->attributes, 'master.product_type'));
        $this->assertSame('Rozmiar', data_get($product->attributes, 'master.variant_attribute'));
        $this->assertSame('SKU-UPSELL', data_get($product->attributes, 'master.related_products.upsell_skus.0'));
        $this->assertSame('SKU-CROSS-2', data_get($product->attributes, 'master.related_products.cross_sell_skus.1'));
        $this->assertSame('One size', data_get($product->attributes, 'master.parameters.0.value'));
        $this->assertTrue(data_get($product->attributes, 'master.parameters.0.variation'));
        $this->assertSame('<p>Short description</p>', data_get($product->attributes, 'master.content.en.additional_description'));
        $mediaSrc = (string) data_get($product->attributes, 'master.media.0.src');
        $this->assertStringStartsWith('/uploads/testing-products/' . $product->id . '/', $mediaSrc);
        $this->assertSame('Koszula AURA', data_get($product->attributes, 'master.media.0.alt'));
        $this->assertFileExists(public_path(ltrim($mediaSrc, '/')));
        $this->assertSame('Dostawca A', data_get($product->attributes, 'master.suppliers.0.name'));
        $this->assertSame(1, AuditLog::query()->where('action', 'product.master_data_updated')->count());

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Źródło danych głównych: ERP')
            ->assertSee('Koszula AURA Czarno-ecru')
            ->assertSee('Koszule')
            ->assertSee('Cena detal')
            ->assertSee('369,00 PLN')
            ->assertSee('Dostępne do sprzedaży')
            ->assertSee('Opis PL HTML')
            ->assertSee('Krótki opis EN HTML')
            ->assertSee('Sprzedaż dodatkowa SKU')
            ->assertSee('Stylowa koszula')
            ->assertSee('Rozmiar')
            ->assertSee($mediaSrc);

        @unlink(public_path(ltrim($mediaSrc, '/')));
    }

    public function test_operator_can_duplicate_product_without_stock_or_channel_mappings(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
            'type' => 'physical',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sklep B2C',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => 'encrypted-key',
            'consumer_secret_encrypted' => 'encrypted-secret',
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
            'invoice_upload_enabled' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-COPY',
            'name' => 'Koszula do kopiowania',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'woocommerce_type' => 'simple',
                'woocommerce_product_id' => '100',
                'woocommerce_permalink' => 'https://shop.test/produkt/stary-produkt',
                'woocommerce_status' => 'publish',
                'master' => [
                    'source' => 'erp',
                    'content' => [
                        'pl' => [
                            'name' => 'Koszula do kopiowania',
                            'description' => '<p>Opis</p>',
                        ],
                    ],
                    'media' => [
                        ['src' => '/uploads/products/1/a.jpg', 'alt' => 'Alt', 'name' => 'a.jpg'],
                    ],
                ],
            ],
        ]);

        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '100',
            'external_sku' => 'SKU-COPY',
            'stock_sync_enabled' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 3,
            'quantity_reserved' => 0,
            'quantity_available' => 3,
        ]);

        $this->post(route('products.duplicate', $product))
            ->assertRedirect();

        $copy = Product::query()->where('sku', 'SKU-COPY-COPY')->firstOrFail();

        $this->assertSame('Koszula do kopiowania (kopia)', $copy->name);
        $this->assertFalse($copy->is_active);
        $this->assertSame('Koszula do kopiowania (kopia)', data_get($copy->attributes, 'master.content.pl.name'));
        $this->assertSame($product->id, data_get($copy->attributes, 'master.copy.created_from_product_id'));
        $this->assertSame('/uploads/products/1/a.jpg', data_get($copy->attributes, 'master.media.0.src'));
        $this->assertNull(data_get($copy->attributes, 'woocommerce_product_id'));
        $this->assertNull(data_get($copy->attributes, 'woocommerce_permalink'));
        $this->assertNull($copy->externalDisplayId());
        $this->assertNull($copy->externalProductUrl());
        $this->assertSame(0, ProductChannelMapping::query()->where('product_id', $copy->id)->count());
        $this->assertSame(0, StockBalance::query()->where('product_id', $copy->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'product.duplicated')->count());

        $this->get(route('products.show', $copy))
            ->assertOk()
            ->assertSee('Ten produkt istnieje tylko w ERP')
            ->assertSee('Wyślij do sklepu')
            ->assertDontSee('Otwórz w sklepie');
    }

    public function test_operator_can_attach_and_remove_variant_relation(): void
    {
        $parent = Product::query()->create([
            'sku' => 'SKU-PARENT',
            'name' => 'Komplet wariantowy',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'content' => ['pl' => ['name' => 'Komplet wariantowy']],
                ],
            ],
        ]);
        $variant = Product::query()->create([
            'sku' => 'SKU-PARENT-S',
            'name' => 'Komplet wariantowy S',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'content' => ['pl' => ['name' => 'Komplet wariantowy S']],
                    'parameters' => [
                        ['name' => 'Rozmiar', 'value' => 'S', 'variation' => true],
                    ],
                ],
            ],
        ]);

        $this->post(route('products.relations.store', $parent), [
            'relation_type' => 'variant',
            'child_sku' => 'SKU-PARENT-S',
            'variant_attribute' => 'Rozmiar',
        ])->assertRedirect()
            ->assertSessionHas('status', 'Dodano SKU-PARENT-S jako wariant produktu SKU-PARENT.');

        $relation = ProductRelation::query()->firstOrFail();

        $this->assertSame($parent->id, $relation->parent_product_id);
        $this->assertSame($variant->id, $relation->child_product_id);
        $this->assertSame('variant', $relation->relation_type);
        $this->assertSame('variable', data_get($parent->refresh()->attributes, 'master.product_type'));
        $this->assertSame('variation', data_get($variant->refresh()->attributes, 'master.product_type'));

        $this->get(route('products.show', $parent))
            ->assertOk()
            ->assertSee('Komplet wariantowy S')
            ->assertSee('Odłącz')
            ->assertSee('Dodaj istniejący produkt jako wariant');

        $this->delete(route('products.relations.destroy', [$parent, $relation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Wariant został odłączony od produktu.');

        $this->assertSame(0, ProductRelation::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'product.variant_attached')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'product.variant_detached')->count());
    }
}
