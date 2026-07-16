<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooOwnedVariantAxisPostRepairCatalogSyncMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_previous_child_assignment_catalog_sync_revision_keeps_critical_priority_after_revision_bump(): void
    {
        Bus::fake([ExportWooCommerceProductDataJob::class]);
        Http::fake(fn () => Http::response([
            'available' => true,
            'attribute_term_translation_link_available' => true,
            'variation_translation_link_available' => true,
            'variation_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations',
            'languages' => ['pl', 'en'],
            'plugin_version' => '0.5.3',
        ]));
        $channel = SalesChannel::query()->create([
            'code' => 'PREVIOUS-CHILD-SYNC',
            'name' => 'Previous child sync',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Previous child sync Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);
        $mapping = $this->completedMapping($channel, 'PREVIOUS-CHILD-SYNC', 'repaired');
        $metadata = (array) $mapping->metadata;
        data_set($metadata, 'product_data_export.legacy_variant_backfill', [
            'status' => 'pending',
            'reason' => LegacyVariantFamilyBackfillService::REASON,
            'revision' => LegacyVariantFamilyBackfillService::PREVIOUS_CHILD_SIZE_ASSIGNMENT_CATALOG_SYNC_REVISION,
            'requested_at' => '2026-07-16T20:00:00+00:00',
        ]);
        $mapping->update(['metadata' => $metadata]);

        $dispatch = app(LegacyVariantFamilyBackfillService::class)->dispatchPending(1);

        $this->assertSame(1, $dispatch['dispatched']);
        Bus::assertDispatched(
            ExportWooCommerceProductDataJob::class,
            fn (ExportWooCommerceProductDataJob $job): bool => $job->queue
                === LegacyVariantFamilyBackfillService::CRITICAL_EXPORT_QUEUE,
        );
    }

    public function test_migration_requeues_completed_repairs_and_preserves_an_unrelated_active_export(): void
    {
        Bus::fake([ExportWooCommerceProductDataJob::class]);
        Http::fake(fn () => Http::response([
            'available' => true,
            'attribute_term_translation_link_available' => true,
            'variation_translation_link_available' => true,
            'variation_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations',
            'languages' => ['pl', 'en'],
            'plugin_version' => '0.5.3',
        ]));
        CarbonImmutable::setTestNow('2026-07-15 23:00:00');

        try {
            $channel = SalesChannel::query()->create([
                'code' => 'POST-AXIS-CATALOG-SYNC',
                'name' => 'Post axis catalog sync',
                'type' => 'woocommerce',
                'is_active' => true,
            ]);
            WordpressIntegration::query()->create([
                'sales_channel_id' => $channel->id,
                'name' => 'Post axis Woo',
                'base_url' => 'https://shop.test',
                'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
                'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
                'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
            ]);
            $repaired = $this->completedMapping($channel, 'POST-AXIS-REPAIRED', 'repaired');
            $alreadyCanonical = $this->completedMapping(
                $channel,
                'POST-AXIS-CANONICAL',
                'already_canonical',
                [
                    'product_data_export' => [
                        'pending_token' => 'older-active-token',
                        'requested_at' => '2026-07-15T19:00:00+00:00',
                        'legacy_variant_backfill' => [
                            'status' => 'queued',
                            'reason' => LegacyVariantFamilyBackfillService::REASON,
                            'revision' => 'older-active-revision',
                            'queued_revision' => 'older-active-revision',
                            'queued_at' => '2026-07-15T19:00:00+00:00',
                        ],
                    ],
                ],
            );
            $manualReview = $this->completedMapping(
                $channel,
                'POST-AXIS-MANUAL',
                'manual_review',
            );
            $manualBefore = $manualReview->metadata;
            $previousRevision = $this->completedMapping(
                $channel,
                'POST-AXIS-OLD-REVISION',
                'repaired',
            );
            $previousMetadata = (array) $previousRevision->metadata;
            data_set(
                $previousMetadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.revision',
                'woo_owned_size_variant_axis_previous',
            );
            $previousRevision->update(['metadata' => $previousMetadata]);
            $previousBefore = $previousRevision->fresh()->metadata;

            $this->runMigration();

            foreach ([$repaired, $alreadyCanonical] as $mapping) {
                $mapping->refresh();
                $this->assertSame(
                    LegacyVariantFamilyBackfillService::WOO_OWNED_POST_AXIS_CATALOG_SYNC_REVISION,
                    data_get($mapping->metadata, 'product_data_export.legacy_variant_backfill.revision'),
                );
                $this->assertSame('pending', data_get(
                    $mapping->metadata,
                    'product_data_export.legacy_variant_backfill.status',
                ));
                $this->assertSame(now()->toISOString(), data_get(
                    $mapping->metadata,
                    'product_data_export.legacy_variant_backfill.requested_at',
                ));
            }

            $this->assertNull(data_get(
                $repaired->metadata,
                'product_data_export.pending_token',
            ));
            $this->assertSame('older-active-token', data_get(
                $alreadyCanonical->metadata,
                'product_data_export.pending_token',
            ));
            $this->assertSame('older-active-revision', data_get(
                $alreadyCanonical->metadata,
                'product_data_export.legacy_variant_backfill.queued_revision',
            ));
            $this->assertSame($manualBefore, $manualReview->refresh()->metadata);
            $this->assertSame($previousBefore, $previousRevision->refresh()->metadata);

            $dispatch = app(LegacyVariantFamilyBackfillService::class)->dispatchPending(1);
            $this->assertSame(1, $dispatch['dispatched']);
            Bus::assertDispatched(
                ExportWooCommerceProductDataJob::class,
                fn (ExportWooCommerceProductDataJob $job): bool => $job->queue
                    === LegacyVariantFamilyBackfillService::CRITICAL_EXPORT_QUEUE,
            );

            $requestedAt = data_get(
                $repaired->metadata,
                'product_data_export.legacy_variant_backfill.requested_at',
            );
            CarbonImmutable::setTestNow('2026-07-15 23:05:00');
            $this->runMigration();
            $this->assertSame($requestedAt, data_get(
                $repaired->refresh()->metadata,
                'product_data_export.legacy_variant_backfill.requested_at',
            ));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /** @param array<string, mixed> $extraMetadata */
    private function completedMapping(
        SalesChannel $channel,
        string $sku,
        string $resultStatus,
        array $extraMetadata = [],
    ): ProductChannelMapping {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => $sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'is_translation' => false,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
            ]],
        ]);
        $metadata = array_replace_recursive([
            'maintenance' => [
                'woo_owned_variant_axis_repair' => [
                    'revision' => WooOwnedVariantAxisRepairService::REVISION,
                    'status' => 'completed',
                    'requested_at' => '2026-07-15T18:00:00+00:00',
                    'completed_at' => '2026-07-15T18:05:00+00:00',
                    'result' => [
                        'status' => $resultStatus,
                        'targets' => 2,
                        'mutations' => $resultStatus === 'repaired' ? 1 : 0,
                    ],
                ],
            ],
        ], $extraMetadata);

        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => (string) (910000 + $product->id),
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => $metadata,
        ]);
    }

    private function runMigration(): void
    {
        (require database_path(
            'migrations/2026_07_15_000020_requeue_successful_woo_axis_catalog_exports.php',
        ))->up();
    }
}
