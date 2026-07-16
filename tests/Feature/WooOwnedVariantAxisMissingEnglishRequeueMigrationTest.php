<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Jobs\RepairWooOwnedVariantAxisJob;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\ProductDataExportService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooOwnedVariantAxisMissingEnglishRequeueMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MISSING_ENGLISH_MAPPING_REASON = 'Brak mapowania istniejącej wersji EN — rodzina nie zostanie naprawiona częściowo.';

    public function test_migration_requeues_exact_states_and_invalidates_their_broad_exports(): void
    {
        Bus::fake([RepairWooOwnedVariantAxisJob::class]);
        Http::fake();
        CarbonImmutable::setTestNow('2026-07-15 20:00:00');

        try {
            $channel = $this->wooChannel('MISSING-EN-REQUEUE');
            $queuedRevision = WooOwnedVariantAxisRepairService::PREVIOUS_SYNCHRONIZED_REVISION
                .':missing-translation:axis-token-manual';
            $manualReview = $this->mapping($channel, 'MISSING-EN-MANUAL', [
                'operator_note' => 'preserve manual note',
                'maintenance' => [
                    'woo_owned_variant_axis_repair' => [
                        'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_SYNCHRONIZED_REVISION,
                        'status' => 'manual_review',
                        'pending_token' => 'stale-axis-token',
                        'queued_at' => '2026-07-15T18:00:00+00:00',
                        'completed_at' => '2026-07-15T18:05:00+00:00',
                        'failed_at' => '2026-07-15T18:05:00+00:00',
                        'next_attempt_at' => '2026-07-16T18:05:00+00:00',
                        'error' => 'historical failure',
                        'attempts' => 3,
                        'result' => [
                            'status' => 'deferred',
                            'reason' => self::MISSING_ENGLISH_MAPPING_REASON,
                            'allow_full_export' => true,
                        ],
                    ],
                ],
                'product_data_export' => [
                    'pending_token' => 'broad-export-token',
                    'requested_at' => '2026-07-15T18:05:00+00:00',
                    'legacy_variant_backfill' => [
                        'status' => 'queued',
                        'reason' => LegacyVariantFamilyBackfillService::REASON,
                        'revision' => $queuedRevision,
                        'queued_revision' => $queuedRevision,
                        'requested_at' => '2026-07-15T18:05:00+00:00',
                        'queued_at' => '2026-07-15T18:05:01+00:00',
                        'attempts' => 2,
                    ],
                ],
            ]);
            $secondaryChannel = $this->wooChannel('MISSING-EN-REQUEUE-SECONDARY');
            $secondaryMapping = $this->mappingForProduct(
                $secondaryChannel,
                $manualReview->product,
                [
                    'mapping_role' => 'secondary',
                    'product_data_export' => [
                        'pending_token' => 'broad-export-token',
                        'requested_at' => '2026-07-15T18:05:00+00:00',
                    ],
                ],
            );
            $pendingRevision = WooOwnedVariantAxisRepairService::PREVIOUS_SYNCHRONIZED_REVISION
                .':missing-translation:axis-token-pending';
            $pending = $this->mapping($channel, 'MISSING-EN-PENDING', [
                'maintenance' => [
                    'woo_owned_variant_axis_repair' => [
                        'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_SYNCHRONIZED_REVISION,
                        'status' => 'pending',
                        'requested_at' => '2026-07-15T18:10:00+00:00',
                        'result' => [
                            'status' => 'deferred',
                            'reason' => self::MISSING_ENGLISH_MAPPING_REASON,
                            'allow_full_export' => true,
                        ],
                    ],
                ],
                'product_data_export' => [
                    'legacy_variant_backfill' => [
                        'status' => 'pending',
                        'reason' => LegacyVariantFamilyBackfillService::REASON,
                        'revision' => $pendingRevision,
                        'requested_at' => '2026-07-15T18:10:00+00:00',
                        'next_attempt_at' => '2026-07-16T18:10:00+00:00',
                    ],
                ],
            ]);
            $queuedMetadata = $this->withTargetBackfill(
                $this->candidateMetadata('queued'),
                'queued-broad-export-token',
            );
            data_set(
                $queuedMetadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.pending_token',
                'stale-queued-axis-token',
            );
            data_set(
                $queuedMetadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.queued_at',
                '2026-07-15T18:15:00+00:00',
            );
            $queued = $this->mapping(
                $channel,
                'MISSING-EN-QUEUED',
                $queuedMetadata,
            );

            $this->runMigration();

            $manualMetadata = (array) $manualReview->refresh()->metadata;
            $pendingMetadata = (array) $pending->refresh()->metadata;
            $queuedMetadata = (array) $queued->refresh()->metadata;

            foreach ([$manualMetadata, $pendingMetadata, $queuedMetadata] as $metadata) {
                $repair = (array) data_get(
                    $metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH,
                );
                $this->assertSame(WooOwnedVariantAxisRepairService::PREVIOUS_SYNCHRONIZED_REVISION, $repair['revision'] ?? null);
                $this->assertSame('pending', $repair['status'] ?? null);
                $this->assertSame(now()->toISOString(), $repair['requested_at'] ?? null);
                $this->assertArrayNotHasKey('result', $repair);
                $this->assertArrayNotHasKey('pending_token', $repair);
                $this->assertArrayNotHasKey('queued_at', $repair);
                $this->assertArrayNotHasKey('completed_at', $repair);
                $this->assertArrayNotHasKey('failed_at', $repair);
                $this->assertArrayNotHasKey('next_attempt_at', $repair);
                $this->assertArrayNotHasKey('error', $repair);
            }

            $manualBackfill = (array) data_get(
                $manualMetadata,
                'product_data_export.legacy_variant_backfill',
            );
            $pendingBackfill = (array) data_get(
                $pendingMetadata,
                'product_data_export.legacy_variant_backfill',
            );

            $this->assertNull(data_get($manualMetadata, 'product_data_export.pending_token'));
            $this->assertNull(data_get($manualMetadata, 'product_data_export.requested_at'));
            $this->assertNull(data_get(
                $secondaryMapping->refresh()->metadata,
                'product_data_export.pending_token',
            ));
            $this->assertNull(data_get(
                $secondaryMapping->metadata,
                'product_data_export.requested_at',
            ));
            $this->assertNull(data_get(
                $queuedMetadata,
                'product_data_export.pending_token',
            ));
            $this->assertSame('secondary', data_get(
                $secondaryMapping->metadata,
                'mapping_role',
            ));
            $this->assertSame('completed', $manualBackfill['status'] ?? null);
            $this->assertSame(
                'superseded_missing_translation_full_export',
                $manualBackfill['reason'] ?? null,
            );
            $this->assertSame(
                LegacyVariantFamilyBackfillService::REASON,
                $manualBackfill['superseded_reason'] ?? null,
            );
            $this->assertSame($queuedRevision, $manualBackfill['revision'] ?? null);
            $this->assertSame(
                'woo_owned_existing_english_mapping_requeue_2026_07_15_000019',
                $manualBackfill['superseded_by'] ?? null,
            );
            $this->assertSame(now()->toISOString(), $manualBackfill['superseded_at'] ?? null);
            $this->assertSame(now()->toISOString(), $manualBackfill['completed_at'] ?? null);
            $this->assertArrayNotHasKey('queued_revision', $manualBackfill);
            $this->assertArrayNotHasKey('queued_at', $manualBackfill);
            $this->assertSame('completed', $pendingBackfill['status'] ?? null);
            $this->assertSame($pendingRevision, $pendingBackfill['revision'] ?? null);
            $this->assertArrayNotHasKey('next_attempt_at', $pendingBackfill);
            $this->assertSame('preserve manual note', $manualMetadata['operator_note'] ?? null);
            $this->assertSame(3, data_get(
                $manualMetadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.attempts',
            ));

            (new ExportWooCommerceProductDataJob(
                (int) $manualReview->product_id,
                'broad-export-token',
            ))->handle(
                app(ProductDataExportService::class),
                app(WooOwnedVariantAxisRepairService::class),
            );
            Http::assertNothingSent();

            $dispatch = app(WooOwnedVariantAxisRepairService::class)
                ->dispatchPending(1, 120);
            $this->assertSame(0, $dispatch['dispatched']);
            Bus::assertNotDispatched(RepairWooOwnedVariantAxisJob::class);

            $requestedAt = data_get(
                $manualMetadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.requested_at',
            );
            $supersededAt = $manualBackfill['superseded_at'] ?? null;
            CarbonImmutable::setTestNow('2026-07-15 20:05:00');
            $this->runMigration();

            $this->assertSame($requestedAt, data_get(
                $manualReview->refresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.requested_at',
            ));
            $this->assertSame($supersededAt, data_get(
                $manualReview->metadata,
                'product_data_export.legacy_variant_backfill.superseded_at',
            ));
            Http::assertNothingSent();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_migration_leaves_other_reasons_revisions_statuses_and_tokens_untouched(): void
    {
        Http::fake();
        CarbonImmutable::setTestNow('2026-07-15 21:00:00');

        try {
            $channel = $this->wooChannel('MISSING-EN-GUARDS');
            $unrelatedBackfill = $this->candidateMetadata('manual_review');
            data_set($unrelatedBackfill, 'product_data_export', [
                'pending_token' => 'unrelated-token',
                'requested_at' => '2026-07-15T19:00:00+00:00',
                'legacy_variant_backfill' => [
                    'status' => 'queued',
                    'reason' => LegacyVariantFamilyBackfillService::REASON,
                    'revision' => 'other_revision_2026_07_15',
                    'queued_revision' => 'other_revision_2026_07_15',
                    'queued_at' => '2026-07-15T19:00:00+00:00',
                ],
            ]);
            $unrelated = $this->mapping($channel, 'UNRELATED-BACKFILL', $unrelatedBackfill);
            $unrelatedBefore = $unrelated->metadata;

            $differentReason = $this->candidateMetadata('manual_review');
            data_set(
                $differentReason,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.result.reason',
                'Inny dokładny powód.',
            );
            $differentReason = $this->withTargetBackfill(
                $differentReason,
                'different-reason-token',
            );
            $differentReasonMapping = $this->mapping(
                $channel,
                'DIFFERENT-REASON',
                $differentReason,
            );
            $differentReasonBefore = $differentReasonMapping->metadata;

            $previousRevision = $this->candidateMetadata('pending');
            data_set(
                $previousRevision,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.revision',
                'woo_owned_size_variant_axis_previous',
            );
            $previousRevision = $this->withTargetBackfill(
                $previousRevision,
                'previous-revision-token',
            );
            $previousRevisionMapping = $this->mapping(
                $channel,
                'PREVIOUS-REVISION',
                $previousRevision,
            );
            $previousRevisionBefore = $previousRevisionMapping->metadata;

            $completed = $this->candidateMetadata('completed');
            $completed = $this->withTargetBackfill($completed, 'completed-token');
            $completedMapping = $this->mapping($channel, 'COMPLETED-STATE', $completed);
            $completedBefore = $completedMapping->metadata;

            $layered = $this->candidateMetadata('pending');
            $layeredRevision = WooOwnedVariantAxisRepairService::PREVIOUS_SYNCHRONIZED_REVISION
                .':missing-translation:layered-request';
            data_set($layered, 'product_data_export', [
                'pending_token' => 'older-unrelated-token',
                'requested_at' => '2026-07-15T19:10:00+00:00',
                'legacy_variant_backfill' => [
                    'status' => 'pending',
                    'reason' => LegacyVariantFamilyBackfillService::REASON,
                    'revision' => $layeredRevision,
                    'queued_revision' => 'older_unrelated_revision',
                    'queued_at' => '2026-07-15T19:00:00+00:00',
                ],
            ]);
            $layeredMapping = $this->mapping($channel, 'LAYERED-TOKEN', $layered);

            $newerRequested = $this->candidateMetadata('pending');
            $oldQueuedRevision = WooOwnedVariantAxisRepairService::PREVIOUS_SYNCHRONIZED_REVISION
                .':missing-translation:older-queued-request';
            data_set($newerRequested, 'product_data_export', [
                'pending_token' => 'older-missing-translation-token',
                'requested_at' => '2026-07-15T19:15:00+00:00',
                'legacy_variant_backfill' => [
                    'status' => 'pending',
                    'reason' => LegacyVariantFamilyBackfillService::REASON,
                    'revision' => 'newer_safe_revision',
                    'queued_revision' => $oldQueuedRevision,
                    'queued_at' => '2026-07-15T19:00:00+00:00',
                ],
            ]);
            $newerRequestedMapping = $this->mapping(
                $channel,
                'NEWER-REQUEST-OVER-OLD-QUEUE',
                $newerRequested,
            );
            $activeUnrelated = $this->candidateMetadata('pending');
            data_set($activeUnrelated, 'product_data_export', [
                'pending_token' => 'active-unrelated-token',
                'requested_at' => '2026-07-15T19:20:00+00:00',
                'legacy_variant_backfill' => [
                    'status' => 'pending',
                    'reason' => LegacyVariantFamilyBackfillService::REASON,
                    'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_SYNCHRONIZED_REVISION
                        .':missing-translation:obsolete-layer',
                    'requested_at' => '2026-07-15T19:20:00+00:00',
                ],
            ]);
            $activeUnrelatedMapping = $this->mapping(
                $channel,
                'ACTIVE-UNRELATED-TOKEN',
                $activeUnrelated,
            );

            $this->runMigration();

            $unrelated->refresh();
            $this->assertSame('unrelated-token', data_get(
                $unrelated->metadata,
                'product_data_export.pending_token',
            ));
            $this->assertSame(
                data_get($unrelatedBefore, 'product_data_export'),
                data_get($unrelated->metadata, 'product_data_export'),
            );
            $this->assertSame('pending', data_get(
                $unrelated->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.status',
            ));
            $this->assertNull(data_get(
                $unrelated->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.result',
            ));

            $this->assertSame($differentReasonBefore, $differentReasonMapping->refresh()->metadata);
            $this->assertSame($previousRevisionBefore, $previousRevisionMapping->refresh()->metadata);
            $this->assertSame($completedBefore, $completedMapping->refresh()->metadata);

            $layeredMapping->refresh();
            $this->assertSame('older-unrelated-token', data_get(
                $layeredMapping->metadata,
                'product_data_export.pending_token',
            ));
            $this->assertSame('older_unrelated_revision', data_get(
                $layeredMapping->metadata,
                'product_data_export.legacy_variant_backfill.revision',
            ));
            $this->assertSame('older_unrelated_revision', data_get(
                $layeredMapping->metadata,
                'product_data_export.legacy_variant_backfill.queued_revision',
            ));
            $this->assertSame('queued', data_get(
                $layeredMapping->metadata,
                'product_data_export.legacy_variant_backfill.status',
            ));
            $this->assertSame('pending', data_get(
                $layeredMapping->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.status',
            ));
            $this->assertNull(data_get(
                $layeredMapping->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.result',
            ));

            $newerRequestedMapping->refresh();
            $this->assertNull(data_get(
                $newerRequestedMapping->metadata,
                'product_data_export.pending_token',
            ));
            $this->assertNull(data_get(
                $newerRequestedMapping->metadata,
                'product_data_export.requested_at',
            ));
            $this->assertSame('newer_safe_revision', data_get(
                $newerRequestedMapping->metadata,
                'product_data_export.legacy_variant_backfill.revision',
            ));
            $this->assertSame('pending', data_get(
                $newerRequestedMapping->metadata,
                'product_data_export.legacy_variant_backfill.status',
            ));
            $this->assertNull(data_get(
                $newerRequestedMapping->metadata,
                'product_data_export.legacy_variant_backfill.queued_revision',
            ));
            $this->assertNull(data_get(
                $newerRequestedMapping->metadata,
                'product_data_export.legacy_variant_backfill.queued_at',
            ));
            (new ExportWooCommerceProductDataJob(
                (int) $newerRequestedMapping->product_id,
                'older-missing-translation-token',
            ))->handle(
                app(ProductDataExportService::class),
                app(WooOwnedVariantAxisRepairService::class),
            );
            Http::assertNothingSent();

            $activeUnrelatedMapping->refresh();
            $this->assertSame('active-unrelated-token', data_get(
                $activeUnrelatedMapping->metadata,
                'product_data_export.pending_token',
            ));
            $this->assertSame('completed', data_get(
                $activeUnrelatedMapping->metadata,
                'product_data_export.legacy_variant_backfill.status',
            ));
            $this->assertSame('superseded_missing_translation_full_export', data_get(
                $activeUnrelatedMapping->metadata,
                'product_data_export.legacy_variant_backfill.reason',
            ));
            $this->assertSame(LegacyVariantFamilyBackfillService::REASON, data_get(
                $activeUnrelatedMapping->metadata,
                'product_data_export.legacy_variant_backfill.superseded_reason',
            ));

            $activeJob = new ExportWooCommerceProductDataJob(
                (int) $activeUnrelatedMapping->product_id,
                'active-unrelated-token',
            );
            $clearToken = (new \ReflectionClass($activeJob))
                ->getMethod('clearCurrentSyncToken');
            $clearToken->invoke($activeJob);
            $activeUnrelatedMapping->refresh();
            $this->assertNull(data_get(
                $activeUnrelatedMapping->metadata,
                'product_data_export.pending_token',
            ));
            $this->assertSame('completed', data_get(
                $activeUnrelatedMapping->metadata,
                'product_data_export.legacy_variant_backfill.status',
            ));
            $this->assertSame('superseded_missing_translation_full_export', data_get(
                $activeUnrelatedMapping->metadata,
                'product_data_export.legacy_variant_backfill.reason',
            ));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_migration_requeues_families_completed_by_an_earlier_repair_for_final_alias_cleanup(): void
    {
        Bus::fake([RepairWooOwnedVariantAxisJob::class]);
        CarbonImmutable::setTestNow('2026-07-15 22:00:00');

        try {
            $channel = $this->wooChannel('COMPLETED-AXIS-CLEANUP');
            $mappings = collect(['repaired', 'already_canonical'])
                ->map(function (string $resultStatus) use ($channel): ProductChannelMapping {
                    $metadata = $this->candidateMetadata('completed');
                    data_set(
                        $metadata,
                        WooOwnedVariantAxisRepairService::STATE_PATH.'.completed_at',
                        '2026-07-15T19:05:00+00:00',
                    );
                    data_set(
                        $metadata,
                        WooOwnedVariantAxisRepairService::STATE_PATH.'.result',
                        [
                            'status' => $resultStatus,
                            'targets' => 2,
                            'mutations' => $resultStatus === 'repaired' ? 1 : 0,
                            'languages' => ['pl', 'en'],
                        ],
                    );

                    return $this->mapping(
                        $channel,
                        'COMPLETED-'.strtoupper($resultStatus),
                        $metadata,
                    );
                });

            $this->runMigration();

            foreach ($mappings as $mapping) {
                $repair = (array) data_get(
                    $mapping->refresh()->metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH,
                );
                $this->assertSame(WooOwnedVariantAxisRepairService::PREVIOUS_SYNCHRONIZED_REVISION, $repair['revision'] ?? null);
                $this->assertSame('pending', $repair['status'] ?? null);
                $this->assertSame(now()->toISOString(), $repair['requested_at'] ?? null);
                $this->assertArrayNotHasKey('completed_at', $repair);
                $this->assertArrayNotHasKey('result', $repair);
            }

            $dispatch = app(WooOwnedVariantAxisRepairService::class)
                ->dispatchPending(10, 120);
            $this->assertSame(0, $dispatch['dispatched']);
            Bus::assertNotDispatched(RepairWooOwnedVariantAxisJob::class);
            $requestedAt = $mappings->mapWithKeys(fn (ProductChannelMapping $mapping): array => [
                $mapping->id => data_get(
                    $mapping->refresh()->metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH.'.requested_at',
                ),
            ]);

            CarbonImmutable::setTestNow('2026-07-15 22:05:00');
            $this->runMigration();

            foreach ($mappings as $mapping) {
                $this->assertSame($requestedAt->get($mapping->id), data_get(
                    $mapping->refresh()->metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH.'.requested_at',
                ));
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /** @return array<string, mixed> */
    private function candidateMetadata(string $status): array
    {
        return [
            'maintenance' => [
                'woo_owned_variant_axis_repair' => [
                    'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_SYNCHRONIZED_REVISION,
                    'status' => $status,
                    'requested_at' => '2026-07-15T19:00:00+00:00',
                    'result' => [
                        'status' => 'deferred',
                        'reason' => self::MISSING_ENGLISH_MAPPING_REASON,
                        'allow_full_export' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withTargetBackfill(array $metadata, string $token): array
    {
        $revision = WooOwnedVariantAxisRepairService::PREVIOUS_SYNCHRONIZED_REVISION
            .':missing-translation:'.$token;
        data_set($metadata, 'product_data_export', [
            'pending_token' => $token,
            'requested_at' => '2026-07-15T19:00:00+00:00',
            'legacy_variant_backfill' => [
                'status' => 'queued',
                'reason' => LegacyVariantFamilyBackfillService::REASON,
                'revision' => $revision,
                'queued_revision' => $revision,
                'queued_at' => '2026-07-15T19:00:00+00:00',
            ],
        ]);

        return $metadata;
    }

    private function wooChannel(string $code): SalesChannel
    {
        return SalesChannel::query()->create([
            'code' => $code,
            'name' => 'Woo '.$code,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function mapping(
        SalesChannel $channel,
        string $sku,
        array $metadata,
    ): ProductChannelMapping {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'weight_kg' => 0,
            'quantity_precision' => 0,
            'is_active' => true,
            'is_translation' => false,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variable',
                ],
            ],
        ]);

        return $this->mappingForProduct($channel, $product, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    private function mappingForProduct(
        SalesChannel $channel,
        Product $product,
        array $metadata,
    ): ProductChannelMapping {
        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => (string) (900000 + $product->id + $channel->id),
            'external_variation_id' => null,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => $metadata,
        ]);
    }

    private function runMigration(): void
    {
        (require database_path(
            'migrations/2026_07_15_000019_requeue_woo_owned_axes_missing_existing_english_mapping.php',
        ))->up();
    }
}
