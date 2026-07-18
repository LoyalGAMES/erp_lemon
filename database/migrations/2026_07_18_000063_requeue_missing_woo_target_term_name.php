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
                WooOwnedVariantAxisRepairService::PREVIOUS_MISSING_TARGET_TERM_NAME_REVISION,
            )
            ->where('metadata->'.$statePath.'->status', 'manual_review')
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
                    $reason = trim((string) ($state['error'] ?? data_get(
                        $state,
                        'result.reason',
                        '',
                    )));

                    if (! $product instanceof Product
                        || isset($visited[$product->id])
                        || ! str_ends_with(
                            $reason,
                            'Naprawa błędnej rodziny tłumaczeń wartości atrybutu nie ma kompletnego kontraktu.',
                        )
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
        // Deliberate no-op: the guarded repair did not mutate remote data.
    }
};
