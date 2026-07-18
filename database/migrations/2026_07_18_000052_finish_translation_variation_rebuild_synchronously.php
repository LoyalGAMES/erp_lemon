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
            || ! Schema::hasTable('sales_channels')
        ) {
            return;
        }

        $repair = app(WooOwnedVariantAxisRepairService::class);
        $visited = [];
        $statePath = str_replace('.', '->', WooOwnedVariantAxisRepairService::STATE_PATH);

        // Requeue only the unresolved, service-marked PL->EN hand-off. The
        // deployment gate will consume its existing full-export token while
        // maintenance and stopped workers provide exclusive catalog access,
        // then immediately verify the reconstructed remote family.
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
                WooOwnedVariantAxisRepairService::PREVIOUS_SYNCHRONOUS_TRANSLATION_VARIATION_REBUILD_REVISION,
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
                    $state = (array) data_get(
                        $mapping->metadata,
                        WooOwnedVariantAxisRepairService::STATE_PATH,
                        [],
                    );
                    $targets = (array) data_get(
                        $state,
                        'result.rebuild_simple_translations',
                        [],
                    );
                    $handoff = $product instanceof Product
                        ? (array) data_get(
                            $product->masterData(),
                            WooOwnedVariantAxisRepairService::STATE_PATH,
                            [],
                        )
                        : [];

                    if (! $product instanceof Product
                        || isset($visited[$product->id])
                        || data_get($state, 'result.allow_full_export') !== true
                        || $targets === []
                        || ! WooOwnedVariantAxisRepairService::isSynchronizedRevision($handoff['revision'] ?? null)
                        || blank($handoff['canonical_full_export_handoff_at'] ?? null)
                        || (array) ($handoff['rebuild_simple_translations'] ?? []) !== $targets
                    ) {
                        continue;
                    }

                    $visited[$product->id] = true;
                    $repair->markPending($product);
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. Reconstructed remote variation IDs and their
        // Polylang links must never be rolled back by a code rollback.
    }
};
