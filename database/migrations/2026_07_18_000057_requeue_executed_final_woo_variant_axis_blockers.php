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

        // Revision 000060 executed both production families and exposed the
        // two guards that precede their safe recovery paths. Retry only those
        // exact service diagnostics after moving the duplicate preflight and
        // making the missing Polish source term explicitly creatable.
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
                WooOwnedVariantAxisRepairService::PREVIOUS_EXECUTED_FINAL_VARIANT_REPAIR_BLOCKERS_REVISION,
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
                    $isExactDuplicateGuard = $reason
                        === 'Lokalne warianty zawierają niepuste lub obce wartości, których zdalna wersja językowa nie może nadpisać.';
                    $isRecoverableSourceTerm = preg_match(
                        '/^WooCommerce [A-Z]{2} #\d+: WooCommerce nie zawiera źródłowej polskiej wartości .+ globalnego atrybutu #\d+\.$/u',
                        $reason,
                    ) === 1;

                    if (! $product instanceof Product
                        || isset($visited[$product->id])
                        || (! $isExactDuplicateGuard && ! $isRecoverableSourceTerm)
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
        // Deliberate no-op. The retry may allocate Polylang term identities or
        // merge duplicate local variants into durable outbound stock aliases.
    }
};
