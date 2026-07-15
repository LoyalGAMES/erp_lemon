<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\ProductDataExportService;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LegacyVariantFamilyBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_backfill_job_stays_pending_for_an_automatic_retry(): void
    {
        Bus::fake();
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-BACKFILL-RETRY',
            'name' => 'Sklep B2C backfill retry',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'LEGACY-BACKFILL-RETRY',
            'name' => 'Historyczna rodzina do ponowienia',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $mapping = ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '900',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'product_data_export' => [
                    'pending_token' => 'legacy-backfill-retry-token',
                    'requested_at' => now()->toISOString(),
                    'legacy_variant_backfill' => [
                        'status' => 'queued',
                        'reason' => LegacyVariantFamilyBackfillService::REASON,
                    ],
                ],
            ],
        ]);

        (new ExportWooCommerceProductDataJob($product->id, 'legacy-backfill-retry-token'))
            ->failed(new \RuntimeException('WooCommerce plugin is not ready yet.'));

        $metadata = $mapping->refresh()->metadata;
        $this->assertNull(data_get($metadata, 'product_data_export.pending_token'));
        $this->assertSame('pending', data_get(
            $metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertNotNull(data_get(
            $metadata,
            'product_data_export.legacy_variant_backfill.next_attempt_at',
        ));

        $this->assertSame(0, Artisan::call('erp:dispatch-legacy-variant-backfill'));
        Bus::assertNotDispatched(ExportWooCommerceProductDataJob::class);
    }

    public function test_new_revision_survives_an_active_older_export_and_is_dispatched_after_it(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-BACKFILL-REVISION',
            'name' => 'Sklep B2C backfill revision',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'B2C Woo backfill revision',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl']]],
        ]);
        $product = Product::query()->create([
            'sku' => 'LEGACY-BACKFILL-REVISION',
            'name' => 'Rodzina ze starszym aktywnym eksportem',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'content' => ['pl' => ['name' => 'Rodzina ze starszym aktywnym eksportem']],
                'media' => [],
            ]],
        ]);
        $mapping = ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '808184',
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'product_data_export' => [
                    'pending_token' => 'older-active-token',
                    'requested_at' => now()->toISOString(),
                    'legacy_variant_backfill' => [
                        'status' => 'queued',
                        'reason' => LegacyVariantFamilyBackfillService::REASON,
                        'revision' => 'older-revision',
                        'queued_revision' => 'older-revision',
                    ],
                ],
            ],
        ]);
        $service = app(LegacyVariantFamilyBackfillService::class);

        $service->markPendingRevision(
            $product,
            LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION,
        );

        $metadata = $mapping->refresh()->metadata;
        $this->assertSame('older-active-token', data_get($metadata, 'product_data_export.pending_token'));
        $this->assertSame('pending', data_get($metadata, 'product_data_export.legacy_variant_backfill.status'));
        $this->assertSame('older-revision', data_get(
            $metadata,
            'product_data_export.legacy_variant_backfill.queued_revision',
        ));
        $this->assertSame(
            LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION,
            data_get($metadata, 'product_data_export.legacy_variant_backfill.revision'),
        );

        Http::fake(function ($request) use ($product) {
            if (str_ends_with(
                $request->url(),
                '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities',
            )) {
                return Http::response([
                    'available' => true,
                    'languages' => ['pl', 'en'],
                    'plugin_version' => '0.5.2',
                ]);
            }

            return Http::response([
                'id' => 808184,
                'sku' => $product->sku,
            ]);
        });
        (new ExportWooCommerceProductDataJob($product->id, 'older-active-token'))
            ->handle(app(ProductDataExportService::class));

        $metadata = $mapping->refresh()->metadata;
        $this->assertNull(data_get($metadata, 'product_data_export.pending_token'));
        $this->assertSame('pending', data_get($metadata, 'product_data_export.legacy_variant_backfill.status'));
        $this->assertNull(data_get($metadata, 'product_data_export.legacy_variant_backfill.queued_revision'));

        Bus::fake();
        $result = $service->dispatchPending(10);

        $this->assertSame(1, $result['dispatched']);
        Bus::assertDispatched(ExportWooCommerceProductDataJob::class, 1);
        $metadata = $mapping->refresh()->metadata;
        $this->assertNotSame('older-active-token', data_get($metadata, 'product_data_export.pending_token'));
        $this->assertSame('queued', data_get($metadata, 'product_data_export.legacy_variant_backfill.status'));
        $this->assertSame(
            LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION,
            data_get($metadata, 'product_data_export.legacy_variant_backfill.queued_revision'),
        );
    }

    public function test_plugin_0_5_2_keeps_all_translated_products_pending_until_safe_gtin_support_is_installed(): void
    {
        Bus::fake();
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-CAPABILITY-SPLIT',
            'name' => 'Sklep capability split',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo capability split',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);
        $simple = Product::query()->create([
            'sku' => 'CAPABILITY-SIMPLE',
            'name' => 'Simple',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => ['source' => 'erp', 'product_type' => 'simple']],
        ]);
        $variable = Product::query()->create([
            'sku' => 'CAPABILITY-VARIABLE',
            'name' => 'Variable',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => ['source' => 'erp', 'product_type' => 'variable']],
        ]);
        $variant = Product::query()->create([
            'sku' => 'CAPABILITY-VARIABLE-S',
            'name' => 'Variable S',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => ['source' => 'erp', 'product_type' => 'variation']],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $variable->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        $simpleMapping = ProductChannelMapping::query()->create([
            'product_id' => $simple->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '9100',
            'external_sku' => $simple->sku,
            'stock_sync_enabled' => true,
        ]);
        $variableMapping = ProductChannelMapping::query()->create([
            'product_id' => $variable->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '9200',
            'external_sku' => $variable->sku,
            'stock_sync_enabled' => true,
        ]);
        $service = app(LegacyVariantFamilyBackfillService::class);
        $service->markPendingRevision($simple, 'capability-split');
        $service->markPendingRevision($variable, 'capability-split');

        Http::fake(fn () => Http::response([
            'available' => true,
            'attribute_term_translation_link_available' => true,
            'languages' => ['pl', 'en'],
            'plugin_version' => '0.5.2',
        ]));

        $result = $service->dispatchPending(10);

        $this->assertSame(2, $result['scanned']);
        $this->assertSame(0, $result['dispatched']);
        $this->assertSame(2, $result['skipped_unready']);
        Bus::assertNotDispatched(ExportWooCommerceProductDataJob::class);
        $this->assertSame('pending', data_get(
            $simpleMapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertNull(data_get(
            $simpleMapping->metadata,
            'product_data_export.pending_token',
        ));
        $this->assertSame('pending', data_get(
            $variableMapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertNull(data_get(
            $variableMapping->metadata,
            'product_data_export.pending_token',
        ));
    }

    public function test_migration_queues_one_durable_family_export_that_creates_the_missing_english_family(): void
    {
        Bus::fake();
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-LEGACY-BACKFILL',
            'name' => 'Sklep B2C legacy backfill',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'B2C Woo legacy backfill',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            // EN remains enabled by the independent, default export policy.
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);
        $parent = Product::query()->create([
            'sku' => 'AUTO-LEGACY-COPY',
            'name' => 'Komplet historyczny',
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
                'prices' => ['retail_price_pln' => 699],
                'content' => [
                    'pl' => ['name' => 'Komplet historyczny', 'description' => '<p>Opis PL rodzica</p>'],
                    'en' => ['name' => 'Legacy set', 'description' => '<p>Parent EN description</p>'],
                ],
                'copy' => ['created_from_product_id' => 1000],
            ]],
        ]);
        $variant = Product::query()->create([
            'sku' => 'AUTO-LEGACY-COPY-S',
            'name' => 'Stara kopia - s',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'prices' => ['retail_price_pln' => 1],
                'content' => [
                    'pl' => ['description' => '<p>Nieaktualny opis</p>'],
                    'en' => ['description' => '<p>Stale description</p>'],
                ],
                'parameters' => [[
                    'name' => 'Rozmiar',
                    'name_en' => 'Size',
                    'value' => 's',
                    'value_en' => 's',
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
        $parentMapping = ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['creation_state' => 'completed'],
        ]);
        $variantMapping = ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_variation_id' => '124',
            'external_sku' => $variant->sku,
            'stock_sync_enabled' => true,
        ]);
        // Older databases may still contain a blank variation ID instead of
        // the normalized NULL now written by the mapping model.
        DB::table('product_channel_mappings')
            ->where('id', $parentMapping->id)
            ->update(['external_variation_id' => '   ']);

        (require database_path('migrations/2026_07_14_000002_promote_legacy_copied_product_variants.php'))->up();
        $backfillMigration = require database_path('migrations/2026_07_14_000005_mark_legacy_variant_families_for_woocommerce_backfill.php');
        $backfillMigration->up();

        $this->assertSame('pending', data_get(
            $parentMapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertSame(LegacyVariantFamilyBackfillService::REASON, data_get(
            $parentMapping->metadata,
            'product_data_export.legacy_variant_backfill.reason',
        ));
        $this->assertNull(data_get(
            $variantMapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill',
        ));

        // Re-running the data migration is safe and does not create a second request.
        $requestedAt = data_get(
            $parentMapping->metadata,
            'product_data_export.legacy_variant_backfill.requested_at',
        );
        $backfillMigration->up();
        $this->assertSame($requestedAt, data_get(
            $parentMapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.requested_at',
        ));

        $pluginReady = false;
        Http::fake(function ($request) use (&$pluginReady) {
            $url = $request->url();

            if ($request->method() === 'GET'
                && $url === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities'
            ) {
                return $pluginReady
                    ? Http::response([
                        'available' => true,
                        'attribute_term_translation_link_available' => true,
                        'resource' => 'product_translation_link',
                        'languages' => ['pl', 'en'],
                        'catalog_contract' => 1,
                        'plugin_version' => '0.5.3',
                        'variation_translation_link_available' => true,
                        'variation_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations',
                    ])
                    : Http::response(['code' => 'rest_no_route'], 404);
            }

            if ($request->method() === 'GET'
                && str_starts_with($url, 'https://shop.test/wp-json/wc/v3/products/attributes?')
            ) {
                return Http::response([[
                    'id' => 70,
                    'name' => 'Rozmiar',
                    'slug' => 'pa_rozmiar',
                ]]);
            }

            if ($request->method() === 'GET'
                && str_starts_with($url, 'https://shop.test/wp-json/wc/v3/products/attributes/70/terms?')
            ) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return Http::response(($query['lang'] ?? 'pl') === 'en' ? [[
                    'id' => 702,
                    'name' => 'S',
                    'slug' => 's-en',
                ]] : [[
                    'id' => 701,
                    'name' => 'S',
                    'slug' => 's-pl',
                ]]);
            }

            if ($request->method() === 'POST'
                && $url === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/attributes/70/terms/translations'
            ) {
                return Http::response([
                    'linked' => true,
                    'attribute_id' => 70,
                    'taxonomy' => 'pa_rozmiar',
                    'translations' => ['en' => 702, 'pl' => 701],
                ]);
            }

            if ($request->method() === 'PUT' && $url === 'https://shop.test/wp-json/wc/v3/products/123') {
                return Http::response(['id' => 123, 'sku' => 'AUTO-LEGACY-COPY']);
            }

            if ($request->method() === 'GET' && str_contains($url, '/wc/v3/products?') && str_contains($url, 'lang=en')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc/v3/products?lang=en') {
                return Http::response(['id' => 223, 'sku' => ''], 201);
            }

            if ($request->method() === 'PUT' && $url === 'https://shop.test/wp-json/wc/v3/products/223') {
                return Http::response(['id' => 223, 'sku' => $request['sku']]);
            }

            if ($request->method() === 'GET' && str_starts_with($url, 'https://shop.test/wp-json/wc/v3/products/223/variations')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc/v3/products/223/variations?lang=en') {
                return Http::response(['id' => 224, 'sku' => $request['sku']], 201);
            }

            if ($request->method() === 'PUT' && in_array($url, [
                'https://shop.test/wp-json/wc/v3/products/123/variations/124',
                'https://shop.test/wp-json/wc/v3/products/223/variations/224?lang=en',
            ], true)) {
                return Http::response(['id' => str_contains($url, '/224') ? 224 : 124, 'sku' => $request['sku']]);
            }

            if ($request->method() === 'POST' && $url === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations') {
                return Http::response([
                    'linked' => true,
                    'translations' => ['en' => 223, 'pl' => 123],
                    'translation_group' => 'product:123|223',
                ]);
            }

            if ($request->method() === 'POST'
                && $url === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations'
            ) {
                return Http::response([
                    'linked' => true,
                    'translations' => $request['translations'],
                    'parents' => $request['parents'],
                    'translation_group' => 'variation:124|224',
                ]);
            }

            throw new \RuntimeException('Unexpected request: '.$request->method().' '.$url);
        });
        $this->assertSame(0, Artisan::call('erp:dispatch-legacy-variant-backfill', [
            '--limit' => 10,
        ]));
        Bus::assertNotDispatched(ExportWooCommerceProductDataJob::class);
        $this->assertNull(data_get(
            $parentMapping->refresh()->metadata,
            'product_data_export.pending_token',
        ));
        $this->assertSame('pending', data_get(
            $parentMapping->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));

        $pluginReady = true;
        $this->assertTrue(
            app(WooCommerceClient::class)->productTranslationLinkingAvailable(
                WordpressIntegration::query()->where('sales_channel_id', $channel->id)->firstOrFail(),
                ['pl', 'en'],
            ),
            json_encode(Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all()) ?: '',
        );
        $this->assertSame(0, Artisan::call('erp:dispatch-legacy-variant-backfill', [
            '--limit' => 10,
        ]));
        Bus::assertDispatched(ExportWooCommerceProductDataJob::class, 1);
        $job = Bus::dispatched(ExportWooCommerceProductDataJob::class)->sole();

        $this->assertNotNull(data_get(
            $parentMapping->refresh()->metadata,
            'product_data_export.pending_token',
        ));
        $this->assertSame('queued', data_get(
            $parentMapping->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertSame(0, Artisan::call('erp:dispatch-legacy-variant-backfill', [
            '--limit' => 10,
        ]));
        Bus::assertDispatched(ExportWooCommerceProductDataJob::class, 1);

        $job->handle(app(ProductDataExportService::class));

        $this->assertNull(data_get(
            $parentMapping->refresh()->metadata,
            'product_data_export.pending_token',
        ));
        $this->assertSame('completed', data_get(
            $parentMapping->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertSame('223', data_get(
            $parent->refresh()->attributes,
            'woocommerce_translations.en.product_id',
        ));
        $this->assertSame('224', data_get(
            $variant->refresh()->attributes,
            'woocommerce_translations.en.variation_id',
        ));
        $this->assertSame(2, ProductChannelAlias::query()
            ->where('sales_channel_id', $channel->id)
            ->where('language', 'en')
            ->count());
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/translations'
            && $request['translations'] === ['en' => 223, 'pl' => 123]);
    }
}
