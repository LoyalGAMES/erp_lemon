<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Jobs\ImportWooCommerceProductsJob;
use App\Models\AuditLog;
use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\ProductDataExportService;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCommerceProductTranslationNotReadyException;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WooCommerceProductDataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_translation_handoff_prefers_its_exact_parent_alias(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'EXACT-HANDOFF-ALIAS',
            'name' => 'Exact handoff alias',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'EXACT-HANDOFF-PARENT',
            'name' => 'Exact handoff parent',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
            ]],
        ]);
        $attributes = (array) $product->attributes;
        data_set($attributes, 'master.'.WooOwnedVariantAxisRepairService::STATE_PATH, [
            'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_EXACT_TRANSLATION_HANDOFF_ALIAS_REVISION,
            'canonical_full_export_handoff_at' => now()->toISOString(),
            'rebuild_simple_translations' => [[
                'language' => 'en',
                'external_product_id' => '223',
            ]],
        ]);
        $product->forceFill(['attributes' => $attributes])->save();
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);

        foreach (['123' => 'stale_import', '223' => 'verified_contract'] as $externalId => $source) {
            $externalId = (string) $externalId;
            ProductChannelAlias::query()->create([
                'product_id' => $product->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => $externalId,
                'external_variation_id' => null,
                'external_key' => ProductChannelAlias::externalKey($externalId, null),
                'external_sku' => $product->sku,
                'language' => 'en',
                'metadata' => ['source' => $source],
            ]);
        }

        $references = (new \ReflectionMethod(
            ProductDataExportService::class,
            'translationReferences',
        ))->invoke(
            app(ProductDataExportService::class),
            $product,
            $channel->id,
        );

        $this->assertSame('223', data_get($references, 'en.product_id'));
        $this->assertNull(data_get($references, 'en.variation_id'));
    }

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
            'settings' => ['product_export' => ['languages' => ['pl']]],
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

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PUT' || $request->url() !== 'https://shop.test/wp-json/wc/v3/products/123') {
                return false;
            }

            $meta = collect($request['meta_data'])->pluck('value', 'key');

            return $request['catalog_visibility'] === 'hidden'
                && ! array_key_exists('low_stock_amount', $request->data())
                && $request['images'][0]['src'] === 'https://shop.test/wp-content/uploads/legacy.jpg'
                && $meta['lemon_shipping_days'] === ''
                && $meta['lemon_shipping_text'] === ''
                && $meta['lemon_preorder'] === 'no';
        });
        $this->assertNull(data_get(
            ProductChannelMapping::query()->where('product_id', $product->id)->firstOrFail()->metadata,
            'product_data_export.pending_token',
        ));
    }

    public function test_replacing_erp_product_image_dispatches_immediate_export_with_only_the_new_gallery(): void
    {
        Bus::fake();
        Http::fake(function ($request) {
            if ($request->method() === 'GET'
                && str_ends_with($request->url(), '/catalog/products/translations/capabilities')
            ) {
                return Http::response($this->readyProductTranslationCapabilities());
            }

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
                'https://shop.test/wp-json/wc/v3/products/124?lang=en',
            ], true)) {
                return Http::response([
                    'id' => str_ends_with(
                        (string) parse_url($request->url(), PHP_URL_PATH),
                        '/124',
                    ) ? 124 : 123,
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
            if ($request->method() !== 'PUT' || $request->url() !== 'https://shop.test/wp-json/wc/v3/products/124?lang=en') {
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

        $this->assertCount(2, $job->middleware());
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
            'settings' => ['product_export' => ['languages' => ['pl']]],
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

    public function test_manual_export_does_not_overlap_an_active_catalog_import(): void
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
            ImportWooCommerceProductsJob::catalogLockKey($integration->id),
            ImportWooCommerceProductsJob::CATALOG_LOCK_SECONDS,
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

        $releasedFamilyLock = Cache::lock(
            ExportWooCommerceProductDataJob::lockKey($product->id),
            ExportWooCommerceProductDataJob::LOCK_SECONDS,
        );
        $this->assertTrue($releasedFamilyLock->get());
        $releasedFamilyLock->release();
    }

    public function test_manual_creation_does_not_overlap_an_active_catalog_import(): void
    {
        Http::fake();
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-CREATE-LOCKED',
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
        $product = Product::query()->create([
            'sku' => 'SKU-CREATE-LOCKED',
            'name' => 'Produkt oczekujący',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => ['source' => 'erp']],
        ]);
        $lock = Cache::lock(
            ImportWooCommerceProductsJob::catalogLockKey($integration->id),
            ImportWooCommerceProductsJob::CATALOG_LOCK_SECONDS,
        );
        $this->assertTrue($lock->get());

        try {
            $this->post(route('products.woocommerce.create', [$product, $integration]))
                ->assertRedirect()
                ->assertSessionHas('status');
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('product_channel_mappings', 0);
    }

    public function test_manual_creation_for_a_variant_uses_the_parent_family_lock(): void
    {
        Http::fake();
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-CREATE-VARIANT-LOCKED',
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
        $parent = Product::query()->create([
            'sku' => 'SKU-CREATE-FAMILY',
            'name' => 'Rodzina synchronizowana',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
            ]],
        ]);
        $variant = Product::query()->create([
            'sku' => 'SKU-CREATE-FAMILY-S',
            'name' => 'Wariant S',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'parameters' => [[
                    'name' => 'Rozmiar',
                    'value' => 'S',
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
        $lock = Cache::lock(
            ExportWooCommerceProductDataJob::lockKey($parent->id),
            ExportWooCommerceProductDataJob::LOCK_SECONDS,
        );
        $this->assertTrue($lock->get());

        try {
            $this->post(route('products.woocommerce.create', [$variant, $integration]))
                ->assertRedirect()
                ->assertSessionHas('status');
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('product_channel_mappings', 0);
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
            if ($request->method() === 'GET'
                && str_ends_with($request->url(), '/catalog/products/translations/capabilities')
            ) {
                return Http::response($this->readyProductTranslationCapabilities());
            }

            if ($request->method() === 'GET' && str_contains($request->url(), 'lang=en')) {
                return Http::response([[
                    'id' => 124,
                    'sku' => 'SKU-REFERENCE-RACE',
                    'lang' => 'en',
                    'translations' => ['pl' => 123, 'en' => 124],
                ]]);
            }

            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'PUT' && str_ends_with($path, '/124')) {
                $fresh = $product->fresh();
                $attributes = (array) $fresh->attributes;
                data_set($attributes, 'master.media', [['src' => '/uploads/products/newer.jpg']]);
                data_set($attributes, 'master.content.pl.name', 'Nowsza treść ERP');
                $fresh->forceFill(['attributes' => $attributes])->save();
            }

            return Http::response([
                'id' => str_ends_with($path, '/124') ? 124 : 123,
                'sku' => 'SKU-REFERENCE-RACE',
            ]);
        });

        app(ProductDataExportService::class)->export($product);

        $fresh = $product->fresh();
        $this->assertSame('/uploads/products/newer.jpg', data_get($fresh->attributes, 'master.media.0.src'));
        $this->assertSame('Nowsza treść ERP', data_get($fresh->attributes, 'master.content.pl.name'));
        $this->assertSame('124', data_get($fresh->attributes, 'woocommerce_translations.en.product_id'));
    }

    public function test_legacy_translation_ids_are_discovered_and_scoped_separately_for_two_stores(): void
    {
        $channelA = SalesChannel::query()->create([
            'code' => 'SHOP-A',
            'name' => 'Sklep A',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $channelB = SalesChannel::query()->create([
            'code' => 'SHOP-B',
            'name' => 'Sklep B',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        foreach ([[$channelA, 'https://a.test'], [$channelB, 'https://b.test']] as [$channel, $url]) {
            WordpressIntegration::query()->create([
                'sales_channel_id' => $channel->id,
                'name' => $channel->name,
                'base_url' => $url,
                'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
                'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            ]);
        }

        $product = Product::query()->create([
            'sku' => 'MULTI-STORE-SKU',
            'name' => 'Produkt wielokanałowy',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'woocommerce_translations' => [
                    // Legacy attributes have no channel and point to store A.
                    'en' => ['product_id' => '101', 'variation_id' => null, 'sku' => 'MULTI-STORE-SKU'],
                ],
                'master' => [
                    'source' => 'erp',
                    'publication_date' => '2026-07-14T12:00',
                    'content' => [
                        'pl' => ['name' => 'Produkt wielokanałowy'],
                        'en' => ['name' => 'Multi-store product'],
                    ],
                ],
            ],
        ]);

        foreach ([[$channelA, '100'], [$channelB, '200']] as [$channel, $externalId]) {
            ProductChannelMapping::query()->create([
                'product_id' => $product->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => $externalId,
                'external_sku' => $product->sku,
                'stock_sync_enabled' => true,
            ]);
        }

        Http::fake(function ($request) {
            $url = $request->url();

            if ($request->method() === 'GET'
                && str_ends_with($url, '/catalog/products/translations/capabilities')
            ) {
                return Http::response($this->readyProductTranslationCapabilities());
            }

            $isStoreA = str_starts_with($url, 'https://a.test/');
            $primaryId = $isStoreA ? 100 : 200;
            $englishId = $isStoreA ? 101 : 201;

            if ($request->method() === 'GET' && str_contains($url, 'lang=en')) {
                return Http::response([[
                    'id' => $englishId,
                    'sku' => 'MULTI-STORE-SKU',
                    'lang' => 'en',
                    'translations' => ['pl' => $primaryId, 'en' => $englishId],
                ]]);
            }

            if ($request->method() === 'PUT' && in_array($url, [
                "https://a.test/wp-json/wc/v3/products/{$primaryId}",
                "https://a.test/wp-json/wc/v3/products/{$englishId}?lang=en",
                "https://b.test/wp-json/wc/v3/products/{$primaryId}",
                "https://b.test/wp-json/wc/v3/products/{$englishId}?lang=en",
            ], true)) {
                return Http::response([
                    'id' => (int) basename((string) parse_url($url, PHP_URL_PATH)),
                    'sku' => 'MULTI-STORE-SKU',
                ]);
            }

            return Http::response([], 404);
        });

        app(ProductDataExportService::class)->export($product);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://a.test/wp-json/wc/v3/products/101?lang=en');
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://b.test/wp-json/wc/v3/products/201?lang=en');
        Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT'
            && in_array($request->url(), [
                'https://a.test/wp-json/wc/v3/products/201',
                'https://b.test/wp-json/wc/v3/products/101',
            ], true));
        $this->assertDatabaseHas('product_channel_aliases', [
            'product_id' => $product->id,
            'sales_channel_id' => $channelA->id,
            'external_product_id' => '101',
            'language' => 'en',
        ]);
        $this->assertDatabaseHas('product_channel_aliases', [
            'product_id' => $product->id,
            'sales_channel_id' => $channelB->id,
            'external_product_id' => '201',
            'language' => 'en',
        ]);
    }

    public function test_discovery_cannot_hijack_an_external_alias_owned_by_another_product(): void
    {
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
        $owner = Product::query()->create([
            'sku' => 'ALIAS-OWNER',
            'name' => 'Właściciel aliasu',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelAlias::query()->create([
            'product_id' => $owner->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '999',
            'external_variation_id' => null,
            'external_key' => ProductChannelAlias::externalKey('999', null),
            'external_sku' => $owner->sku,
            'language' => 'en',
        ]);
        $target = Product::query()->create([
            'sku' => 'ALIAS-TARGET',
            'name' => 'Produkt docelowy',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'publication_date' => '2026-07-14T12:00',
                'content' => [
                    'pl' => ['name' => 'Produkt docelowy'],
                    'en' => ['name' => 'Target product'],
                ],
            ]],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $target->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $target->sku,
            'stock_sync_enabled' => true,
        ]);

        Http::fake(function ($request) {
            if ($request->method() === 'GET'
                && str_ends_with($request->url(), '/catalog/products/translations/capabilities')
            ) {
                return Http::response($this->readyProductTranslationCapabilities());
            }

            if ($request->method() === 'GET' && str_contains($request->url(), 'lang=en')) {
                return Http::response([[
                    'id' => 999,
                    'sku' => 'ALIAS-TARGET',
                    'lang' => 'en',
                    'translations' => ['pl' => 123, 'en' => 999],
                ]]);
            }

            return Http::response(['id' => str_ends_with($request->url(), '/999') ? 999 : 123, 'sku' => 'ALIAS-TARGET']);
        });

        try {
            app(ProductDataExportService::class)->export($target);
            $this->fail('Próba przejęcia aliasu powinna przerwać eksport.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('jest już przypisany do innego produktu ERP', $exception->getMessage());
        }

        $this->assertSame($owner->id, ProductChannelAlias::query()
            ->where('sales_channel_id', $channel->id)
            ->where('external_key', ProductChannelAlias::externalKey('999', null))
            ->firstOrFail()
            ->product_id);
        $this->assertNull(data_get($target->fresh()->attributes, 'woocommerce_translations.en'));
    }

    public function test_existing_non_english_translation_keeps_its_configured_language_payload(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'DE-STORE',
            'name' => 'Sklep niemiecki',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'DE Woo',
            'base_url' => 'https://de.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'de']]],
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-DE',
            'name' => 'Koszula',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'content' => [
                    'pl' => ['name' => 'Koszula', 'description' => '<p>Opis PL</p>'],
                    'en' => ['name' => 'Shirt', 'description' => '<p>English description</p>'],
                    'de' => ['name' => 'Hemd', 'description' => '<p>Deutsche Beschreibung</p>'],
                ],
            ]],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '300',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        ProductChannelAlias::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '301',
            'external_variation_id' => null,
            'external_key' => ProductChannelAlias::externalKey('301', null),
            'external_sku' => $product->sku,
            'language' => 'de',
        ]);

        Http::fake([
            'https://de.test/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities' => Http::response(array_replace(
                $this->readyProductTranslationCapabilities(),
                ['languages' => ['pl', 'de']],
            )),
            'https://de.test/wp-json/wc/v3/products/300' => Http::response(['id' => 300, 'sku' => 'SKU-DE']),
            'https://de.test/wp-json/wc/v3/products/301?lang=de' => Http::response(['id' => 301, 'sku' => 'SKU-DE']),
        ]);

        app(ProductDataExportService::class)->export($product);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://de.test/wp-json/wc/v3/products/301?lang=de'
            && $request['name'] === 'Hemd'
            && $request['description'] === '<p>Deutsche Beschreibung</p>');
    }

    public function test_export_uses_exact_erp_gallery_and_can_remove_all_woocommerce_images(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities' => Http::response($this->readyProductTranslationCapabilities()),
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
            'settings' => ['product_export' => ['languages' => ['pl']]],
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
        $this->createDefaultSizeDictionary();
        $this->fakeWooWithGlobalAttributes([
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
        $this->createDefaultSizeDictionary(['One size']);
        $this->fakeWooWithGlobalAttributes([
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
            'settings' => ['product_export' => ['languages' => ['pl']]],
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

        $request = Http::recorded()
            ->map(fn (array $entry) => $entry[0])
            ->first(fn ($recordedRequest): bool => $recordedRequest->method() === 'PUT'
                && $recordedRequest->url() === 'https://shop.test/wp-json/wc/v3/products/123');

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
        $this->assertSame(70, $request['attributes'][0]['id']);
        $this->assertArrayNotHasKey('name', $request['attributes'][0]);
        $this->assertArrayNotHasKey('source_name', $request['attributes'][0]);
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
            'settings' => ['product_export' => ['languages' => ['pl']]],
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

        $request = Http::recorded()
            ->map(fn (array $entry) => $entry[0])
            ->first(fn ($recordedRequest): bool => $recordedRequest->method() === 'PUT'
                && $recordedRequest->url() === 'https://shop.test/wp-json/wc/v3/products/123');
        $this->assertArrayNotHasKey('sku', $request->data());
        $this->assertSame('preserved_remote_duplicate', data_get(
            ProductChannelMapping::query()->where('product_id', $parent->id)->firstOrFail()->metadata,
            'last_product_export_sku_status',
        ));
    }

    public function test_export_sends_sku_shared_with_polylang_translation(): void
    {
        Http::fake([
            'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities' => Http::response($this->readyProductTranslationCapabilities()),
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response([
                'id' => 123,
                'sku' => 'POLYLANG-SKU',
                'name' => 'Polish product',
            ]),
            'https://shop.test/wp-json/wc/v3/products/124?lang=en' => Http::response([
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

        [$request] = Http::recorded()->first(fn (array $record): bool => $record[0]->method() === 'PUT'
            && $record[0]->url() === 'https://shop.test/wp-json/wc/v3/products/123');
        $this->assertSame('POLYLANG-SKU', $request['sku']);
    }

    public function test_export_updates_catalog_visibility_for_existing_translation_without_translated_content(): void
    {
        $this->fakeWooWithGlobalAttributes([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response([
                'id' => 123,
                'sku' => 'SKU-HIDDEN',
            ]),
            'https://shop.test/wp-json/wc/v3/products/124?lang=en' => Http::response([
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
                    'parameters' => [[
                        'name' => 'Kolor',
                        'name_en' => 'Colour',
                        'value' => 'Czarny',
                        'value_en' => 'Black',
                    ]],
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
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124?lang=en'
            && $request['catalog_visibility'] === 'hidden');

        $requests = Http::recorded()->map(fn (array $record) => $record[0])->values();
        $termLinkIndex = $requests->search(fn ($request): bool => $request->method() === 'POST'
            && preg_match('#/catalog/products/attributes/\d+/terms/translations$#', $request->url()) === 1);
        $polishUpdateIndex = $requests->search(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123');

        $this->assertIsInt($termLinkIndex);
        $this->assertIsInt($polishUpdateIndex);
        $this->assertLessThan($polishUpdateIndex, $termLinkIndex);
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
            'settings' => ['product_export' => ['languages' => ['pl']]],
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

            if ($request->method() === 'GET'
                && str_ends_with($url, '/catalog/products/translations/capabilities')
            ) {
                return Http::response($this->readyProductTranslationCapabilities());
            }

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

            if ($request->method() === 'PUT' && $url === 'https://shop.test/wp-json/wc/v3/products/124?lang=en') {
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
            && $request['date_created'] === '2026-07-15T09:30:00+02:00'
            && str_ends_with($request['images'][0]['src'], '/uploads/products/shared-pl-en.jpg'));

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'lang=en'));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124?lang=en'
            && $request['date_created'] === '2026-07-15T09:30:00+02:00'
            && str_ends_with($request['images'][0]['src'], '/uploads/products/shared-pl-en.jpg'));
    }

    public function test_erp_product_can_be_created_in_unmapped_woocommerce_channel(): void
    {
        $this->fakeWooWithGlobalAttributes([
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

        $request = Http::recorded()
            ->map(fn (array $entry) => $entry[0])
            ->first(fn ($recordedRequest): bool => $recordedRequest->method() === 'POST'
                && $recordedRequest->url() === 'https://shop.test/wp-json/wc/v3/products');
        $this->assertSame('Komplet ERP', $request['name']);
        $this->assertSame('SKU-CREATE', $request['sku']);
        $this->assertSame('499.00', $request['regular_price']);
        $this->assertSame('<p>Opis kompletu</p>', $request['description']);
        $this->assertSame('<p>Krótki opis</p>', $request['short_description']);
        $this->assertStringEndsWith('/uploads/products/10/komplet.jpg', $request['images'][0]['src']);
        $this->assertSame(70, $request['attributes'][0]['id']);
        $this->assertArrayNotHasKey('name', $request['attributes'][0]);

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
            if ($request->method() === 'GET'
                && str_ends_with($request->url(), '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities')
            ) {
                return Http::response($this->readyProductTranslationCapabilities());
            }

            if ($request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc/v3/products') {
                return Http::response(['id' => 555, 'sku' => 'SKU-BILINGUAL'], 201);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc/v3/products?lang=en') {
                return Http::response(['id' => 556, 'sku' => ''], 201);
            }

            if ($request->method() === 'PUT' && $request->url() === 'https://shop.test/wp-json/wc/v3/products/556?lang=en') {
                return Http::response(['id' => 556, 'sku' => $request['sku']]);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations') {
                return Http::response([
                    'linked' => true,
                    'translations' => ['en' => 556, 'pl' => 555],
                    'translation_group' => 'product:555|556',
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
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-BILINGUAL',
            'ean' => '5901234567890',
            'name' => 'Produkt polski',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'publication_status' => 'publish',
                'catalog_visibility' => 'catalog',
                'content' => [
                    'pl' => ['name' => 'Produkt polski'],
                    'en' => ['name' => 'English product'],
                ],
                'prices' => ['retail_price_pln' => 129.99],
            ]],
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'WH-BILINGUAL',
            'name' => 'Magazyn bilingual',
            'type' => 'own',
            'allow_negative_stock' => false,
            'is_active' => true,
        ]);
        $balance = StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 7,
            'quantity_reserved' => 0,
            'quantity_available' => 7,
        ]);
        $product->load('stockBalances');
        $this->assertSame(7, (int) $product->stockBalances->sum('quantity_available'));
        StockBalance::query()->whereKey($balance->id)->update([
            'quantity_on_hand' => 11,
            'quantity_available' => 11,
        ]);
        $this->assertSame(7, (int) $product->stockBalances->sum('quantity_available'));

        $result = app(ProductDataExportService::class)->create($product, $integration);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products'
            && $request['manage_stock'] === true
            && $request['stock_quantity'] === 11
            && $request['stock_status'] === 'instock');
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products?lang=en'
            && $request['name'] === 'English product'
            && str_starts_with((string) $request['sku'], 'LEMON-TR-')
            && $request['status'] === 'draft'
            && $request['catalog_visibility'] === 'hidden'
            && $request['manage_stock'] === true
            && $request['stock_quantity'] === 0
            && $request['stock_status'] === 'outofstock'
            && $request['backorders'] === 'no'
            && ! isset($request['global_unique_id'])
            && collect((array) $request['meta_data'])->doesntContain(
                fn (array $meta): bool => in_array($meta['key'] ?? null, ['_ean', '_sempre_erp_ean'], true),
            )
            && ! isset($request['translations']));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/556?lang=en'
            && $request['sku'] === 'SKU-BILINGUAL'
            && $request['global_unique_id'] === '5901234567890'
            && $request['status'] === 'publish'
            && $request['catalog_visibility'] === 'catalog'
            && $request['manage_stock'] === true
            && $request['stock_quantity'] === 11
            && $request['stock_status'] === 'instock'
            && $request['backorders'] === 'no'
            && collect((array) $request['meta_data'])->contains(
                fn (array $meta): bool => ($meta['key'] ?? null) === '_ean'
                    && ($meta['value'] ?? null) === '5901234567890',
            )
            && collect((array) $request['meta_data'])->contains(
                fn (array $meta): bool => ($meta['key'] ?? null) === '_sempre_erp_ean'
                    && ($meta['value'] ?? null) === '5901234567890',
            ));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations'
            && $request['translations'] === ['en' => 556, 'pl' => 555]);
        $this->assertSame('556', data_get($product->fresh()->attributes, 'woocommerce_translations.en.product_id'));
        $this->assertSame('SKU-BILINGUAL', data_get($product->fresh()->attributes, 'woocommerce_translations.en.sku'));
        $this->assertDatabaseHas('product_channel_aliases', [
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '556',
            'language' => 'en',
        ]);
        $this->assertCount(1, $result['translation_responses']);
    }

    public function test_existing_translated_simple_product_is_not_mutated_by_plugin_0_5_2(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-SIMPLE-OLD-PLUGIN',
            'name' => 'B2C simple old plugin',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo old plugin',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);
        $product = Product::query()->create([
            'sku' => 'SIMPLE-OLD-PLUGIN',
            'ean' => '5901234567890',
            'name' => 'Simple PL',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'simple',
                'content' => [
                    'pl' => ['name' => 'Simple PL'],
                    'en' => ['name' => 'Simple EN'],
                ],
            ]],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '6100',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        Http::fake(fn () => Http::response([
            'available' => true,
            'attribute_term_translation_link_available' => true,
            'languages' => ['pl', 'en'],
            'plugin_version' => '0.5.2',
        ]));

        try {
            app(ProductDataExportService::class)->export($product);
            $this->fail('Plugin 0.5.2 must not receive canonical translated GTIN writes.');
        } catch (WooCommerceProductTranslationNotReadyException $exception) {
            $this->assertStringContainsString('0.5.3', $exception->getMessage());
        }

        $this->assertCount(1, Http::recorded());
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_ends_with($request->url(), '/catalog/products/translations/capabilities'));
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true));
    }

    public function test_existing_polish_only_simple_product_exports_without_translation_capability_probe(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-SIMPLE-PL',
            'name' => 'B2C simple PL',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo PL only',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl']]],
        ]);
        $product = Product::query()->create([
            'sku' => 'SIMPLE-PL-ONLY',
            'name' => 'Simple PL only',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'simple',
                'content' => ['pl' => ['name' => 'Simple PL only']],
            ]],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '6200',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
        Http::fake(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/6200'
                ? Http::response(['id' => 6200, 'sku' => $request['sku']])
                : Http::response([], 404));

        $result = app(ProductDataExportService::class)->export($product);

        $this->assertSame(1, $result['exported']);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/6200');
        Http::assertNotSent(fn ($request): bool => str_ends_with(
            $request->url(),
            '/catalog/products/translations/capabilities',
        ));
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
                    'publication_date' => '2026-07-14T13:47',
                    'content' => [
                        'pl' => ['name' => 'Produkt PL'],
                        'en' => [
                            'name' => 'Product EN',
                            'description' => '<p>Full resumed English content</p>',
                        ],
                    ],
                ],
            ],
        ]);

        $retry = false;
        Http::fake(function ($request) use (&$retry) {
            if ($request->method() === 'GET'
                && str_ends_with($request->url(), '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities')
            ) {
                return Http::response($this->readyProductTranslationCapabilities());
            }

            if (! $retry && $request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc/v3/products') {
                return Http::response(['id' => 123, 'sku' => 'SKU-RESUME', 'name' => 'Produkt PL']);
            }

            if (! $retry && $request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc/v3/products?lang=en') {
                return Http::response(['id' => 223, 'name' => 'Product EN']);
            }

            if ($retry && $request->method() === 'PUT' && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123') {
                return Http::response(['id' => 123, 'sku' => 'SKU-RESUME', 'name' => 'Produkt PL']);
            }

            if ($request->method() === 'PUT' && $request->url() === 'https://shop.test/wp-json/wc/v3/products/223?lang=en') {
                return Http::response(['id' => 223, 'sku' => 'SKU-RESUME', 'name' => 'Product EN']);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations') {
                return $retry ? Http::response([
                    'linked' => true,
                    'translations' => ['en' => 223, 'pl' => 123],
                    'translation_group' => 'product:123|223',
                ]) : Http::response([
                    'code' => 'lemon_erp_product_translation_verification_failed',
                    'message' => 'Polylang nie potwierdził relacji.',
                ], 500);
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
        $this->assertSame('223', data_get($product->fresh()->attributes, 'woocommerce_translations.en.product_id'));
        $this->assertDatabaseHas('product_channel_aliases', [
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '223',
            'language' => 'en',
        ]);
        ProductChannelAlias::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '323',
            'external_key' => ProductChannelAlias::externalKey('323', null),
            'external_sku' => $product->sku,
            'language' => 'de',
        ]);

        $retry = true;

        $this->post(route('products.woocommerce.create', [$product, $integration]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Wznowiono i dokończono synchronizację produktu w WooCommerce dla kanału B2C.');

        $this->assertSame('completed', data_get($mapping->refresh()->metadata, 'creation_state'));
        $this->assertSame('223', data_get($product->refresh()->attributes, 'woocommerce_translations.en.product_id'));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123');
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/223?lang=en'
            && $request['sku'] === 'SKU-RESUME'
            && $request['name'] === 'Product EN'
            && $request['description'] === '<p>Full resumed English content</p>'
            && $request['date_created'] === '2026-07-14T13:47:00+02:00'
            && collect((array) $request['meta_data'])->contains(
                fn (array $meta): bool => $meta['key'] === '_sempre_erp_publication_date'
                    && $meta['value'] === '2026-07-14T13:47:00',
            ));
        $this->assertSame(1, Http::recorded()->filter(fn ($pair): bool => $pair[0]->method() === 'POST'
            && $pair[0]->url() === 'https://shop.test/wp-json/wc/v3/products')->count());
        $this->assertSame(1, Http::recorded()->filter(fn ($pair): bool => $pair[0]->method() === 'POST'
            && $pair[0]->url() === 'https://shop.test/wp-json/wc/v3/products?lang=en')->count());
        $this->assertSame(2, Http::recorded()->filter(fn ($pair): bool => $pair[0]->method() === 'POST'
            && $pair[0]->url() === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations')->count());
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/products/323'));
    }

    public function test_ambiguous_english_creation_response_is_resumed_by_erp_token_without_a_second_post(): void
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
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-AMBIGUOUS-EN',
            'name' => 'Produkt PL',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'content' => [
                    'pl' => ['name' => 'Produkt PL'],
                    'en' => ['name' => 'English product'],
                ],
            ]],
        ]);

        $retry = false;
        $creationToken = null;
        $creationSku = null;
        Http::fake(function ($request) use (&$retry, &$creationToken, &$creationSku) {
            $url = $request->url();

            if ($request->method() === 'GET'
                && str_ends_with($url, '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities')
            ) {
                return Http::response($this->readyProductTranslationCapabilities());
            }

            if ($request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc/v3/products') {
                return Http::response(['id' => 555, 'sku' => 'SKU-AMBIGUOUS-EN'], 201);
            }

            if ($request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc/v3/products?lang=en') {
                $creationToken = (string) data_get(
                    collect((array) $request['meta_data'])->firstWhere(
                        'key',
                        '_sempre_erp_translation_creation_token',
                    ),
                    'value',
                );
                $creationSku = (string) $request['sku'];

                // The gateway lost WooCommerce's successful response. The
                // product becomes visible to the next ERP retry.
                return Http::response(['message' => 'Gateway timeout'], 504);
            }

            if ($request->method() === 'GET'
                && str_starts_with($url, 'https://shop.test/wp-json/wc/v3/products?')
                && str_contains($url, 'lang=en')
                && (string) $request['sku'] === $creationSku
            ) {
                return $retry ? Http::response([[
                    'id' => 556,
                    'sku' => $creationSku,
                    'lang' => 'en',
                    'meta_data' => [[
                        'key' => '_sempre_erp_translation_creation_token',
                        'value' => $creationToken,
                    ]],
                ]]) : Http::response([]);
            }

            if ($retry && $request->method() === 'PUT' && $url === 'https://shop.test/wp-json/wc/v3/products/555') {
                return Http::response(['id' => 555, 'sku' => 'SKU-AMBIGUOUS-EN']);
            }

            if ($request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations') {
                return Http::response([
                    'linked' => true,
                    'translations' => ['en' => 556, 'pl' => 555],
                    'translation_group' => 'product:555|556',
                ]);
            }

            if ($request->method() === 'PUT' && $url === 'https://shop.test/wp-json/wc/v3/products/556?lang=en') {
                return Http::response(['id' => 556, 'sku' => $request['sku']]);
            }

            throw new \RuntimeException('Unexpected request: '.$request->method().' '.$url);
        });

        try {
            app(ProductDataExportService::class)->create($product, $integration);
            $this->fail('Pierwszy eksport powinien zachować niejednoznaczny stan do wznowienia.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 504', $exception->getMessage());
        }

        $mapping = ProductChannelMapping::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertNotSame('', $creationToken);
        $this->assertMatchesRegularExpression('/^LEMON-TR-[a-f0-9]{40}$/', (string) $creationSku);
        $this->assertSame($creationToken, data_get(
            $mapping->metadata,
            'product_translation_creation.en.token',
        ));
        $this->assertTrue((bool) data_get(
            $mapping->metadata,
            'product_translation_creation.en.pending',
        ));

        $retry = true;
        $result = app(ProductDataExportService::class)->create($product->fresh(), $integration->fresh());

        $this->assertTrue($result['resumed']);
        $this->assertSame('556', data_get($product->fresh()->attributes, 'woocommerce_translations.en.product_id'));
        $this->assertFalse((bool) data_get(
            $mapping->refresh()->metadata,
            'product_translation_creation.en.pending',
        ));
        $this->assertSame('556', data_get(
            $mapping->metadata,
            'product_translation_creation.en.external_product_id',
        ));
        $this->assertSame(1, Http::recorded()->filter(fn ($pair): bool => $pair[0]->method() === 'POST'
            && $pair[0]->url() === 'https://shop.test/wp-json/wc/v3/products?lang=en')->count());

        $requests = Http::recorded()->map(fn (array $record) => $record[0])->values();
        $linkRequestIndex = $requests->search(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations');
        $skuRequestIndex = $requests->search(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/556?lang=en');
        $this->assertIsInt($linkRequestIndex);
        $this->assertIsInt($skuRequestIndex);
        $this->assertLessThan($skuRequestIndex, $linkRequestIndex);
    }

    public function test_existing_polish_mapped_copy_creates_missing_english_family_and_retries_link_safely(): void
    {
        $this->createDefaultSizeDictionary();
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
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);
        $parent = Product::query()->create([
            'sku' => 'LEGACY-ARDEN-COPY',
            'name' => 'Komplet ARDEN kopia',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'publication_status' => 'publish',
                'publication_date' => '2026-07-14T12:30',
                'content' => [
                    'pl' => ['name' => 'Komplet ARDEN kopia', 'description' => '<p>Opis rodzica</p>'],
                    'en' => ['name' => 'ARDEN set copy', 'description' => '<p>Parent description</p>'],
                ],
                'copy' => ['created_from_product_id' => 1000],
            ]],
        ]);
        $variant = Product::query()->create([
            'sku' => 'LEGACY-ARDEN-COPY-S',
            'name' => 'Czarny - S (kopia)',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'publication_date' => '2026-07-15T09:15',
                'prices' => ['retail_price_pln' => 1],
                'content' => [
                    'pl' => ['description' => '<p>Stary opis</p>'],
                    'en' => ['description' => '<p>Stale description</p>'],
                ],
                'parameters' => [[
                    'name' => 'Rozmiar',
                    'name_en' => 'Size',
                    'value' => 'S',
                    'value_en' => 'S',
                    'variation' => true,
                ]],
                'copy' => ['created_from_product_id' => 1001],
            ]],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
            'metadata' => [
                'copied_from_relation_id' => 777,
                'copied_at' => '2026-07-13T10:00:00+00:00',
            ],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['creation_state' => 'completed'],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_variation_id' => '124',
            'external_sku' => $variant->sku,
            'stock_sync_enabled' => true,
        ]);

        $retry = false;
        $this->fakeWooWithGlobalAttributes(function ($request) use (&$retry) {
            $url = $request->url();

            if ($request->method() === 'PUT' && $url === 'https://shop.test/wp-json/wc/v3/products/123') {
                return Http::response(['id' => 123, 'sku' => 'LEGACY-ARDEN-COPY']);
            }

            if ($request->method() === 'GET' && str_contains($url, '/wc/v3/products?') && str_contains($url, 'lang=en')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc/v3/products?lang=en') {
                return Http::response(['id' => 223, 'sku' => ''], 201);
            }

            if ($request->method() === 'PUT' && $url === 'https://shop.test/wp-json/wc/v3/products/223?lang=en') {
                return Http::response(['id' => 223, 'sku' => $request['sku']]);
            }

            if ($request->method() === 'GET' && str_starts_with($url, 'https://shop.test/wp-json/wc/v3/products/223/variations')) {
                return Http::response([]);
            }

            if ($request->method() === 'GET' && $url === 'https://shop.test/wp-json/wc/v3/products/123/variations/124') {
                return Http::response([
                    'id' => 124,
                    'sku' => 'LEGACY-ARDEN-COPY-S',
                    'regular_price' => '699.00',
                    'sale_price' => '',
                ]);
            }

            if ($request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc/v3/products/223/variations?lang=en') {
                return Http::response(['id' => 224, 'sku' => $request['sku']], 201);
            }

            if ($request->method() === 'PUT' && in_array($url, [
                'https://shop.test/wp-json/wc/v3/products/123/variations/124',
                'https://shop.test/wp-json/wc/v3/products/223/variations/224?lang=en',
            ], true)) {
                return Http::response([
                    'id' => str_contains($url, '/224') ? 224 : 124,
                    'sku' => $request['sku'],
                    'regular_price' => str_contains($url, '/124') ? '699.00' : ($request['regular_price'] ?? ''),
                ]);
            }

            if ($request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations') {
                return $retry ? Http::response([
                    'linked' => true,
                    'translations' => ['en' => 223, 'pl' => 123],
                    'translation_group' => 'product:123|223',
                ]) : Http::response([
                    'code' => 'lemon_erp_product_translation_verification_failed',
                    'message' => 'Polylang nie potwierdził relacji.',
                ], 500);
            }

            throw new \RuntimeException('Unexpected request: '.$request->method().' '.$url);
        });

        try {
            app(ProductDataExportService::class)->export($parent);
            $this->fail('Pierwsze linkowanie Polylang powinno zakończyć się błędem.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Polylang nie potwierdził relacji', $exception->getMessage());
        }

        $this->assertSame('223', data_get($parent->fresh()->attributes, 'woocommerce_translations.en.product_id'));
        $this->assertNull(data_get($variant->fresh()->attributes, 'woocommerce_translations.en.variation_id'));
        $parentMapping = ProductChannelMapping::query()->where('product_id', $parent->id)->firstOrFail();
        $this->assertTrue((bool) data_get(
            $parentMapping->metadata,
            'product_translation_link.pending',
        ));
        $this->assertTrue((bool) data_get(
            $parentMapping->metadata,
            'product_translation_creation.en.pending',
        ));

        // Prove that the durable creation marker itself closes the crash
        // window. Even if the older link marker were lost, export must resume
        // the allocated EN product instead of accepting the half-linked alias.
        $metadata = (array) $parentMapping->metadata;
        data_forget($metadata, 'product_translation_link.pending');
        $parentMapping->forceFill(['metadata' => $metadata])->save();

        $retry = true;
        app(ProductDataExportService::class)->export($parent->fresh());

        $this->assertSame(1, Http::recorded()->filter(fn ($pair): bool => $pair[0]->method() === 'POST'
            && $pair[0]->url() === 'https://shop.test/wp-json/wc/v3/products?lang=en')->count());
        $this->assertSame(1, Http::recorded()->filter(fn ($pair): bool => $pair[0]->method() === 'POST'
            && $pair[0]->url() === 'https://shop.test/wp-json/wc/v3/products/223/variations?lang=en')->count());
        $this->assertSame(2, Http::recorded()->filter(fn ($pair): bool => $pair[0]->method() === 'POST'
            && $pair[0]->url() === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations')->count());
        $this->assertFalse((bool) data_get(
            ProductChannelMapping::query()->where('product_id', $parent->id)->firstOrFail()->metadata,
            'product_translation_link.pending',
        ));
        $this->assertFalse((bool) data_get(
            ProductChannelMapping::query()->where('product_id', $parent->id)->firstOrFail()->metadata,
            'product_translation_creation.en.pending',
        ));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123/variations/124'
            && $request['description'] === '<p>Opis rodzica</p>'
            && ! array_key_exists('regular_price', $request->data())
            && $request['menu_order'] === 10);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/223/variations/224?lang=en'
            && $request['description'] === '<p>Parent description</p>'
            && $request['regular_price'] === '699.00'
            && $request['menu_order'] === 10);

        $requests = Http::recorded()->map(fn (array $record) => $record[0])->values();
        $successfulLinkIndex = $requests
            ->filter(fn ($request): bool => $request->method() === 'POST'
                && $request->url() === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations'
                && $request['translations'] === ['en' => 223, 'pl' => 123])
            ->keys()
            ->last();
        $finalEnglishPutIndex = $requests->search(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/223?lang=en');
        $this->assertIsInt($successfulLinkIndex);
        $this->assertIsInt($finalEnglishPutIndex);
        $this->assertLessThan($finalEnglishPutIndex, $successfulLinkIndex);
    }

    public function test_direct_variant_export_uses_parent_inheritance_for_both_languages(): void
    {
        $this->createDefaultSizeDictionary();
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
            'sku' => 'DIRECT-PARENT',
            'name' => 'Produkt główny',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'publication_status' => 'publish',
                'publication_date' => '2026-07-14T13:00',
                'content' => [
                    'pl' => ['name' => 'Produkt główny', 'description' => '<p>Opis PL rodzica</p>'],
                    'en' => ['name' => 'Main product', 'description' => '<p>Parent EN description</p>'],
                ],
            ]],
        ]);
        $variant = Product::query()->create([
            'sku' => 'DIRECT-PARENT-M',
            'name' => 'Stary wariant M',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'publication_date' => '2026-07-15T09:15',
                'prices' => ['retail_price_pln' => 1],
                'content' => [
                    'pl' => ['description' => '<p>Stary opis</p>'],
                    'en' => ['description' => '<p>Stale description</p>'],
                ],
                'parameters' => [[
                    'name' => 'Rozmiar',
                    'name_en' => 'Size',
                    'value' => 'M',
                    'value_en' => 'M',
                    'variation' => true,
                ]],
                'inheritance' => ['mode' => 'parent', 'parent_product_id' => $parent->id],
            ]],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 7,
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
        ProductChannelAlias::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '124',
            'external_variation_id' => '457',
            'external_key' => ProductChannelAlias::externalKey('124', '457'),
            'external_sku' => $variant->sku,
            'language' => 'en',
        ]);

        $this->fakeWooWithGlobalAttributes([
            'https://shop.test/wp-json/wc/v3/products/123/variations/456' => Http::response([
                'id' => 456,
                'sku' => $variant->sku,
                'regular_price' => '699.00',
                'sale_price' => '649.00',
                'date_on_sale_from' => '2026-07-15T08:00:00',
                'date_on_sale_to' => '2026-07-20T22:00:00',
            ]),
            'https://shop.test/wp-json/wc/v3/products/124/variations/457?lang=en' => Http::response(['id' => 457, 'sku' => $variant->sku]),
        ]);

        app(ProductDataExportService::class)->export($variant);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123/variations/456'
            && $request['description'] === '<p>Opis PL rodzica</p>'
            && ! array_key_exists('regular_price', $request->data())
            && $request['menu_order'] === 20
            && $request['attributes'][0] === ['id' => 70, 'option' => 'M']
            && ! array_key_exists('date_created', $request->data())
            && collect($request['meta_data'])->contains(fn (array $meta): bool => $meta['key'] === '_sempre_erp_publication_date'
                && $meta['value'] === '2026-07-14T13:00:00'));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124/variations/457?lang=en'
            && $request['description'] === '<p>Parent EN description</p>'
            && $request['regular_price'] === '699.00'
            && $request['sale_price'] === '649.00'
            && $request['date_on_sale_from'] === '2026-07-15T08:00:00'
            && $request['date_on_sale_to'] === '2026-07-20T22:00:00'
            && $request['menu_order'] === 20
            && $request['attributes'][0] === ['id' => 70, 'option' => 'M']
            && ! array_key_exists('date_created', $request->data())
            && collect($request['meta_data'])->contains(fn (array $meta): bool => $meta['key'] === '_sempre_erp_publication_date'
                && $meta['value'] === '2026-07-14T13:00:00'));
    }

    public function test_copied_variable_family_creates_linked_english_product_with_inherited_variant_data(): void
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
            // Import is intentionally Polish-only. Exporting EN is a separate policy.
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['ONE SIZE', 'S', 'M'],
            'values_en' => ['ONE SIZE', 'S', 'M'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 10,
        ]);
        $source = Product::query()->create([
            'sku' => 'ARDEN-SOURCE',
            'name' => 'Komplet ARDEN',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'publication_status' => 'publish',
                'publication_date' => '2025-01-01T08:00',
                'prices' => ['retail_price_pln' => 699],
                'inventory' => ['manage_stock' => true, 'backorders' => 'no'],
                'content' => [
                    'pl' => [
                        'name' => 'Komplet ARDEN',
                        'description' => '<p>Opis rodzica PL</p>',
                    ],
                    'en' => [
                        'name' => 'ARDEN set',
                        'description' => '<p>Parent description EN</p>',
                    ],
                ],
            ]],
        ]);

        foreach ([
            ['sku' => 'ARDEN-S', 'option' => 'S', 'order' => 10],
            ['sku' => 'ARDEN-M', 'option' => 'M', 'order' => 20],
        ] as $row) {
            $variant = Product::query()->create([
                'sku' => $row['sku'],
                'name' => 'Nieaktualna kopia '.$row['option'],
                'unit' => 'szt',
                'vat_rate' => 23,
                'quantity_precision' => 0,
                'is_active' => true,
                'attributes' => ['master' => [
                    'source' => 'erp',
                    'product_type' => 'variation',
                    'prices' => ['retail_price_pln' => 1],
                    'content' => [
                        'pl' => ['description' => '<p>NIEAKTUALNY OPIS</p>'],
                        'en' => ['description' => '<p>STALE DESCRIPTION</p>'],
                    ],
                    'parameters' => [[
                        'name' => 'Rozmiar',
                        'name_en' => 'Size',
                        'value' => mb_strtolower($row['option']),
                        'value_en' => mb_strtolower($row['option']),
                        'variation' => true,
                    ]],
                ]],
            ]);
            ProductRelation::query()->create([
                'parent_product_id' => $source->id,
                'child_product_id' => $variant->id,
                'relation_type' => 'variant',
                'sort_order' => $row['order'],
            ]);
        }

        $this->post(route('products.duplicate', $source))->assertRedirect();
        $copy = Product::query()->get()->first(
            fn (Product $candidate): bool => (int) data_get($candidate->masterData(), 'copy.created_from_product_id') === $source->id,
        );
        $this->assertInstanceOf(Product::class, $copy);
        $copy->forceFill(['is_active' => true])->save();
        $copy->load('variantChildren');
        $publicationDate = data_get($copy->masterData(), 'publication_date').':00';
        $publicationDateWithOffset = $publicationDate.'+02:00';

        $this->fakeWooWithGlobalAttributes(function ($request) {
            $url = $request->url();

            if ($request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc/v3/products') {
                return Http::response(['id' => 700, 'sku' => $request['sku']], 201);
            }

            if ($request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc/v3/products?lang=en') {
                return Http::response(['id' => 800, 'sku' => ''], 201);
            }

            if ($request->method() === 'PUT' && $url === 'https://shop.test/wp-json/wc/v3/products/800?lang=en') {
                return Http::response(['id' => 800, 'sku' => $request['sku']]);
            }

            if ($request->method() === 'GET' && str_starts_with($url, 'https://shop.test/wp-json/wc/v3/products/800/variations')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST' && in_array($url, [
                'https://shop.test/wp-json/wc/v3/products/700/variations',
                'https://shop.test/wp-json/wc/v3/products/800/variations?lang=en',
            ], true)) {
                $base = str_contains($url, '/800/') ? 800 : 700;
                $id = $base + ($request['attributes'][0]['option'] === 'S' ? 1 : 2);

                return Http::response(['id' => $id, 'sku' => $request['sku']], 201);
            }

            if ($request->method() === 'PUT'
                && preg_match('#^https://shop\.test/wp-json/wc/v3/products/800/variations/80[12]\?lang=en$#', $url) === 1
            ) {
                return Http::response(['id' => (int) basename((string) parse_url($url, PHP_URL_PATH)), 'sku' => $request['sku']]);
            }

            if ($request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations') {
                return Http::response([
                    'linked' => true,
                    'translations' => ['en' => 800, 'pl' => 700],
                    'translation_group' => 'product:700|800',
                ]);
            }

            throw new \RuntimeException('Unexpected request: '.$request->method().' '.$url);
        });

        app(ProductDataExportService::class)->create($copy, $integration);

        $requests = Http::recorded()->map(fn (array $record) => $record[0])->values();
        $termCreates = $requests->filter(fn ($request): bool => $request->method() === 'POST'
            && preg_match('#/wc/v3/products/attributes/\d+/terms(?:\?|$)#', $request->url()) === 1)->values();
        $termLinks = $requests->filter(fn ($request): bool => $request->method() === 'POST'
            && preg_match('#/catalog/products/attributes/\d+/terms/translations$#', $request->url()) === 1)->values();
        $plParent = $requests->first(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products');
        $enParent = $requests->first(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products?lang=en');
        $plVariants = $requests->filter(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/700/variations')->values();
        $enVariants = $requests->filter(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/800/variations?lang=en')->values();
        $firstProductMutation = $requests->search(fn ($request): bool => in_array($request->method(), ['POST', 'PUT'], true)
            && preg_match('#/wp-json/wc/v3/products(?:\?|$|/\d+)#', $request->url()) === 1);
        $lastTermLink = $requests->search(fn ($request): bool => $request === $termLinks->last());

        $this->assertSame(['s-pl', 'm-pl', 's-en', 'm-en'], $termCreates->pluck('slug')->all());
        $this->assertSame([20, 30, 20, 30], $termCreates->pluck('menu_order')->all());
        $this->assertCount(2, $termLinks);
        $termLinks->each(function ($request): void {
            $this->assertArrayHasKey('pl', $request['translations']);
            $this->assertArrayHasKey('en', $request['translations']);
            $this->assertNotSame($request['translations']['pl'], $request['translations']['en']);
        });
        $this->assertIsInt($firstProductMutation);
        $this->assertIsInt($lastTermLink);
        $this->assertLessThan($firstProductMutation, $lastTermLink);
        $this->assertSame('<p>Opis rodzica PL</p>', $plParent['description']);
        $this->assertSame('<p>Parent description EN</p>', $enParent['description']);
        $this->assertSame(
            collect($plParent['attributes'])->firstWhere('variation', true)['id'],
            collect($enParent['attributes'])->firstWhere('variation', true)['id'],
        );
        $this->assertSame($publicationDateWithOffset, $plParent['date_created']);
        $this->assertSame($publicationDateWithOffset, $enParent['date_created']);
        $this->assertSame([20, 30], $plVariants->pluck('menu_order')->all());
        $this->assertSame([20, 30], $enVariants->pluck('menu_order')->all());
        $this->assertSame(['<p>Opis rodzica PL</p>', '<p>Opis rodzica PL</p>'], $plVariants->pluck('description')->all());
        $this->assertSame(['<p>Parent description EN</p>', '<p>Parent description EN</p>'], $enVariants->pluck('description')->all());
        $this->assertSame(['699.00', '699.00'], $plVariants->pluck('regular_price')->all());
        $this->assertSame(['699.00', '699.00'], $enVariants->pluck('regular_price')->all());
        $this->assertSame(['S', 'M'], $plVariants->map(fn ($request) => $request['attributes'][0]['option'])->all());
        $this->assertSame(['S', 'M'], $enVariants->map(fn ($request) => $request['attributes'][0]['option'])->all());
        foreach ($plVariants->concat($enVariants) as $variationRequest) {
            $this->assertArrayNotHasKey('date_created', $variationRequest->data());
            $publicationMeta = collect($variationRequest['meta_data'])->firstWhere(
                'key',
                '_sempre_erp_publication_date',
            );
            $this->assertSame($publicationDate, $publicationMeta['value'] ?? null);
        }
        $this->assertSame(3, ProductChannelAlias::query()
            ->where('sales_channel_id', $channel->id)
            ->where('language', 'en')
            ->count());
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations'
            && $request['translations'] === ['en' => 800, 'pl' => 700]);
    }

    public function test_variable_creation_resumes_missing_polish_variants_before_translations(): void
    {
        $this->createDefaultSizeDictionary();
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
            'settings' => ['product_export' => ['languages' => ['pl']]],
        ]);
        $parent = Product::query()->create([
            'sku' => 'RETRY-FAMILY',
            'name' => 'Rodzina do wznowienia',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'content' => ['pl' => ['name' => 'Rodzina do wznowienia']],
                'prices' => ['retail_price_pln' => 199],
            ]],
        ]);
        $variantS = $this->createVariantProduct('RETRY-FAMILY-S', 'S', 199);
        $variantM = $this->createVariantProduct('RETRY-FAMILY-M', 'M', 199);

        foreach ([[$variantS, 10], [$variantM, 20]] as [$variant, $order]) {
            ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $variant->id,
                'relation_type' => 'variant',
                'sort_order' => $order,
            ]);
        }

        $retry = false;
        $this->fakeWooWithGlobalAttributes(function ($request) use (&$retry) {
            $url = $request->url();

            if (! $retry && $request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc/v3/products') {
                return Http::response(['id' => 500, 'sku' => 'RETRY-FAMILY'], 201);
            }

            if ($retry && $request->method() === 'PUT' && $url === 'https://shop.test/wp-json/wc/v3/products/500') {
                return Http::response(['id' => 500, 'sku' => 'RETRY-FAMILY']);
            }

            if ($request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc/v3/products/500/variations') {
                if ($request['sku'] === 'RETRY-FAMILY-M' && ! $retry) {
                    return Http::response(['message' => 'temporary variation failure'], 503);
                }

                return Http::response([
                    'id' => $request['sku'] === 'RETRY-FAMILY-S' ? 501 : 502,
                    'sku' => $request['sku'],
                ], 201);
            }

            if ($retry && $request->method() === 'PUT' && $url === 'https://shop.test/wp-json/wc/v3/products/500/variations/501') {
                return Http::response(['id' => 501, 'sku' => 'RETRY-FAMILY-S']);
            }

            throw new \RuntimeException('Unexpected request: '.$request->method().' '.$url);
        });

        $this->post(route('products.woocommerce.create', [$parent, $integration]))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(2, ProductChannelMapping::query()->count());
        $this->assertSame('creating', data_get(
            ProductChannelMapping::query()->where('product_id', $parent->id)->firstOrFail()->metadata,
            'creation_state',
        ));

        $retry = true;
        $this->post(route('products.woocommerce.create', [$parent, $integration]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Wznowiono i dokończono synchronizację produktu w WooCommerce dla kanału B2C.');

        $this->assertSame(3, ProductChannelMapping::query()->count());
        $this->assertSame('completed', data_get(
            ProductChannelMapping::query()->where('product_id', $parent->id)->firstOrFail()->metadata,
            'creation_state',
        ));
        $this->assertSame(1, Http::recorded()->filter(fn ($pair): bool => $pair[0]->method() === 'POST'
            && $pair[0]->url() === 'https://shop.test/wp-json/wc/v3/products')->count());
        $this->assertSame(1, Http::recorded()->filter(fn ($pair): bool => $pair[0]->method() === 'POST'
            && $pair[0]->url() === 'https://shop.test/wp-json/wc/v3/products/500/variations'
            && $pair[0]['sku'] === 'RETRY-FAMILY-S')->count());
        $this->assertSame(2, Http::recorded()->filter(fn ($pair): bool => $pair[0]->method() === 'POST'
            && $pair[0]->url() === 'https://shop.test/wp-json/wc/v3/products/500/variations'
            && $pair[0]['sku'] === 'RETRY-FAMILY-M')->count());
    }

    public function test_erp_variable_product_with_only_non_woo_mapping_creates_canonical_parent_and_variants_in_woocommerce(): void
    {
        $this->createDefaultSizeDictionary();
        $this->fakeWooWithGlobalAttributes(function ($request) {
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
        $marketplaceChannel = SalesChannel::query()->create([
            'code' => 'BL-NON-WOO',
            'name' => 'BaseLinker marketplace',
            'type' => 'marketplace',
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
                    'variant_attribute' => 'BLVariant',
                    'prices' => ['retail_price_pln' => 819.00],
                    'content' => [
                        'pl' => [
                            'name' => 'Komplet AMORA',
                            'description' => '<p>Opis</p>',
                        ],
                    ],
                    'parameters' => [
                        ['name' => 'wariant', 'value' => 'S, M', 'variation' => true],
                        ['name' => 'System', 'value' => 's, m', 'variation' => true],
                        ['name' => 'Rozmiar', 'value' => 's, m', 'variation' => true],
                        ['name' => 'Kolor', 'value' => 'Kremowy'],
                    ],
                ],
            ],
        ]);
        $variantS = $this->createVariantProduct('SET-AMORA-S', 's', 819.00);
        $variantM = $this->createVariantProduct('SET-AMORA-M', 'm', 829.00);

        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variantS->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
            'metadata' => ['variant_attribute' => 'Rozmiar'],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variantM->id,
            'relation_type' => 'variant',
            'sort_order' => 20,
            'metadata' => ['variant_attribute' => 'Size'],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $marketplaceChannel->id,
            'external_product_id' => 'BL-SET-AMORA',
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
        ]);

        $this->post(route('products.woocommerce.create', [$parent, $integration]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Produkt utworzony w WooCommerce dla kanału B2C razem z 2 wariantami.');

        $requests = Http::recorded()->map(fn (array $record) => $record[0])->values();
        $parentRequest = $requests->first(fn ($request) => $request->url() === 'https://shop.test/wp-json/wc/v3/products');
        $variationRequests = $requests->filter(fn ($request) => $request->url() === 'https://shop.test/wp-json/wc/v3/products/700/variations')->values();

        $this->assertSame('variable', $parentRequest['type']);
        $variantAttributes = collect($parentRequest['attributes'])
            ->filter(fn (array $attribute): bool => (bool) $attribute['variation'])
            ->values();
        $this->assertCount(1, $variantAttributes);
        $variantAttributeId = $variantAttributes[0]['id'];
        $this->assertIsInt($variantAttributeId);
        $this->assertSame(
            0,
            $variantAttributes[0]['position'],
            json_encode($parentRequest['attributes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $this->assertSame(['S', 'M'], $variantAttributes[0]['options']);
        $this->assertTrue(collect($parentRequest['attributes'])
            ->reject(fn (array $attribute): bool => $attribute['id'] === $variantAttributeId)
            ->every(fn (array $attribute): bool => $attribute['variation'] === false));
        $this->assertTrue(collect($parentRequest['attributes'])->every(
            fn (array $attribute): bool => ! array_key_exists('name', $attribute)
                && ! array_key_exists('source_name', $attribute),
        ));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/attributes'
            && $request['name'] === 'Rozmiar'
            && $request['slug'] === 'pa_rozmiar');
        $this->assertFalse($parentRequest['manage_stock']);
        $this->assertSame([], $parentRequest['default_attributes']);
        $this->assertSame(2, $variationRequests->count());
        $this->assertSame('SET-AMORA-S', $variationRequests[0]['sku']);
        $this->assertCount(1, $variationRequests[0]['attributes']);
        $this->assertSame($variantAttributeId, $variationRequests[0]['attributes'][0]['id']);
        $this->assertSame('S', $variationRequests[0]['attributes'][0]['option']);
        $this->assertSame(10, $variationRequests[0]['menu_order']);
        $this->assertSame('819.00', $variationRequests[0]['regular_price']);
        $this->assertSame('SET-AMORA-M', $variationRequests[1]['sku']);
        $this->assertCount(1, $variationRequests[1]['attributes']);
        $this->assertSame($variantAttributeId, $variationRequests[1]['attributes'][0]['id']);
        $this->assertSame('M', $variationRequests[1]['attributes'][0]['option']);
        $this->assertSame(20, $variationRequests[1]['menu_order']);
        $this->assertSame('829.00', $variationRequests[1]['regular_price']);

        $this->assertSame(4, ProductChannelMapping::query()->count());
        $this->assertSame('700', ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->where('sales_channel_id', $channel->id)
            ->firstOrFail()
            ->external_product_id);
        $this->assertSame('701', ProductChannelMapping::query()->where('product_id', $variantS->id)->firstOrFail()->external_variation_id);
        $this->assertSame('702', ProductChannelMapping::query()->where('product_id', $variantM->id)->firstOrFail()->external_variation_id);
        $this->assertSame(1, IntegrationSyncLog::query()->where('operation', 'create_product')->count());
        $this->assertSame(2, IntegrationSyncLog::query()->where('operation', 'create_product_variation')->count());
    }

    public function test_export_converts_existing_mapped_product_to_variable_and_creates_missing_variants(): void
    {
        $this->createDefaultSizeDictionary();
        $this->fakeWooWithGlobalAttributes(function ($request) {
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
        $this->assertSame(70, $parentRequest['attributes'][0]['id']);
        $this->assertSame(['S'], $parentRequest['attributes'][0]['options']);
        $this->assertTrue($parentRequest['attributes'][0]['variation']);
        $this->assertTrue(collect($parentRequest['attributes'])->every(
            fn (array $attribute): bool => array_key_exists('id', $attribute)
                && ! array_key_exists('name', $attribute)
                && ! array_key_exists('source_name', $attribute),
        ));
        $this->assertSame('SET-LUNA-S', $variationRequest['sku']);
        $this->assertSame(70, $variationRequest['attributes'][0]['id']);
        $this->assertArrayNotHasKey('name', $variationRequest['attributes'][0]);
        $this->assertArrayNotHasKey('source_name', $variationRequest['attributes'][0]);
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
        Http::fake(function ($request) {
            if ($request->method() === 'GET'
                && str_ends_with($request->url(), '/catalog/products/translations/capabilities')
            ) {
                return Http::response($this->readyProductTranslationCapabilities());
            }

            return Http::response(['id' => 123, 'sku' => 'SET-REMOVE']);
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
        Http::fake(function ($request) {
            if ($request->method() === 'GET'
                && str_ends_with($request->url(), '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities')
            ) {
                return Http::response($this->readyProductTranslationCapabilities());
            }

            return Http::response([]);
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
            if ($request->method() === 'GET'
                && str_ends_with($request->url(), '/catalog/products/translations/capabilities')
            ) {
                return Http::response($this->readyProductTranslationCapabilities());
            }

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
        $this->fakeWooWithGlobalAttributes([
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response(['id' => 123, 'sku' => 'SKU-I18N']),
            'https://shop.test/wp-json/wc/v3/products/124?lang=en' => Http::response(['id' => 124, 'sku' => 'SKU-I18N']),
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
            'sort_order' => 10,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Kolor',
            'name_en' => 'Colour',
            'slug' => 'kolor',
            'input_type' => 'select',
            'values' => ['Czerwony', 'Niebieski'],
            'values_en' => ['Red', 'Blue'],
            'sort_order' => 20,
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
                        ['name' => 'Kolor', 'value' => 'Czerwony'],
                        [
                            'name' => 'Rozmiar',
                            'value' => 'S',
                            'name_en' => 'Sizing',
                            'value_en' => 'Petite',
                        ],
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
                ['id' => 70, 'position' => 0, 'visible' => true, 'variation' => false, 'options' => ['S']],
                ['id' => 71, 'position' => 1, 'visible' => true, 'variation' => false, 'options' => ['Czerwony']],
            ]);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124?lang=en'
            && $request['attributes'] === [
                ['id' => 70, 'position' => 0, 'visible' => true, 'variation' => false, 'options' => ['Petite']],
                ['id' => 71, 'position' => 1, 'visible' => true, 'variation' => false, 'options' => ['Red']],
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
            'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities' => Http::response($this->readyProductTranslationCapabilities()),
            'https://shop.test/wp-json/wc/v3/products/123' => Http::response(['id' => 123, 'sku' => 'SKU-FULL']),
            'https://shop.test/wp-json/wc/v3/products/124?lang=en' => Http::response(['id' => 124, 'sku' => 'SKU-FULL']),
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
                    'shipping' => [
                        'days' => 11,
                        'text' => 'Planowana wysyłka: {date}',
                        'text_en' => 'Planned shipping: {date}',
                        'preorder' => true,
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
                    'https://shop.test/wp-json/wc/v3/products/124?lang=en',
                ], true))
            ->keyBy(fn ($request): string => $request->url());
        $this->assertSame(
            $languageRequests['https://shop.test/wp-json/wc/v3/products/123']['images'],
            $languageRequests['https://shop.test/wp-json/wc/v3/products/124?lang=en']['images'],
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
                && $meta['_lemon_product_label_bg_color'] === '#112233'
                && $meta['lemon_shipping_days'] === '11'
                && $meta['lemon_shipping_text'] === 'Planowana wysyłka: {date}'
                && $meta['lemon_preorder'] === 'yes';
        });
        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PUT' || $request->url() !== 'https://shop.test/wp-json/wc/v3/products/124?lang=en') {
                return false;
            }

            $meta = collect($request['meta_data'])->pluck('value', 'key');

            return $request['name'] === 'Product EN'
                && $request['description'] === '<p>Description EN</p>'
                && $request['short_description'] === 'Short EN'
                && $meta['_lemon_product_label_text'] === 'New'
                && $meta['lemon_shipping_days'] === '11'
                && $meta['lemon_shipping_text'] === 'Planned shipping: {date}'
                && $meta['lemon_preorder'] === 'yes';
        });
    }

    public function test_export_discovers_existing_english_variant_instead_of_creating_duplicate(): void
    {
        $this->createDefaultSizeDictionary(['S'], ['Small']);
        $this->fakeWooWithGlobalAttributes(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/products/124/variations')) {
                return Http::response([[
                    'id' => 457,
                    'sku' => '',
                    'attributes' => [[
                        'id' => 70,
                        'option' => 'Small',
                    ]],
                ]]);
            }

            if ($request->method() === 'GET'
                && str_contains($request->url(), '/wc/v3/products?')
                && str_contains($request->url(), 'lang=en')) {
                return Http::response([[
                    'id' => 124,
                    'sku' => 'SET-LEGACY',
                    'lang' => 'en',
                    'translations' => ['pl' => 123, 'en' => 124],
                ]]);
            }

            return match ([$request->method(), $request->url()]) {
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/123'] => Http::response(['id' => 123, 'sku' => 'SET-LEGACY']),
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/124?lang=en'] => Http::response(['id' => 124, 'sku' => 'SET-LEGACY']),
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/123/variations/456'] => Http::response(['id' => 456, 'sku' => 'SET-LEGACY-S']),
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/124/variations/457?lang=en'] => Http::response(['id' => 457, 'sku' => 'SET-LEGACY-S']),
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
                'prices' => ['retail_price_pln' => 699],
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
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123/variations/456'
            && $request['menu_order'] === 10);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124/variations/457?lang=en'
            && $request['menu_order'] === 10
            && str_ends_with($request['image']['src'], '/uploads/products/legacy-variant-new.jpg'));
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/wp-json/wc/v3/products/')
            && str_contains($request->url(), '/variations'));
        $this->assertSame('124', data_get($parent->fresh()->attributes, 'woocommerce_translations.en.product_id'));
        $this->assertSame('457', data_get($variant->fresh()->attributes, 'woocommerce_translations.en.variation_id'));
    }

    public function test_export_creates_new_variant_for_polish_and_english_polylang_parents(): void
    {
        $this->fakeWooWithGlobalAttributes(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/products/124/variations')) {
                return Http::response([]);
            }

            if ($request->method() === 'GET'
                && str_contains($request->url(), '/wc/v3/products?')
                && str_contains($request->url(), 'lang=en')) {
                return Http::response([[
                    'id' => 124,
                    'sku' => 'SET-NEW',
                    'lang' => 'en',
                    'translations' => ['pl' => 123, 'en' => 124],
                ]]);
            }

            return match ([$request->method(), $request->url()]) {
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/123'] => Http::response(['id' => 123, 'sku' => 'SET-NEW']),
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/124?lang=en'] => Http::response(['id' => 124, 'sku' => 'SET-NEW']),
                ['POST', 'https://shop.test/wp-json/wc/v3/products/123/variations'] => Http::response(['id' => 456, 'sku' => 'SEM-NEW-S'], 201),
                ['POST', 'https://shop.test/wp-json/wc/v3/products/124/variations?lang=en'] => Http::response(['id' => 457, 'sku' => $request['sku']], 201),
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/124/variations/457?lang=en'] => Http::response(['id' => 457, 'sku' => $request['sku']]),
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
                    'publication_date' => '2026-07-15T08:30',
                    'prices' => ['retail_price_pln' => 699],
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
            'sort_order' => 0,
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
            && $request['menu_order'] === 10
            && $request['global_unique_id'] === '5901234567890'
            && str_ends_with($request['image']['src'], '/uploads/products/shared-variant-pl-en.jpg'));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124/variations?lang=en'
            && preg_match('/^LEMON-VTR-[a-f0-9]{40}$/', (string) $request['sku']) === 1
            && $request['status'] === 'draft'
            && $request['manage_stock'] === true
            && $request['stock_quantity'] === 0
            && $request['stock_status'] === 'outofstock'
            && $request['backorders'] === 'no'
            && ! isset($request['global_unique_id'])
            && collect((array) $request['meta_data'])->doesntContain(
                fn (array $meta): bool => in_array($meta['key'] ?? null, ['_ean', '_sempre_erp_ean'], true),
            )
            && $request['menu_order'] === 10
            && $request['attributes'][0]['id'] === 70
            && $request['attributes'][0]['option'] === 'Small'
            && str_ends_with($request['image']['src'], '/uploads/products/shared-variant-pl-en.jpg'));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124/variations/457?lang=en'
            && $request['sku'] === 'SEM-NEW-S'
            && $request['global_unique_id'] === '5901234567890'
            && $request['status'] === 'publish'
            && $request['manage_stock'] === true
            && $request['stock_quantity'] === 0
            && $request['stock_status'] === 'outofstock'
            && $request['backorders'] === 'no'
            && $request['menu_order'] === 10
            && $request['attributes'][0]['option'] === 'Small'
            && ! array_key_exists('date_created', $request->data())
            && collect((array) $request['meta_data'])->contains(
                fn (array $meta): bool => ($meta['key'] ?? null) === '_ean'
                    && ($meta['value'] ?? null) === '5901234567890',
            )
            && collect((array) $request['meta_data'])->contains(
                fn (array $meta): bool => ($meta['key'] ?? null) === '_sempre_erp_ean'
                    && ($meta['value'] ?? null) === '5901234567890',
            )
            && collect((array) $request['meta_data'])->contains(
                fn (array $meta): bool => $meta['key'] === '_sempre_erp_publication_date'
                    && $meta['value'] === '2026-07-15T08:30:00',
            ));
        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $variant->id,
            'external_product_id' => '123',
            'external_variation_id' => '456',
        ]);
        $this->assertSame('124', data_get($variant->fresh()->attributes, 'woocommerce_translations.en.product_id'));
        $this->assertSame('457', data_get($variant->fresh()->attributes, 'woocommerce_translations.en.variation_id'));
    }

    public function test_marketplace_only_plural_axis_uses_canonical_spelling_translation_and_order_with_unknown_fallback(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiary',
            'name_en' => 'Sizes',
            'slug' => 'rozmiary',
            'input_type' => 'select',
            'values' => ['S-M', 'M-L', 'Niestandardowy'],
            'values_en' => ['Legacy Small/Medium', 'Legacy Medium/Large', 'Legacy custom'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 1,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['M/L', 'S/M'],
            'values_en' => ['Medium/Large', 'Small/Medium'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 999,
        ]);
        $channel = SalesChannel::query()->create([
            'code' => 'MARKETPLACE-ORDER',
            'name' => 'Marketplace order',
            'type' => 'marketplace',
            'is_active' => true,
        ]);
        $parent = Product::query()->create([
            'sku' => 'MARKETPLACE-PLURAL-ORDER',
            'name' => 'Marketplace plural order',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiary',
                'content' => [
                    'pl' => ['name' => 'Marketplace plural order'],
                    'en' => ['name' => 'Marketplace plural order EN'],
                ],
                'parameters' => [[
                    'name' => 'Rozmiary',
                    'name_en' => 'Sizes',
                    'value' => 's-m | m-l | Niestandardowy',
                    'value_en' => 'Legacy Small/Medium | Legacy Medium/Large | Legacy custom',
                    'variation' => true,
                ]],
            ]],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => 'BL-MARKETPLACE-ORDER',
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
        ]);

        foreach ([
            ['option' => 'm-l', 'value_en' => 'Legacy Medium/Large', 'sort_order' => 80],
            ['option' => 's-m', 'value_en' => 'Legacy Small/Medium', 'sort_order' => 90],
            ['option' => 'Niestandardowy', 'value_en' => 'Bespoke legacy', 'sort_order' => 70],
        ] as $row) {
            $option = $row['option'];
            $variant = Product::query()->create([
                'sku' => 'MARKETPLACE-PLURAL-'.strtoupper($option),
                'name' => 'Marketplace plural '.$option,
                'unit' => 'szt',
                'vat_rate' => 23,
                'quantity_precision' => 0,
                'is_active' => true,
                'attributes' => ['master' => [
                    'source' => 'erp',
                    'product_type' => 'variation',
                    'variant_attribute' => 'Rozmiary',
                    'parameters' => [[
                        'name' => 'Rozmiary',
                        'name_en' => 'Sizes',
                        'value' => $option,
                        'value_en' => $row['value_en'],
                        'variation' => true,
                    ]],
                ]],
            ]);
            ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $variant->id,
                'relation_type' => 'variant',
                'sort_order' => $row['sort_order'],
                'metadata' => [
                    'variant_attribute' => 'Rozmiary',
                    'variant_option' => $option,
                ],
            ]);
        }

        $service = app(ProductDataExportService::class);
        $variants = $parent->variantChildren()->get();
        $prepareVariablePayload = new \ReflectionMethod($service, 'prepareVariablePayload');

        foreach ([
            'pl' => ['name' => 'Rozmiar', 'options' => ['M/L', 'S/M', 'Niestandardowy']],
            'en' => ['name' => 'Size', 'options' => ['Medium/Large', 'Small/Medium', 'Legacy custom']],
        ] as $language => $expected) {
            $payload = $prepareVariablePayload->invoke(
                $service,
                $parent,
                $variants,
                ['meta_data' => []],
                $language,
            );
            $axis = collect($payload['attributes'])->firstWhere('variation', true);
            $this->assertSame('Rozmiar', $axis['source_name'] ?? null);
            $this->assertSame($expected['name'], $axis['name'] ?? null);
            $this->assertSame(['M/L', 'S/M', 'Niestandardowy'], $axis['source_options'] ?? null);
            $this->assertSame($expected['options'], $axis['options'] ?? null);
            $this->assertSame([10, 20, 30], $axis['source_option_orders'] ?? null);
            $this->assertSame('Rozmiar', collect($payload['meta_data'])
                ->firstWhere('key', '_sempre_erp_variant_attribute')['value'] ?? null);
        }

        $variationPayload = new \ReflectionMethod($service, 'variationPayload');
        $menuOrders = $variants->mapWithKeys(fn (Product $variant): array => [
            $variant->sku => $variationPayload->invoke(
                $service,
                $parent,
                $variant,
                $channel->id,
                'pl',
            )['menu_order'],
        ]);
        $this->assertSame(10, $menuOrders->get('MARKETPLACE-PLURAL-M-L'));
        $this->assertSame(20, $menuOrders->get('MARKETPLACE-PLURAL-S-M'));
        $this->assertSame(30, $menuOrders->get('MARKETPLACE-PLURAL-NIESTANDARDOWY'));
    }

    public function test_proven_generic_size_axis_uses_canonical_dictionary_spelling_translation_and_order(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S/M', 'M/L'],
            'values_en' => ['Small/Medium', 'Medium/Large'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 10,
        ]);
        $channel = SalesChannel::query()->create([
            'code' => 'GENERIC-SIZE-ORDER',
            'name' => 'Generic size order',
            'type' => 'marketplace',
            'is_active' => true,
        ]);
        $parent = Product::query()->create([
            'sku' => 'GENERIC-SIZE-PARENT',
            'name' => 'Generic size parent',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'BLVariant',
                'parameters' => [[
                    'name' => 'BLVariant',
                    'value' => 'm-l | s-m',
                    'value_en' => 'Legacy Medium/Large | Legacy Small/Medium',
                    'variation' => true,
                ]],
            ]],
        ]);

        foreach ([
            ['option' => 'm-l', 'value_en' => 'Legacy Medium/Large', 'sort_order' => 80],
            ['option' => 's-m', 'value_en' => 'Legacy Small/Medium', 'sort_order' => 90],
        ] as $row) {
            $variant = Product::query()->create([
                'sku' => 'GENERIC-SIZE-'.strtoupper($row['option']),
                'name' => 'Generic size '.$row['option'],
                'unit' => 'szt',
                'vat_rate' => 23,
                'quantity_precision' => 0,
                'is_active' => true,
                'attributes' => ['master' => [
                    'source' => 'erp',
                    'product_type' => 'variation',
                    'variant_attribute' => 'BLVariant',
                    'parameters' => [[
                        'name' => 'BLVariant',
                        'value' => $row['option'],
                        'value_en' => $row['value_en'],
                        'variation' => true,
                    ]],
                ]],
            ]);
            ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $variant->id,
                'relation_type' => 'variant',
                'sort_order' => $row['sort_order'],
                'metadata' => [
                    'variant_attribute' => 'BLVariant',
                    'variant_option' => $row['option'],
                ],
            ]);
        }

        $service = app(ProductDataExportService::class);
        $variants = $parent->variantChildren()->get();
        $prepareVariablePayload = new \ReflectionMethod($service, 'prepareVariablePayload');

        foreach ([
            'pl' => ['name' => 'Rozmiar', 'options' => ['S/M', 'M/L']],
            'en' => ['name' => 'Size', 'options' => ['Small/Medium', 'Medium/Large']],
        ] as $language => $expected) {
            $payload = $prepareVariablePayload->invoke(
                $service,
                $parent,
                $variants,
                ['meta_data' => []],
                $language,
            );
            $axis = collect($payload['attributes'])->firstWhere('variation', true);
            $this->assertSame('Rozmiar', $axis['source_name'] ?? null);
            $this->assertSame($expected['name'], $axis['name'] ?? null);
            $this->assertSame(['S/M', 'M/L'], $axis['source_options'] ?? null);
            $this->assertSame($expected['options'], $axis['options'] ?? null);
            $this->assertSame([10, 20], $axis['source_option_orders'] ?? null);
        }

        $variationPayload = new \ReflectionMethod($service, 'variationPayload');
        $english = $variants->mapWithKeys(fn (Product $variant): array => [
            $variant->sku => $variationPayload->invoke(
                $service,
                $parent,
                $variant,
                $channel->id,
                'en',
            ),
        ]);
        $this->assertSame('Small/Medium', data_get($english, 'GENERIC-SIZE-S-M.attributes.0.option'));
        $this->assertSame(10, data_get($english, 'GENERIC-SIZE-S-M.menu_order'));
        $this->assertSame('Medium/Large', data_get($english, 'GENERIC-SIZE-M-L.attributes.0.option'));
        $this->assertSame(20, data_get($english, 'GENERIC-SIZE-M-L.menu_order'));
    }

    public function test_size_term_orders_use_one_dense_union_of_all_legacy_size_dictionaries(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['M/L', 'Custom B', 'S/M', 'Custom A'],
            'values_en' => ['M/L', 'Custom B', 'S/M', 'Custom A'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 10,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiary',
            'name_en' => 'Sizes',
            'slug' => 'rozmiary',
            'input_type' => 'select',
            'values' => ['XS', 'ONE SIZE'],
            'values_en' => ['XS', 'ONE SIZE'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 20,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'BLVariant',
            'slug' => 'blvariant',
            'input_type' => 'select',
            // Proven generic legacy dictionary; slash/hyphen spellings must
            // deduplicate against the canonical entries without shifting ranks.
            'values' => ['s-m', 'm-l'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 30,
        ]);
        $method = new \ReflectionMethod(
            ProductDataExportService::class,
            'parameterOptionMenuOrders',
        );

        $orders = $method->invoke(
            app(ProductDataExportService::class),
            ['name' => 'Rozmiar', 'slug' => 'rozmiar'],
            ['M/L', 'Custom A', 'XS', 'S/M', 'Custom B', 'ONE SIZE'],
        );

        $this->assertSame([10, 40, 50, 30, 20, 60], $orders);
    }

    public function test_size_export_rejects_a_value_absent_from_every_erp_size_dictionary(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S/M', 'M/L'],
            'values_en' => ['S/M', 'M/L'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 10,
        ]);
        $method = new \ReflectionMethod(
            ProductDataExportService::class,
            'parameterOptionMenuOrders',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Outside dictionary');

        $method->invoke(
            app(ProductDataExportService::class),
            ['name' => 'Rozmiar', 'slug' => 'rozmiar'],
            ['Outside dictionary'],
        );
    }

    public function test_direct_size_axis_without_any_erp_dictionary_fails_closed(): void
    {
        $method = new \ReflectionMethod(
            ProductDataExportService::class,
            'parameterOptionMenuOrders',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S/M');

        $method->invoke(
            app(ProductDataExportService::class),
            ['name' => 'Rozmiar', 'slug' => 'rozmiar'],
            ['S/M', 'M/L'],
        );
    }

    public function test_size_order_uses_the_shared_union_without_an_exact_canonical_row_and_for_a_proven_generic_row(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiary',
            'name_en' => 'Sizes',
            'slug' => 'rozmiary',
            'input_type' => 'select',
            'values' => ['M/L', 'S/M'],
            'values_en' => ['Medium/Large', 'Small/Medium'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 10,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Imported axis',
            'slug' => 'blvariant',
            'input_type' => 'select',
            'values' => ['m-l', 's-m'],
            'values_en' => ['Legacy Medium/Large', 'Legacy Small/Medium'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 20,
        ]);
        $method = new \ReflectionMethod(
            ProductDataExportService::class,
            'parameterOptionMenuOrders',
        );
        $service = app(ProductDataExportService::class);

        $this->assertSame([10, 20], $method->invoke(
            $service,
            ['name' => 'Rozmiar', 'slug' => 'rozmiar'],
            ['M/L', 'S/M'],
        ));
        $this->assertSame([10, 20], $method->invoke(
            $service,
            ['name' => 'Imported axis', 'slug' => 'blvariant'],
            ['m-l', 's-m'],
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Outside dictionary');

        $method->invoke(
            $service,
            ['name' => 'Imported axis', 'slug' => 'blvariant'],
            ['Outside dictionary'],
        );
    }

    public function test_export_replaces_legacy_blvariant_axis_with_canonical_global_size_for_pl_and_en(): void
    {
        $this->fakeWooWithGlobalAttributes(function ($request) {
            $url = $request->url();

            if ($request->method() === 'PUT' && in_array($url, [
                'https://shop.test/wp-json/wc/v3/products/123',
                'https://shop.test/wp-json/wc/v3/products/223?lang=en',
                'https://shop.test/wp-json/wc/v3/products/123/variations/124',
                'https://shop.test/wp-json/wc/v3/products/123/variations/125',
                'https://shop.test/wp-json/wc/v3/products/223/variations/224?lang=en',
                'https://shop.test/wp-json/wc/v3/products/223/variations/225?lang=en',
            ], true)) {
                return Http::response([
                    'id' => (int) basename((string) parse_url($url, PHP_URL_PATH)),
                    'sku' => (string) ($request['sku'] ?? ''),
                ]);
            }

            throw new \RuntimeException('Unexpected request: '.$request->method().' '.$url);
        });
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-LEGACY-SIZE',
            'name' => 'B2C legacy size',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo legacy size',
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
            'values' => ['S/M', 'M/L'],
            'values_en' => ['S/M', 'M/L'],
            'is_variant' => true,
            'sort_order' => 10,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Kolor',
            'name_en' => 'Colour',
            'slug' => 'kolor',
            'input_type' => 'select',
            'values' => ['Kremowy'],
            'values_en' => ['Cream'],
            'sort_order' => 20,
        ]);
        $parent = Product::query()->create([
            'sku' => 'LEGACY-BLVARIANT',
            'name' => 'Spodnie legacy',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variable',
                    'variant_attribute' => 'BLVariant',
                    'publication_date' => '2026-07-15T09:30',
                    'prices' => ['retail_price_pln' => 539],
                    'content' => [
                        'pl' => ['name' => 'Spodnie legacy'],
                        'en' => ['name' => 'Legacy trousers'],
                    ],
                    'parameters' => [
                        ['name' => 'BLVariant', 'value' => 's-m | m-l', 'variation' => true],
                        ['name' => 'Kolor', 'name_en' => 'Colour', 'value' => 'Kremowy', 'value_en' => 'Cream'],
                    ],
                ],
                'woocommerce_translations' => [
                    'en' => ['product_id' => '223', 'variation_id' => null, 'sku' => 'LEGACY-BLVARIANT'],
                ],
            ],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'LEGACY-SIZE-WH',
            'name' => 'Legacy size warehouse',
            'type' => 'own',
            'allow_negative_stock' => false,
            'is_active' => true,
        ]);

        foreach ([
            ['option' => 's-m', 'pl_id' => '124', 'en_id' => '224', 'order' => 20, 'stock' => 2],
            ['option' => 'm-l', 'pl_id' => '125', 'en_id' => '225', 'order' => 10, 'stock' => 3],
        ] as $row) {
            $variant = Product::query()->create([
                'sku' => 'LEGACY-BLVARIANT-'.strtoupper($row['option']),
                'name' => 'Spodnie legacy '.$row['option'],
                'unit' => 'szt',
                'vat_rate' => 23,
                'quantity_precision' => 0,
                'is_active' => true,
                'attributes' => [
                    'master' => [
                        'source' => 'erp',
                        'product_type' => 'variation',
                        'variant_attribute' => 'BLVariant',
                        'prices' => ['retail_price_pln' => 539],
                        'parameters' => [[
                            'name' => 'BLVariant',
                            'value' => $row['option'],
                            'variation' => true,
                        ], [
                            'name' => 'Rozmiar',
                            'name_en' => 'Size',
                            'value' => $row['option'] === 's-m' ? 'S/M' : 'M/L',
                            'variation' => true,
                        ]],
                    ],
                    'woocommerce_translations' => [
                        'en' => [
                            'product_id' => '223',
                            'variation_id' => $row['en_id'],
                            'sku' => 'LEGACY-BLVARIANT-'.strtoupper($row['option']),
                        ],
                    ],
                ],
            ]);
            ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $variant->id,
                'relation_type' => 'variant',
                'sort_order' => $row['order'],
                'metadata' => [
                    'variant_attribute' => 'BLVariant',
                    'variant_option' => $row['option'],
                ],
            ]);
            ProductChannelMapping::query()->create([
                'product_id' => $variant->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => '123',
                'external_variation_id' => $row['pl_id'],
                'external_sku' => $variant->sku,
                'stock_sync_enabled' => true,
            ]);
            StockBalance::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $variant->id,
                'quantity_on_hand' => $row['stock'],
                'quantity_reserved' => 0,
                'quantity_available' => $row['stock'],
            ]);
        }

        $service = app(ProductDataExportService::class);
        $variants = $parent->variantChildren()->get();
        $prepareVariablePayload = new \ReflectionMethod($service, 'prepareVariablePayload');
        $legacyPayload = $prepareVariablePayload->invoke(
            $service,
            $parent,
            $variants,
            ['meta_data' => []],
            'pl',
        );
        $legacyAxis = collect($legacyPayload['attributes'])
            ->firstWhere('variation', true);
        $this->assertSame('BLVariant', $legacyAxis['source_name'] ?? null);
        $this->assertSame('BLVariant', $legacyAxis['name'] ?? null);
        $this->assertSame('BLVariant', collect($legacyPayload['meta_data'])
            ->firstWhere('key', '_sempre_erp_variant_attribute')['value'] ?? null);

        $attributes = (array) $parent->attributes;
        data_set($attributes, 'master.'.WooOwnedVariantAxisRepairService::STATE_PATH, [
            'revision' => WooOwnedVariantAxisRepairService::REVISION,
            'synchronized_at' => now()->toISOString(),
        ]);
        $parent->forceFill(['attributes' => $attributes])->save();

        foreach (['pl' => 'Rozmiar', 'en' => 'Size'] as $language => $renderedName) {
            $canonicalPayload = $prepareVariablePayload->invoke(
                $service,
                $parent,
                $variants,
                ['meta_data' => []],
                $language,
            );
            $canonicalAxis = collect($canonicalPayload['attributes'])
                ->firstWhere('variation', true);
            $this->assertSame('Rozmiar', $canonicalAxis['source_name'] ?? null);
            $this->assertSame($renderedName, $canonicalAxis['name'] ?? null);
            $this->assertSame(['S/M', 'M/L'], $canonicalAxis['options'] ?? null);
            $this->assertSame('Rozmiar', collect($canonicalPayload['meta_data'])
                ->firstWhere('key', '_sempre_erp_variant_attribute')['value'] ?? null);
        }

        $service->export($parent);

        $mutations = Http::recorded()
            ->map(fn (array $record) => $record[0])
            ->filter(fn ($request): bool => $request->method() === 'PUT'
                && str_contains($request->url(), '/wp-json/wc/v3/products/'))
            ->values();
        $this->assertCount(6, $mutations);

        $parents = $mutations->filter(fn ($request): bool => ! str_contains(
            (string) parse_url($request->url(), PHP_URL_PATH),
            '/variations/',
        ));
        $parents->each(function ($request): void {
            $variationAttributes = collect($request['attributes'])
                ->filter(fn (array $attribute): bool => (bool) $attribute['variation'])
                ->values();
            $this->assertCount(1, $variationAttributes);
            $this->assertSame(0, $variationAttributes->first()['position']);
            $this->assertSame(['S/M', 'M/L'], $variationAttributes->first()['options']);
            $this->assertSame([0, 1], collect($request['attributes'])->pluck('position')->all());
            $this->assertSame(['S/M', 'M/L'], $request['attributes'][0]['options']);
            $this->assertSame(
                str_contains($request->url(), '/products/223') ? ['Cream'] : ['Kremowy'],
                $request['attributes'][1]['options'],
            );
            $this->assertSame('Rozmiar', collect($request['meta_data'])
                ->firstWhere('key', '_sempre_erp_variant_attribute')['value'] ?? null);
        });

        $variations = $mutations->filter(fn ($request): bool => str_contains(
            (string) parse_url($request->url(), PHP_URL_PATH),
            '/variations/',
        ));
        $sizes = $variations->groupBy(fn ($request): string => $request['attributes'][0]['option']);
        $this->assertSame(['S/M', 'M/L'], $sizes->keys()->sortBy(
            fn (string $option): int => $option === 'S/M' ? 0 : 1,
        )->values()->all());
        $this->assertCount(2, $sizes->get('S/M'));
        $this->assertSame([10], $sizes->get('S/M')->pluck('menu_order')->unique()->values()->all());
        $this->assertSame([2], $sizes->get('S/M')->pluck('stock_quantity')->unique()->values()->all());
        $this->assertCount(2, $sizes->get('M/L'));
        $this->assertSame([20], $sizes->get('M/L')->pluck('menu_order')->unique()->values()->all());
        $this->assertSame([3], $sizes->get('M/L')->pluck('stock_quantity')->unique()->values()->all());
        $variations->each(function ($request): void {
            $this->assertTrue($request['manage_stock']);
            $this->assertSame('instock', $request['stock_status']);
            $this->assertSame('publish', $request['status']);
            $this->assertSame('Rozmiar', collect($request['meta_data'])
                ->firstWhere('key', '_sempre_erp_variant_attribute')['value'] ?? null);
        });

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/attributes'
            && $request['name'] === 'Rozmiar'
            && $request['slug'] === 'pa_rozmiar');
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/attributes'
            && in_array(mb_strtolower((string) $request['name']), ['wariant', 'variant', 'blvariant'], true));
    }

    public function test_independent_blvariant_color_is_preserved_as_non_variation_next_to_size_axis(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S', 'M'],
            'values_en' => ['S', 'M'],
            'is_variant' => true,
            'sort_order' => 10,
        ]);
        $parent = Product::query()->create([
            'sku' => 'SIZE-WITH-BLVARIANT-COLOR',
            'name' => 'Rozmiar z kolorem legacy',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'parameters' => [
                    ['name' => 'Rozmiar', 'value' => 'S | M', 'variation' => true],
                    ['name' => 'BLVariant', 'value' => 'Czarny | Biały', 'variation' => true],
                ],
            ]],
        ]);

        foreach (['S', 'M'] as $sortOrder => $option) {
            $variant = Product::query()->create([
                'sku' => 'SIZE-WITH-BLVARIANT-COLOR-'.$option,
                'name' => 'Rozmiar z kolorem legacy '.$option,
                'unit' => 'szt',
                'vat_rate' => 23,
                'quantity_precision' => 0,
                'is_active' => true,
                'attributes' => ['master' => [
                    'source' => 'erp',
                    'product_type' => 'variation',
                    'variant_attribute' => 'Rozmiar',
                    'parameters' => [[
                        'name' => 'Rozmiar',
                        'value' => $option,
                        'variation' => true,
                    ]],
                ]],
            ]);
            ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $variant->id,
                'relation_type' => 'variant',
                'sort_order' => $sortOrder,
                'metadata' => [
                    'variant_attribute' => 'Rozmiar',
                    'variant_option' => $option,
                ],
            ]);
        }

        $service = app(ProductDataExportService::class);
        $prepareVariablePayload = new \ReflectionMethod($service, 'prepareVariablePayload');
        $payload = $prepareVariablePayload->invoke(
            $service,
            $parent,
            $parent->variantChildren()->get(),
            ['meta_data' => []],
            'pl',
        );
        $attributes = collect($payload['attributes']);
        $variationAttributes = $attributes
            ->filter(fn (array $attribute): bool => (bool) ($attribute['variation'] ?? false))
            ->values();

        $this->assertCount(2, $attributes);
        $this->assertCount(1, $variationAttributes);
        $this->assertSame('Rozmiar', $variationAttributes->first()['source_name'] ?? null);
        $this->assertSame(['S', 'M'], $variationAttributes->first()['options'] ?? null);

        $color = $attributes->firstWhere('source_name', 'BLVariant');
        $this->assertIsArray($color);
        $this->assertFalse($color['variation'] ?? true);
        $this->assertSame(['Czarny | Biały'], $color['source_options'] ?? null);
        $this->assertSame(['Czarny | Biały'], $color['options'] ?? null);
    }

    public function test_direct_size_parameter_lists_are_split_into_atomic_terms_before_globalization(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S/M', 'M/L', '36', '37', '38', '39', '40'],
            'values_en' => ['S/M', 'M/L', '36', '37', '38', '39', '40'],
            'is_variant' => true,
        ]);
        $master = ['parameters' => [
            [
                'name' => 'Rozmiar',
                'name_en' => 'Size',
                'value' => 'M/L, S/M',
                'value_en' => 'M/L, S/M',
            ],
            [
                'name' => 'Rozmiary',
                'name_en' => 'Sizes',
                'value' => '36, 37, 38, 39, 40, 36, 37, 38, 39, 40',
                'value_en' => '36, 37, 38, 39, 40, 36, 37, 38, 39, 40',
            ],
        ]];
        $attributes = new \ReflectionMethod(ProductDataExportService::class, 'attributes');
        $service = app(ProductDataExportService::class);

        foreach (['pl', 'en'] as $language) {
            $resolved = $attributes->invoke($service, $master, $language);

            $this->assertSame(['M/L', 'S/M'], $resolved[0]['source_options']);
            $this->assertSame(['M/L', 'S/M'], $resolved[0]['options']);
            $this->assertSame(['36', '37', '38', '39', '40'], $resolved[1]['source_options']);
            $this->assertSame(['36', '37', '38', '39', '40'], $resolved[1]['options']);
            $this->assertTrue(collect($resolved[1]['source_option_orders'])
                ->every(fn (mixed $order): bool => is_int($order) && $order > 0));
        }
    }

    public function test_global_size_client_rejects_an_aggregate_term_before_any_http_request(): void
    {
        Http::fake();
        $integration = new WordpressIntegration;

        try {
            app(WooCommerceClient::class)->ensureGlobalProductAttribute(
                $integration,
                'Rozmiar',
                ['36, 37, 38, 39, 40'],
                'pl',
            );
            $this->fail('Zbiorczy term Rozmiar nie może dotrzeć do WooCommerce.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('zbiorczą wartość', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_global_attribute_resolution_paginates_attribute_and_term_collections(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/attributes') {
                if ($page === 1) {
                    return Http::response(collect(range(1, 100))->map(fn (int $id): array => [
                        'id' => $id,
                        'name' => 'Atrybut '.$id,
                        'slug' => 'pa_atrybut-'.$id,
                    ])->all());
                }

                return Http::response([[
                    'id' => 901,
                    'name' => 'Rozmiar',
                    'slug' => 'pa_rozmiar',
                ]]);
            }

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/attributes/901/terms') {
                if ($page === 1) {
                    return Http::response(collect(range(1, 100))->map(fn (int $id): array => [
                        'id' => 1000 + $id,
                        'name' => 'Wartość '.$id,
                        'slug' => 'wartosc-'.$id,
                    ])->all());
                }

                return Http::response([[
                    'id' => 1200,
                    'name' => 'S',
                    'slug' => 's',
                ]]);
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

        $resolved = app(WooCommerceClient::class)->ensureGlobalProductAttribute(
            $integration,
            'Rozmiar',
            ['S'],
            'pl',
        );

        $this->assertSame(901, $resolved['id']);
        $this->assertSame(['S'], $resolved['options']);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/products/attributes?')
            && str_contains($request->url(), 'page=2'));
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/products/attributes/901/terms?')
            && str_contains($request->url(), 'page=2'));
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/attributes'));
    }

    public function test_global_attribute_syncs_canonical_term_order_for_polish_and_english_idempotently(): void
    {
        $attribute = [
            'id' => 90,
            'name' => 'Rozmiar',
            'slug' => 'pa_rozmiar',
            'order_by' => 'name',
        ];
        $terms = [
            'pl' => [
                ['id' => 701, 'name' => 'S', 'slug' => 's-pl', 'lang' => 'pl', 'menu_order' => 0],
                ['id' => 702, 'name' => 'M', 'slug' => 'm-pl', 'lang' => 'pl', 'menu_order' => 0],
            ],
            'en' => [
                ['id' => 801, 'name' => 'Small', 'slug' => 'small-en', 'lang' => 'en', 'menu_order' => 0],
                ['id' => 802, 'name' => 'Medium', 'slug' => 'medium-en', 'lang' => 'en', 'menu_order' => 0],
            ],
        ];

        Http::fake(function ($request) use (&$attribute, &$terms) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/attributes') {
                return Http::response([$attribute]);
            }

            if ($request->method() === 'PUT' && $path === '/wp-json/wc/v3/products/attributes/90') {
                $attribute['order_by'] = (string) $request['order_by'];

                return Http::response($attribute);
            }

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/attributes/90/terms') {
                return Http::response($terms[(string) ($query['lang'] ?? 'pl')] ?? []);
            }

            if ($request->method() === 'PUT'
                && preg_match('#^/wp-json/wc/v3/products/attributes/90/terms/(\d+)$#', $path, $matches) === 1
            ) {
                $termId = (int) $matches[1];

                foreach ($terms as $language => $languageTerms) {
                    foreach ($languageTerms as $index => $term) {
                        if ((int) $term['id'] !== $termId) {
                            continue;
                        }

                        $terms[$language][$index]['menu_order'] = (int) $request['menu_order'];

                        return Http::response($terms[$language][$index]);
                    }
                }
            }

            if ($request->method() === 'POST'
                && $path === '/wp-json/wc-lemon-erp/v1/catalog/products/attributes/90/terms/translations'
            ) {
                return Http::response([
                    'linked' => true,
                    'attribute_id' => 90,
                    'translations' => $request['translations'],
                ]);
            }

            return Http::response([], 404);
        });
        $integration = $this->createGlobalTermIntegration(['pl', 'en']);
        $client = app(WooCommerceClient::class);

        $resolved = $client->ensureGlobalProductAttribute(
            $integration,
            'Rozmiar',
            ['Small', 'Medium'],
            'en',
            ['S', 'M'],
            [10, 20],
        );

        $this->assertSame(['Small', 'Medium'], $resolved['options']);
        $this->assertSame([801, 802], $resolved['term_ids']);
        $this->assertSame('menu_order', $attribute['order_by']);
        $this->assertSame([10, 20], collect($terms['pl'])->pluck('menu_order')->all());
        $this->assertSame([10, 20], collect($terms['en'])->pluck('menu_order')->all());
        $firstMutationCount = Http::recorded()->filter(
            fn (array $pair): bool => $pair[0]->method() === 'PUT',
        )->count();
        $this->assertSame(5, $firstMutationCount);

        $client->ensureGlobalProductAttribute(
            $integration,
            'Rozmiar',
            ['Small', 'Medium'],
            'en',
            ['S', 'M'],
            [10, 20],
        );

        $this->assertSame($firstMutationCount, Http::recorded()->filter(
            fn (array $pair): bool => $pair[0]->method() === 'PUT',
        )->count());
    }

    public function test_global_attribute_sync_repairs_order_when_woocommerce_omits_order_fields(): void
    {
        $attribute = [
            'id' => 90,
            'name' => 'Rozmiar',
            'slug' => 'pa_rozmiar',
        ];
        $terms = [
            ['id' => 701, 'name' => 'S/M', 'slug' => 's-m'],
            ['id' => 702, 'name' => 'M/L', 'slug' => 'm-l'],
        ];

        Http::fake(function ($request) use (&$attribute, &$terms) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/attributes') {
                return Http::response([$attribute]);
            }

            if ($request->method() === 'PUT' && $path === '/wp-json/wc/v3/products/attributes/90') {
                // Reproduce a response filter that strips the order field
                // even though Woo accepted the requested mutation.
                return Http::response($attribute);
            }

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/attributes/90/terms') {
                return Http::response($terms);
            }

            if ($request->method() === 'PUT'
                && preg_match('#^/wp-json/wc/v3/products/attributes/90/terms/(\d+)$#', $path, $matches) === 1
            ) {
                $termId = (int) $matches[1];
                $index = collect($terms)->search(
                    fn (array $term): bool => (int) $term['id'] === $termId,
                );

                // The term response may omit menu_order for the same reason.
                return Http::response($terms[$index]);
            }

            return Http::response([], 404);
        });
        $integration = $this->createGlobalTermIntegration(['pl']);
        $client = app(WooCommerceClient::class);

        $resolved = $client->ensureGlobalProductAttribute(
            $integration,
            'Rozmiar',
            ['S/M', 'M/L'],
            'pl',
            ['S/M', 'M/L'],
            [10, 20],
        );

        $this->assertSame(['S/M', 'M/L'], $resolved['options']);
        $this->assertSame(3, Http::recorded()->filter(
            fn (array $pair): bool => $pair[0]->method() === 'PUT',
        )->count());

        $client->ensureGlobalProductAttribute(
            $integration,
            'Rozmiar',
            ['S/M', 'M/L'],
            'pl',
            ['S/M', 'M/L'],
            [10, 20],
        );

        $this->assertSame(6, Http::recorded()->filter(
            fn (array $pair): bool => $pair[0]->method() === 'PUT',
        )->count());
    }

    public function test_export_matches_normalized_size_option_to_spaced_dictionary_value_order(): void
    {
        $this->fakeWooWithGlobalAttributes(function ($request) {
            return match ([$request->method(), $request->url()]) {
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/123'] => Http::response(['id' => 123]),
                ['PUT', 'https://shop.test/wp-json/wc/v3/products/123/variations/456'] => Http::response(['id' => 456]),
                default => Http::response([], 404),
            };
        });
        $integration = $this->createGlobalTermIntegration(['pl']);
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['XS', 'S / M'],
            'values_en' => ['XS', 'S/M'],
            'is_variant' => true,
        ]);
        $parent = Product::query()->create([
            'sku' => 'SPACED-SIZE-PARENT',
            'name' => 'Rodzina ze spacją',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
            ]],
        ]);
        $variant = Product::query()->create([
            'sku' => 'SPACED-SIZE-SM',
            'name' => 'Rodzina ze spacją - S/M',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'parameters' => [[
                    'name' => 'Rozmiar',
                    'value' => 's / m',
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
            'sales_channel_id' => $integration->sales_channel_id,
            'external_product_id' => '123',
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $integration->sales_channel_id,
            'external_product_id' => '123',
            'external_variation_id' => '456',
            'external_sku' => $variant->sku,
            'stock_sync_enabled' => true,
        ]);

        app(ProductDataExportService::class)->export($parent);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && preg_match('#/wc/v3/products/attributes/\d+/terms(?:\?|$)#', $request->url()) === 1
            && $request['name'] === 'S/M'
            && $request['menu_order'] === 20);
    }

    public function test_global_attribute_term_prefers_exact_localized_slug_over_duplicate_name_matches(): void
    {
        $this->fakeSempreGlobalAttributeTerms([
            ['id' => 701, 'name' => 'SEMPRE', 'slug' => 'sempre'],
            ['id' => 702, 'name' => 'SEMPRE', 'slug' => 'sempre-pl'],
            ['id' => 703, 'name' => 'SEMPRE', 'slug' => 'sempre-kopia'],
        ]);
        $integration = $this->createGlobalTermIntegration();

        $resolved = app(WooCommerceClient::class)->ensureGlobalProductAttribute(
            $integration,
            'Oficjalny producent',
            ['SEMPRE'],
            'pl',
        );

        $this->assertSame(90, $resolved['id']);
        $this->assertSame(['SEMPRE'], $resolved['options']);
        $this->assertSame([702], $resolved['term_ids']);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/attributes/90/terms'));
    }

    public function test_global_attribute_term_prefers_used_legacy_slug_over_empty_localized_slug(): void
    {
        $this->fakeSempreGlobalAttributeTerms([
            ['id' => 701, 'name' => 'SEMPRE', 'slug' => 'sempre', 'count' => 835],
            ['id' => 702, 'name' => 'SEMPRE', 'slug' => 'sempre-pl', 'count' => 0],
        ]);
        $integration = $this->createGlobalTermIntegration();

        $resolved = app(WooCommerceClient::class)->ensureGlobalProductAttribute(
            $integration,
            'Oficjalny producent',
            ['SEMPRE'],
            'pl',
        );

        $this->assertSame(['SEMPRE'], $resolved['options']);
        $this->assertSame([701], $resolved['term_ids']);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/attributes/90/terms'));
    }

    public function test_global_attribute_term_does_not_treat_a_used_foreign_language_term_as_polish(): void
    {
        $this->fakeSempreGlobalAttributeTerms([
            ['id' => 702, 'name' => 'SEMPRE', 'slug' => 'sempre-pl', 'count' => 0],
            ['id' => 703, 'name' => 'SEMPRE', 'slug' => 'sempre-en', 'count' => 835],
        ]);
        $integration = $this->createGlobalTermIntegration();

        $resolved = app(WooCommerceClient::class)->ensureGlobalProductAttribute(
            $integration,
            'Oficjalny producent',
            ['SEMPRE'],
            'pl',
        );

        $this->assertSame([702], $resolved['term_ids']);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/attributes/90/terms'));
    }

    public function test_global_attribute_term_does_not_treat_a_single_explicit_english_term_as_polish(): void
    {
        $this->fakeSempreGlobalAttributeTerms([
            ['id' => 703, 'name' => 'SEMPRE', 'slug' => 'sempre-en', 'lang' => 'en', 'count' => 835],
        ], function ($request) {
            if ($request->method() === 'POST'
                && str_contains($request->url(), '/wc/v3/products/attributes/90/terms')
            ) {
                return Http::response([
                    'id' => 702,
                    'name' => 'SEMPRE',
                    'slug' => 'sempre-pl',
                    'lang' => 'pl',
                    'count' => 0,
                ], 201);
            }

            return Http::response([], 404);
        });
        $integration = $this->createGlobalTermIntegration();

        $resolved = app(WooCommerceClient::class)->ensureGlobalProductAttribute(
            $integration,
            'Oficjalny producent',
            ['SEMPRE'],
            'pl',
        );

        $this->assertSame([702], $resolved['term_ids']);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/attributes/90/terms')
            && $request['slug'] === 'sempre-pl');
    }

    public function test_global_attribute_term_does_not_reuse_an_implicit_polish_slug_for_english(): void
    {
        $this->fakeSempreGlobalAttributeTerms([
            ['id' => 702, 'name' => 'SEMPRE', 'slug' => 'sempre-pl', 'count' => 0],
        ], function ($request) {
            if ($request->method() === 'POST'
                && str_contains($request->url(), '/wc/v3/products/attributes/90/terms')
            ) {
                return Http::response([
                    'id' => 703,
                    'name' => 'SEMPRE',
                    'slug' => 'sempre-en',
                    'count' => 0,
                ], 201);
            }

            if ($request->method() === 'POST'
                && str_contains($request->url(), '/catalog/products/attributes/90/terms/translations')
            ) {
                return Http::response([
                    'linked' => true,
                    'attribute_id' => 90,
                    'translations' => ['en' => 703, 'pl' => 702],
                ]);
            }

            return Http::response([], 404);
        });
        $integration = $this->createGlobalTermIntegration(['pl', 'en']);

        $resolved = app(WooCommerceClient::class)->ensureGlobalProductAttribute(
            $integration,
            'Oficjalny producent',
            ['SEMPRE'],
            'en',
            ['SEMPRE'],
        );

        $this->assertSame([703], $resolved['term_ids']);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/attributes/90/terms')
            && str_contains($request->url(), 'lang=en')
            && $request['slug'] === 'sempre-en');
    }

    public function test_global_attribute_term_uses_used_legacy_base_slug_for_polish_when_english_term_is_also_used(): void
    {
        $this->fakeSempreGlobalAttributeTerms([
            ['id' => 701, 'name' => 'SEMPRE', 'slug' => 'sempre', 'count' => 835],
            ['id' => 703, 'name' => 'SEMPRE', 'slug' => 'sempre-en', 'count' => 18],
        ]);
        $integration = $this->createGlobalTermIntegration(['pl', 'en']);

        $resolved = app(WooCommerceClient::class)->ensureGlobalProductAttribute(
            $integration,
            'Oficjalny producent',
            ['SEMPRE'],
            'pl',
        );

        $this->assertSame([701], $resolved['term_ids']);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/wp-json/wc/v3/products/attributes/90/terms');
    }

    public function test_global_attribute_term_uses_used_localized_english_slug_when_legacy_polish_term_is_also_used(): void
    {
        $this->fakeSempreGlobalAttributeTerms([
            ['id' => 701, 'name' => 'SEMPRE', 'slug' => 'sempre', 'count' => 835],
            ['id' => 703, 'name' => 'SEMPRE', 'slug' => 'sempre-en', 'count' => 18],
        ], function ($request) {
            if ($request->method() === 'POST'
                && str_contains($request->url(), '/catalog/products/attributes/90/terms/translations')
            ) {
                return Http::response([
                    'linked' => true,
                    'attribute_id' => 90,
                    'translations' => ['pl' => 701, 'en' => 703],
                ]);
            }

            return Http::response([], 404);
        });
        $integration = $this->createGlobalTermIntegration(['pl', 'en']);

        $resolved = app(WooCommerceClient::class)->ensureGlobalProductAttribute(
            $integration,
            'Oficjalny producent',
            ['SEMPRE'],
            'en',
            ['SEMPRE'],
        );

        $this->assertSame([703], $resolved['term_ids']);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/wp-json/wc/v3/products/attributes/90/terms');
    }

    public function test_global_attribute_term_keeps_english_lookup_ambiguous_with_an_unknown_used_duplicate(): void
    {
        $this->fakeSempreGlobalAttributeTerms([
            ['id' => 701, 'name' => 'SEMPRE', 'slug' => 'sempre', 'count' => 835],
            ['id' => 703, 'name' => 'SEMPRE', 'slug' => 'sempre-en', 'count' => 18],
            ['id' => 704, 'name' => 'SEMPRE', 'slug' => 'sempre-copy', 'count' => 2],
        ]);
        $integration = $this->createGlobalTermIntegration(['pl', 'en']);

        try {
            app(WooCommerceClient::class)->ensureGlobalProductAttribute(
                $integration,
                'Oficjalny producent',
                ['SEMPRE'],
                'en',
                ['SEMPRE'],
            );
            $this->fail('Nieznany używany duplikat musi zachować niejednoznaczność termu angielskiego.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'kilka wartości SEMPRE globalnego atrybutu #90',
                $exception->getMessage(),
            );
        }

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/wp-json/wc/v3/products/attributes/90/terms');
    }

    public function test_global_attribute_term_keeps_two_used_terms_ambiguous_even_with_one_exact_slug(): void
    {
        $this->fakeSempreGlobalAttributeTerms([
            ['id' => 701, 'name' => 'SEMPRE', 'slug' => 'sempre', 'count' => 835],
            ['id' => 702, 'name' => 'SEMPRE', 'slug' => 'sempre-pl', 'count' => 12],
        ]);
        $integration = $this->createGlobalTermIntegration();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('kilka wartości SEMPRE globalnego atrybutu #90');

        app(WooCommerceClient::class)->ensureGlobalProductAttribute(
            $integration,
            'Oficjalny producent',
            ['SEMPRE'],
            'pl',
        );
    }

    public function test_global_attribute_term_keeps_identical_canonical_slugs_ambiguous_without_creating_another_term(): void
    {
        $this->fakeSempreGlobalAttributeTerms([
            ['id' => 702, 'name' => 'SEMPRE', 'slug' => 'sempre-pl'],
            ['id' => 703, 'name' => 'SEMPRE', 'slug' => 'sempre-pl'],
        ]);
        $integration = $this->createGlobalTermIntegration();

        try {
            app(WooCommerceClient::class)->ensureGlobalProductAttribute(
                $integration,
                'Oficjalny producent',
                ['SEMPRE'],
                'pl',
            );
            $this->fail('Dwa różne termy o kanonicznym slugu muszą pozostać niejednoznaczne.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'kilka wartości SEMPRE globalnego atrybutu #90',
                $exception->getMessage(),
            );
        }

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/attributes/90/terms'));
    }

    public function test_global_attribute_term_keeps_name_only_duplicate_slugs_ambiguous_without_creating_another_term(): void
    {
        $this->fakeSempreGlobalAttributeTerms([
            ['id' => 701, 'name' => 'SEMPRE', 'slug' => 'sempre', 'count' => 835],
            ['id' => 703, 'name' => 'SEMPRE', 'slug' => 'sempre-kopia', 'count' => 12],
        ]);
        $integration = $this->createGlobalTermIntegration();

        try {
            app(WooCommerceClient::class)->ensureGlobalProductAttribute(
                $integration,
                'Oficjalny producent',
                ['SEMPRE'],
                'pl',
            );
            $this->fail('Duplikaty nazwy bez kanonicznego sluga muszą pozostać niejednoznaczne.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'kilka wartości SEMPRE globalnego atrybutu #90',
                $exception->getMessage(),
            );
        }

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/attributes/90/terms'));
    }

    public function test_product_export_uses_canonical_sempre_term_and_does_not_create_a_duplicate(): void
    {
        $this->fakeSempreGlobalAttributeTerms([
            ['id' => 701, 'name' => 'SEMPRE', 'slug' => 'sempre'],
            ['id' => 702, 'name' => 'SEMPRE', 'slug' => 'sempre-pl'],
        ], function ($request) {
            if ($request->method() === 'PUT'
                && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123'
            ) {
                return Http::response(['id' => 123, 'sku' => 'SEMPRE-CANONICAL']);
            }

            return Http::response([], 404);
        });
        $integration = $this->createGlobalTermIntegration();
        $product = Product::query()->create([
            'sku' => 'SEMPRE-CANONICAL',
            'name' => 'Produkt SEMPRE',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'content' => ['pl' => ['name' => 'Produkt SEMPRE']],
                'parameters' => [[
                    'name' => 'Oficjalny producent',
                    'value' => 'SEMPRE',
                ]],
            ]],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $integration->sales_channel_id,
            'external_product_id' => '123',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);

        $result = app(ProductDataExportService::class)->export($product);

        $this->assertSame(1, $result['exported']);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123'
            && $request['attributes'] === [[
                'id' => 90,
                'position' => 0,
                'visible' => true,
                'variation' => false,
                'options' => ['SEMPRE'],
            ]]);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/attributes/90/terms'));
    }

    public function test_unmapped_simple_product_creation_uses_used_legacy_sempre_term_without_creating_a_duplicate(): void
    {
        $this->fakeSempreGlobalAttributeTerms([
            ['id' => 701, 'name' => 'SEMPRE', 'slug' => 'sempre', 'count' => 835],
            ['id' => 702, 'name' => 'SEMPRE', 'slug' => 'sempre-pl', 'count' => 0],
        ], function ($request) {
            if ($request->method() === 'POST'
                && $request->url() === 'https://shop.test/wp-json/wc/v3/products'
            ) {
                return Http::response([
                    'id' => 321,
                    'sku' => 'SEMPRE-NEW-SIMPLE',
                    'name' => 'Nowy produkt SEMPRE',
                    'permalink' => 'https://shop.test/produkt/nowy-produkt-sempre',
                ], 201);
            }

            return Http::response([], 404);
        });
        $integration = $this->createGlobalTermIntegration();
        $product = Product::query()->create([
            'sku' => 'SEMPRE-NEW-SIMPLE',
            'name' => 'Nowy produkt SEMPRE',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'simple',
                'content' => ['pl' => ['name' => 'Nowy produkt SEMPRE']],
                'parameters' => [[
                    'name' => 'Oficjalny producent',
                    'value' => 'SEMPRE',
                ]],
            ]],
        ]);

        $result = app(ProductDataExportService::class)->create($product, $integration);

        $this->assertSame('321', $result['mapping']->external_product_id);
        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $product->id,
            'sales_channel_id' => $integration->sales_channel_id,
            'external_product_id' => '321',
            'external_variation_id' => null,
            'external_sku' => 'SEMPRE-NEW-SIMPLE',
        ]);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products'
            && $request['attributes'] === [[
                'id' => 90,
                'position' => 0,
                'visible' => true,
                'variation' => false,
                'options' => ['SEMPRE'],
            ]]);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/products/attributes/90/terms'));
    }

    public function test_global_attribute_failure_aborts_before_updating_existing_product(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/attributes') {
                return Http::response([]);
            }

            if ($request->method() === 'POST' && $path === '/wp-json/wc/v3/products/attributes') {
                return Http::response([
                    'code' => 'woocommerce_rest_cannot_create',
                    'message' => 'Nie można utworzyć atrybutu.',
                ], 500);
            }

            if ($request->method() === 'PUT' && $path === '/wp-json/wc/v3/products/123') {
                return Http::response(['id' => 123, 'sku' => 'FAIL-ATTRIBUTE']);
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
            'settings' => ['product_export' => ['languages' => ['pl']]],
        ]);
        $product = Product::query()->create([
            'sku' => 'FAIL-ATTRIBUTE',
            'name' => 'Produkt z błędnym atrybutem',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'parameters' => [[
                    'name' => 'Kolor',
                    'value' => 'Czarny',
                ]],
            ]],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);

        try {
            app(ProductDataExportService::class)->export($product);
            $this->fail('Eksport powinien zatrzymać się przed aktualizacją produktu.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('nie utworzył globalnego atrybutu', $exception->getMessage());
            $this->assertStringContainsString('HTTP 500', $exception->getMessage());
        }

        Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123');
        $this->assertSame(0, IntegrationSyncLog::query()
            ->where('operation', 'export_product_data')
            ->where('status', 'success')
            ->count());
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

    /**
     * @param  list<array{id:int,name:string,slug:string,count?:int,lang?:string,language?:string,translations?:array<string,int>}>  $terms
     */
    private function fakeSempreGlobalAttributeTerms(array $terms, ?callable $fallback = null): void
    {
        Http::fake(function ($request) use ($terms, $fallback) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/attributes') {
                return Http::response([[
                    'id' => 90,
                    'name' => 'Oficjalny producent',
                    'slug' => 'pa_oficjalny-producent',
                ]]);
            }

            if ($request->method() === 'GET'
                && $path === '/wp-json/wc/v3/products/attributes/90/terms'
            ) {
                return Http::response($terms);
            }

            if (is_callable($fallback)) {
                return $fallback($request);
            }

            return Http::response([], 404);
        });
    }

    private function createGlobalTermIntegration(array $languages = ['pl']): WordpressIntegration
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => $languages]],
        ]);
    }

    /** @return array<string, mixed> */
    /**
     * @param  list<string>  $values
     * @param  list<string>|null  $valuesEn
     */
    private function createDefaultSizeDictionary(
        array $values = ['S', 'M'],
        ?array $valuesEn = null,
    ): ProductParameterDefinition {
        return ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => $values,
            'values_en' => $valuesEn ?? $values,
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 10,
        ]);
    }

    private function readyProductTranslationCapabilities(): array
    {
        return [
            'available' => true,
            'plugin_version' => '0.5.3',
            'languages' => ['pl', 'en'],
            'attribute_term_translation_link_available' => true,
            'variation_translation_link_available' => true,
            'variation_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations',
        ];
    }

    /**
     * Add a small stateful Woo taxonomy server in front of a test's existing
     * HTTP fake. Product export now resolves global attributes and their terms
     * before sending the product payload, so older scenario tests should not
     * have to duplicate that protocol in every callback.
     *
     * @param  array<string, mixed>|callable|null  $fallback
     */
    private function fakeWooWithGlobalAttributes(array|callable|null $fallback = null): void
    {
        $attributes = [];
        $terms = [];
        $nextAttributeId = 70;
        $nextTermId = 700;

        Http::fake(function ($request) use (
            &$attributes,
            &$terms,
            &$nextAttributeId,
            &$nextTermId,
            $fallback,
        ) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            if ($request->method() === 'GET'
                && $path === '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities'
            ) {
                return Http::response([
                    'available' => true,
                    'plugin_version' => '0.5.3',
                    'languages' => ['pl', 'en'],
                    'attribute_term_translation_link_available' => true,
                    'variation_translation_link_available' => true,
                    'variation_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations',
                ]);
            }

            if ($request->method() === 'POST'
                && $path === '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations'
            ) {
                return Http::response([
                    'linked' => true,
                    'changed' => true,
                    'translations' => $request['translations'],
                    'parents' => $request['parents'],
                ]);
            }

            if ($path === '/wp-json/wc/v3/products/attributes') {
                if ($request->method() === 'GET') {
                    return Http::response(array_values($attributes));
                }

                if ($request->method() === 'POST') {
                    $attribute = [
                        'id' => $nextAttributeId++,
                        'name' => (string) $request['name'],
                        'slug' => (string) $request['slug'],
                        'order_by' => (string) ($request['order_by'] ?? 'menu_order'),
                    ];
                    $attributes[$attribute['slug']] = $attribute;

                    return Http::response($attribute, 201);
                }
            }

            if (preg_match('#^/wp-json/wc/v3/products/attributes/(\d+)$#', $path, $matches) === 1
                && $request->method() === 'PUT'
            ) {
                $attributeId = (int) $matches[1];
                $key = collect($attributes)->search(
                    fn (array $attribute): bool => (int) $attribute['id'] === $attributeId,
                );

                if ($key !== false) {
                    $attributes[$key]['order_by'] = (string) $request['order_by'];

                    return Http::response($attributes[$key]);
                }
            }

            if (preg_match('#^/wp-json/wc/v3/products/attributes/(\d+)/terms$#', $path, $matches) === 1) {
                $attributeId = (int) $matches[1];
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $language = trim((string) ($query['lang'] ?? ''));

                if ($request->method() === 'GET') {
                    return Http::response(collect($terms[$attributeId] ?? [])
                        ->filter(fn (array $term): bool => $language === '' || $term['_language'] === $language)
                        ->map(function (array $term): array {
                            unset($term['_language']);

                            return $term;
                        })
                        ->values()
                        ->all());
                }

                if ($request->method() === 'POST') {
                    $term = [
                        'id' => $nextTermId++,
                        'name' => (string) $request['name'],
                        'slug' => (string) $request['slug'],
                        'menu_order' => (int) ($request['menu_order'] ?? 0),
                        '_language' => $language,
                    ];
                    $terms[$attributeId][] = $term;
                    unset($term['_language']);

                    return Http::response($term, 201);
                }
            }

            if (preg_match(
                '#^/wp-json/wc/v3/products/attributes/(\d+)/terms/(\d+)$#',
                $path,
                $matches,
            ) === 1 && $request->method() === 'PUT') {
                $attributeId = (int) $matches[1];
                $termId = (int) $matches[2];
                $index = collect($terms[$attributeId] ?? [])->search(
                    fn (array $term): bool => (int) $term['id'] === $termId,
                );

                if ($index !== false) {
                    $terms[$attributeId][$index]['menu_order'] = (int) $request['menu_order'];
                    $term = $terms[$attributeId][$index];
                    unset($term['_language']);

                    return Http::response($term);
                }
            }

            if (preg_match(
                '#^/wp-json/wc-lemon-erp/v1/catalog/products/attributes/(\d+)/terms/translations$#',
                $path,
                $matches,
            ) === 1 && $request->method() === 'POST') {
                $attributeId = (int) $matches[1];
                $attribute = collect($attributes)->first(
                    fn (array $candidate): bool => (int) $candidate['id'] === $attributeId,
                );

                return Http::response([
                    'linked' => true,
                    'changed' => true,
                    'resource' => 'product_attribute_term',
                    'attribute_id' => $attributeId,
                    'taxonomy' => (string) ($attribute['slug'] ?? ''),
                    'translations' => (array) $request['translations'],
                ]);
            }

            if (is_callable($fallback)) {
                return $fallback($request);
            }

            if (is_array($fallback)) {
                foreach ($fallback as $pattern => $response) {
                    if (is_string($pattern) && Str::is($pattern, $url)) {
                        return is_callable($response) ? $response($request) : $response;
                    }
                }

                return Http::response([], 404);
            }

            return Http::response();
        });
    }
}
