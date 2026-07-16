<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooCommerceAttributePositionsAndLiveStockBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_requeues_only_a_mapped_canonical_variable_family_and_is_idempotent(): void
    {
        Http::fake();
        CarbonImmutable::setTestNow('2026-07-16 12:00:00');

        try {
            $channel = $this->createWooIntegration();
            $parent = $this->product('ATTRIBUTE-STOCK-VARIABLE', 'variable');
            $variant = $this->product('ATTRIBUTE-STOCK-VARIABLE-S', 'variation');
            ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $variant->id,
                'relation_type' => 'variant',
                'sort_order' => 10,
            ]);
            $parentMapping = $this->mapping($parent, $channel, '9700', [
                'product_data_export' => [
                    'legacy_variant_backfill' => [
                        'status' => 'completed',
                        'reason' => LegacyVariantFamilyBackfillService::REASON,
                        'revision' => LegacyVariantFamilyBackfillService::VARIATION_STOCK_MANAGEMENT_RECOVERY_REVISION,
                        'completed_at' => now()->subDay()->toISOString(),
                    ],
                ],
            ]);

            $simpleMapping = $this->mapping(
                $this->product('ATTRIBUTE-STOCK-SIMPLE', 'simple'),
                $channel,
                '9800',
            );
            $unmappedVariable = $this->product('ATTRIBUTE-STOCK-UNMAPPED', 'variable');
            $unmappedVariant = $this->product('ATTRIBUTE-STOCK-UNMAPPED-S', 'variation');
            ProductRelation::query()->create([
                'parent_product_id' => $unmappedVariable->id,
                'child_product_id' => $unmappedVariant->id,
                'relation_type' => 'variant',
                'sort_order' => 10,
            ]);

            $this->runMigration();

            $backfill = (array) data_get(
                $parentMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill',
            );
            $this->assertSame('pending', $backfill['status'] ?? null);
            $this->assertSame(LegacyVariantFamilyBackfillService::REASON, $backfill['reason'] ?? null);
            $this->assertSame(
                LegacyVariantFamilyBackfillService::ATTRIBUTE_POSITIONS_AND_LIVE_STOCK_REVISION,
                $backfill['revision'] ?? null,
            );
            $this->assertSame(now()->toISOString(), $backfill['requested_at'] ?? null);
            $this->assertArrayNotHasKey('completed_at', $backfill);

            $this->assertNull(data_get(
                $simpleMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill',
            ));
            $this->assertDatabaseMissing('product_channel_mappings', [
                'product_id' => $unmappedVariable->id,
            ]);

            $requestedAt = $backfill['requested_at'];
            CarbonImmutable::setTestNow('2026-07-16 12:10:00');
            $this->runMigration();

            $rerunBackfill = (array) data_get(
                $parentMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill',
            );
            $this->assertSame($requestedAt, $rerunBackfill['requested_at'] ?? null);
            $this->assertSame($backfill, $rerunBackfill);
            $this->assertNull(data_get(
                $simpleMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill',
            ));
            Http::assertNothingSent();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_backfill_waits_for_plugin_0_5_6_before_exporting_the_repair(): void
    {
        Bus::fake();
        $channel = $this->createWooIntegration();
        $parent = $this->product('ATTRIBUTE-STOCK-PLUGIN-GATE', 'variable');
        $variant = $this->product('ATTRIBUTE-STOCK-PLUGIN-GATE-S', 'variation');
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        $mapping = $this->mapping($parent, $channel, '9900');
        $this->runMigration();

        $pluginVersion = '0.5.5';
        Http::fake(function () use (&$pluginVersion) {
            return Http::response([
                'available' => true,
                'plugin_version' => $pluginVersion,
                'languages' => ['pl', 'en'],
                'attribute_term_translation_link_available' => true,
                'variation_translation_link_available' => true,
                'variation_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations',
            ]);
        });
        $blocked = app(LegacyVariantFamilyBackfillService::class)->dispatchPending(1);

        $this->assertSame(0, $blocked['dispatched'], json_encode($blocked) ?: 'blocked result');
        $this->assertSame(1, $blocked['skipped_unready'], json_encode($blocked) ?: 'blocked result');
        $this->assertSame('pending', data_get(
            $mapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        Bus::assertNotDispatched(ExportWooCommerceProductDataJob::class);

        $pluginVersion = '0.5.6';
        $dispatched = app(LegacyVariantFamilyBackfillService::class)->dispatchPending(1);

        $this->assertSame(1, $dispatched['dispatched'], json_encode($dispatched) ?: 'dispatch result');
        $this->assertSame(0, $dispatched['skipped_unready'], json_encode($dispatched) ?: 'dispatch result');
        $this->assertSame('queued', data_get(
            $mapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        Bus::assertDispatched(
            ExportWooCommerceProductDataJob::class,
            fn (ExportWooCommerceProductDataJob $job): bool => $job->queue
                === LegacyVariantFamilyBackfillService::CRITICAL_EXPORT_QUEUE,
        );
        Bus::assertDispatched(ExportWooCommerceProductDataJob::class, 1);
    }

    public function test_reservation_rejects_a_revision_changed_after_readiness_was_checked(): void
    {
        $channel = $this->createWooIntegration();
        $parent = $this->product('ATTRIBUTE-STOCK-REVISION-RACE', 'variable');
        $variant = $this->product('ATTRIBUTE-STOCK-REVISION-RACE-S', 'variation');
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        $mapping = $this->mapping($parent, $channel, '9950');
        $service = app(LegacyVariantFamilyBackfillService::class);
        $service->markPendingRevision($parent, 'older-revision');
        $service->markPendingRevision(
            $parent,
            LegacyVariantFamilyBackfillService::ATTRIBUTE_POSITIONS_AND_LIVE_STOCK_REVISION,
        );
        $reserve = new \ReflectionMethod($service, 'reserve');
        $reserve->setAccessible(true);

        $result = $reserve->invoke($service, $mapping->id, 120, 'older-revision');

        $this->assertSame(['status' => 'changed'], $result);
        $this->assertSame('pending', data_get(
            $mapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertSame(
            LegacyVariantFamilyBackfillService::ATTRIBUTE_POSITIONS_AND_LIVE_STOCK_REVISION,
            data_get(
                $mapping->metadata,
                'product_data_export.legacy_variant_backfill.revision',
            ),
        );
        $this->assertNull(data_get($mapping->metadata, 'product_data_export.pending_token'));
    }

    private function createWooIntegration(): SalesChannel
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-ATTRIBUTE-STOCK-REEXPORT',
            'name' => 'Woo attribute and stock re-export',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo attribute and stock re-export',
            'base_url' => 'https://attribute-stock-reexport.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);

        return $channel;
    }

    private function product(string $sku, string $type): Product
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
                'source' => 'erp',
                'product_type' => $type,
                'content' => [
                    'pl' => ['name' => $sku],
                    'en' => ['name' => $sku.' EN'],
                ],
            ]],
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function mapping(
        Product $product,
        SalesChannel $channel,
        string $externalProductId,
        array $metadata = [],
    ): ProductChannelMapping {
        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => $externalProductId,
            'external_variation_id' => null,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => array_replace_recursive(
                ['mapping_role' => 'primary', 'language' => 'pl'],
                $metadata,
            ),
        ]);
    }

    private function runMigration(): void
    {
        (require database_path(
            'migrations/2026_07_16_000023_reexport_woocommerce_attribute_positions_and_live_stock.php',
        ))->up();
    }
}
