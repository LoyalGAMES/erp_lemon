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

        // Revision 000052 surfaced an ambiguous historical EN alias before
        // any variation was created. Requeue only its unresolved, exact
        // service-marked hand-off; the exporter now selects the verified
        // parent ID from that hand-off instead of the oldest language alias.
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
                WooOwnedVariantAxisRepairService::PREVIOUS_EXACT_TRANSLATION_HANDOFF_ALIAS_REVISION,
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
        // Deliberate no-op. A successful retry creates durable Woo variation
        // identities and Polylang links which must not be rolled back.
    }
};
