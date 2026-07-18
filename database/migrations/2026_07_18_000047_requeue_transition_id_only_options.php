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

        // Revision 000046 proved that the remaining translated parents return
        // global axes by ID only and collapse `36` + `36-en` into the same ERP
        // Size option. Retry exactly those unresolved families with the
        // canonical option comparator.
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
                WooOwnedVariantAxisRepairService::PREVIOUS_TRANSITION_ID_ONLY_OPTIONS_REVISION,
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

                    if (! $product instanceof Product || isset($visited[$product->id])) {
                        continue;
                    }

                    $visited[$product->id] = true;
                    $repair->markPending($product);
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. A completed remote repair must never be reverted.
    }
};
