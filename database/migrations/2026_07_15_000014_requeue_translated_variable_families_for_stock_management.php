<?php

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use App\Services\Inventory\StockSyncQueueService;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_relations')
            || ! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('wordpress_integrations')
            || ! Schema::hasTable('sales_channels')
        ) {
            return;
        }

        $canQueueImmediateStockRepair = Schema::hasTable('warehouses')
            && Schema::hasTable('warehouse_channel_routes')
            && Schema::hasTable('stock_balances')
            && Schema::hasTable('stock_sync_queue_items')
            && Schema::hasTable('stock_sync_states');

        $translatedWooChannelIds = WordpressIntegration::query()
            ->with('salesChannel')
            ->get()
            ->groupBy('sales_channel_id')
            ->filter(fn ($integrations): bool => $integrations->count() === 1)
            ->map(fn ($integrations): WordpressIntegration => $integrations->first())
            ->filter(fn (WordpressIntegration $integration): bool => $integration->salesChannel?->is_active
                && $integration->salesChannel?->type === 'woocommerce'
                && collect($integration->productExportLanguages())->contains(
                    fn (string $language): bool => mb_strtolower(trim($language)) !== 'pl',
                ))
            ->keys();

        if ($translatedWooChannelIds->isEmpty()) {
            return;
        }

        $backfill = app(LegacyVariantFamilyBackfillService::class);
        $stockSync = $canQueueImmediateStockRepair
            ? app(StockSyncQueueService::class)
            : null;
        $markedProductIds = [];
        $relations = ['product.variantChildren', 'product.parentRelations', 'product.channelMappings'];

        if ($canQueueImmediateStockRepair) {
            $relations[] = 'product.variantChildren.stockBalances';
        }

        ProductChannelMapping::query()
            ->whereIn('sales_channel_id', $translatedWooChannelIds)
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->with($relations)
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use ($backfill, $stockSync, &$markedProductIds): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product
                        || isset($markedProductIds[$product->id])
                        || $product->variantChildren->isEmpty()
                        || ! $this->isCanonicalErpRoot($product)
                        || $product->channelMappings->pluck('sales_channel_id')->unique()->count() !== 1
                        || ! $this->isPrimaryPolishMapping($mapping)
                    ) {
                        continue;
                    }

                    $existingBackfill = (array) data_get(
                        (array) $mapping->metadata,
                        'product_data_export.legacy_variant_backfill',
                    );
                    $revisionAlreadyRequested = ($existingBackfill['reason'] ?? null)
                            === LegacyVariantFamilyBackfillService::REASON
                        && ($existingBackfill['revision'] ?? null)
                            === LegacyVariantFamilyBackfillService::VARIATION_STOCK_MANAGEMENT_RECOVERY_REVISION
                        && in_array(
                            $existingBackfill['status'] ?? null,
                            ['pending', 'queued', 'completed'],
                            true,
                        );

                    $backfill->markPendingRevision(
                        $product,
                        LegacyVariantFamilyBackfillService::VARIATION_STOCK_MANAGEMENT_RECOVERY_REVISION,
                    );

                    if (! $revisionAlreadyRequested && $stockSync instanceof StockSyncQueueService) {
                        $stockSync->queueForTriggers(
                            $product->variantChildren
                                ->flatMap(fn (Product $variant) => $variant->stockBalances->map(
                                    fn ($balance): array => [
                                        'warehouse_id' => (int) $balance->warehouse_id,
                                        'product_id' => (int) $variant->id,
                                    ],
                                ))
                                ->values()
                                ->all(),
                            LegacyVariantFamilyBackfillService::VARIATION_STOCK_MANAGEMENT_RECOVERY_REVISION,
                        );
                    }

                    $markedProductIds[$product->id] = true;
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. Remote stock corrections and durable queue history
        // must survive an application rollback.
    }

    private function isCanonicalErpRoot(Product $product): bool
    {
        return ! $product->is_translation
            && trim((string) $product->sku) !== ''
            && $product->masterSource() === 'erp'
            && data_get($product->masterData(), 'product_type') !== 'variation'
            && ! $product->parentRelations->contains(
                fn ($relation): bool => $relation->relation_type === 'variant',
            );
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
