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

        // The preceding release added the two fail-closed recovery paths, but
        // these families were already manual_review in the same revision and
        // therefore were not synchronous deployment candidates. Advance only
        // those exact diagnostics so the new recovery code actually runs.
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
                WooOwnedVariantAxisRepairService::PREVIOUS_FINAL_VARIANT_REPAIR_BLOCKERS_REVISION,
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
                    $isExactDuplicateFamily = $reason
                        === 'Polskie warianty nie odpowiadają dokładnie wariantom rodziny ERP.';
                    $isRecoverableSourceTerm = preg_match(
                        '/^WooCommerce [A-Z]{2} #\d+: WooCommerce nie zawiera źródłowej polskiej wartości .+ globalnego atrybutu #\d+\.$/u',
                        $reason,
                    ) === 1;

                    if (! $product instanceof Product
                        || isset($visited[$product->id])
                        || (! $isExactDuplicateFamily && ! $isRecoverableSourceTerm)
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
        // Deliberate no-op. Remote term IDs, merged product aliases and stock
        // routing created by a successful retry must survive code rollback.
    }
};
