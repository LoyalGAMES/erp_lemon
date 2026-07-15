<?php

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREVIOUS_WOO_REVISION = 'woo_owned_size_variant_axis_2026_07_15_000016';

    private const LOCAL_AXIS_REPAIR_PATH = 'maintenance.legacy_size_variant_axis_recovery';

    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('product_relations')
            || ! Schema::hasTable('sales_channels')
        ) {
            return;
        }

        $axisRepair = app(WooOwnedVariantAxisRepairService::class);
        $fullExport = app(LegacyVariantFamilyBackfillService::class);
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
                'product.channelAliases',
                'product.parentRelations',
                'product.variantChildren.channelMappings',
                'product.variantChildren.channelAliases',
                'product.variantChildren.parentRelations',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use (
                $axisRepair,
                $fullExport,
                &$visited,
            ): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product || isset($visited[$product->id])) {
                        continue;
                    }

                    $visited[$product->id] = true;
                    $hasPreviousRetryableAxisState = $product->channelMappings
                        ->filter(fn (ProductChannelMapping $candidate): bool => in_array(
                            trim((string) $candidate->external_variation_id),
                            ['', '0'],
                            true,
                        ))
                        ->contains(function (ProductChannelMapping $candidate): bool {
                            $state = (array) data_get(
                                $candidate->metadata,
                                WooOwnedVariantAxisRepairService::STATE_PATH,
                                [],
                            );

                            return ($state['revision'] ?? null) === self::PREVIOUS_WOO_REVISION
                                && in_array(
                                    $state['status'] ?? null,
                                    ['manual_review', 'pending', 'queued'],
                                    true,
                                );
                        });

                    if ($hasPreviousRetryableAxisState
                        && $axisRepair->isWooOwnedVariantRootCandidate($product)
                    ) {
                        $axisRepair->markPending($product);

                        continue;
                    }

                    if ($this->isLocallyRecoveredErpFamily($product)) {
                        $fullExport->markPendingRevision(
                            $product,
                            LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_FOLLOWUP_REVISION,
                        );
                    }
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. Repair/export requests may already be running and
        // must not be rolled back to the invalid historical axis or ordering.
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
};
