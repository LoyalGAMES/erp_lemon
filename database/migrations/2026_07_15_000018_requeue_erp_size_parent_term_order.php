<?php

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Jobs\RepairWooOwnedVariantAxisJob;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LOCAL_AXIS_REPAIR_PATH = 'maintenance.legacy_size_variant_axis_recovery';

    private const BACKFILL_PATH = 'product_data_export.legacy_variant_backfill';

    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('product_relations')
            || ! Schema::hasTable('sales_channels')
        ) {
            return;
        }

        $this->promoteQueuedAxisRepairs();

        $backfill = app(LegacyVariantFamilyBackfillService::class);
        $visited = [];

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
            ->with([
                'product.channelMappings',
                'product.parentRelations',
                'product.variantChildren.parentRelations',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use ($backfill, &$visited): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product
                        || isset($visited[$product->id])
                        || ! $this->isPrimaryPolishMapping($mapping)
                    ) {
                        continue;
                    }

                    $visited[$product->id] = true;

                    if (! $this->hasExpectedPreviousBackfill($mapping)
                        || ! $this->isLocallyRecoveredErpFamily($product)
                    ) {
                        continue;
                    }

                    $backfill->markPendingRevision(
                        $product,
                        LegacyVariantFamilyBackfillService::LEGACY_SIZE_PARENT_TERM_ORDER_FOLLOWUP_REVISION,
                    );
                }
            });

        $this->promoteActiveParentTermOrderExports();
    }

    /**
     * Axis jobs reserved before this release are already token-protected and
     * safe to move without duplicating work. Rehome only unreserved jobs with
     * the exact class identity; unrelated default-queue traffic is untouched.
     */
    private function promoteQueuedAxisRepairs(): void
    {
        if (! Schema::hasTable('jobs')) {
            return;
        }

        DB::table('jobs')
            ->where('queue', 'default')
            ->whereNull('reserved_at')
            ->orderBy('id')
            ->chunkById(100, function ($jobs): void {
                foreach ($jobs as $job) {
                    $payload = json_decode((string) $job->payload, true);

                    if (! is_array($payload)
                        || ($payload['displayName'] ?? null) !== RepairWooOwnedVariantAxisJob::class
                    ) {
                        continue;
                    }

                    DB::table('jobs')
                        ->where('id', $job->id)
                        ->where('queue', 'default')
                        ->whereNull('reserved_at')
                        ->update(['queue' => WooOwnedVariantAxisRepairService::REPAIR_QUEUE]);
                }
            });
    }

    /**
     * markPendingRevision deliberately preserves an older active token. If its
     * job is still waiting on the default queue, move that exact reservation
     * rather than creating a duplicate critical export.
     */
    private function promoteActiveParentTermOrderExports(): void
    {
        if (! Schema::hasTable('jobs')) {
            return;
        }

        $tokens = ProductChannelMapping::query()
            ->get(['metadata'])
            ->filter(fn (ProductChannelMapping $mapping): bool => data_get(
                $mapping->metadata,
                self::BACKFILL_PATH.'.revision',
            ) === LegacyVariantFamilyBackfillService::LEGACY_SIZE_PARENT_TERM_ORDER_FOLLOWUP_REVISION)
            ->map(fn (ProductChannelMapping $mapping): string => trim((string) data_get(
                $mapping->metadata,
                'product_data_export.pending_token',
                '',
            )))
            ->filter()
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            return;
        }

        DB::table('jobs')
            ->where('queue', 'default')
            ->whereNull('reserved_at')
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use ($tokens): void {
                foreach ($jobs as $job) {
                    $payload = json_decode((string) $job->payload, true);
                    $command = is_array($payload)
                        ? (string) data_get($payload, 'data.command', '')
                        : '';

                    if (! is_array($payload)
                        || ($payload['displayName'] ?? null) !== ExportWooCommerceProductDataJob::class
                        || ! $tokens->contains(fn (string $token): bool => str_contains($command, $token))
                    ) {
                        continue;
                    }

                    DB::table('jobs')
                        ->where('id', $job->id)
                        ->where('queue', 'default')
                        ->whereNull('reserved_at')
                        ->update(['queue' => LegacyVariantFamilyBackfillService::CRITICAL_EXPORT_QUEUE]);
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. Completed catalog writes and newer durable queue
        // requests must never be rolled back to alphabetical size ordering.
    }

    private function hasExpectedPreviousBackfill(ProductChannelMapping $mapping): bool
    {
        $state = (array) data_get($mapping->metadata, self::BACKFILL_PATH, []);

        return ($state['reason'] ?? null) === LegacyVariantFamilyBackfillService::REASON
            && in_array($state['revision'] ?? null, [
                LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION,
                LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_FOLLOWUP_REVISION,
            ], true);
    }

    private function isLocallyRecoveredErpFamily(Product $product): bool
    {
        if ($product->masterSource() !== 'erp'
            || $product->is_translation
            || data_get($product->masterData(), 'product_type') === 'variation'
            || $product->parentRelations->contains(
                fn ($relation): bool => $relation->relation_type === 'variant',
            )
            || $product->variantChildren->isEmpty()
            || data_get(
                $product->masterData(),
                self::LOCAL_AXIS_REPAIR_PATH.'.revision',
            ) !== LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION
        ) {
            return false;
        }

        if (! $product->variantChildren->every(function (Product $variant) use ($product): bool {
            $variantParents = $variant->parentRelations
                ->filter(fn (ProductRelation $relation): bool => $relation->relation_type === 'variant')
                ->values();

            return $variant->masterSource() === 'erp'
                && ! $variant->is_translation
                && data_get($variant->masterData(), 'product_type') === 'variation'
                && $variantParents->count() === 1
                && (int) $variantParents->first()->parent_product_id === (int) $product->id
                && data_get(
                    $variant->masterData(),
                    self::LOCAL_AXIS_REPAIR_PATH.'.revision',
                ) === LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION;
        })) {
            return false;
        }

        $relations = ProductRelation::query()
            ->where('parent_product_id', $product->id)
            ->where('relation_type', 'variant')
            ->get();

        return $relations->count() === $product->variantChildren->count()
            && $relations->every(fn (ProductRelation $relation): bool => data_get(
                $relation->metadata,
                self::LOCAL_AXIS_REPAIR_PATH.'.revision',
            ) === LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION);
    }

    private function isPrimaryPolishMapping(ProductChannelMapping $mapping): bool
    {
        $metadata = (array) $mapping->metadata;
        $language = mb_strtolower(trim((string) ($metadata['language'] ?? '')));
        $role = mb_strtolower(trim((string) ($metadata['mapping_role'] ?? '')));
        $isPolish = $language === ''
            || $language === 'pl'
            || str_starts_with($language, 'pl-')
            || str_starts_with($language, 'pl_');

        return ! (bool) ($metadata['is_translation'] ?? false)
            && $isPolish
            && in_array($role, ['', 'primary'], true)
            && ctype_digit(trim((string) $mapping->external_product_id))
            && (int) $mapping->external_product_id > 0;
    }
};
