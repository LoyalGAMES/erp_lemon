<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\ProductDataExportService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooCommerceCustomProductLabelBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_requeues_only_mapped_erp_roots_with_a_custom_label_and_is_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-07-17 10:00:00');

        try {
            $channel = SalesChannel::query()->create([
                'code' => 'B2C-LABEL-BACKFILL',
                'name' => 'Woo label backfill',
                'type' => 'woocommerce',
                'is_active' => true,
            ]);
            $labeledMapping = $this->mapping(
                $this->product('LABEL-PREORDER', [
                    'pl' => 'PRZEDSPRZEDAŻ',
                    'en' => 'PREORDER',
                    'bg_color' => '#191d1e',
                    'text_color' => '#ffffff',
                ]),
                $channel,
                '8100',
            );
            $unlabeledMapping = $this->mapping(
                $this->product('LABEL-EMPTY', [
                    'pl' => null,
                    'en' => null,
                    'bg_color' => '#191d1e',
                    'text_color' => '#ffffff',
                ]),
                $channel,
                '8200',
            );
            $woocommerceOwnedMapping = $this->mapping(
                $this->product('LABEL-WOO-OWNED', ['pl' => 'PROMOCJA'], 'woocommerce'),
                $channel,
                '8300',
            );

            $this->runMigration();

            $backfill = (array) data_get(
                $labeledMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill',
            );
            $this->assertSame('pending', $backfill['status'] ?? null);
            $this->assertSame(LegacyVariantFamilyBackfillService::REASON, $backfill['reason'] ?? null);
            $this->assertSame(
                LegacyVariantFamilyBackfillService::CUSTOM_PRODUCT_LABELS_CATALOG_SYNC_REVISION,
                $backfill['revision'] ?? null,
            );
            $this->assertSame(now()->toISOString(), $backfill['requested_at'] ?? null);
            $this->assertNull(data_get(
                $unlabeledMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill',
            ));
            $this->assertNull(data_get(
                $woocommerceOwnedMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill',
            ));

            CarbonImmutable::setTestNow('2026-07-17 10:10:00');
            $this->runMigration();

            $this->assertSame($backfill, data_get(
                $labeledMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill',
            ));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_label_backfill_is_prioritized_on_the_critical_export_queue(): void
    {
        Bus::fake();
        Http::fake(Http::response([], 404));
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-LABEL-DISPATCH',
            'name' => 'Woo label dispatch',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo label dispatch',
            'base_url' => 'https://label-dispatch.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);
        $mapping = $this->mapping(
            $this->product('LABEL-DISPATCH', ['pl' => 'PRZEDSPRZEDAŻ', 'en' => 'PREORDER']),
            $channel,
            '8400',
        );
        $this->runMigration();

        $result = app(LegacyVariantFamilyBackfillService::class)->dispatchPending(1);

        $this->assertSame(1, $result['dispatched'], json_encode($result) ?: 'dispatch result');
        $this->assertSame('queued', data_get(
            $mapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        Bus::assertDispatched(
            ExportWooCommerceProductDataJob::class,
            fn (ExportWooCommerceProductDataJob $job): bool => $job->queue
                === LegacyVariantFamilyBackfillService::CRITICAL_EXPORT_QUEUE,
        );
        Http::assertNothingSent();
    }

    public function test_label_backfill_updates_only_storefront_meta_on_polish_and_english_products(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'PUT') {
                return Http::response([
                    'id' => str_contains($request->url(), '/products/500535') ? 500535 : 4382,
                ]);
            }

            return Http::response([], 404);
        });
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-LABEL-META',
            'name' => 'Woo label meta',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo label meta',
            'base_url' => 'https://label-meta.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);
        $product = $this->product('BLS6A0B124182B47', [
            'pl' => 'PREORDER',
            'en' => 'PREORDER',
            'bg_color' => '#191d1e',
            'text_color' => '#ffffff',
        ]);
        $attributes = (array) $product->attributes;
        data_set($attributes, 'master.shipping', [
            'days' => 11,
            'text' => 'Planowana wysyłka: {date}',
            'text_en' => 'Planned shipping: {date}',
            'preorder' => true,
        ]);
        data_set($attributes, 'woocommerce_translations.en', [
            'product_id' => '500535',
            'variation_id' => null,
            'sku' => $product->sku,
        ]);
        $product->forceFill(['attributes' => $attributes])->save();
        $token = 'custom-label-meta-token';
        $mapping = $this->mapping($product, $channel, '4382');
        $mapping->forceFill(['metadata' => [
            'product_data_export' => [
                'pending_token' => $token,
                'requested_at' => now()->toISOString(),
                'legacy_variant_backfill' => [
                    'status' => 'queued',
                    'reason' => LegacyVariantFamilyBackfillService::REASON,
                    'revision' => LegacyVariantFamilyBackfillService::CUSTOM_PRODUCT_LABELS_CATALOG_SYNC_REVISION,
                    'queued_revision' => LegacyVariantFamilyBackfillService::CUSTOM_PRODUCT_LABELS_CATALOG_SYNC_REVISION,
                ],
            ],
        ]])->save();

        (new ExportWooCommerceProductDataJob($product->id, $token))->handle(
            app(ProductDataExportService::class),
        );

        $requests = Http::recorded()
            ->map(fn (array $record) => $record[0])
            ->filter(fn ($request): bool => $request->method() === 'PUT')
            ->keyBy(fn ($request): string => $request->url());
        $this->assertSame([
            'https://label-meta.test/wp-json/wc/v3/products/4382',
            'https://label-meta.test/wp-json/wc/v3/products/500535?lang=en',
        ], $requests->keys()->all());

        foreach ($requests as $requestUrl => $request) {
            $this->assertSame(['meta_data'], array_keys($request->data()));
            $this->assertSame([
                '_lemon_product_label_text' => 'PREORDER',
                '_lemon_product_label_bg_color' => '#191d1e',
                '_lemon_product_label_text_color' => '#ffffff',
                'lemon_shipping_days' => '11',
                'lemon_shipping_text' => str_contains($requestUrl, '?lang=en')
                    ? 'Planned shipping: {date}'
                    : 'Planowana wysyłka: {date}',
                'lemon_preorder' => 'yes',
            ], collect($request['meta_data'])->pluck('value', 'key')->all());
        }

        $this->assertNull(data_get(
            $mapping->refresh()->metadata,
            'product_data_export.pending_token',
        ));
        $this->assertSame('completed', data_get(
            $mapping->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertDatabaseHas('integration_sync_logs', [
            'sales_channel_id' => $channel->id,
            'operation' => 'export_product_labels',
            'status' => 'success',
            'external_id' => '4382',
        ]);
    }

    public function test_deployment_sync_flushes_pending_label_meta_without_waiting_for_dispatcher(): void
    {
        Http::fake(Http::response(['id' => 4382]));
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-LABEL-DEPLOY',
            'name' => 'Woo label deploy',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo label deploy',
            'base_url' => 'https://label-deploy.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl']]],
        ]);
        $mapping = $this->mapping(
            $this->product('LABEL-DEPLOY', [
                'pl' => 'PREORDER',
                'en' => 'PREORDER',
                'bg_color' => '#191d1e',
                'text_color' => '#ffffff',
            ]),
            $channel,
            '4382',
        );
        $this->runMigration();

        $result = app(LegacyVariantFamilyBackfillService::class)
            ->syncPendingCustomProductLabels();

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(1, $result['succeeded']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('completed', data_get(
            $mapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://label-deploy.test/wp-json/wc/v3/products/4382'
            && collect($request['meta_data'])->pluck('value', 'key')->all() === [
                '_lemon_product_label_text' => 'PREORDER',
                '_lemon_product_label_bg_color' => '#191d1e',
                '_lemon_product_label_text_color' => '#ffffff',
                'lemon_shipping_days' => '',
                'lemon_shipping_text' => '',
                'lemon_preorder' => 'no',
            ]);
    }

    public function test_blocked_full_export_still_updates_storefront_configuration_once(): void
    {
        Http::fake(Http::response(['id' => 4382]));
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-STOREFRONT-BLOCKED',
            'name' => 'Woo storefront blocked',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo storefront blocked',
            'base_url' => 'https://storefront-blocked.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl']]],
        ]);
        $product = $this->product('BLS6A4E3B15CDF1D', [
            'pl' => 'PRZEDSPRZEDAŻ',
            'en' => 'PREORDER',
            'bg_color' => '#191d1e',
            'text_color' => '#ffffff',
        ]);
        $attributes = (array) $product->attributes;
        data_set($attributes, 'master.shipping', [
            'days' => 11,
            'text' => 'Planowana wysyłka: {date}',
            'preorder' => true,
        ]);
        $product->forceFill(['attributes' => $attributes])->save();
        $token = 'blocked-storefront-token';
        $mapping = $this->mapping($product, $channel, '4382');
        $metadata = [
            'product_data_export' => [
                'pending_token' => $token,
                'requested_at' => now()->toISOString(),
            ],
        ];
        data_set($metadata, WooOwnedVariantAxisRepairService::STATE_PATH, [
            'revision' => WooOwnedVariantAxisRepairService::REVISION,
            'status' => 'queued',
            'pending_token' => 'axis-repair-token',
        ]);
        $mapping->forceFill(['metadata' => $metadata])->save();
        $axisRepair = app(WooOwnedVariantAxisRepairService::class);
        $this->assertTrue($axisRepair->blocksFullExport($product));

        $job = new ExportWooCommerceProductDataJob($product->id, $token);
        $job->handle(app(ProductDataExportService::class), $axisRepair);
        $job->handle(app(ProductDataExportService::class), $axisRepair);

        $requests = Http::recorded()
            ->map(fn (array $record) => $record[0])
            ->filter(fn ($request): bool => $request->method() === 'PUT')
            ->values();
        $this->assertCount(1, $requests);
        $this->assertSame(['meta_data'], array_keys($requests->first()->data()));
        $this->assertSame([
            '_lemon_product_label_text' => 'PRZEDSPRZEDAŻ',
            '_lemon_product_label_bg_color' => '#191d1e',
            '_lemon_product_label_text_color' => '#ffffff',
            'lemon_shipping_days' => '11',
            'lemon_shipping_text' => 'Planowana wysyłka: {date}',
            'lemon_preorder' => 'yes',
        ], collect($requests->first()['meta_data'])->pluck('value', 'key')->all());
        $this->assertSame($token, data_get(
            $mapping->refresh()->metadata,
            'product_data_export.pending_token',
        ));
        $this->assertSame($token, data_get(
            $mapping->metadata,
            'product_data_export.storefront_metadata.synced_token',
        ));
    }

    public function test_deployment_repairs_storefront_metadata_for_a_recent_failed_full_export(): void
    {
        Http::fake(Http::response(['id' => 4382]));
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-STOREFRONT-DEPLOY',
            'name' => 'Woo storefront deploy repair',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo storefront deploy repair',
            'base_url' => 'https://storefront-deploy.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl']]],
        ]);
        $product = $this->product('BLS6A4E3B15CDF1D', [
            'pl' => 'PRZEDSPRZEDAŻ',
            'en' => 'PREORDER',
            'bg_color' => '#191d1e',
            'text_color' => '#ffffff',
        ]);
        $attributes = (array) $product->attributes;
        data_set($attributes, 'master.shipping', [
            'days' => 11,
            'text' => 'Planowana wysyłka: {date}',
            'preorder' => true,
        ]);
        $product->forceFill(['attributes' => $attributes])->save();
        $mapping = $this->mapping($product, $channel, '4382');
        $mapping->forceFill(['metadata' => [
            'product_data_export' => [
                'failed_at' => now()->toISOString(),
                'error' => 'unrelated historical attribute ambiguity',
            ],
        ]])->save();
        Artisan::call('down', ['--retry' => 60]);

        try {
            $exitCode = Artisan::call(
                'erp:sync-pending-woocommerce-storefront-metadata-during-maintenance',
                ['--limit' => 50],
            );
            $output = Artisan::output();
        } finally {
            Artisan::call('up');
        }

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('BLS6A4E3B15CDF1D', $output);
        $this->assertStringContainsString('scanned=1, succeeded=1, failed=0', $output);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://storefront-deploy.test/wp-json/wc/v3/products/4382'
            && collect($request['meta_data'])->pluck('value', 'key')->all() === [
                '_lemon_product_label_text' => 'PRZEDSPRZEDAŻ',
                '_lemon_product_label_bg_color' => '#191d1e',
                '_lemon_product_label_text_color' => '#ffffff',
                'lemon_shipping_days' => '11',
                'lemon_shipping_text' => 'Planowana wysyłka: {date}',
                'lemon_preorder' => 'yes',
            ]);
    }

    public function test_storefront_translation_migration_sets_and_exports_english_shipping_text(): void
    {
        Http::fake(Http::response(['id' => 4382]));
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-STOREFRONT-TRANSLATIONS',
            'name' => 'Woo storefront translations',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo storefront translations',
            'base_url' => 'https://storefront-translations.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);
        $product = $this->product('BLS6A4E3B15CDF1D', [
            'pl' => 'PRZEDSPRZEDAŻ',
            'en' => 'PREORDER',
            'bg_color' => '#191d1e',
            'text_color' => '#ffffff',
        ]);
        $attributes = (array) $product->attributes;
        data_set($attributes, 'master.shipping', [
            'days' => 15,
            'text' => 'Planowana wysyłka: {date}',
            'preorder' => true,
        ]);
        data_set($attributes, 'woocommerce_translations.en', [
            'product_id' => '500535',
            'variation_id' => null,
            'sku' => $product->sku,
        ]);
        $product->forceFill(['attributes' => $attributes])->save();
        $mapping = $this->mapping($product, $channel, '4382');

        $this->runStorefrontTranslationMigration();

        $this->assertSame(
            'Planned shipping: {date}',
            data_get($product->refresh()->masterData(), 'shipping.text_en'),
        );
        $this->assertSame(
            LegacyVariantFamilyBackfillService::STOREFRONT_TRANSLATIONS_SYNC_REVISION,
            data_get(
                $mapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill.revision',
            ),
        );

        $result = app(LegacyVariantFamilyBackfillService::class)
            ->syncPendingCustomProductLabels();

        $this->assertSame(1, $result['succeeded']);
        $requests = Http::recorded()
            ->map(fn (array $record) => $record[0])
            ->filter(fn ($request): bool => $request->method() === 'PUT')
            ->keyBy(fn ($request): string => $request->url());
        $this->assertSame(
            'Planowana wysyłka: {date}',
            collect($requests['https://storefront-translations.test/wp-json/wc/v3/products/4382']['meta_data'])
                ->pluck('value', 'key')
                ->get('lemon_shipping_text'),
        );
        $this->assertSame(
            'Planned shipping: {date}',
            collect($requests['https://storefront-translations.test/wp-json/wc/v3/products/500535?lang=en']['meta_data'])
                ->pluck('value', 'key')
                ->get('lemon_shipping_text'),
        );
    }

    private function product(string $sku, array $label, string $source = 'erp'): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'is_translation' => false,
            'attributes' => ['master' => [
                'source' => $source,
                'product_type' => 'variable',
                'custom_label' => $label,
            ]],
        ]);
    }

    private function mapping(Product $product, SalesChannel $channel, string $externalId): ProductChannelMapping
    {
        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => $externalId,
            'external_variation_id' => null,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
        ]);
    }

    private function runMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_07_17_000035_reexport_woocommerce_custom_product_label_meta.php',
        );
        $migration->up();
    }

    private function runStorefrontTranslationMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_07_17_000036_reexport_woocommerce_storefront_translations.php',
        );
        $migration->up();
    }
}
