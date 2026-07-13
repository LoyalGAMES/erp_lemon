<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\AuditLog;
use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\ProductDataExportService;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WooCommerceProductDataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_legacy_product_dispatches_immediate_and_durable_export_without_losing_images(): void
    {
        Bus::fake();
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response([
                'id' => 123,
                'sku' => 'SKU-AUTO-VISIBILITY',
                'catalog_visibility' => 'hidden',
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
            'sku' => 'SKU-AUTO-VISIBILITY',
            'name' => 'Produkt automatyczny',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'catalog_visibility' => 'visible',
                ],
                'woocommerce_images' => [
                    ['src' => 'https://shop.test/wp-content/uploads/legacy.jpg', 'alt' => 'Zdjęcie legacy'],
                ],
            ],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'SKU-AUTO-VISIBILITY',
            'stock_sync_enabled' => true,
        ]);

        $this->put(route('products.update', $product), [
            'sku' => 'SKU-AUTO-VISIBILITY',
            'name' => 'Produkt automatyczny',
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => '1',
            'publication_status' => 'publish',
            'catalog_visibility' => 'hidden',
            'product_type' => 'simple',
        ])->assertRedirect(route('products.edit', $product));

        $this->assertSame('hidden', data_get($product->refresh()->masterData(), 'catalog_visibility'));
        $this->assertSame(
            'https://shop.test/wp-content/uploads/legacy.jpg',
            data_get($product->masterData(), 'media.0.src'),
        );
        $this->assertCount(1, Bus::dispatched(ExportWooCommerceProductDataJob::class));
        Bus::assertDispatchedAfterResponse(ExportWooCommerceProductDataJob::class, 1);
        $job = Bus::dispatchedAfterResponse(ExportWooCommerceProductDataJob::class)->sole();
        $job->handle(app(ProductDataExportService::class));

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123'
            && $request['catalog_visibility'] === 'hidden'
            && $request['images'][0]['src'] === 'https://shop.test/wp-content/uploads/legacy.jpg');
        $this->assertNull(data_get(
            ProductChannelMapping::query()->where('product_id', $product->id)->firstOrFail()->metadata,
            'product_data_export.pending_token',
        ));
    }

    public function test_replacing_erp_product_image_dispatches_immediate_export_with_only_the_new_gallery(): void
    {
        Bus::fake();
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), 'lang=en')) {
                return Http::response([
                    [
                        'id' => 123,
                        'sku' => 'SKU-AUTO-IMAGE',
                        'lang' => 'pl',
                        'translations' => ['pl' => 123, 'en' => 124],
                    ],
                    [
                        'id' => 124,
                        'sku' => 'SKU-AUTO-IMAGE',
                        'lang' => 'en',
                        'translations' => ['pl' => 123, 'en' => 124],
                    ],
                    [
                        'id' => 999,
                        'sku' => 'SKU-AUTO-IMAGE',
                        'lang' => 'en',
                        'translations' => ['de' => 998, 'en' => 999],
                    ],
                ]);
            }

            if ($request->method() === 'PUT' && in_array($request->url(), [
                'https://shop.test/wp-json/wc/v3/products/123',
                'https://shop.test/wp-json/wc/v3/products/124',
            ], true)) {
                return Http::response([
                    'id' => str_ends_with($request->url(), '/124') ? 124 : 123,
                    'sku' => 'SKU-AUTO-IMAGE',
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
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-AUTO-IMAGE',
            'name' => 'Produkt ze zdjęciem',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'media' => [
                        ['src' => '/uploads/products/old.jpg', 'alt' => 'Stare ERP'],
                    ],
                ],
                'woocommerce_images' => [
                    ['src' => 'https://shop.test/wp-content/uploads/old-woo.jpg', 'alt' => 'Stare Woo'],
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

        $this->put(route('products.update', $product), [
            'sku' => $product->sku,
            'name' => $product->name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => '1',
            'existing_media' => [
                ['src' => '/uploads/products/old.jpg', 'alt' => 'Stare ERP', 'remove' => '1'],
            ],
            'new_media' => [UploadedFile::fake()->image('nowe-zdjecie.jpg', 120, 120)],
            'new_media_alt' => 'Nowe zdjęcie ERP',
        ])->assertRedirect(route('products.edit', $product));

        $this->assertCount(1, Bus::dispatched(ExportWooCommerceProductDataJob::class));
        Bus::assertDispatchedAfterResponse(ExportWooCommerceProductDataJob::class, 1);
        $job = Bus::dispatchedAfterResponse(ExportWooCommerceProductDataJob::class)->sole();
        $job->handle(app(ProductDataExportService::class));

        $media = $product->fresh()->mediaImages();
        $this->assertCount(1, $media);
        $this->assertSame('Nowe zdjęcie ERP', $media[0]['alt']);
        $this->assertStringContainsString('/uploads/', $media[0]['src']);
        $this->assertStringContainsString('/'.$product->id.'/', $media[0]['src']);

        Http::assertSent(function ($request) use ($product): bool {
            if ($request->method() !== 'PUT' || $request->url() !== 'https://shop.test/wp-json/wc/v3/products/123') {
                return false;
            }

            return count($request['images']) === 1
                && str_contains($request['images'][0]['src'], '/uploads/')
                && str_contains($request['images'][0]['src'], '/'.$product->id.'/')
                && $request['images'][0]['alt'] === 'Nowe zdjęcie ERP'
                && ! str_contains(json_encode($request['images']) ?: '', 'old-woo.jpg');
        });
        Http::assertSent(function ($request) use ($product): bool {
            if ($request->method() !== 'PUT' || $request->url() !== 'https://shop.test/wp-json/wc/v3/products/124') {
                return false;
            }

            return count($request['images']) === 1
                && str_contains($request['images'][0]['src'], '/uploads/')
                && str_contains($request['images'][0]['src'], '/'.$product->id.'/')
                && $request['images'][0]['alt'] === 'Nowe zdjęcie ERP'
                && ! str_contains(json_encode($request['images']) ?: '', 'old-woo.jpg');
        });
        Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/999');
        $this->assertNull(data_get(
            ProductChannelMapping::query()->where('product_id', $product->id)->firstOrFail()->metadata,
            'product_data_export.pending_token',
        ));
        $this->assertSame('124', data_get($product->fresh()->attributes, 'woocommerce_translations.en.product_id'));

        File::delete(public_path(ltrim($media[0]['src'], '/')));
    }

    public function test_failed_immediate_export_leaves_durable_retry_pending(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response([
                'message' => 'temporary WooCommerce failure',
            ], 503),
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
            'sku' => 'SKU-RETRY',
            'name' => 'Produkt do ponowienia',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => ['source' => 'erp', 'media' => []]],
        ]);
        $syncToken = 'sync-retry-token';
        $mapping = ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'product_data_export' => [
                    'pending_token' => $syncToken,
                    'requested_at' => now()->toISOString(),
                ],
            ],
        ]);

        $job = new ExportWooCommerceProductDataJob($product->id, $syncToken);
        $job->setJob(new SyncJob(app(), '{}', 'sync', 'sync'));
        $job->handle(app(ProductDataExportService::class));

        $this->assertCount(1, $job->middleware());
        $this->assertSame($syncToken, data_get($mapping->fresh()->metadata, 'product_data_export.pending_token'));
        $this->assertDatabaseCount('integration_sync_logs', 0);
    }

    public function test_older_export_keeps_newer_pending_token_and_newer_job_finishes_sync(): void
    {
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
            'sku' => 'SKU-SUPERSEDED',
            'name' => 'Produkt zmieniany dwukrotnie',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => ['source' => 'erp', 'media' => []]],
        ]);
        $mapping = ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'product_data_export' => [
                    'pending_token' => 'token-older',
                    'requested_at' => now()->toISOString(),
                ],
            ],
        ]);
        $requests = 0;
        Http::fake(function () use ($mapping, &$requests) {
            $requests++;

            if ($requests === 1) {
                $metadata = (array) $mapping->fresh()->metadata;
                data_set($metadata, 'product_data_export.pending_token', 'token-newer');
                data_set($metadata, 'product_data_export.requested_at', now()->addSecond()->toISOString());
                $mapping->forceFill(['metadata' => $metadata])->save();
            }

            return Http::response(['id' => 123, 'sku' => 'SKU-SUPERSEDED']);
        });

        (new ExportWooCommerceProductDataJob($product->id, 'token-older'))
            ->handle(app(ProductDataExportService::class));

        $this->assertSame('token-newer', data_get($mapping->fresh()->metadata, 'product_data_export.pending_token'));

        (new ExportWooCommerceProductDataJob($product->id, 'token-newer'))
            ->handle(app(ProductDataExportService::class));

        $this->assertSame(2, $requests);
        $this->assertNull(data_get($mapping->fresh()->metadata, 'product_data_export.pending_token'));
    }

    public function test_manual_export_does_not_overlap_an_active_automatic_export(): void
    {
        Http::fake();
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
            'sku' => 'SKU-LOCKED',
            'name' => 'Produkt synchronizowany',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        $lock = Cache::lock(
            ExportWooCommerceProductDataJob::lockKey($product->id),
            ExportWooCommerceProductDataJob::LOCK_SECONDS,
        );
        $this->assertTrue($lock->get());

        try {
            $this->post(route('products.woocommerce.export', $product))
                ->assertRedirect()
                ->assertSessionHas('status');
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }

    public function test_discovered_translation_reference_does_not_overwrite_newer_erp_media(): void
    {
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
            'sku' => 'SKU-REFERENCE-RACE',
            'name' => 'Produkt przed zmianą',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'media' => [['src' => '/uploads/products/older.jpg']],
                'media_updated_at' => now()->toISOString(),
                'content' => ['pl' => ['name' => 'Starsza treść']],
            ]],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        Http::fake(function ($request) use ($product) {
            if ($request->method() === 'GET' && str_contains($request->url(), 'lang=en')) {
                return Http::response([[
                    'id' => 124,
                    'sku' => 'SKU-REFERENCE-RACE',
                    'lang' => 'en',
                    'translations' => ['pl' => 123, 'en' => 124],
                ]]);
            }

            if ($request->method() === 'PUT' && str_ends_with($request->url(), '/124')) {
                $fresh = $product->fresh();
                $attributes = (array) $fresh->attributes;
                data_set($attributes, 'master.media', [['src' => '/uploads/products/newer.jpg']]);
                data_set($attributes, 'master.content.pl.name', 'Nowsza treść ERP');
                $fresh->forceFill(['attributes' => $attributes])->save();
            }

            return Http::response([
                'id' => str_ends_with($request->url(), '/124') ? 124 : 123,
                'sku' => 'SKU-REFERENCE-RACE',
            ]);
        });

        app(ProductDataExportService::class)->export($product);

        $fresh = $product->fresh();
        $this->assertSame('/uploads/products/newer.jpg', data_get($fresh->attributes, 'master.media.0.src'));
        $this->assertSame('Nowsza treść ERP', data_get($fresh->attributes, 'master.content.pl.name'));
        $this->assertSame('124', data_get($fresh->attributes, 'woocommerce_translations.en.product_id'));
    }

    public function test_export_uses_exact_erp_gallery_and_can_remove_all_woocommerce_images(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response([
                'id' => 123,
                'sku' => 'SKU-EXACT-GALLERY',
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
            'sku' => 'SKU-EXACT-GALLERY',
            'name' => 'Dokładna galeria',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'media' => [
                        ['src' => '/uploads/products/new-second.jpg', 'alt' => 'Drugie'],
                        ['src' => '/uploads/products/new-first.jpg', 'alt' => 'Pierwsze'],
                    ],
                ],
                'woocommerce_images' => [
                    ['src' => 'https://shop.test/wp-content/uploads/stale.jpg'],
                ],
                'woocommerce_parent_images' => [
                    ['src' => 'https://shop.test/wp-content/uploads/stale-parent.jpg'],
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

        app(ProductDataExportService::class)->export($product);

        $firstRequest = Http::recorded()
            ->map(fn (array $entry) => $entry[0])
            ->first(fn ($request): bool => $request->method() === 'PUT');
        $this->assertCount(2, $firstRequest['images']);
        $this->assertStringEndsWith('/uploads/products/new-second.jpg', $firstRequest['images'][0]['src']);
        $this->assertStringEndsWith('/uploads/products/new-first.jpg', $firstRequest['images'][1]['src']);
        $this->assertStringNotContainsString('stale', json_encode($firstRequest['images']) ?: '');

        $attributes = (array) $product->attributes;
        data_set($attributes, 'master.media', []);
        $product->forceFill(['attributes' => $attributes])->save();

        app(ProductDataExportService::class)->export($product->fresh());

        $lastRequest = Http::recorded()
            ->map(fn (array $entry) => $entry[0])
            ->filter(fn ($request): bool => $request->method() === 'PUT')
            ->last();
        $this->assertSame([], $lastRequest['images']);
        $this->assertSame([], $product->fresh()->mediaImages());
        $this->assertNull($product->fresh()->imageUrl());
    }

    public function test_export_clears_removed_erp_variant_image_in_woocommerce(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response(['id' => 123, 'sku' => 'SET-CLEAR']),
            'https://shop.test/wp-json/wc/v3/products/123/variations/456' => Http::response(['id' => 456, 'sku' => 'SET-CLEAR-S']),
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
            'sku' => 'SET-CLEAR',
            'name' => 'Komplet',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variable',
                    'variant_attribute' => 'Rozmiar',
                    'media' => [],
                ],
            ],
        ]);
        $variant = Product::query()->create([
            'sku' => 'SET-CLEAR-S',
            'name' => 'Komplet S',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variation',
                    'media' => [],
                    'parameters' => [
                        ['name' => 'Rozmiar', 'value' => 'S', 'variation' => true],
                    ],
                ],
                'woocommerce_image' => [
                    'src' => 'https://shop.test/wp-content/uploads/stale-variant.jpg',
                ],
            ],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
            'metadata' => ['variant_attribute' => 'Rozmiar'],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_variation_id' => '456',
            'external_sku' => $variant->sku,
            'stock_sync_enabled' => true,
        ]);

        app(ProductDataExportService::class)->export($parent);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123/variations/456'
            && $request['image'] === []);
    }

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
            'https://shop.test/wp-json/wc/v3/products/124' => Http::response([
                'id' => 124,
                'sku' => 'POLYLANG-SKU',
                'name' => 'English product',
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

    public function test_export_updates_catalog_visibility_for_existing_translation_without_translated_content(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response([
                'id' => 123,
                'sku' => 'SKU-HIDDEN',
            ]),
            'https://shop.test/wp-json/wc/v3/products/124' => Http::response([
                'id' => 124,
                'sku' => 'SKU-HIDDEN',
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
            'sku' => 'SKU-HIDDEN',
            'name' => 'Ukryty produkt',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'catalog_visibility' => 'hidden',
                ],
                'woocommerce_translations' => [
                    'en' => [
                        'product_id' => '124',
                        'variation_id' => null,
                        'sku' => 'SKU-HIDDEN',
                    ],
                ],
            ],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'SKU-HIDDEN',
            'stock_sync_enabled' => true,
        ]);

        app(ProductDataExportService::class)->export($product);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123'
            && $request['catalog_visibility'] === 'hidden');
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124'
            && $request['catalog_visibility'] === 'hidden');
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
                        [
                            'id' => 124,
                            'sku' => 'SKU-DATE',
                            'lang' => 'en',
                            'translations' => ['pl' => 123, 'en' => 124],
                        ],
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
                    'media' => [
                        ['src' => '/uploads/products/shared-pl-en.jpg', 'alt' => 'Wspólne zdjęcie'],
                    ],
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
            && $request['date_created'] === '2026-07-15T09:30:00'
            && str_ends_with($request['images'][0]['src'], '/uploads/products/shared-pl-en.jpg'));

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'lang=en'));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124'
            && $request['date_created'] === '2026-07-15T09:30:00'
            && str_ends_with($request['images'][0]['src'], '/uploads/products/shared-pl-en.jpg'));
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

        $this->get(route('products.edit', $product))
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

    public function test_failed_bilingual_creation_can_be_resumed_without_duplicating_polish_product(): void
    {
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
            'settings' => ['product_import' => ['languages' => ['pl', 'en']]],
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-RESUME',
            'name' => 'Produkt PL',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => false,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'publication_status' => 'draft',
                    'content' => [
                        'pl' => ['name' => 'Produkt PL'],
                        'en' => ['name' => 'Product EN'],
                    ],
                ],
            ],
        ]);

        $retry = false;
        Http::fake(function ($request) use (&$retry) {
            if (! $retry && $request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc/v3/products') {
                return Http::response(['id' => 123, 'sku' => 'SKU-RESUME', 'name' => 'Produkt PL']);
            }

            if (! $retry && $request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc/v3/products?lang=en') {
                return Http::response([], 500);
            }

            if ($retry && $request->method() === 'PUT' && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123') {
                return Http::response(['id' => 123, 'sku' => 'SKU-RESUME', 'name' => 'Produkt PL']);
            }

            if ($retry && $request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc/v3/products?lang=en') {
                return Http::response(['id' => 223, 'name' => 'Product EN']);
            }

            if ($retry && $request->method() === 'PUT' && $request->url() === 'https://shop.test/wp-json/wc/v3/products/223') {
                return Http::response(['id' => 223, 'sku' => 'SKU-RESUME', 'name' => 'Product EN']);
            }

            throw new \RuntimeException('Unexpected request: '.$request->method().' '.$request->url());
        });

        $this->post(route('products.woocommerce.create', [$product, $integration]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, Http::recorded()->filter(fn ($pair): bool => $pair[0]->method() === 'POST'
            && $pair[0]->url() === 'https://shop.test/wp-json/wc/v3/products?lang=en')->count());

        $mapping = ProductChannelMapping::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('123', $mapping->external_product_id);
        $this->assertSame('creating', data_get($mapping->metadata, 'creation_state'));

        $retry = true;

        $this->post(route('products.woocommerce.create', [$product, $integration]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Wznowiono i dokończono synchronizację produktu w WooCommerce dla kanału B2C.');

        $this->assertSame('completed', data_get($mapping->refresh()->metadata, 'creation_state'));
        $this->assertSame('223', data_get($product->refresh()->attributes, 'woocommerce_translations.en.product_id'));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123');
        $this->assertSame(1, Http::recorded()->filter(fn ($pair): bool => $pair[0]->method() === 'POST'
            && $pair[0]->url() === 'https://shop.test/wp-json/wc/v3/products')->count());
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

    public function test_detached_variant_is_deleted_from_polish_and_english_woocommerce_products(): void
    {
        Http::fake(fn ($request) => Http::response(['id' => 123, 'sku' => 'SET-REMOVE']));

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
        ]);
        $parent = Product::query()->create([
            'sku' => 'SET-REMOVE',
            'name' => 'Komplet',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variable',
                    'content' => [
                        'pl' => ['name' => 'Komplet'],
                        'en' => ['name' => 'Set'],
                    ],
                ],
                'woocommerce_translations' => [
                    'en' => ['product_id' => '223', 'variation_id' => null, 'sku' => 'SET-REMOVE'],
                ],
            ],
        ]);
        $variant = Product::query()->create([
            'sku' => 'SET-REMOVE-S',
            'name' => 'Komplet S',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => ['source' => 'erp', 'product_type' => 'variation'],
                'woocommerce_translations' => [
                    'en' => ['product_id' => '223', 'variation_id' => '224', 'sku' => 'SET-REMOVE-S'],
                ],
            ],
        ]);
        $relation = ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'SET-REMOVE',
            'stock_sync_enabled' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_variation_id' => '124',
            'external_sku' => 'SET-REMOVE-S',
            'stock_sync_enabled' => true,
        ]);

        $this->delete(route('products.relations.destroy', [$parent, $relation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Wariant został odłączony od produktu.');

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/223/variations/224?force=true');
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123/variations/124?force=true');
        $this->assertDatabaseMissing('product_channel_mappings', ['product_id' => $variant->id]);
        $this->assertNull(data_get($variant->refresh()->attributes, 'woocommerce_translations.en'));
        $this->assertDatabaseHas('integration_sync_logs', [
            'operation' => 'delete_product_variation',
            'external_id' => '124',
            'status' => 'success',
        ]);
    }

    public function test_product_create_resumes_synchronization_when_channel_mapping_already_exists(): void
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
            ->assertSessionHas('status', 'Wznowiono i dokończono synchronizację produktu w WooCommerce dla kanału B2C.');

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123');
        $this->assertSame(1, ProductChannelMapping::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'product.woocommerce_created')->count());
    }

    public function test_export_creates_selected_erp_category_for_both_languages_and_maps_it_to_product(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/products/categories')) {
                $ids = [
                    'Odzież' => 40,
                    'Clothing' => 41,
                    'Koszule' => 50,
                    'Shirts' => 60,
                ];

                return Http::response(['id' => $ids[$request['name']] ?? 0]);
            }

            if ($request->method() === 'POST' && str_contains($request->url(), '/lemon-erp/v1/catalog/categories/translations')) {
                $translations = $request['translations'];

                return Http::response([
                    'linked' => true,
                    'translations' => $translations,
                    'translation_group' => 'category:'.implode('|', array_values($translations)),
                ]);
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
            'wp_api_username' => 'erp-user',
            'wp_api_password_encrypted' => Crypt::encryptString('application-password'),
            'settings' => ['product_import' => ['languages' => ['pl', 'en']]],
        ]);
        $parentCategory = ProductCategory::query()->create([
            'external_id' => 'ERP-ODZIEZ',
            'name' => 'Odzież',
            'slug' => 'odziez',
            'path' => 'Odzież',
            'metadata' => [
                'translations' => [
                    'en' => ['name' => 'Clothing', 'slug' => 'clothing'],
                ],
            ],
        ]);
        $category = ProductCategory::query()->create([
            'external_id' => 'ERP-KOSZULE',
            'parent_external_id' => $parentCategory->external_id,
            'name' => 'Koszule',
            'slug' => 'koszule',
            'path' => 'Odzież > Koszule',
            'metadata' => [
                'translations' => [
                    'en' => ['name' => 'Shirts', 'slug' => 'shirts'],
                ],
            ],
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
            && $request['name'] === 'Odzież'
            && ! isset($request['parent']));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/categories?lang=en')
            && $request['name'] === 'Clothing'
            && $request['translations'] === ['pl' => 40]
            && ! isset($request['parent']));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/categories?lang=pl')
            && $request['name'] === 'Koszule'
            && $request['parent'] === 40);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/categories?lang=en')
            && $request['name'] === 'Shirts'
            && $request['parent'] === 41
            && $request['translations'] === ['pl' => 50]);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/lemon-erp/v1/catalog/categories/translations'
            && $request['translations'] === ['pl' => 50, 'en' => 60]);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123'
            && $request['categories'] === [['id' => 50]]);

        $category->refresh();
        $this->assertSame((string) $channel->id, (string) $category->sales_channel_id);
        $this->assertSame('50', data_get($category->metadata, 'woocommerce_ids.pl'));
        $this->assertSame('60', data_get($category->metadata, 'woocommerce_ids.en'));
        $this->assertSame('category:50|60', data_get($category->metadata, 'polylang.translation_group'));
        $this->assertSame('40', data_get($parentCategory->fresh()->metadata, 'woocommerce_ids.pl'));
        $this->assertSame('41', data_get($parentCategory->fresh()->metadata, 'woocommerce_ids.en'));
    }

    public function test_export_uses_inline_then_dictionary_parameter_translations_for_english_product(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response(['id' => 123, 'sku' => 'SKU-I18N']),
            'https://shop.test/wp-json/wc/v3/products/124' => Http::response(['id' => 124, 'sku' => 'SKU-I18N']),
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
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S', 'M'],
            'values_en' => ['Small', 'Medium'],
            'is_variant' => true,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Kolor',
            'name_en' => 'Colour',
            'slug' => 'kolor',
            'input_type' => 'select',
            'values' => ['Czerwony', 'Niebieski'],
            'values_en' => ['Red', 'Blue'],
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-I18N',
            'name' => 'Koszula',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'content' => [
                        'pl' => ['name' => 'Koszula'],
                        'en' => ['name' => 'Shirt'],
                    ],
                    'parameters' => [
                        [
                            'name' => 'Rozmiar',
                            'value' => 'S',
                            'name_en' => 'Sizing',
                            'value_en' => 'Petite',
                        ],
                        ['name' => 'Kolor', 'value' => 'Czerwony'],
                    ],
                ],
                'woocommerce_translations' => [
                    'en' => ['product_id' => '124', 'variation_id' => null, 'sku' => 'SKU-I18N'],
                ],
            ],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'SKU-I18N',
            'stock_sync_enabled' => true,
        ]);

        app(ProductDataExportService::class)->export($product);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123'
            && $request['attributes'] === [
                ['name' => 'Rozmiar', 'visible' => true, 'variation' => false, 'options' => ['S']],
                ['name' => 'Kolor', 'visible' => true, 'variation' => false, 'options' => ['Czerwony']],
            ]);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124'
            && $request['attributes'] === [
                ['name' => 'Sizing', 'visible' => true, 'variation' => false, 'options' => ['Petite']],
                ['name' => 'Colour', 'visible' => true, 'variation' => false, 'options' => ['Red']],
            ]);
    }

    public function test_category_translation_link_requires_wordpress_application_credentials(): void
    {
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Brak loginu i hasła aplikacji WordPress REST wymaganych do powiązania tłumaczeń kategorii Polylang.');

        app(WooCommerceClient::class)->linkProductCategoryTranslations($integration, ['pl' => 50, 'en' => 60]);
    }

    public function test_category_translation_link_reports_outdated_wordpress_plugin(): void
    {
        Http::fake([
            'https://shop.test/wp-json/lemon-erp/v1/catalog/categories/translations' => Http::response([
                'code' => 'rest_no_route',
                'message' => 'No route was found.',
            ], 404),
        ]);
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
            'wp_api_username' => 'erp-user',
            'wp_api_password_encrypted' => Crypt::encryptString('application-password'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wtyczki Lemon ERP co najmniej 0.3.0');

        app(WooCommerceClient::class)->linkProductCategoryTranslations($integration, ['pl' => 50, 'en' => 60]);
    }

    public function test_category_translation_link_reports_a_missing_remote_category_instead_of_an_outdated_plugin(): void
    {
        Http::fake([
            'https://shop.test/wp-json/lemon-erp/v1/catalog/categories/translations' => Http::response([
                'code' => 'lemon_erp_category_not_found',
                'message' => 'Nie znaleziono kategorii WooCommerce ID 60.',
            ], 404),
        ]);
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
            'wp_api_username' => 'erp-user',
            'wp_api_password_encrypted' => Crypt::encryptString('application-password'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nie znaleziono kategorii WooCommerce ID 60.');

        app(WooCommerceClient::class)->linkProductCategoryTranslations($integration, ['pl' => 50, 'en' => 60]);
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
                    'media' => [
                        ['src' => '/uploads/products/shared-first.jpg', 'alt' => 'Pierwsze wspólne'],
                        ['src' => '/uploads/products/shared-second.jpg', 'alt' => 'Drugie wspólne'],
                    ],
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

        $languageRequests = Http::recorded()
            ->map(fn (array $entry) => $entry[0])
            ->filter(fn ($request): bool => $request->method() === 'PUT'
                && in_array($request->url(), [
                    'https://shop.test/wp-json/wc/v3/products/123',
                    'https://shop.test/wp-json/wc/v3/products/124',
                ], true))
            ->keyBy(fn ($request): string => $request->url());
        $this->assertSame(
            $languageRequests['https://shop.test/wp-json/wc/v3/products/123']['images'],
            $languageRequests['https://shop.test/wp-json/wc/v3/products/124']['images'],
        );
        $this->assertCount(2, $languageRequests['https://shop.test/wp-json/wc/v3/products/123']['images']);

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

    public function test_export_discovers_existing_english_variant_instead_of_creating_duplicate(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), 'lang=en')) {
                return Http::response([[
                    'id' => 124,
                    'sku' => 'SET-LEGACY',
                    'lang' => 'en',
                    'translations' => ['pl' => 123, 'en' => 124],
                ]]);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/products/124/variations')) {
                return Http::response([[
                    'id' => 457,
                    'sku' => '',
                    'attributes' => [[
                        'name' => 'Size',
                        'option' => 'Small',
                    ]],
                ]]);
            }

            return match ([$request->method(), $request->url()]) {
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/123'] => Http::response(['id' => 123, 'sku' => 'SET-LEGACY']),
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/124'] => Http::response(['id' => 124, 'sku' => 'SET-LEGACY']),
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/123/variations/456'] => Http::response(['id' => 456, 'sku' => 'SET-LEGACY-S']),
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/124/variations/457'] => Http::response(['id' => 457, 'sku' => 'SET-LEGACY-S']),
                default => Http::response([], 404),
            };
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
        $parent = Product::query()->create([
            'sku' => 'SET-LEGACY',
            'name' => 'Komplet legacy',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'content' => [
                    'pl' => ['name' => 'Komplet legacy'],
                    'en' => ['name' => 'Legacy set'],
                ],
            ]],
        ]);
        $variant = Product::query()->create([
            'sku' => 'SET-LEGACY-S',
            'name' => 'Komplet legacy - S',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'media' => [['src' => '/uploads/products/legacy-variant-new.jpg']],
                'media_updated_at' => now()->toISOString(),
                'parameters' => [[
                    'name' => 'Rozmiar',
                    'value' => 'S',
                    'name_en' => 'Size',
                    'value_en' => 'Small',
                    'variation' => true,
                ]],
            ]],
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
            'external_product_id' => '123',
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_variation_id' => '456',
            'external_sku' => $variant->sku,
            'stock_sync_enabled' => true,
        ]);

        app(ProductDataExportService::class)->export($parent);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124/variations/457'
            && str_ends_with($request['image']['src'], '/uploads/products/legacy-variant-new.jpg'));
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/variations'));
        $this->assertSame('124', data_get($parent->fresh()->attributes, 'woocommerce_translations.en.product_id'));
        $this->assertSame('457', data_get($variant->fresh()->attributes, 'woocommerce_translations.en.variation_id'));
    }

    public function test_export_creates_new_variant_for_polish_and_english_polylang_parents(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), 'lang=en')) {
                return Http::response([[
                    'id' => 124,
                    'sku' => 'SET-NEW',
                    'lang' => 'en',
                    'translations' => ['pl' => 123, 'en' => 124],
                ]]);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/products/124/variations')) {
                return Http::response([]);
            }

            return match ([$request->method(), $request->url()]) {
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/123'] => Http::response(['id' => 123, 'sku' => 'SET-NEW']),
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/124'] => Http::response(['id' => 124, 'sku' => 'SET-NEW']),
                ['POST', 'https://shop.test/wp-json/wc/v3/products/123/variations'] => Http::response(['id' => 456, 'sku' => 'SEM-NEW-S'], 201),
                ['POST', 'https://shop.test/wp-json/wc/v3/products/124/variations'] => Http::response(['id' => 457, 'sku' => 'SEM-NEW-S'], 201),
                default => Http::response([], 404),
            };
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
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S'],
            'values_en' => ['Small'],
            'is_variant' => true,
        ]);
        $parent = Product::query()->create([
            'sku' => 'SET-NEW',
            'name' => 'Nowy komplet',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variable',
                    'variant_attribute' => 'Rozmiar',
                    'content' => [
                        'pl' => ['name' => 'Nowy komplet'],
                        'en' => ['name' => 'New set'],
                    ],
                ],
            ],
        ]);
        $variant = Product::query()->create([
            'sku' => 'SEM-NEW-S',
            'ean' => '5901234567890',
            'name' => 'Nowy komplet - S',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'media' => [
                    ['src' => '/uploads/products/shared-variant-pl-en.jpg', 'alt' => 'Wspólne zdjęcie wariantu'],
                ],
                'media_updated_at' => now()->toISOString(),
                'parameters' => [[
                    'name' => 'Rozmiar',
                    'value' => 'S',
                    'name_en' => 'Sizing',
                    'value_en' => 'Petite',
                    'variation' => true,
                ]],
            ]],
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
            'external_product_id' => '123',
            'external_sku' => 'SET-NEW',
            'stock_sync_enabled' => true,
        ]);

        app(ProductDataExportService::class)->export($parent);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123/variations'
            && $request['sku'] === 'SEM-NEW-S'
            && $request['global_unique_id'] === '5901234567890'
            && str_ends_with($request['image']['src'], '/uploads/products/shared-variant-pl-en.jpg'));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124/variations'
            && $request['sku'] === 'SEM-NEW-S'
            && $request['attributes'][0]['name'] === 'Sizing'
            && $request['attributes'][0]['option'] === 'Petite'
            && str_ends_with($request['image']['src'], '/uploads/products/shared-variant-pl-en.jpg'));
        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $variant->id,
            'external_product_id' => '123',
            'external_variation_id' => '456',
        ]);
        $this->assertSame('124', data_get($variant->fresh()->attributes, 'woocommerce_translations.en.product_id'));
        $this->assertSame('457', data_get($variant->fresh()->attributes, 'woocommerce_translations.en.variation_id'));
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
