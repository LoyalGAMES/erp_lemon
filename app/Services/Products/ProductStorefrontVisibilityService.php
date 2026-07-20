<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\StockBalance;
use App\Services\Audit\AuditLogService;
use App\Services\Inventory\StockSyncQueueService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ProductStorefrontVisibilityService
{
    private const RESTORABLE_VISIBILITIES = ['visible', 'catalog', 'search'];

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly StockSyncQueueService $stockSyncQueue,
    ) {}

    /**
     * @return array{root:Product,family:Collection<int, Product>,changed:bool,queued:int}
     */
    public function hide(Product $product): array
    {
        $root = $this->familyRoot($product);

        $result = DB::transaction(function () use ($root): array {
            [$root, $family] = $this->lockedFamily($root);
            $this->ensureRootMappings($root, $family);
            $hiddenAt = now();
            $changed = $family->contains(
                fn (Product $member): bool => ! $member->isStorefrontHidden()
                    || ! $member->requiresStockVerification(),
            );
            $before = [
                'catalog_visibility' => data_get($root->masterData(), 'catalog_visibility', 'visible'),
                'storefront_hidden_at' => $root->storefront_hidden_at?->toISOString(),
                'stock_verification_required' => $family->contains(
                    fn (Product $member): bool => $member->requiresStockVerification(),
                ),
            ];
            $restoreVisibility = 'visible';

            foreach ($family as $member) {
                $values = [
                    'storefront_hidden_at' => $member->storefront_hidden_at ?? $hiddenAt,
                    'stock_verification_required_at' => $member->stock_verification_required_at ?? $hiddenAt,
                ];

                if ($member->is($root)) {
                    $attributes = (array) $member->attributes;
                    data_set($attributes, 'master.catalog_visibility', 'hidden');
                    $values['attributes'] = $attributes;
                    $values['storefront_restore_visibility'] = $restoreVisibility;
                }

                $member->forceFill($values)->save();
            }

            $queued = $this->queueFamilyStock($family, 'storefront_hidden');

            $root = $family->firstWhere('id', $root->id) ?? $root;

            if ($changed) {
                $this->audit->record('product.storefront_hidden', $root, $before, [
                    'catalog_visibility' => 'hidden',
                    'storefront_hidden_at' => $root->storefront_hidden_at?->toISOString(),
                    'stock_verification_required' => true,
                ], [
                    'family_product_ids' => $family->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    'stock_policy' => 'woocommerce_zero_until_manual_verification',
                ]);
            }

            return compact('root', 'family', 'changed', 'queued');
        }, 3);

        $this->dispatchRootExport($result['root']);

        return $result;
    }

    /**
     * @return array{root:Product,family:Collection<int, Product>,changed:bool,queued:int}
     */
    public function reveal(Product $product): array
    {
        $root = $this->familyRoot($product);

        $result = DB::transaction(function () use ($root): array {
            [$root, $family] = $this->lockedFamily($root);
            $this->ensureRootMappings($root, $family);
            $changed = $family->contains(fn (Product $member): bool => $member->isStorefrontHidden());

            if (! $changed) {
                return [...compact('root', 'family', 'changed'), 'queued' => 0];
            }

            $restoreVisibility = in_array(
                $root->storefront_restore_visibility,
                self::RESTORABLE_VISIBILITIES,
                true,
            ) ? $root->storefront_restore_visibility : 'visible';
            $before = [
                'catalog_visibility' => data_get($root->masterData(), 'catalog_visibility', 'hidden'),
                'storefront_hidden_at' => $root->storefront_hidden_at?->toISOString(),
                'stock_verification_required' => $family->contains(
                    fn (Product $member): bool => $member->requiresStockVerification(),
                ),
            ];

            foreach ($family as $member) {
                $values = ['storefront_hidden_at' => null];

                if ($member->is($root)) {
                    $attributes = (array) $member->attributes;
                    data_set($attributes, 'master.catalog_visibility', $restoreVisibility);
                    $values['attributes'] = $attributes;
                }

                $member->forceFill($values)->save();
            }

            $queued = $this->queueFamilyStock($family, 'storefront_revealed_with_stock_hold');

            $root = $family->firstWhere('id', $root->id) ?? $root;

            if ($changed) {
                $this->audit->record('product.storefront_revealed', $root, $before, [
                    'catalog_visibility' => $restoreVisibility,
                    'storefront_hidden_at' => null,
                    'stock_verification_required' => true,
                ], [
                    'family_product_ids' => $family->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    'stock_policy' => 'woocommerce_zero_until_manual_verification',
                ]);
            }

            return compact('root', 'family', 'changed', 'queued');
        }, 3);

        $this->dispatchRootExport($result['root']);

        return $result;
    }

    /**
     * @return array{root:Product,family:Collection<int, Product>,changed:bool,queued:int,export_token:?string}
     */
    public function verifyStock(Product $product): array
    {
        $root = $this->familyRoot($product);

        $result = DB::transaction(function () use ($root): array {
            [$root, $family] = $this->lockedFamily($root);
            $this->ensureRootMappings($root, $family);

            if ($family->contains(fn (Product $member): bool => $member->isStorefrontHidden())) {
                throw new RuntimeException('Najpierw odkryj produkt, a dopiero potem potwierdź jego stan magazynowy.');
            }

            $changed = $family->contains(fn (Product $member): bool => $member->requiresStockVerification());

            $queued = $changed
                ? $this->queueFamilyStock($family, 'storefront_stock_manually_verified')
                : 0;
            $exportToken = null;

            if ($changed && $queued === 0) {
                $exportToken = $this->prepareRootExport($root, protectStockImport: true);

                if (is_string($exportToken)) {
                    $root->forceFill(['storefront_restore_visibility' => 'visible'])->save();
                }
            }

            foreach ($family as $member) {
                if ($member->requiresStockVerification()) {
                    $member->forceFill(['stock_verification_required_at' => null])->save();
                }
            }

            if ($changed) {
                $this->audit->record('product.storefront_stock_verified', $root, [
                    'stock_verification_required' => true,
                ], [
                    'stock_verification_required' => false,
                ], [
                    'family_product_ids' => $family->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                ]);
            }

            return [
                ...compact('root', 'family', 'changed', 'queued'),
                'export_token' => $exportToken,
            ];
        }, 3);

        if (is_string($result['export_token'])) {
            $this->dispatchPreparedRootExport($result['root'], $result['export_token']);
        }

        return $result;
    }

    public function completeSuccessfulManualExport(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $mappings = ProductChannelMapping::query()
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->get();

            foreach ($mappings as $mapping) {
                $metadata = (array) $mapping->metadata;

                if (data_get($metadata, 'product_data_export.stock_release_pending') !== true) {
                    continue;
                }

                data_forget($metadata, 'product_data_export.stock_release_pending');
                $mapping->forceFill(['metadata' => $metadata])->save();
            }

            $hasPendingExport = $mappings->contains(fn (ProductChannelMapping $mapping): bool => filled(
                data_get($mapping->metadata, 'product_data_export.pending_token'),
            ));

            if (! $product->isStorefrontHidden() && ! $hasPendingExport) {
                $product->forceFill(['storefront_restore_visibility' => null])->save();
            }
        }, 3);
    }

    public function familyRoot(Product $product): Product
    {
        $parentIds = ProductRelation::query()
            ->where('child_product_id', $product->id)
            ->where('relation_type', 'variant')
            ->pluck('parent_product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique();

        $variationMappings = ProductChannelMapping::query()
            ->where('product_id', $product->id)
            ->whereNotNull('external_variation_id')
            ->get(['sales_channel_id', 'external_product_id']);

        foreach ($variationMappings as $mapping) {
            ProductChannelMapping::query()
                ->where('sales_channel_id', $mapping->sales_channel_id)
                ->where('external_product_id', $mapping->external_product_id)
                ->whereNull('external_variation_id')
                ->where('product_id', '!=', $product->id)
                ->pluck('product_id')
                ->each(fn ($id) => $parentIds->push((int) $id));

            $siblingProductIds = ProductChannelMapping::query()
                ->where('sales_channel_id', $mapping->sales_channel_id)
                ->where('external_product_id', $mapping->external_product_id)
                ->whereNotNull('external_variation_id')
                ->pluck('product_id');

            ProductRelation::query()
                ->whereIn('child_product_id', $siblingProductIds)
                ->where('relation_type', 'variant')
                ->pluck('parent_product_id')
                ->each(fn ($id) => $parentIds->push((int) $id));
        }

        $parentIds = $parentIds->unique()->values();

        if ($parentIds->count() > 1) {
            throw new RuntimeException('Nie można jednoznacznie ustalić produktu głównego dla tego wariantu.');
        }

        // A variant can be orphaned: the operator deleted the ERP parent (its
        // parent mapping cascaded away) while this child kept its variation
        // mapping. No local parent candidate exists at all then — treat the
        // variant as its own root so it can still be hidden or archived
        // instead of failing with "complete the mapping" it can never satisfy.
        return $parentIds->isEmpty()
            ? $product
            : Product::query()->findOrFail((int) $parentIds->first());
    }

    /**
     * @return array{0:Product,1:Collection<int, Product>}
     */
    private function lockedFamily(Product $root): array
    {
        $relationChildIds = ProductRelation::query()
            ->where('parent_product_id', $root->id)
            ->where('relation_type', 'variant')
            ->pluck('child_product_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $mappedChildIds = $this->mappedFamilyVariantIds($root, $relationChildIds);
        $childIds = collect([...$relationChildIds, ...$mappedChildIds])
            ->unique()
            ->values()
            ->all();
        $family = Product::query()
            ->whereIn('id', [(int) $root->id, ...$childIds])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $lockedRoot = $family->firstWhere('id', $root->id);

        if (! $lockedRoot instanceof Product) {
            throw new RuntimeException('Nie znaleziono produktu głównego.');
        }

        foreach ($family->where('id', '!=', $lockedRoot->id) as $member) {
            ProductRelation::query()->firstOrCreate(
                [
                    'parent_product_id' => $lockedRoot->id,
                    'child_product_id' => $member->id,
                    'relation_type' => 'variant',
                ],
                [
                    'sort_order' => 100,
                    'metadata' => [
                        'source' => 'storefront_visibility_recovery',
                        'recovered_at' => now()->toISOString(),
                    ],
                ],
            );
        }

        return [$lockedRoot, $family];
    }

    private function dispatchRootExport(Product $root): void
    {
        $syncToken = DB::transaction(fn (): ?string => $this->prepareRootExport($root));

        if ($syncToken === null) {
            return;
        }

        $this->dispatchPreparedRootExport($root, $syncToken);
    }

    private function prepareRootExport(Product $root, bool $protectStockImport = false): ?string
    {
        $syncToken = (string) Str::uuid();
        $mappings = ProductChannelMapping::query()
            ->where('product_id', $root->id)
            ->lockForUpdate()
            ->get();

        if ($mappings->isEmpty()) {
            return null;
        }

        foreach ($mappings as $mapping) {
            $metadata = (array) $mapping->metadata;
            data_set($metadata, 'product_data_export.pending_token', $syncToken);
            data_set($metadata, 'product_data_export.requested_at', now()->toISOString());

            if ($protectStockImport) {
                data_set($metadata, 'product_data_export.stock_release_pending', true);
            }

            $mapping->forceFill(['metadata' => $metadata])->save();
        }

        return $syncToken;
    }

    private function dispatchPreparedRootExport(Product $root, string $syncToken): void
    {
        ExportWooCommerceProductDataJob::dispatch((int) $root->id, $syncToken)
            ->onConnection('database');
        ExportWooCommerceProductDataJob::dispatchAfterResponse((int) $root->id, $syncToken);
    }

    /**
     * @param  list<int>  $relationChildIds
     * @return list<int>
     */
    private function mappedFamilyVariantIds(Product $root, array $relationChildIds): array
    {
        $familyPairKeys = ProductChannelMapping::query()
            ->where('product_id', $root->id)
            ->whereNull('external_variation_id')
            ->get(['sales_channel_id', 'external_product_id'])
            ->mapWithKeys(fn (ProductChannelMapping $mapping): array => [
                $this->mappingPairKey($mapping) => true,
            ]);

        if ($relationChildIds !== []) {
            ProductChannelMapping::query()
                ->whereIn('product_id', $relationChildIds)
                ->whereNotNull('external_variation_id')
                ->get(['sales_channel_id', 'external_product_id'])
                ->each(function (ProductChannelMapping $mapping) use ($familyPairKeys): void {
                    $familyPairKeys->put($this->mappingPairKey($mapping), true);
                });
        }

        if ($familyPairKeys->isEmpty()) {
            return [];
        }

        $salesChannelIds = $familyPairKeys->keys()
            ->map(fn (string $key): int => (int) Str::before($key, ':'))
            ->unique()
            ->values()
            ->all();

        return ProductChannelMapping::query()
            ->whereIn('sales_channel_id', $salesChannelIds)
            ->whereNotNull('external_variation_id')
            ->get(['product_id', 'sales_channel_id', 'external_product_id'])
            ->filter(fn (ProductChannelMapping $mapping): bool => $familyPairKeys->has(
                $this->mappingPairKey($mapping),
            ))
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function mappingPairKey(ProductChannelMapping $mapping): string
    {
        return (int) $mapping->sales_channel_id.':'.(string) $mapping->external_product_id;
    }

    /** @param Collection<int, Product> $family */
    private function ensureRootMappings(Product $root, Collection $family): void
    {
        $rootMappings = ProductChannelMapping::query()
            ->where('product_id', $root->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('sales_channel_id');
        $variationMappings = ProductChannelMapping::query()
            ->whereIn('product_id', $family->where('id', '!=', $root->id)->pluck('id')->all())
            ->whereNotNull('external_variation_id')
            ->lockForUpdate()
            ->get()
            ->groupBy('sales_channel_id');

        foreach ($variationMappings as $salesChannelId => $mappings) {
            $externalProductIds = $mappings
                ->pluck('external_product_id')
                ->map(fn ($id): string => (string) $id)
                ->unique()
                ->values();

            if ($externalProductIds->count() !== 1) {
                throw new RuntimeException('Warianty rodziny wskazują różne produkty główne WooCommerce w tym samym kanale.');
            }

            $externalProductId = (string) $externalProductIds->first();
            $rootMapping = $rootMappings->get($salesChannelId);

            if ($rootMapping instanceof ProductChannelMapping) {
                if ((string) $rootMapping->external_product_id !== $externalProductId) {
                    throw new RuntimeException('Mapowanie produktu głównego nie zgadza się z mapowaniami jego wariantów.');
                }

                continue;
            }

            $rootMapping = ProductChannelMapping::query()->create([
                'product_id' => $root->id,
                'sales_channel_id' => (int) $salesChannelId,
                'external_product_id' => $externalProductId,
                'external_variation_id' => null,
                'external_sku' => $root->sku,
                'stock_sync_enabled' => true,
                'metadata' => [
                    'source' => 'erp',
                    'mapping_role' => 'parent',
                    'recovered_from_variant_mappings_at' => now()->toISOString(),
                ],
            ]);
            $rootMappings->put($salesChannelId, $rootMapping);
        }
    }

    /** @param Collection<int, Product> $family */
    private function queueFamilyStock(Collection $family, string $reason): int
    {
        $triggers = StockBalance::query()
            ->whereIn('product_id', $family->pluck('id')->all())
            ->get(['warehouse_id', 'product_id'])
            ->map(fn (StockBalance $balance): array => [
                'warehouse_id' => (int) $balance->warehouse_id,
                'product_id' => (int) $balance->product_id,
            ])
            ->values()
            ->all();

        return $this->stockSyncQueue->queueForTriggers($triggers, $reason);
    }
}
