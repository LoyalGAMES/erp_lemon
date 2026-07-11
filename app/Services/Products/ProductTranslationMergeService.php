<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\StockSyncQueueItem;
use App\Services\Inventory\ChannelStockAvailabilityService;
use App\Services\Inventory\StockReservationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;

final class ProductTranslationMergeService
{
    /**
     * Tables whose product reference can be moved without collapsing rows.
     * Stock balances and channel mappings need dedicated conflict handling.
     *
     * @var list<string>
     */
    private const PRODUCT_REFERENCE_TABLES = [
        'external_order_lines',
        'stock_reservations',
        'stock_sync_queue_items',
        'warehouse_document_lines',
        'stock_ledger_entries',
        'return_case_lines',
        'invoice_lines',
        'packing_tasks',
    ];

    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly ChannelStockAvailabilityService $channelStock,
    ) {}

    /**
     * Consolidate a WooCommerce translation row into one ERP product.
     *
     * The duplicate is intentionally retained as an inactive historical marker.
     * This makes the operation reversible and prevents cascading foreign keys
     * from deleting warehouse or order history.
     *
     * Supported context keys: source, reason, language, sales_channel_id,
     * external_product_id, external_variation_id, external_sku, metadata,
     * user_id.
     *
     * @param  array<string, mixed>  $context
     */
    public function merge(Product $canonical, Product $duplicate, array $context = []): void
    {
        $canonicalId = (int) $canonical->getKey();
        $duplicateId = (int) $duplicate->getKey();

        if ($canonicalId <= 0 || $duplicateId <= 0) {
            throw new InvalidArgumentException('Scalane produkty muszą być zapisane w bazie.');
        }

        if ($canonicalId === $duplicateId) {
            return;
        }

        DB::transaction(function () use ($canonicalId, $duplicateId, $context): void {
            $products = Product::query()
                ->whereKey([$canonicalId, $duplicateId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $canonical = $products->get($canonicalId);
            $duplicate = $products->get($duplicateId);

            if (! $canonical instanceof Product || ! $duplicate instanceof Product) {
                throw new LogicException('Nie znaleziono jednego z produktów wybranych do scalenia.');
            }

            $previousCanonicalId = (int) (
                data_get($duplicate->attributes, 'master.merge.canonical_product_id')
                ?? data_get($duplicate->attributes, 'master.translation_merge.merged_into_product_id')
                ?? 0
            );

            if ($previousCanonicalId > 0 && $previousCanonicalId !== $canonicalId) {
                throw new LogicException(
                    "Produkt {$duplicateId} został już scalony z innym produktem ({$previousCanonicalId}).",
                );
            }

            $balanceSnapshot = $this->balanceSnapshot($canonicalId, $duplicateId);
            $warehouseIds = $this->affectedWarehouseIds($canonicalId, $duplicateId, $balanceSnapshot);
            $aliasIds = $this->migrateChannelMappings($canonical, $duplicate, $context);

            foreach (self::PRODUCT_REFERENCE_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                DB::table($table)
                    ->where('product_id', $duplicateId)
                    ->update(['product_id' => $canonicalId]);
            }

            $this->mergeBalances($canonicalId, $duplicateId, $warehouseIds);
            $this->refreshOpenStockQueueItems($canonicalId);
            $this->migrateProductRelations($canonicalId, $duplicateId);
            $this->migrateAuditReferences($canonicalId, $duplicateId);
            $this->markProductsMerged(
                $canonical,
                $duplicate,
                $context,
                $aliasIds,
                $balanceSnapshot,
            );

            if ($previousCanonicalId === 0 && Schema::hasTable('audit_logs')) {
                $this->recordMergeAudit($canonical, $duplicate, $context, $aliasIds, $balanceSnapshot);
            }
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<int>
     */
    private function migrateChannelMappings(Product $canonical, Product $duplicate, array $context): array
    {
        $aliasIds = [];
        $duplicateMappings = ProductChannelMapping::query()
            ->where('product_id', $duplicate->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($duplicateMappings as $mapping) {
            $primary = ProductChannelMapping::query()
                ->where('product_id', $canonical->id)
                ->where('sales_channel_id', $mapping->sales_channel_id)
                ->lockForUpdate()
                ->first();

            if (! $primary instanceof ProductChannelMapping) {
                $existingAlias = ProductChannelAlias::query()
                    ->forExternalIdentity(
                        (int) $mapping->sales_channel_id,
                        (string) $mapping->external_product_id,
                        $mapping->external_variation_id !== null
                            ? (string) $mapping->external_variation_id
                            : null,
                    )
                    ->lockForUpdate()
                    ->first();

                if ($existingAlias instanceof ProductChannelAlias
                    && (int) $existingAlias->product_id !== (int) $canonical->id
                ) {
                    throw new LogicException(
                        "Mapowanie {$mapping->id} wskazuje zewnętrzny alias przypisany do produktu {$existingAlias->product_id}.",
                    );
                }

                $existingAlias?->delete();

                $mapping->forceFill(['product_id' => $canonical->id])->save();

                continue;
            }

            if ($this->sameExternalIdentity($primary, $mapping)) {
                $mapping->delete();

                continue;
            }

            $alias = $this->storeAlias(
                $canonical,
                $duplicate,
                (int) $mapping->sales_channel_id,
                (string) $mapping->external_product_id,
                $mapping->external_variation_id !== null
                    ? (string) $mapping->external_variation_id
                    : null,
                $mapping->external_sku !== null ? (string) $mapping->external_sku : null,
                $this->language($context, (array) $mapping->metadata),
                array_replace_recursive((array) $mapping->metadata, [
                    'product_merge' => [
                        'source' => 'product_channel_mapping',
                        'source_mapping_id' => $mapping->id,
                    ],
                ]),
            );
            $aliasIds[] = (int) $alias->id;
            $mapping->delete();
        }

        $contextAlias = $this->storeContextAlias($canonical, $duplicate, $context);

        if ($contextAlias instanceof ProductChannelAlias) {
            $aliasIds[] = (int) $contextAlias->id;
        }

        return collect($aliasIds)->unique()->sort()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function storeContextAlias(
        Product $canonical,
        Product $duplicate,
        array $context,
    ): ?ProductChannelAlias {
        $salesChannelId = (int) ($context['sales_channel_id'] ?? 0);
        $externalProductId = trim((string) ($context['external_product_id'] ?? ''));

        if ($salesChannelId <= 0 || $externalProductId === '') {
            return null;
        }

        $externalVariationId = filled($context['external_variation_id'] ?? null)
            ? (string) $context['external_variation_id']
            : null;
        $primaryHasIdentity = ProductChannelMapping::query()
            ->where('product_id', $canonical->id)
            ->where('sales_channel_id', $salesChannelId)
            ->where('external_product_id', $externalProductId)
            ->when(
                $externalVariationId !== null,
                fn ($query) => $query->where('external_variation_id', $externalVariationId),
                fn ($query) => $query->whereNull('external_variation_id'),
            )
            ->exists();

        if ($primaryHasIdentity) {
            return null;
        }

        return $this->storeAlias(
            $canonical,
            $duplicate,
            $salesChannelId,
            $externalProductId,
            $externalVariationId,
            filled($context['external_sku'] ?? null) ? (string) $context['external_sku'] : null,
            $this->language($context),
            array_replace_recursive((array) ($context['metadata'] ?? []), [
                'product_merge' => ['source' => 'merge_context'],
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function storeAlias(
        Product $canonical,
        Product $duplicate,
        int $salesChannelId,
        string $externalProductId,
        ?string $externalVariationId,
        ?string $externalSku,
        ?string $language,
        array $metadata,
    ): ProductChannelAlias {
        $externalKey = ProductChannelAlias::externalKey($externalProductId, $externalVariationId);
        $alias = ProductChannelAlias::query()
            ->where('sales_channel_id', $salesChannelId)
            ->where('external_key', $externalKey)
            ->lockForUpdate()
            ->first();

        if ($alias instanceof ProductChannelAlias && (int) $alias->product_id !== (int) $canonical->id) {
            throw new LogicException(
                "Zewnętrzny alias {$externalKey} jest już przypisany do produktu {$alias->product_id}.",
            );
        }

        $mergeMetadata = array_replace_recursive((array) $alias?->metadata, $metadata, [
            'product_merge' => [
                'canonical_product_id' => (int) $canonical->id,
                'merged_from_product_id' => (int) $duplicate->id,
                'merged_at' => data_get($alias?->metadata, 'product_merge.merged_at') ?? now()->toISOString(),
            ],
        ]);

        $alias ??= new ProductChannelAlias;
        $alias->fill([
            'product_id' => $canonical->id,
            'sales_channel_id' => $salesChannelId,
            'source_product_id' => $duplicate->id,
            'external_product_id' => trim($externalProductId),
            'external_variation_id' => $externalVariationId,
            'external_key' => $externalKey,
            'external_sku' => $externalSku,
            'language' => $language,
            'metadata' => $mergeMetadata,
        ])->save();

        return $alias;
    }

    private function sameExternalIdentity(
        ProductChannelMapping $left,
        ProductChannelMapping $right,
    ): bool {
        return ProductChannelAlias::externalKey(
            (string) $left->external_product_id,
            $left->external_variation_id !== null ? (string) $left->external_variation_id : null,
        ) === ProductChannelAlias::externalKey(
            (string) $right->external_product_id,
            $right->external_variation_id !== null ? (string) $right->external_variation_id : null,
        );
    }

    /**
     * @return array{canonical:array<int,array<string,float>>,duplicate:array<int,array<string,float>>}
     */
    private function balanceSnapshot(int $canonicalId, int $duplicateId): array
    {
        $snapshot = [
            'canonical' => [],
            'duplicate' => [],
        ];

        StockBalance::query()
            ->whereIn('product_id', [$canonicalId, $duplicateId])
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->each(function (StockBalance $balance) use (&$snapshot, $canonicalId): void {
                $side = (int) $balance->product_id === $canonicalId ? 'canonical' : 'duplicate';
                $snapshot[$side][(int) $balance->warehouse_id] = [
                    'quantity_on_hand' => (float) $balance->quantity_on_hand,
                    'quantity_reserved' => (float) $balance->quantity_reserved,
                    'quantity_available' => (float) $balance->quantity_available,
                ];
            });

        return $snapshot;
    }

    /**
     * @param  array{canonical:array<int,array<string,float>>,duplicate:array<int,array<string,float>>}  $snapshot
     * @return list<int>
     */
    private function affectedWarehouseIds(int $canonicalId, int $duplicateId, array $snapshot): array
    {
        $reservationWarehouseIds = StockReservation::query()
            ->whereIn('product_id', [$canonicalId, $duplicateId])
            ->pluck('warehouse_id');

        return collect([
            ...array_keys($snapshot['canonical']),
            ...array_keys($snapshot['duplicate']),
            ...$reservationWarehouseIds->all(),
        ])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $warehouseIds
     */
    private function mergeBalances(int $canonicalId, int $duplicateId, array $warehouseIds): void
    {
        foreach ($warehouseIds as $warehouseId) {
            $canonicalBalance = StockBalance::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $canonicalId)
                ->lockForUpdate()
                ->first();
            $duplicateBalance = StockBalance::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $duplicateId)
                ->lockForUpdate()
                ->first();

            if (! $canonicalBalance instanceof StockBalance && $duplicateBalance instanceof StockBalance) {
                // With no canonical balance, the translated row is the only
                // available snapshot. Move it; never add two on-hand values.
                $duplicateBalance->forceFill(['product_id' => $canonicalId])->save();
                $canonicalBalance = $duplicateBalance;
                $duplicateBalance = null;
            }

            if ($canonicalBalance instanceof StockBalance && $duplicateBalance instanceof StockBalance) {
                // Both translations describe the same physical quantity. The
                // canonical/primary balance wins, even when the values differ.
                $duplicateBalance->delete();
            }

            $this->reservations->recalculateBalance($warehouseId, $canonicalId);
        }
    }

    private function migrateProductRelations(int $canonicalId, int $duplicateId): void
    {
        $relations = ProductRelation::query()
            ->where(function ($query) use ($duplicateId): void {
                $query
                    ->where('parent_product_id', $duplicateId)
                    ->orWhere('child_product_id', $duplicateId);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($relations as $relation) {
            $parentId = (int) $relation->parent_product_id === $duplicateId
                ? $canonicalId
                : (int) $relation->parent_product_id;
            $childId = (int) $relation->child_product_id === $duplicateId
                ? $canonicalId
                : (int) $relation->child_product_id;

            if ($parentId === $childId) {
                $relation->delete();

                continue;
            }

            $existing = ProductRelation::query()
                ->whereKeyNot($relation->id)
                ->where('parent_product_id', $parentId)
                ->where('child_product_id', $childId)
                ->where('relation_type', $relation->relation_type)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ProductRelation) {
                $metadata = array_replace_recursive((array) $relation->metadata, (array) $existing->metadata);
                data_set($metadata, 'product_merge.source_relation_ids', collect([
                    ...((array) data_get($metadata, 'product_merge.source_relation_ids', [])),
                    (int) $relation->id,
                ])->unique()->values()->all());
                $existing->forceFill([
                    'sort_order' => min((int) $existing->sort_order, (int) $relation->sort_order),
                    'metadata' => $metadata,
                ])->save();
                $relation->delete();

                continue;
            }

            $metadata = (array) $relation->metadata;
            data_set($metadata, 'product_merge.merged_from_product_id', $duplicateId);
            $relation->forceFill([
                'parent_product_id' => $parentId,
                'child_product_id' => $childId,
                'metadata' => $metadata,
            ])->save();
        }
    }

    private function refreshOpenStockQueueItems(int $canonicalId): void
    {
        $items = StockSyncQueueItem::query()
            ->where('product_id', $canonicalId)
            ->whereNotNull('sales_channel_id')
            ->whereIn('status', ['pending', 'queued'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $availabilityByChannel = [];

        foreach ($items as $item) {
            $salesChannelId = (int) $item->sales_channel_id;
            $availabilityByChannel[$salesChannelId] ??= $this->channelStock->availabilityForProduct(
                $salesChannelId,
                $canonicalId,
            );
            $availability = $availabilityByChannel[$salesChannelId];
            $metadata = (array) $item->metadata;
            data_set($metadata, 'product_translation_merge.refreshed_at', now()->toISOString());
            data_set($metadata, 'product_translation_merge.breakdown', $availability['breakdown']);
            $item->forceFill([
                'quantity_to_push' => max(0, (float) $availability['quantity']),
                'metadata' => $metadata,
            ])->save();
        }
    }

    private function migrateAuditReferences(int $canonicalId, int $duplicateId): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')
            ->where('auditable_type', (new Product)->getMorphClass())
            ->where('auditable_id', $duplicateId)
            ->update(['auditable_id' => $canonicalId]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<int>  $aliasIds
     * @param  array{canonical:array<int,array<string,float>>,duplicate:array<int,array<string,float>>}  $balanceSnapshot
     */
    private function markProductsMerged(
        Product $canonical,
        Product $duplicate,
        array $context,
        array $aliasIds,
        array $balanceSnapshot,
    ): void {
        $mergedAt = data_get($duplicate->attributes, 'master.merge.merged_at') ?? now()->toISOString();
        $canonicalAttributes = (array) $canonical->attributes;
        $mergedProductIds = collect((array) data_get(
            $canonicalAttributes,
            'master.translation_merge.product_ids',
            [],
        ))
            ->push((int) $duplicate->id)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        data_set($canonicalAttributes, 'master.translation_merge.product_ids', $mergedProductIds);
        data_set($canonicalAttributes, 'master.translation_merge.last_merged_at', $mergedAt);
        $canonical->forceFill([
            'is_translation' => false,
            'attributes' => $canonicalAttributes,
        ])->save();

        $duplicateAttributes = (array) $duplicate->attributes;
        $mergeMetadata = array_replace_recursive(
            (array) data_get($duplicateAttributes, 'master.merge', []),
            [
                'canonical_product_id' => (int) $canonical->id,
                'reason' => $this->mergeReason($context),
                'merged_at' => $mergedAt,
                'channel_alias_ids' => $aliasIds,
                'balance_snapshot' => $balanceSnapshot,
                'context' => $this->safeContext($context),
            ],
        );
        data_set($duplicateAttributes, 'master.merge', $mergeMetadata);
        data_set($duplicateAttributes, 'master.translation_merge.merged_into_product_id', (int) $canonical->id);
        data_set($duplicateAttributes, 'master.translation_merge.merged_at', $mergedAt);
        data_set(
            $duplicateAttributes,
            'master.translation_merge.reason',
            $this->mergeReason($context),
        );
        $duplicate->forceFill([
            'is_active' => false,
            'is_favorite' => false,
            'is_translation' => true,
            'attributes' => $duplicateAttributes,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<int>  $aliasIds
     * @param  array{canonical:array<int,array<string,float>>,duplicate:array<int,array<string,float>>}  $balanceSnapshot
     */
    private function recordMergeAudit(
        Product $canonical,
        Product $duplicate,
        array $context,
        array $aliasIds,
        array $balanceSnapshot,
    ): void {
        AuditLog::query()->create([
            'user_id' => filled($context['user_id'] ?? null) ? (int) $context['user_id'] : null,
            'action' => 'product.translation_merged',
            'auditable_type' => $canonical->getMorphClass(),
            'auditable_id' => $canonical->id,
            'before' => [
                'canonical_product_id' => (int) $canonical->id,
                'duplicate_product_id' => (int) $duplicate->id,
                'duplicate_sku' => $duplicate->sku,
                'duplicate_name' => $duplicate->name,
                'balances' => $balanceSnapshot,
            ],
            'after' => [
                'canonical_product_id' => (int) $canonical->id,
                'duplicate_product_id' => (int) $duplicate->id,
                'channel_alias_ids' => $aliasIds,
                'duplicate_retained' => true,
            ],
            'metadata' => [
                'source' => 'ProductTranslationMergeService',
                'context' => $this->safeContext($context),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $metadata
     */
    private function language(array $context, array $metadata = []): ?string
    {
        $language = trim((string) (
            $context['language']
            ?? data_get($metadata, 'language')
            ?? data_get($metadata, 'woocommerce_language')
            ?? ''
        ));

        return $language !== '' ? mb_strtolower($language) : null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function safeContext(array $context): array
    {
        return Arr::only($context, [
            'source',
            'reason',
            'language',
            'sales_channel_id',
            'external_product_id',
            'external_variation_id',
            'external_sku',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function mergeReason(array $context): string
    {
        return trim((string) ($context['reason'] ?? $context['source'] ?? 'woocommerce_translation'))
            ?: 'woocommerce_translation';
    }
}
