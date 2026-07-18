<?php

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BACKFILL_PATH = 'product_data_export.legacy_variant_backfill';

    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('sales_channels')
            || ! Schema::hasTable('jobs')
        ) {
            return;
        }

        $repair = app(WooOwnedVariantAxisRepairService::class);
        $visited = [];
        $statePath = str_replace('.', '->', WooOwnedVariantAxisRepairService::STATE_PATH);

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
            ->where(
                'metadata->'.$statePath.'->revision',
                WooOwnedVariantAxisRepairService::PREVIOUS_PRIORITIZED_TRANSLATION_VARIATION_REBUILD_REVISION,
            )
            ->whereIn('metadata->'.$statePath.'->status', [
                'pending',
                'queued',
                'failed',
                'manual_review',
            ])
            ->with('product')
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use ($repair, &$visited): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product
                        || isset($visited[$product->id])
                        || ! $this->hasCanonicalTranslationRebuildHandoff($product, $mapping)
                    ) {
                        continue;
                    }

                    $visited[$product->id] = true;
                    $this->prioritizeOrReleaseMissingTranslationExport((int) $product->id);
                    $repair->markPending($product);
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. A promoted or replaced export may already have
        // created translated Woo variations and must never be reversed.
    }

    private function hasCanonicalTranslationRebuildHandoff(
        Product $product,
        ProductChannelMapping $mapping,
    ): bool {
        $repair = (array) data_get(
            $mapping->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
            [],
        );
        $handoff = (array) data_get(
            $product->masterData(),
            WooOwnedVariantAxisRepairService::STATE_PATH,
            [],
        );

        return data_get($repair, 'result.allow_full_export') === true
            && (array) data_get($repair, 'result.rebuild_simple_translations', []) !== []
            && WooOwnedVariantAxisRepairService::isSynchronizedRevision($handoff['revision'] ?? null)
            && filled($handoff['canonical_full_export_handoff_at'] ?? null)
            && (array) ($handoff['rebuild_simple_translations'] ?? [])
                === (array) data_get($repair, 'result.rebuild_simple_translations', []);
    }

    private function prioritizeOrReleaseMissingTranslationExport(int $productId): void
    {
        DB::transaction(function () use ($productId): void {
            $mappings = ProductChannelMapping::query()
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->get();
            $tokens = $mappings
                ->filter(function (ProductChannelMapping $mapping): bool {
                    $metadata = (array) $mapping->metadata;
                    $backfill = (array) data_get($metadata, self::BACKFILL_PATH, []);

                    return ($backfill['reason'] ?? null) === LegacyVariantFamilyBackfillService::REASON
                        && in_array(($backfill['status'] ?? null), ['pending', 'queued'], true)
                        && $this->isMissingTranslationRevision($backfill['queued_revision'] ?? null)
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

            foreach ($tokens as $token) {
                $matchingJobIds = DB::table('jobs')
                    ->whereNull('reserved_at')
                    ->where('payload', 'like', '%'.$token.'%')
                    ->get(['id', 'payload'])
                    ->filter(function (object $job) use ($token): bool {
                        $payload = json_decode((string) $job->payload, true);
                        $command = is_array($payload)
                            ? (string) data_get($payload, 'data.command', '')
                            : '';

                        return is_array($payload)
                            && ($payload['displayName'] ?? null) === ExportWooCommerceProductDataJob::class
                            && str_contains($command, $token);
                    })
                    ->pluck('id');

                if ($matchingJobIds->isNotEmpty()) {
                    DB::table('jobs')
                        ->whereIn('id', $matchingJobIds)
                        ->whereNull('reserved_at')
                        ->update([
                            'queue' => LegacyVariantFamilyBackfillService::CRITICAL_EXPORT_QUEUE,
                            'available_at' => now()->timestamp,
                        ]);

                    continue;
                }

                foreach ($mappings as $mapping) {
                    $metadata = (array) $mapping->metadata;

                    if (data_get($metadata, 'product_data_export.pending_token') !== $token) {
                        continue;
                    }

                    data_forget($metadata, 'product_data_export.pending_token');
                    data_forget($metadata, 'product_data_export.requested_at');
                    $backfill = (array) data_get($metadata, self::BACKFILL_PATH, []);

                    if (($backfill['reason'] ?? null) === LegacyVariantFamilyBackfillService::REASON
                        && $this->isMissingTranslationRevision($backfill['queued_revision'] ?? null)
                    ) {
                        $backfill['status'] = 'pending';
                        unset(
                            $backfill['queued_at'],
                            $backfill['queued_revision'],
                            $backfill['failed_at'],
                            $backfill['next_attempt_at'],
                            $backfill['error'],
                        );
                        data_set($metadata, self::BACKFILL_PATH, $backfill);
                    }

                    $mapping->forceFill(['metadata' => $metadata])->save();
                }
            }
        }, 3);
    }

    private function isMissingTranslationRevision(mixed $revision): bool
    {
        if (! is_string($revision)
            || ! str_contains($revision, ':missing-translation:')
        ) {
            return false;
        }

        [$repairRevision] = explode(':missing-translation:', $revision, 2);

        return WooOwnedVariantAxisRepairService::isSynchronizedRevision($repairRevision);
    }
};
