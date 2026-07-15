<?php

use App\Models\ProductChannelMapping;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MISSING_ENGLISH_MAPPING_REASON = 'Brak mapowania istniejącej wersji EN — rodzina nie zostanie naprawiona częściowo.';

    private const BACKFILL_PATH = 'product_data_export.legacy_variant_backfill';

    private const REPAIR_REVISION = 'woo_owned_size_variant_axis_2026_07_15_000017';

    private const MISSING_TRANSLATION_PREFIX = self::REPAIR_REVISION.':missing-translation:';

    private const MIGRATION_REVISION = 'woo_owned_existing_english_mapping_requeue_2026_07_15_000019';

    private const SUPERSEDED_BACKFILL_REASON = 'superseded_missing_translation_full_export';

    public function up(): void
    {
        if (! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('sales_channels')
        ) {
            return;
        }

        $visitedProductIds = [];

        ProductChannelMapping::query()
            ->whereHas('salesChannel', fn ($query) => $query
                ->where('type', 'woocommerce')
                ->where('is_active', true))
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use (&$visitedProductIds): void {
                foreach ($mappings as $mapping) {
                    $productId = (int) $mapping->product_id;

                    if (isset($visitedProductIds[$productId])
                        || ! $this->isRepairCandidate($mapping)
                    ) {
                        continue;
                    }

                    $visitedProductIds[$productId] = true;
                    $this->requeueFamily($productId);
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. The invalidated broad export may already have
        // reached a worker and must never become current again on rollback.
    }

    private function requeueFamily(int $productId): void
    {
        DB::transaction(function () use ($productId): void {
            $mappings = ProductChannelMapping::query()
                ->where('product_id', $productId)
                ->with('salesChannel')
                ->lockForUpdate()
                ->get();
            $repairMappings = $mappings->filter(fn (ProductChannelMapping $mapping): bool => $this->isActiveWooParentMapping($mapping)
                && $this->isRepairCandidate($mapping));

            if ($repairMappings->isEmpty()) {
                return;
            }

            $now = now()->toISOString();
            $invalidatedTokens = $mappings
                ->filter(function (ProductChannelMapping $mapping): bool {
                    $metadata = (array) $mapping->metadata;
                    $backfill = (array) data_get($metadata, self::BACKFILL_PATH, []);
                    $queuedRevision = trim((string) ($backfill['queued_revision'] ?? ''));

                    return ($backfill['reason'] ?? null) === LegacyVariantFamilyBackfillService::REASON
                        && in_array(($backfill['status'] ?? null), ['pending', 'queued'], true)
                        && str_starts_with($queuedRevision, self::MISSING_TRANSLATION_PREFIX)
                        && filled(data_get($metadata, 'product_data_export.pending_token'));
                })
                ->map(fn (ProductChannelMapping $mapping): string => trim((string) data_get(
                    $mapping->metadata,
                    'product_data_export.pending_token',
                    '',
                )))
                ->filter()
                ->unique()
                ->values();

            foreach ($mappings as $mapping) {
                $metadata = (array) $mapping->metadata;
                $originalMetadata = $metadata;
                $pendingToken = trim((string) data_get(
                    $metadata,
                    'product_data_export.pending_token',
                    '',
                ));

                if ($pendingToken !== '' && $invalidatedTokens->contains($pendingToken)) {
                    data_forget($metadata, 'product_data_export.pending_token');
                    data_forget($metadata, 'product_data_export.requested_at');
                }

                $backfill = (array) data_get($metadata, self::BACKFILL_PATH, []);
                $backfillRevision = trim((string) ($backfill['revision'] ?? ''));
                $queuedRevision = trim((string) ($backfill['queued_revision'] ?? ''));
                $hasActiveToken = $pendingToken !== '';
                $invalidatedOwnToken = $invalidatedTokens->contains($pendingToken);
                $isActiveBackfill = ($backfill['reason'] ?? null)
                        === LegacyVariantFamilyBackfillService::REASON
                    && in_array(($backfill['status'] ?? null), ['pending', 'queued'], true);
                $requestedMissingTranslation = str_starts_with(
                    $backfillRevision,
                    self::MISSING_TRANSLATION_PREFIX,
                );
                $queuedMissingTranslation = str_starts_with(
                    $queuedRevision,
                    self::MISSING_TRANSLATION_PREFIX,
                );

                if ($isActiveBackfill && $queuedMissingTranslation && $invalidatedOwnToken) {
                    unset(
                        $backfill['queued_at'],
                        $backfill['queued_revision'],
                        $backfill['failed_at'],
                        $backfill['next_attempt_at'],
                        $backfill['error'],
                    );

                    if ($requestedMissingTranslation) {
                        $backfill = $this->completeSupersededBackfill($backfill, $now);
                    } else {
                        $backfill['status'] = 'pending';
                        unset(
                            $backfill['completed_at'],
                            $backfill['superseded_at'],
                            $backfill['superseded_by'],
                        );
                    }

                    data_set($metadata, self::BACKFILL_PATH, $backfill);
                } elseif ($isActiveBackfill && $requestedMissingTranslation) {
                    if (! $hasActiveToken) {
                        data_set(
                            $metadata,
                            self::BACKFILL_PATH,
                            $this->completeSupersededBackfill($backfill, $now),
                        );
                    } elseif ($queuedRevision !== '' && ! $queuedMissingTranslation) {
                        // markPendingRevision can layer this obsolete request
                        // over an unrelated active export. Restore that exact
                        // queued revision so its completion cannot revive the
                        // unsafe missing-translation backfill.
                        $backfill['revision'] = $queuedRevision;
                        $backfill['status'] = 'queued';
                        unset(
                            $backfill['completed_at'],
                            $backfill['failed_at'],
                            $backfill['next_attempt_at'],
                            $backfill['error'],
                            $backfill['superseded_at'],
                            $backfill['superseded_by'],
                        );
                        data_set($metadata, self::BACKFILL_PATH, $backfill);
                    } elseif ($queuedRevision === '') {
                        // The active token belongs to an unrelated export and
                        // must survive. Detach the obsolete request from the
                        // legacy dispatcher so completion of that job cannot
                        // revive this unsafe broad export as pending.
                        data_set(
                            $metadata,
                            self::BACKFILL_PATH,
                            $this->completeSupersededBackfill($backfill, $now),
                        );
                    }
                }

                if ($repairMappings->contains(
                    fn (ProductChannelMapping $candidate): bool => $candidate->is($mapping),
                )) {
                    $repair = (array) data_get(
                        $metadata,
                        WooOwnedVariantAxisRepairService::STATE_PATH,
                        [],
                    );
                    $repair['revision'] = self::REPAIR_REVISION;
                    $repair['status'] = 'pending';
                    $repair['requested_at'] = $now;
                    unset(
                        $repair['pending_token'],
                        $repair['queued_at'],
                        $repair['completed_at'],
                        $repair['failed_at'],
                        $repair['next_attempt_at'],
                        $repair['error'],
                        $repair['result'],
                    );
                    data_set(
                        $metadata,
                        WooOwnedVariantAxisRepairService::STATE_PATH,
                        $repair,
                    );
                }

                if ($metadata !== $originalMetadata) {
                    $mapping->forceFill(['metadata' => $metadata])->save();
                }
            }
        });
    }

    private function isRepairCandidate(ProductChannelMapping $mapping): bool
    {
        $repair = (array) data_get(
            $mapping->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
            [],
        );

        if (($repair['revision'] ?? null) !== self::REPAIR_REVISION) {
            return false;
        }

        $status = (string) ($repair['status'] ?? '');
        $resultStatus = (string) data_get($repair, 'result.status', '');
        $isMissingEnglishMapping = in_array(
            $status,
            ['pending', 'queued', 'manual_review'],
            true,
        ) && data_get($repair, 'result.reason') === self::MISSING_ENGLISH_MAPPING_REASON;
        $wasCompletedByEarlierRepair = $status === 'completed'
            && in_array($resultStatus, ['repaired', 'already_canonical'], true);

        return $isMissingEnglishMapping || $wasCompletedByEarlierRepair;
    }

    /**
     * @param  array<string, mixed>  $backfill
     * @return array<string, mixed>
     */
    private function completeSupersededBackfill(array $backfill, string $now): array
    {
        $backfill['status'] = 'completed';
        $backfill['completed_at'] = $now;
        $backfill['superseded_at'] = $now;
        $backfill['superseded_by'] = self::MIGRATION_REVISION;
        $backfill['superseded_reason'] = $backfill['reason'] ?? null;
        $backfill['reason'] = self::SUPERSEDED_BACKFILL_REASON;
        unset(
            $backfill['queued_at'],
            $backfill['queued_revision'],
            $backfill['failed_at'],
            $backfill['next_attempt_at'],
            $backfill['error'],
        );

        return $backfill;
    }

    private function isActiveWooParentMapping(ProductChannelMapping $mapping): bool
    {
        $variationId = trim((string) $mapping->external_variation_id);

        return $mapping->salesChannel?->type === 'woocommerce'
            && (bool) $mapping->salesChannel?->is_active
            && in_array($variationId, ['', '0'], true);
    }
};
