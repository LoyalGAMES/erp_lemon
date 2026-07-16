<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WooOwnedVariantAxisExactLegacyDefaultSlugRequeueMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_previous_000029_snapshots_remain_synchronized_after_the_revision_bump(): void
    {
        $this->assertTrue(WooOwnedVariantAxisRepairService::isSynchronizedRevision(
            WooOwnedVariantAxisRepairService::PREVIOUS_EXACT_LEGACY_DEFAULT_SLUG_REVISION,
        ));
    }

    public function test_migration_requeues_only_the_exact_active_woo_parent_failure_and_is_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-07-16 22:30:00');

        try {
            $woo = SalesChannel::query()->create([
                'code' => 'AXIS-EXACT-SLUG',
                'name' => 'Axis exact slug',
                'type' => 'woocommerce',
                'is_active' => true,
            ]);
            $exactState = $this->manualState($this->reason(500460, 6));
            $unrelatedState = $this->manualState($this->reason(500460, 6).' dodatkowy tekst');
            $completedState = [
                'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_EXACT_LEGACY_DEFAULT_SLUG_REVISION,
                'status' => 'completed',
                'completed_at' => '2026-07-16T20:00:00+00:00',
                'result' => ['status' => 'already_canonical', 'mutations' => 0],
            ];
            $exact = $this->mapping($woo, 'EXACT', $exactState);
            $unrelated = $this->mapping($woo, 'UNRELATED', $unrelatedState);
            $completed = $this->mapping($woo, 'COMPLETED', $completedState);
            $child = $this->mapping($woo, 'CHILD', $exactState, '600639');

            $this->runMigration();

            $state = (array) data_get(
                $exact->refresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH,
                [],
            );
            $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, $state['revision']);
            $this->assertSame('pending', $state['status']);
            $this->assertSame(now()->toISOString(), $state['requested_at']);
            $this->assertSame($exactState, $state['requeued_from']);
            $this->assertArrayNotHasKey('pending_token', $state);
            $this->assertArrayNotHasKey('completed_at', $state);

            $expectedUnrelated = $unrelatedState;
            $expectedUnrelated['revision'] = WooOwnedVariantAxisRepairService::REVISION;
            $this->assertSame($expectedUnrelated, data_get(
                $unrelated->refresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH,
            ));

            $expectedCompleted = $completedState;
            $expectedCompleted['revision'] = WooOwnedVariantAxisRepairService::REVISION;
            $this->assertSame($expectedCompleted, data_get(
                $completed->refresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH,
            ));
            $this->assertSame($exactState, data_get(
                $child->refresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH,
            ));

            $afterFirstRun = ProductChannelMapping::query()
                ->orderBy('id')
                ->pluck('metadata', 'id')
                ->all();
            CarbonImmutable::setTestNow('2026-07-16 22:35:00');
            $this->runMigration();
            $this->assertSame(
                $afterFirstRun,
                ProductChannelMapping::query()->orderBy('id')->pluck('metadata', 'id')->all(),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /** @return array<string,mixed> */
    private function manualState(string $reason): array
    {
        return [
            'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_EXACT_LEGACY_DEFAULT_SLUG_REVISION,
            'status' => 'manual_review',
            'requested_at' => '2026-07-16T19:00:00+00:00',
            'completed_at' => '2026-07-16T19:05:00+00:00',
            'pending_token' => 'obsolete-token',
            'result' => [
                'status' => 'manual_review',
                'targets' => 2,
                'mutations' => 0,
                'reason' => $reason,
            ],
        ];
    }

    private function reason(int $productId, int $attributeId): string
    {
        return "WooCommerce EN #{$productId}: Domyślny wariant starej globalnej osi #{$attributeId} nie wskazuje jednego terminu rozmiaru we właściwym języku.";
    }

    /** @param array<string,mixed> $state */
    private function mapping(
        SalesChannel $channel,
        string $suffix,
        array $state,
        ?string $variationId = null,
    ): ProductChannelMapping {
        $product = Product::query()->create([
            'sku' => 'AXIS-EXACT-'.$suffix,
            'name' => 'Axis exact '.$suffix,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => ['variant_attribute' => 'Rozmiar']],
        ]);

        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => (string) (500000 + $product->id),
            'external_variation_id' => $variationId,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'operator_note' => 'preserve-'.$suffix,
                'maintenance' => [
                    'woo_owned_variant_axis_repair' => $state,
                ],
            ],
        ]);
    }

    private function runMigration(): void
    {
        (require database_path(
            'migrations/2026_07_16_000030_requeue_exact_legacy_global_size_default_slug_repairs.php',
        ))->up();
    }
}
