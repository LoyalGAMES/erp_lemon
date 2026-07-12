<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportStockToWooCommerceJob;
use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\StockReservation;
use App\Models\StockSyncQueueItem;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\StockSyncExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
            ->assertDontSee('>Szczegóły<', false)
            ->assertSee('Edytuj')
            ->assertSee('Ogółem')
            ->assertSee('Dostępne')
            ->assertDontSee('5,0000')
            ->assertDontSee('4,0000');

        $this->get(route('products.show', $product))
            ->assertRedirect(route('products.edit', $product));
    }

    public function test_product_catalog_defaults_to_newest_products_first(): void
    {
        Product::query()->create([
            'sku' => 'SKU-OLD',
            'name' => 'Aardvark stary produkt',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'publication_date' => '2026-01-02T09:00',
                ],
            ],
        ]);

        Product::query()->create([
            'sku' => 'SKU-NEW',
            'name' => 'Zeta najnowszy produkt',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'publication_date' => '2026-07-08T12:30',
                ],
            ],
        ]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'Zeta najnowszy produkt',
                'Aardvark stary produkt',
            ]);
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
            ->assertDontSee('requestSubmit', false)
            ->assertSee('pagination-bar', false)
            ->assertSee('Szybkie wyszukiwanie')
            ->assertSee('Magazyn')
            ->assertSee('Filtruj');

        $this->get(route('products.index', ['q' => 'Opis po polsku']))
            ->assertOk()
            ->assertSee('Sukienka PARIS Różowa')
            ->assertDontSee('>Buty zimowe</a>', false);

        $this->get(route('products.index', [
            'channel' => 'B2C',
            'warehouse' => $warehouse->id,
            'stock' => 'available',
            'category' => 'Sukienki',
            'status' => 'publish',
        ]))
            ->assertOk()
            ->assertSee('Sukienka PARIS Różowa')
            ->assertDontSee('>Buty zimowe</a>', false);
    }

    public function test_operator_can_manage_product_categories_and_parameter_dictionary(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $this->get(route('products.categories.index'))
            ->assertOk()
            ->assertSee('Kategorie')
            ->assertSee('Parametry')
            ->assertSee('Opis kategorii');

        $this->post(route('products.categories.store'), [
            'sales_channel_id' => $channel->id,
            'external_id' => '88',
            'name' => 'Akcesoria',
            'path' => 'Moda > Akcesoria',
            'slug' => 'akcesoria',
            'description' => 'Akcesoria do stylizacji i kompletów.',
        ])->assertRedirect();

        $this->post(route('products.parameters.store'), [
            'name' => 'Rozmiar',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values_text' => "S\nM\nL",
            'is_variant' => '1',
            'sort_order' => '10',
        ])->assertRedirect();

        $this->assertDatabaseHas('product_categories', [
            'sales_channel_id' => $channel->id,
            'external_id' => '88',
            'name' => 'Akcesoria',
            'description' => 'Akcesoria do stylizacji i kompletów.',
        ]);

        $parent = ProductCategory::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'parent',
            'name' => 'Odzież',
            'slug' => 'odziez',
            'path' => 'Odzież',
            'sort_order' => 10,
        ]);
        $child = ProductCategory::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'child',
            'parent_external_id' => 'parent',
            'name' => 'Koszule',
            'slug' => 'koszule',
            'path' => 'Odzież > Koszule',
            'sort_order' => 20,
        ]);

        $this->postJson(route('products.categories.sort'), [
            'items' => [
                [
                    'id' => $child->id,
                    'parent_external_id' => null,
                    'sort_order' => 10,
                ],
                [
                    'id' => $parent->id,
                    'parent_external_id' => null,
                    'sort_order' => 20,
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('product_categories', [
            'id' => $child->id,
            'parent_external_id' => null,
            'path' => 'Koszule',
            'sort_order' => 10,
        ]);
        $this->assertDatabaseHas('product_categories', [
            'id' => $parent->id,
            'sort_order' => 20,
        ]);
        $this->assertDatabaseHas('product_parameter_definitions', [
            'name' => 'Rozmiar',
            'slug' => 'rozmiar',
            'is_variant' => true,
        ]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Akcesoria')
            ->assertSee('product-parameter-name-options', false)
            ->assertSee('Rozmiar');
    }

    public function test_operator_can_create_product_with_stepper_and_server_media(): void
    {
        $response = $this->post(route('products.store'), [
            'sku' => 'SKU-NEW',
            'name' => 'Nowa koszula ERP',
            'ean' => '5901234567008',
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
            'sale_price_pln' => '249.00',
            'sale_price_starts_at' => '2026-06-01',
            'sale_price_ends_at' => '2026-06-30',
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

        $response->assertRedirect(route('products.edit', $product));
        $this->assertSame('Nowa koszula ERP', $product->name);
        $this->assertSame('erp', data_get($product->attributes, 'master.source'));
        $this->assertSame('Koszule', data_get($product->attributes, 'master.category'));
        $this->assertSame('M', data_get($product->attributes, 'master.parameters.0.value'));
        $this->assertTrue(data_get($product->attributes, 'master.parameters.0.variation'));
        $this->assertNull(data_get($product->attributes, 'master.stock.quantity'));
        $this->assertSame('A-02-01', data_get($product->attributes, 'master.stock.location'));
        $this->assertSame('catalog', data_get($product->attributes, 'master.catalog_visibility'));
        $this->assertSame('variable', data_get($product->attributes, 'master.product_type'));
        $this->assertSame('Rozmiar', data_get($product->attributes, 'master.variant_attribute'));
        $this->assertSame(['SKU-UP-1', 'SKU-UP-2'], data_get($product->attributes, 'master.related_products.upsell_skus'));
        $this->assertSame('<p>Short ERP</p>', data_get($product->attributes, 'master.content.en.additional_description'));
        $this->assertEquals(round(299 / 4.55, 2), data_get($product->attributes, 'master.prices.price_eur'));
        $this->assertEquals(249.0, data_get($product->attributes, 'master.prices.sale_price_pln'));
        $this->assertSame('2026-06-01', data_get($product->attributes, 'master.prices.sale_price_starts_at'));
        $this->assertSame('2026-06-30', data_get($product->attributes, 'master.prices.sale_price_ends_at'));
        $this->assertSame('Nowa koszula ERP', data_get($product->attributes, 'master.media.0.alt'));

        $mediaSrc = (string) data_get($product->attributes, 'master.media.0.src');
        $this->assertStringStartsWith('/uploads/testing-products/'.$product->id.'/', $mediaSrc);
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

    public function test_operator_can_adjust_product_stock_from_product_card_per_warehouse(): void
    {
        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-ADJUST',
            'name' => 'Produkt do korekty',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 5,
            'quantity_reserved' => 2,
            'quantity_available' => 3,
        ]);

        $this->get(route('products.show', $product))
            ->assertRedirect(route('products.edit', $product));

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('Sprzedaż i magazyn')
            ->assertSee('Ręczna zmiana tworzy dokument KOR')
            ->assertSeeInOrder(['Stan ogółem', 'Cena hurt (PLN)'])
            ->assertSee('synchronizacji z WooCommerce')
            ->assertDontSee('<th>Powód</th>', false)
            ->assertSee('data-stock-adjust-submit', false);

        $listResponse = $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Magazyny i korekta')
            ->assertSee('Ręczna zmiana tworzy dokument KOR')
            ->assertSee('data-stock-adjust-submit', false)
            ->assertSee('data-stock-modal-card', false)
            ->assertSee('data-stock-modal-body', false)
            ->assertSee('data-stock-label="Nowy stan ogółem"', false);

        $listHtml = $listResponse->getContent();
        $this->assertMatchesRegularExpression(
            '/<button\b(?=[^>]*\bdata-stock-modal-open="stock-modal-'.$product->id.'")(?=[^>]*\baria-haspopup="dialog")(?=[^>]*\baria-controls="stock-modal-'.$product->id.'")(?=[^>]*\baria-expanded="false")[^>]*>/s',
            $listHtml,
        );
        $this->assertMatchesRegularExpression(
            '/<tr\b(?=[^>]*\bdata-stock-adjust-row)(?=[^>]*\bdata-stock-adjust-state="idle")(?=[^>]*\bdata-warehouse-id="'.$warehouse->id.'")[^>]*>/s',
            $listHtml,
        );
        $this->assertMatchesRegularExpression(
            '/<td\b(?=[^>]*\bdata-stock-label="Nowy stan ogółem")[^>]*>\s*<input\b(?=[^>]*\bdata-stock-adjust-quantity)(?=[^>]*\binputmode="decimal")(?=[^>]*\benterkeyhint="done")(?=[^>]*\baria-describedby="[^"]+-error")[^>]*>/s',
            $listHtml,
        );
        $this->assertMatchesRegularExpression(
            '/<div\b(?=[^>]*\bdata-stock-adjust-error)(?=[^>]*\brole="alert")(?=[^>]*\baria-live="polite")[^>]*><\/div>/s',
            $listHtml,
        );

        $this->post(route('products.stock.adjust', $product), [
            'warehouse_id' => $warehouse->id,
            'new_quantity' => 8,
            'notes' => 'Inwentaryzacja testowa',
        ])->assertRedirect(route('products.edit', $product))
            ->assertSessionHas('status');

        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('8.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('2.0000', (string) $balance->quantity_reserved);
        $this->assertSame('6.0000', (string) $balance->quantity_available);

        $document = WarehouseDocument::query()->with('lines')->firstOrFail();
        $this->assertSame('KOR', $document->type);
        $this->assertSame('posted', $document->status);
        $this->assertSame($warehouse->id, $document->destination_warehouse_id);
        $this->assertSame('3.0000', (string) $document->lines->first()->quantity);

        $ledger = StockLedgerEntry::query()->firstOrFail();
        $this->assertSame($document->id, $ledger->warehouse_document_id);
        $this->assertSame('3.0000', (string) $ledger->quantity_change);
        $this->assertSame('in', $ledger->direction);

        $this->assertSame(1, AuditLog::query()->where('action', 'product.stock_adjusted')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'warehouse_document.posted')->count());

        $this->post(route('products.stock.adjust', $product), [
            'warehouse_id' => $warehouse->id,
            'new_quantity' => 6,
            'notes' => 'Korekta z listy produktów',
            'redirect_url' => route('products.index'),
        ])->assertRedirect(route('products.index'))
            ->assertSessionHas('status');

        $balance->refresh();
        $this->assertSame('6.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('2.0000', (string) $balance->quantity_reserved);
        $this->assertSame('4.0000', (string) $balance->quantity_available);
        $this->assertSame(2, WarehouseDocument::query()->where('type', 'KOR')->where('status', 'posted')->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'product.stock_adjusted')->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'warehouse_document.posted')->count());
    }

    public function test_setting_unchanged_stock_queues_available_quantity_and_exports_it_to_woocommerce(): void
    {
        Queue::fake();
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/777' => Http::response([
                'id' => 777,
                'sku' => 'SKU-UNCHANGED-SYNC',
                'stock_quantity' => 2,
                'stock_status' => 'instock',
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
            'sku' => 'SKU-UNCHANGED-SYNC',
            'name' => 'Produkt z niezmienionym stanem',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '777',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 4,
            'quantity_reserved' => 2,
            'quantity_available' => 2,
        ]);
        StockReservation::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_order_id' => 'ORDER-RESERVED-2',
            'quantity' => 2,
            'status' => 'active',
            'reserved_at' => now(),
        ]);

        $this->post(route('products.stock.adjust', $product), [
            'warehouse_id' => $warehouse->id,
            'new_quantity' => 4,
        ])->assertRedirect(route('products.edit', $product))
            ->assertSessionHas('status');

        $this->assertSame(0, WarehouseDocument::query()->where('type', 'KOR')->count());

        $queueItem = StockSyncQueueItem::query()->firstOrFail();
        $this->assertSame('2.0000', (string) $queueItem->quantity_to_push);
        $this->assertSame('manual_stock_sync_requested', $queueItem->metadata['reason']);
        Queue::assertPushed(ExportStockToWooCommerceJob::class, 1);

        app(StockSyncExportService::class)->export($queueItem);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/777'
            && $request['manage_stock'] === true
            && $request['stock_quantity'] === 2
            && $request['stock_status'] === 'instock');
    }

    public function test_variable_product_stock_is_consolidated_into_one_quick_edit_table(): void
    {
        $warehouse = Warehouse::query()->create([
            'code' => 'WC_B2C',
            'name' => 'WooCommerce B2C',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $shopWarehouse = Warehouse::query()->create([
            'code' => 'SHOP',
            'name' => 'Sklep stacjonarny',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'HEROS-BEZ',
            'name' => 'Klapki HEROS Beżowe',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variable',
                    'variant_attribute' => 'Rozmiar',
                ],
            ],
        ]);

        $variants = collect([
            ['size' => '37', 'sku' => 'HEROS-BEZ-37', 'ean' => '5900000000037', 'stock' => [5, 1, 4]],
            ['size' => '38', 'sku' => 'HEROS-BEZ-38', 'ean' => '5900000000038', 'stock' => [4, 0, 4]],
        ])->map(function (array $row, int $index) use ($product, $warehouse): Product {
            $variant = Product::query()->create([
                'sku' => $row['sku'],
                'ean' => $row['ean'],
                'name' => "Klapki HEROS Beżowe - {$row['size']}",
                'unit' => 'szt',
                'vat_rate' => 23,
                'quantity_precision' => 0,
                'is_active' => true,
                'attributes' => [
                    'master' => [
                        'source' => 'erp',
                        'product_type' => 'variation',
                        'parameters' => [
                            ['name' => 'Rozmiar', 'value' => $row['size'], 'variation' => true],
                        ],
                    ],
                ],
            ]);

            ProductRelation::query()->create([
                'parent_product_id' => $product->id,
                'child_product_id' => $variant->id,
                'relation_type' => 'variant',
                'sort_order' => ($index + 1) * 10,
                'metadata' => ['variant_attribute' => 'Rozmiar'],
            ]);
            StockBalance::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $variant->id,
                'quantity_on_hand' => $row['stock'][0],
                'quantity_reserved' => $row['stock'][1],
                'quantity_available' => $row['stock'][2],
            ]);

            return $variant;
        });

        foreach ($variants as $variant) {
            StockBalance::query()->create([
                'warehouse_id' => $shopWarehouse->id,
                'product_id' => $variant->id,
                'quantity_on_hand' => 2,
                'quantity_reserved' => 0,
                'quantity_available' => 2,
            ]);
        }

        $response = $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('Stany magazynowe wariantów')
            ->assertSee('Każdy wariant i magazyn edytujesz w jednym wierszu.')
            ->assertSee('data-variant-stock-table', false)
            ->assertSee('HEROS-BEZ-37')
            ->assertSee('5900000000037')
            ->assertSee('HEROS-BEZ-38')
            ->assertSee('5900000000038')
            ->assertSee('Magazyn')
            ->assertSee('WC_B2C')
            ->assertSee('SHOP')
            ->assertSee('Nowy stan')
            ->assertSee('Ustaw')
            ->assertSee('Edytuj')
            ->assertDontSee('variant-stock-management-item', false)
            ->assertDontSee('Stan ogółem');

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'data-variant-stock-table'));
        $this->assertSame(4, substr_count($html, '<tr data-variant-stock-row>'));
        $this->assertStringNotContainsString('class="stock-readonly-panel"', $html);
        $this->assertSame(4, preg_match_all('/<button\b[^>]*\bdata-stock-adjust-submit\b[^>]*>/s', $html));

        foreach ($variants as $variant) {
            $this->assertSame(2, substr_count(
                $html,
                'data-action="'.route('products.stock.adjust', $variant).'"',
            ));
        }

        $this->post(route('products.stock.adjust', $variants->first()), [
            'warehouse_id' => $warehouse->id,
            'new_quantity' => 7,
            'redirect_url' => route('products.edit', $product),
        ])->assertRedirect(route('products.edit', $product));

        $this->assertSame('7.0000', (string) StockBalance::query()
            ->whereBelongsTo($variants->first())
            ->whereBelongsTo($warehouse)
            ->value('quantity_on_hand'));
        $this->assertSame('2.0000', (string) StockBalance::query()
            ->whereBelongsTo($variants->first())
            ->whereBelongsTo($shopWarehouse)
            ->value('quantity_on_hand'));
        $this->assertSame('4.0000', (string) StockBalance::query()
            ->whereBelongsTo($variants->last())
            ->whereBelongsTo($warehouse)
            ->value('quantity_on_hand'));
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
            ->assertSee('data-product-tab="warianty"', false)
            ->assertSee('Status publikacji w sklepie')
            ->assertSee('Data publikacji w sklepie')
            ->assertSee('product-category-checklist', false)
            ->assertSee('Dodaj zdjęcia z komputera');

        $this->post(route('products.update', $product), [
            '_method' => 'PUT',
            'sku' => 'SKU-BASE',
            'name' => 'Koszula AURA Czarno-ecru',
            'ean' => '5901234567893',
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
            'publication_date' => '2026-07-20T08:15',
            'catalog_visibility' => 'visible',
            'product_type' => 'variable',
            'variant_attribute' => 'Rozmiar',
            'wholesale_price_pln' => '149.00',
            'retail_price_pln' => '369.00',
            'sale_price_pln' => '319.00',
            'sale_price_starts_at' => '2026-07-01',
            'sale_price_ends_at' => '2026-07-15',
            'price_eur' => '81.18',
            'price_gbp' => '70.11',
            'price_usd' => '88.56',
            'purchase_price_pln' => '100.00',
            'extra_cost_pln' => '5.00',
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
        ])->assertRedirect(route('products.edit', $product));

        $product->refresh();
        $this->assertSame('Koszula AURA Czarno-ecru', $product->name);
        $this->assertSame('erp', data_get($product->attributes, 'master.source'));
        $this->assertSame('Koszule', data_get($product->attributes, 'master.category'));
        $this->assertSame(['koszula', 'aura'], data_get($product->attributes, 'master.tags'));
        $this->assertEquals(369.0, data_get($product->attributes, 'master.prices.retail_price_pln'));
        $this->assertEquals(319.0, data_get($product->attributes, 'master.prices.sale_price_pln'));
        $this->assertSame('2026-07-01', data_get($product->attributes, 'master.prices.sale_price_starts_at'));
        $this->assertSame('2026-07-15', data_get($product->attributes, 'master.prices.sale_price_ends_at'));
        $this->assertEquals(round(369 / 4.55, 2), data_get($product->attributes, 'master.prices.price_eur'));
        $this->assertNull(data_get($product->attributes, 'master.stock.quantity'));
        $this->assertSame('A-01-03', data_get($product->attributes, 'master.stock.location'));
        $this->assertSame('2026-07-20T08:15', data_get($product->attributes, 'master.publication_date'));
        $this->assertSame('variable', data_get($product->attributes, 'master.product_type'));
        $this->assertSame('Rozmiar', data_get($product->attributes, 'master.variant_attribute'));
        $this->assertSame('SKU-UPSELL', data_get($product->attributes, 'master.related_products.upsell_skus.0'));
        $this->assertSame('SKU-CROSS-2', data_get($product->attributes, 'master.related_products.cross_sell_skus.1'));
        $this->assertSame('One size', data_get($product->attributes, 'master.parameters.0.value'));
        $this->assertTrue(data_get($product->attributes, 'master.parameters.0.variation'));
        $this->assertSame('<p>Short description</p>', data_get($product->attributes, 'master.content.en.additional_description'));
        $mediaSrc = (string) data_get($product->attributes, 'master.media.0.src');
        $this->assertStringStartsWith('/uploads/testing-products/'.$product->id.'/', $mediaSrc);
        $this->assertSame('Koszula AURA', data_get($product->attributes, 'master.media.0.alt'));
        $this->assertFileExists(public_path(ltrim($mediaSrc, '/')));
        $this->assertSame('Dostawca A', data_get($product->attributes, 'master.suppliers.0.name'));
        $this->assertSame(1, AuditLog::query()->where('action', 'product.master_data_updated')->count());

        $this->get(route('products.show', $product))
            ->assertRedirect(route('products.edit', $product));

        @unlink(public_path(ltrim($mediaSrc, '/')));
    }

    public function test_editing_mapped_erp_product_queues_woocommerce_data_export(): void
    {
        Queue::fake();

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-AUTO-EXPORT',
            'name' => 'Przed zmianą',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'SKU-AUTO-EXPORT',
            'stock_sync_enabled' => true,
        ]);

        $this->put(route('products.update', $product), [
            'sku' => 'SKU-AUTO-EXPORT',
            'name' => 'Po zmianie w ERP',
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => '1',
        ])
            ->assertRedirect(route('products.edit', $product))
            ->assertSessionHas('status', 'Dane produktu zostały zapisane jako dane główne ERP. Zmapowane kanały WooCommerce zostaną zsynchronizowane w tle.');

        Queue::assertPushed(ExportWooCommerceProductDataJob::class, 1);
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
                    'product_type' => 'variable',
                    'variant_attribute' => 'Rozmiar',
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
        $sourceVariant = Product::query()->create([
            'sku' => 'SKU-COPY-M',
            'ean' => '5901234567890',
            'name' => 'Koszula do kopiowania M',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'woocommerce_variation_id' => '101',
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variation',
                    'content' => [
                        'pl' => [
                            'name' => 'Koszula do kopiowania M',
                            'description' => '<p>Opis wariantu</p>',
                        ],
                    ],
                    'parameters' => [
                        ['name' => 'Rozmiar', 'value' => 'M', 'variation' => true],
                    ],
                    'media' => [
                        ['src' => '/uploads/products/2/m.jpg', 'alt' => 'M', 'name' => 'm.jpg'],
                    ],
                ],
            ],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $product->id,
            'child_product_id' => $sourceVariant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
            'metadata' => ['variant_attribute' => 'Rozmiar'],
        ]);

        $this->post(route('products.duplicate', $product))
            ->assertRedirect();

        $copy = Product::query()->get()->first(
            fn (Product $candidate): bool => (int) data_get($candidate->attributes, 'master.copy.created_from_product_id') === $product->id
        );

        $this->assertInstanceOf(Product::class, $copy);

        $this->assertSame('Koszula do kopiowania (kopia)', $copy->name);
        $this->assertSame('SEM-'.str_pad((string) $copy->id, 8, '0', STR_PAD_LEFT), $copy->sku);
        $this->assertStringNotContainsString('COPY', $copy->sku);
        $this->assertFalse($copy->is_active);
        $this->assertSame('Koszula do kopiowania (kopia)', data_get($copy->attributes, 'master.content.pl.name'));
        $this->assertSame('<p>Opis</p>', data_get($copy->attributes, 'master.content.pl.description'));
        $this->assertSame($product->id, data_get($copy->attributes, 'master.copy.created_from_product_id'));
        $this->assertSame([], data_get($copy->attributes, 'master.media'));
        $this->assertNull(data_get($copy->attributes, 'woocommerce_product_id'));
        $this->assertNull(data_get($copy->attributes, 'woocommerce_permalink'));
        $this->assertNull($copy->externalDisplayId());
        $this->assertNull($copy->externalProductUrl());
        $this->assertSame(0, ProductChannelMapping::query()->where('product_id', $copy->id)->count());
        $this->assertSame(0, StockBalance::query()->where('product_id', $copy->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'product.duplicated')->count());

        $variantCopy = $copy->variantChildren()->firstOrFail();
        $this->assertSame('SEM-'.str_pad((string) $variantCopy->id, 8, '0', STR_PAD_LEFT), $variantCopy->sku);
        $this->assertStringNotContainsString('COPY', $variantCopy->sku);
        $this->assertNull($variantCopy->ean);
        $this->assertTrue($variantCopy->is_active);
        $this->assertSame('M', data_get($variantCopy->attributes, 'master.parameters.0.value'));
        $this->assertSame('<p>Opis wariantu</p>', data_get($variantCopy->attributes, 'master.content.pl.description'));
        $this->assertSame([], data_get($variantCopy->attributes, 'master.media'));
        $this->assertNull(data_get($variantCopy->attributes, 'woocommerce_variation_id'));
        $this->assertSame(0, ProductChannelMapping::query()->where('product_id', $variantCopy->id)->count());
        $this->assertSame(0, StockBalance::query()->where('product_id', $variantCopy->id)->count());

        $this->get(route('products.show', $copy))
            ->assertRedirect(route('products.edit', $copy));

        $response = $this->get(route('products.edit', $copy))
            ->assertOk()
            ->assertSee('Stany magazynowe wariantów')
            ->assertSee($variantCopy->sku)
            ->assertSee('Każdy wariant i magazyn edytujesz w jednym wierszu.')
            ->assertSee('data-variant-stock-table', false)
            ->assertSee('Edytuj')
            ->assertDontSee('variant-stock-management-item', false)
            ->assertDontSee('Edytuj cenę, EAN, SKU i stan');

        $this->assertSame(1, substr_count($response->getContent(), '<tr data-variant-stock-row>'));
    }

    public function test_new_variable_product_generates_variants_with_unique_sku_and_inherited_gs1_ean(): void
    {
        AppSetting::query()->create([
            'key' => 'gs1_configuration',
            'value' => [
                'company_prefix' => '5901234',
                'next_item_reference' => 1,
                'register_products' => false,
            ],
        ]);
        $category = ProductCategory::query()->create([
            'external_id' => 'ERP-KOMPLETY',
            'name' => 'Komplety',
            'path' => 'Odzież > Komplety',
            'gs1_gpc_code' => '10001352',
            'gs1_gpc_label' => 'Komplety odzieżowe',
        ]);

        $this->post(route('products.store'), [
            'name' => 'Komplet tworzony od zera',
            'name_en' => 'New set',
            'sku' => '',
            'ean' => '',
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => 1,
            'product_type' => 'variable',
            'variant_attribute' => 'Rozmiar',
            'new_variant_values' => ['S', 'M'],
            'category_ids' => [$category->id],
            'retail_price_pln' => 399.99,
            'description_pl' => '<p>Opis kompletu</p>',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $parent = Product::query()->where('name', 'Komplet tworzony od zera')->firstOrFail();
        $parent->load('variantChildren');
        $this->assertSame('variable', data_get($parent->masterData(), 'product_type'));
        $this->assertSame('SEM-'.str_pad((string) $parent->id, 8, '0', STR_PAD_LEFT), $parent->sku);
        $this->assertNotNull($parent->ean);
        $this->assertCount(2, $parent->variantChildren);

        $variantEans = [];

        foreach ($parent->variantChildren as $variant) {
            $option = data_get($variant->masterData(), 'parameters.0.value');
            $this->assertContains($option, ['S', 'M']);
            $this->assertSame('Rozmiar', data_get($variant->masterData(), 'parameters.0.name'));
            $this->assertTrue(data_get($variant->masterData(), 'parameters.0.variation'));
            $this->assertSame('variation', data_get($variant->masterData(), 'product_type'));
            $this->assertSame('SEM-'.str_pad((string) $variant->id, 8, '0', STR_PAD_LEFT), $variant->sku);
            $this->assertNotNull($variant->ean);
            $this->assertSame('10001352', data_get($variant->masterData(), 'gs1.gpc_code'));
            $this->assertEquals(399.99, data_get($variant->masterData(), 'prices.retail_price_pln'));
            $this->assertSame('<p>Opis kompletu</p>', data_get($variant->masterData(), 'content.pl.description'));
            $this->assertSame([], data_get($variant->masterData(), 'media'));
            $this->assertSame(0, StockBalance::query()->where('product_id', $variant->id)->count());
            $variantEans[] = $variant->ean;
        }

        $this->assertCount(3, array_unique([$parent->ean, ...$variantEans]));
        $this->assertDatabaseHas('product_parameter_definitions', [
            'name' => 'Rozmiar',
            'is_variant' => true,
        ]);

        $this->put(route('products.update', $parent), [
            'name' => $parent->name,
            'name_en' => 'New set',
            'sku' => $parent->sku,
            'ean' => $parent->ean,
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => 1,
            'product_type' => 'variable',
            'variant_attribute' => 'Rozmiar',
            'new_variant_values' => ['S', 'M', 'L'],
            'category_ids' => [$category->id],
            'retail_price_pln' => 399.99,
            'description_pl' => '<p>Opis kompletu</p>',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $parent->refresh()->load('variantChildren');
        $this->assertCount(3, $parent->variantChildren);
        $this->assertSame(
            ['L', 'M', 'S'],
            $parent->variantChildren
                ->map(fn (Product $variant): string => (string) data_get($variant->masterData(), 'parameters.0.value'))
                ->sort()
                ->values()
                ->all(),
        );
        $this->assertSame(3, $parent->variantChildren->pluck('sku')->unique()->count());
        $this->assertSame(3, $parent->variantChildren->pluck('ean')->filter()->unique()->count());
    }

    public function test_product_form_rejects_duplicate_invalid_ean_and_variants_without_attribute(): void
    {
        Product::query()->create([
            'sku' => 'SKU-EAN-EXISTING',
            'ean' => '5901234567893',
            'name' => 'Istniejący produkt',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->from(route('products.index'))->post(route('products.store'), [
            'name' => 'Duplikat EAN',
            'sku' => 'SKU-EAN-DUPLICATE',
            'ean' => '5901234567893',
            'unit' => 'szt',
            'vat_rate' => 23,
        ])->assertRedirect(route('products.index'))->assertSessionHasErrors('ean');

        $this->from(route('products.index'))->post(route('products.store'), [
            'name' => 'Niepoprawny EAN',
            'sku' => 'SKU-EAN-INVALID',
            'ean' => '5901234567890',
            'unit' => 'szt',
            'vat_rate' => 23,
        ])->assertRedirect(route('products.index'))->assertSessionHasErrors('ean');

        $this->from(route('products.index'))->post(route('products.store'), [
            'name' => 'Wariant bez atrybutu',
            'sku' => 'SKU-NO-ATTRIBUTE',
            'unit' => 'szt',
            'vat_rate' => 23,
            'product_type' => 'variable',
            'new_variant_values' => ['S'],
        ])->assertRedirect(route('products.index'))->assertSessionHasErrors('variant_attribute');

        $this->assertSame(1, Product::query()->count());
    }

    public function test_failed_variant_generation_rolls_back_product_variants_and_uploaded_media(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Inny atrybut',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => [],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 10,
        ]);
        $this->withoutExceptionHandling();
        $directory = public_path('uploads/testing-products/1');
        $filesBefore = File::isDirectory($directory)
            ? collect(File::files($directory))->map->getFilename()->sort()->values()->all()
            : [];

        try {
            $this->post(route('products.store'), [
                'name' => 'Produkt do wycofania',
                'sku' => '',
                'unit' => 'szt',
                'vat_rate' => 23,
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'new_variant_values' => ['S'],
                'new_media' => [UploadedFile::fake()->image('rollback.jpg')],
            ]);
        } catch (\Throwable) {
            $this->assertSame(0, Product::query()->count());
            $filesAfter = File::isDirectory($directory)
                ? collect(File::files($directory))->map->getFilename()->sort()->values()->all()
                : [];
            $this->assertSame($filesBefore, $filesAfter);

            return;
        }

        $this->fail('Oczekiwano błędu zapisu definicji wariantu.');
    }

    public function test_edit_can_clear_all_categories_and_preserves_hidden_commercial_data(): void
    {
        $category = ProductCategory::query()->create([
            'external_id' => 'ERP-OLD',
            'name' => 'Stara kategoria',
            'path' => 'Stara kategoria',
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-CLEAR-CATEGORY',
            'name' => 'Produkt do edycji',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'category' => 'Stara kategoria',
                    'category_ids' => [$category->id],
                    'categories' => ['Stara kategoria'],
                    'prices' => ['extra_cost_pln' => 12.5],
                    'suppliers' => [[
                        'name' => 'Dostawca historyczny',
                        'product_code' => 'OLD-1',
                        'purchase_price_pln' => 80,
                    ]],
                ],
            ],
        ]);

        $this->put(route('products.update', $product), [
            'sku' => $product->sku,
            'name' => $product->name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => 1,
            'product_type' => 'simple',
        ])->assertRedirect(route('products.edit', $product))->assertSessionHasNoErrors();

        $master = $product->refresh()->masterData();
        $this->assertNull(data_get($master, 'category'));
        $this->assertSame([], data_get($master, 'category_ids'));
        $this->assertSame([], data_get($master, 'categories'));
        $this->assertEquals(12.5, data_get($master, 'prices.extra_cost_pln'));
        $this->assertSame('Dostawca historyczny', data_get($master, 'suppliers.0.name'));
    }

    public function test_copy_renames_both_languages_and_generates_ean_after_category_is_confirmed(): void
    {
        AppSetting::query()->create([
            'key' => 'gs1_configuration',
            'value' => [
                'company_prefix' => '5901234',
                'next_item_reference' => 1,
                'register_products' => false,
            ],
        ]);
        $category = ProductCategory::query()->create([
            'external_id' => 'ERP-NEW-CATEGORY',
            'name' => 'Nowa kategoria',
            'path' => 'Nowa kategoria',
            'gs1_gpc_code' => '10001352',
            'gs1_gpc_label' => 'Nowa kategoria GS1',
        ]);
        $source = Product::query()->create([
            'sku' => 'SKU-BILINGUAL-COPY',
            'ean' => '5901234567893',
            'name' => 'Polska nazwa',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'content' => [
                        'pl' => ['name' => 'Polska nazwa'],
                        'en' => ['name' => 'English name'],
                    ],
                    'media' => [['src' => '/old.jpg']],
                ],
            ],
        ]);

        $this->post(route('products.duplicate', $source))->assertRedirect();
        $copy = Product::query()->whereKeyNot($source->id)->firstOrFail();
        $this->assertNull($copy->ean);
        $this->assertSame('Polska nazwa (kopia)', data_get($copy->masterData(), 'content.pl.name'));
        $this->assertSame('English name (kopia)', data_get($copy->masterData(), 'content.en.name'));

        $this->put(route('products.update', $copy), [
            'sku' => $copy->sku,
            'name' => $copy->name,
            'name_en' => 'New English name',
            'unit' => 'szt',
            'vat_rate' => 23,
            'product_type' => 'simple',
            'category_ids' => [$category->id],
        ])->assertRedirect(route('products.edit', $copy))->assertSessionHasNoErrors();

        $copy->refresh();
        $this->assertNotNull($copy->ean);
        $this->assertSame('10001352', data_get($copy->masterData(), 'gs1.gpc_code'));
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
            ->assertRedirect(route('products.edit', $parent));

        $this->delete(route('products.relations.destroy', [$parent, $relation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Wariant został odłączony od produktu.');

        $this->assertSame(0, ProductRelation::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'product.variant_attached')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'product.variant_detached')->count());
    }
}
