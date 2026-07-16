<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Services\WooCommerce\WooOwnedVariantAxisDeploymentGate;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WooOwnedVariantAxisCurrentLegacyDefaultLanguageRequeueMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_previous_000028_local_snapshots_remain_synchronized_after_the_revision_bump(): void
    {
        $this->assertTrue(WooOwnedVariantAxisRepairService::isSynchronizedRevision(
            WooOwnedVariantAxisRepairService::PREVIOUS_CANONICAL_SIZE_TAXONOMY_REVISION,
        ));
    }

    public function test_migration_requeues_only_the_exact_active_woo_parent_failure_and_promotes_every_other_000028_gate_state(): void
    {
        CarbonImmutable::setTestNow('2026-07-16 22:00:00');

        try {
            $woo = $this->channel('AXIS-CURRENT-DEFAULT-WOO', 'woocommerce', true);
            $inactiveWoo = $this->channel('AXIS-CURRENT-DEFAULT-INACTIVE', 'woocommerce', false);
            $otherChannel = $this->channel('AXIS-CURRENT-DEFAULT-OTHER', 'api', true);
            $exactStates = [
                'axis-6' => $this->manualState($this->reason(500237, 6)),
                'axis-417' => $this->manualState($this->reason(500417, 417)),
            ];
            $exact = collect($exactStates)->mapWithKeys(fn (array $state, string $suffix): array => [
                $suffix => $this->mapping($woo, strtoupper($suffix), $state),
            ]);
            $otherStates = [
                'pl' => $this->manualState(str_replace(
                    'WooCommerce EN',
                    'WooCommerce PL',
                    $this->reason(500237, 6),
                )),
                'suffix' => $this->manualState($this->reason(500237, 6).' dodatkowy tekst'),
                'bare' => $this->manualState(
                    'Domyślny wariant starej globalnej osi #6 nie wskazuje jednego terminu rozmiaru we właściwym języku.',
                ),
            ];
            $other = collect($otherStates)->mapWithKeys(fn (array $state, string $suffix): array => [
                $suffix => $this->mapping($woo, 'OTHER-'.strtoupper($suffix), $state),
            ]);
            $completedState = [
                'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_CANONICAL_SIZE_TAXONOMY_REVISION,
                'status' => 'completed',
                'completed_at' => '2026-07-16T19:30:00+00:00',
                'result' => ['status' => 'already_canonical', 'mutations' => 0],
            ];
            $completed = $this->mapping($woo, 'COMPLETED', $completedState);
            $inactiveState = $this->manualState($this->reason(500237, 6));
            $inactive = $this->mapping($inactiveWoo, 'INACTIVE', $inactiveState);
            $nonWooState = $this->manualState($this->reason(500237, 6));
            $nonWoo = $this->mapping($otherChannel, 'NON-WOO', $nonWooState);
            $olderState = $this->manualState($this->reason(500237, 6));
            $olderState['revision'] = WooOwnedVariantAxisRepairService::PREVIOUS_LEGACY_DEFAULT_TERM_LANGUAGE_REVISION;
            $older = $this->mapping($woo, 'OLDER', $olderState);
            $currentState = $this->manualState($this->reason(500237, 6));
            $currentState['revision'] = WooOwnedVariantAxisRepairService::REVISION;
            $current = $this->mapping($woo, 'CURRENT', $currentState);
            $childState = $this->manualState($this->reason(500237, 6));
            $child = $this->mapping($woo, 'CHILD', $childState, '600001');

            DB::table('product_channel_mappings')
                ->where('id', $exact['axis-6']->id)
                ->update([
                    'external_product_id' => ' 500237 ',
                    'external_variation_id' => '0',
                    'external_identity_key' => 'legacy-current-raw-parent-zero',
                ]);
            $rawIdentityBefore = $this->rawIdentity($exact['axis-6']->id);
            $productAttributesBefore = Product::query()
                ->orderBy('id')
                ->pluck('attributes', 'id')
                ->all();
            $olderBefore = $older->metadata;
            $currentBefore = $current->metadata;
            $childBefore = $child->metadata;

            $this->runMigration();

            foreach ($exact as $suffix => $mapping) {
                $metadata = (array) $mapping->refresh()->metadata;
                $state = (array) data_get(
                    $metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH,
                    [],
                );

                $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, $state['revision']);
                $this->assertSame('pending', $state['status']);
                $this->assertSame(now()->toISOString(), $state['requested_at']);
                $this->assertSame($exactStates[$suffix], $state['requeued_from']);
                $this->assertArrayNotHasKey('pending_token', $state);
                $this->assertArrayNotHasKey('queued_at', $state);
                $this->assertArrayNotHasKey('next_attempt_at', $state);
                $this->assertArrayNotHasKey('failed_at', $state);
                $this->assertArrayNotHasKey('error', $state);
                $this->assertArrayNotHasKey('completed_at', $state);
                $this->assertSame(
                    'preserve-'.mb_strtolower($mapping->external_sku),
                    data_get($metadata, 'operator_note'),
                );
                $this->assertSame(
                    'export-'.$mapping->external_sku,
                    data_get($metadata, 'product_data_export.pending_token'),
                );
            }

            foreach ($other as $suffix => $mapping) {
                $expected = $otherStates[$suffix];
                $expected['revision'] = WooOwnedVariantAxisRepairService::REVISION;
                $this->assertSame($expected, data_get(
                    $mapping->refresh()->metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH,
                ));
            }

            $expectedCompleted = $completedState;
            $expectedCompleted['revision'] = WooOwnedVariantAxisRepairService::REVISION;
            $this->assertSame($expectedCompleted, data_get(
                $completed->refresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH,
            ));

            foreach ([[$inactive, $inactiveState], [$nonWoo, $nonWooState]] as [$mapping, $state]) {
                $state['revision'] = WooOwnedVariantAxisRepairService::REVISION;
                $this->assertSame($state, data_get(
                    $mapping->refresh()->metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH,
                ));
            }

            $this->assertSame($olderBefore, $older->refresh()->metadata);
            $this->assertSame($currentBefore, $current->refresh()->metadata);
            $this->assertSame($childBefore, $child->refresh()->metadata);
            $this->assertSame($rawIdentityBefore, $this->rawIdentity($exact['axis-6']->id));
            $this->assertSame(
                $productAttributesBefore,
                Product::query()->orderBy('id')->pluck('attributes', 'id')->all(),
            );

            $postcondition = app(WooOwnedVariantAxisDeploymentGate::class)->postcondition();
            $this->assertFalse($postcondition['passed']);
            $this->assertSame(2, $postcondition['statuses']['pending']);

            $afterFirstRun = ProductChannelMapping::query()
                ->orderBy('id')
                ->pluck('metadata', 'id')
                ->all();
            CarbonImmutable::setTestNow('2026-07-16 22:05:00');
            $this->runMigration();
            $this->assertSame(
                $afterFirstRun,
                ProductChannelMapping::query()->orderBy('id')->pluck('metadata', 'id')->all(),
            );
            $this->assertSame($rawIdentityBefore, $this->rawIdentity($exact['axis-6']->id));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_requeued_state_receives_a_new_isolated_reservation_without_reviving_the_old_token(): void
    {
        $woo = $this->channel('AXIS-CURRENT-RESERVATION', 'woocommerce', true);
        $oldState = $this->manualState($this->reason(500237, 6));
        $target = $this->mapping($woo, 'RESERVATION', $oldState);
        $unrelated = $this->mapping(
            $woo,
            'UNRELATED',
            $this->manualState($this->reason(500237, 6).' dodatkowy tekst'),
        );
        $service = app(WooOwnedVariantAxisRepairService::class);

        $this->runMigration();

        $this->assertFalse($service->hasCurrentReservation(
            $target->product_id,
            'obsolete-token',
        ));
        Artisan::call('down', ['--retry' => 60]);

        try {
            $reservation = $service->reserveForIsolatedSynchronousRepair($target->product_id);
            $unrelatedReservation = $service->reserveForIsolatedSynchronousRepair(
                $unrelated->product_id,
            );
        } finally {
            Artisan::call('up');
        }

        $this->assertSame('reserved', $reservation['status']);
        $this->assertNotSame('obsolete-token', $reservation['token']);
        $this->assertSame(['status' => 'missing'], $unrelatedReservation);
        $state = (array) data_get(
            $target->refresh()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
            [],
        );
        $this->assertSame('queued', $state['status']);
        $this->assertSame($reservation['token'], $state['pending_token']);
        $this->assertSame(1, $state['attempts']);
        $this->assertSame($oldState, $state['requeued_from']);
        $this->assertArrayNotHasKey('next_attempt_at', $state);
    }

    private function reason(int $productId, int $attributeId): string
    {
        return "WooCommerce EN #{$productId}: Domyślny wariant starej globalnej osi #{$attributeId} nie wskazuje jednego terminu rozmiaru we właściwym języku.";
    }

    private function channel(string $code, string $type, bool $active): SalesChannel
    {
        return SalesChannel::query()->create([
            'code' => $code,
            'name' => $code,
            'type' => $type,
            'is_active' => $active,
        ]);
    }

    /** @return array<string,mixed> */
    private function manualState(string $reason): array
    {
        return [
            'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_CANONICAL_SIZE_TAXONOMY_REVISION,
            'status' => 'manual_review',
            'requested_at' => '2026-07-16T19:00:00+00:00',
            'completed_at' => '2026-07-16T19:05:00+00:00',
            'pending_token' => 'obsolete-token',
            'queued_at' => '2026-07-16T19:01:00+00:00',
            'next_attempt_at' => '2026-07-16T19:15:00+00:00',
            'failed_at' => '2026-07-16T19:02:00+00:00',
            'error' => 'old default language diagnostic',
            'result' => [
                'status' => 'manual_review',
                'targets' => 2,
                'mutations' => 0,
                'reason' => $reason,
            ],
        ];
    }

    /** @param array<string,mixed> $state */
    private function mapping(
        SalesChannel $channel,
        string $suffix,
        array $state,
        ?string $variationId = null,
    ): ProductChannelMapping {
        $product = Product::query()->create([
            'sku' => 'AXIS-CURRENT-'.$suffix,
            'name' => 'Axis current '.$suffix,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'woocommerce_import',
                    'product_type' => $variationId === null ? 'variable' : 'variation',
                    'variant_attribute' => 'Rozmiar',
                    'maintenance' => [
                        'legacy_size_variant_axis_recovery' => [
                            'previous_variant_attribute' => 'wariant',
                        ],
                    ],
                ],
            ],
        ]);

        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => (string) (500000 + $product->id),
            'external_variation_id' => $variationId,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'operator_note' => 'preserve-'.mb_strtolower($product->sku),
                'maintenance' => [
                    'legacy_size_variant_axis_recovery' => [
                        'snapshot' => 'legacy-'.$product->sku,
                    ],
                    'woo_owned_variant_axis_repair' => $state,
                ],
                'product_data_export' => [
                    'pending_token' => 'export-'.$product->sku,
                    'requested_at' => '2026-07-16T18:30:00+00:00',
                ],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function rawIdentity(int $mappingId): array
    {
        return (array) DB::table('product_channel_mappings')
            ->where('id', $mappingId)
            ->first([
                'external_product_id',
                'external_variation_id',
                'external_identity_key',
                'external_sku',
                'stock_sync_enabled',
            ]);
    }

    private function runMigration(): void
    {
        (require database_path(
            'migrations/2026_07_16_000029_requeue_current_legacy_global_size_default_language_repairs.php',
        ))->up();
    }
}
