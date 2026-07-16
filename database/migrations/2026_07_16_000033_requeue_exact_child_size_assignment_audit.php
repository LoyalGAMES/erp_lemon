<?php

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('product_relations')
            || ! Schema::hasTable('sales_channels')
        ) {
            return;
        }

        $repair = app(WooOwnedVariantAxisRepairService::class);
        $visited = [];
        $statePath = str_replace('.', '->', WooOwnedVariantAxisRepairService::STATE_PATH);
        $verifiedWooFamilies = ['1506', '3744', '3770', '3890', '700101'];

        // Revision 000032 deliberately audited all historical Size roots and
        // proved that this was too broad for a deployment gate. A production
        // read-only audit then verified these exact PL families and their EN
        // aliases: ERP has a complete child SKU -> Size bijection, while only
        // the translated legacy parent option list is empty. Keep this data
        // migration explicit; the generic repair code remains reusable, but
        // no uninspected historical family may enter a release gate.
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
            ->whereIn('external_product_id', $verifiedWooFamilies)
            ->where(
                'metadata->'.$statePath.'->revision',
                WooOwnedVariantAxisRepairService::PREVIOUS_BLANK_CHILD_ASSIGNMENT_AUDIT_REVISION,
            )
            ->whereIn('metadata->'.$statePath.'->status', [
                'pending',
                'queued',
                'manual_review',
            ])
            ->with([
                'product.parentRelations',
                'product.variantChildren.parentRelations',
                'product.variantChildren.channelMappings',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use ($repair, &$visited): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;
                    $previousReason = (string) data_get(
                        $mapping->metadata,
                        WooOwnedVariantAxisRepairService::STATE_PATH.'.result.reason',
                    );

                    if (! $product instanceof Product
                        || isset($visited[$product->id])
                        || ! str_contains(
                            $previousReason,
                            'Domyślny wariant starej globalnej osi',
                        )
                    ) {
                        continue;
                    }

                    $visited[$product->id] = true;

                    try {
                        $candidate = $repair->isChildSizeAssignmentAuditCandidate($product);
                    } catch (DomainException) {
                        $candidate = false;
                    }

                    if ($candidate) {
                        $repair->markPending($product);
                    }
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. A completed remote repair must never be reverted.
    }
};
