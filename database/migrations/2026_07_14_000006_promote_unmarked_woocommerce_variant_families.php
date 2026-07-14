<?php

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Services\Products\ProductVariantInheritanceService;
use App\Services\Products\ProductVariantOptionNormalizer;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REPAIR_PATH = 'maintenance.legacy_variant_family_promotion';

    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_relations')
            || ! Schema::hasTable('product_channel_mappings')
        ) {
            return;
        }

        $inheritance = app(ProductVariantInheritanceService::class);
        $variantOptions = app(ProductVariantOptionNormalizer::class);
        $backfill = app(LegacyVariantFamilyBackfillService::class);

        Product::query()
            ->whereHas('childRelations', fn ($query) => $query->where('relation_type', 'variant'))
            ->with([
                'childRelations' => fn ($query) => $query
                    ->where('relation_type', 'variant')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'childRelations.childProduct',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($parents) use (
                $inheritance,
                $variantOptions,
                $backfill,
            ): void {
                foreach ($parents as $parent) {
                    $relations = $parent->childRelations
                        ->filter(fn (ProductRelation $relation): bool => $relation->childProduct instanceof Product)
                        ->values();

                    if ($relations->isEmpty()
                        || $relations->count() !== $parent->childRelations->count()
                        || ! $this->hasSoleParentBindings($parent, $relations, $inheritance)
                    ) {
                        continue;
                    }

                    $needsPromotion = $relations->contains(
                        fn (ProductRelation $relation): bool => ! $inheritance->inheritsFromParent(
                            $relation->childProduct,
                            $parent,
                        ),
                    );
                    $alreadyRepaired = data_get(
                        $parent->masterData(),
                        self::REPAIR_PATH.'.revision',
                    ) === LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION;

                    if ((! $alreadyRepaired && ! $this->hasStrongLegacySignal($parent, $relations))
                        || ! $this->hasUniqueConcreteOptions($parent, $relations, $variantOptions)
                    ) {
                        continue;
                    }

                    $wooIdentity = $this->unambiguousWooIdentity($parent, $relations);

                    if ($wooIdentity === null) {
                        continue;
                    }

                    $this->repairParent($parent, $wooIdentity);

                    foreach ($relations as $relation) {
                        $variant = $relation->childProduct;

                        if ($needsPromotion && ! $inheritance->inheritsFromParent($variant, $parent)) {
                            $attributes = (array) $variant->attributes;
                            $master = $variant->masterData();
                            $inheritanceMetadata = (array) data_get($master, 'inheritance', []);
                            $inheritanceMetadata = array_merge($inheritanceMetadata, [
                                'mode' => ProductVariantInheritanceService::MODE_PARENT,
                                'parent_product_id' => $parent->id,
                                'promoted_by' => LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION,
                                'promoted_at' => now()->toISOString(),
                            ]);
                            data_set($master, 'inheritance', $inheritanceMetadata);
                            $attributes['master'] = $master;
                            $variant->forceFill(['attributes' => $attributes])->save();
                        }

                        if ($needsPromotion) {
                            $inheritance->synchronizeVariant($parent, $variant);
                        }

                        $this->recordRelationRepair($relation, $wooIdentity);
                    }

                    $backfill->markPendingRevision(
                        $parent,
                        LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION,
                    );
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op: variants and remote translations may already rely
        // on the repaired parent binding.
    }

    /**
     * @param  Collection<int, ProductRelation>  $relations
     */
    private function hasSoleParentBindings(
        Product $parent,
        Collection $relations,
        ProductVariantInheritanceService $inheritance,
    ): bool {
        foreach ($relations as $relation) {
            $variant = $relation->childProduct;

            if (ProductRelation::query()
                ->where('child_product_id', $variant->id)
                ->where('relation_type', 'variant')
                ->count() !== 1
            ) {
                return false;
            }

            if (data_get($variant->masterData(), 'inheritance.mode') === ProductVariantInheritanceService::MODE_PARENT
                && ! $inheritance->inheritsFromParent($variant, $parent)
            ) {
                // Never overwrite an invalid or conflicting explicit binding.
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<int, ProductRelation>  $relations
     */
    private function hasStrongLegacySignal(Product $parent, Collection $relations): bool
    {
        $products = $relations
            ->map(fn (ProductRelation $relation): Product => $relation->childProduct)
            ->prepend($parent);

        if ($products->contains(fn (Product $product): bool => $this->hasCopyName($product)
            || $this->hasPartialCopyMarker($product)
        )) {
            return true;
        }

        if ($relations->contains(fn (ProductRelation $relation): bool => filled(
            data_get($relation->metadata, 'copied_from_relation_id'),
        ) || filled(data_get($relation->metadata, 'copied_at')))) {
            return true;
        }

        return $parent->masterSource() === 'erp'
            && $relations->contains(fn (ProductRelation $relation): bool => data_get(
                $relation->metadata,
                'source',
            ) === 'woocommerce_mapping_relation_repair');
    }

    private function hasCopyName(Product $product): bool
    {
        return collect([
            $product->name,
            data_get($product->masterData(), 'content.pl.name'),
            data_get($product->masterData(), 'content.en.name'),
        ])->contains(fn (mixed $name): bool => preg_match(
            '/\(\s*kopia\s*\)/iu',
            (string) $name,
        ) === 1);
    }

    private function hasPartialCopyMarker(Product $product): bool
    {
        return collect((array) data_get($product->masterData(), 'copy', []))
            ->contains(fn (mixed $value): bool => filled($value));
    }

    /**
     * @param  Collection<int, ProductRelation>  $relations
     */
    private function hasUniqueConcreteOptions(
        Product $parent,
        Collection $relations,
        ProductVariantOptionNormalizer $normalizer,
    ): bool {
        $attribute = trim((string) data_get($parent->masterData(), 'variant_attribute', ''));

        if ($attribute === '') {
            return false;
        }

        $identities = $relations->map(function (ProductRelation $relation) use (
            $attribute,
            $normalizer,
        ): ?string {
            $value = $this->concreteOption($relation->childProduct, $attribute);

            return $value === null ? null : $normalizer->identity($attribute, $value);
        });

        return ! $identities->contains(null)
            && $identities->filter(fn (string $identity): bool => $identity !== '')->count() === $relations->count()
            && $identities->unique()->count() === $relations->count();
    }

    private function concreteOption(Product $variant, string $attribute): ?string
    {
        $parameters = collect((array) data_get($variant->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter));
        $matching = $parameters->filter(fn (array $parameter): bool => mb_strtolower(
            trim((string) ($parameter['name'] ?? '')),
        ) === mb_strtolower($attribute));
        $candidates = $matching->isNotEmpty()
            ? $matching
            : $parameters->filter(fn (array $parameter): bool => (bool) ($parameter['variation'] ?? false));
        $values = $candidates
            ->map(fn (array $parameter): string => trim((string) ($parameter['value'] ?? '')))
            ->filter(fn (string $value): bool => $value !== ''
                && preg_match('/[,;|]/u', $value) !== 1)
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->values();

        return $values->count() === 1 ? $values->first() : null;
    }

    /**
     * @param  Collection<int, ProductRelation>  $relations
     * @return array{sales_channel_id:int, external_product_id:string, parent_mapping_id:int, variations:array<int, string>}|null
     */
    private function unambiguousWooIdentity(Product $parent, Collection $relations): ?array
    {
        $childIds = $relations
            ->pluck('child_product_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values();
        $parentMappings = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->orderBy('sales_channel_id')
            ->orderBy('id')
            ->get();

        foreach ($parentMappings as $parentMapping) {
            $externalProductId = trim((string) $parentMapping->external_product_id);

            if ($externalProductId === '' || ! $this->isPrimaryPolishMapping($parentMapping)) {
                continue;
            }

            $familyMappings = ProductChannelMapping::query()
                ->where('sales_channel_id', $parentMapping->sales_channel_id)
                ->where('external_product_id', $externalProductId)
                ->orderBy('id')
                ->get();
            $baseMappings = $familyMappings->filter(
                fn (ProductChannelMapping $mapping): bool => $this->isParentMapping($mapping),
            );
            $variationMappings = $familyMappings->reject(
                fn (ProductChannelMapping $mapping): bool => $this->isParentMapping($mapping),
            )->values();
            $externalVariationIds = $variationMappings
                ->map(fn (ProductChannelMapping $mapping): string => trim((string) $mapping->external_variation_id));

            if ($baseMappings->count() !== 1
                || ! $baseMappings->first()->is($parentMapping)
                || ! $variationMappings->every(fn (ProductChannelMapping $mapping): bool => $this->isPrimaryPolishMapping($mapping))
                || $variationMappings->count() !== $childIds->count()
                || $externalVariationIds->contains(fn (string $id): bool => $id === '' || $id === '0')
                || $externalVariationIds->unique()->count() !== $variationMappings->count()
                || $variationMappings->pluck('product_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all()
                    !== $childIds->all()
                || $variationMappings->groupBy('product_id')->contains(fn (Collection $mappings): bool => $mappings->count() !== 1)
            ) {
                continue;
            }

            return [
                'sales_channel_id' => (int) $parentMapping->sales_channel_id,
                'external_product_id' => $externalProductId,
                'parent_mapping_id' => (int) $parentMapping->id,
                'variations' => $variationMappings->mapWithKeys(fn (ProductChannelMapping $mapping): array => [
                    (int) $mapping->product_id => trim((string) $mapping->external_variation_id),
                ])->all(),
            ];
        }

        return null;
    }

    private function isParentMapping(ProductChannelMapping $mapping): bool
    {
        $variationId = trim((string) ($mapping->external_variation_id ?? ''));

        return $variationId === '' || $variationId === '0';
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

        return $isPolish && in_array($role, ['', 'primary'], true);
    }

    /**
     * @param  array{sales_channel_id:int, external_product_id:string, parent_mapping_id:int, variations:array<int, string>}  $wooIdentity
     */
    private function repairParent(Product $parent, array $wooIdentity): void
    {
        $attributes = (array) $parent->attributes;
        $master = $parent->masterData();

        if (! is_array(data_get($master, 'content.en'))) {
            data_set($master, 'content.en', []);
        }

        if (! filled(data_get($master, 'publication_date'))
            && $parent->is_active
            && (string) data_get($master, 'publication_status', 'publish') === 'publish'
        ) {
            data_set($master, 'publication_date', now()->format('Y-m-d\TH:i'));
        }

        if (data_get($master, self::REPAIR_PATH.'.revision')
            !== LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION
        ) {
            data_set($master, self::REPAIR_PATH, [
                'revision' => LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION,
                'source' => 'unambiguous_woocommerce_mapping_family',
                'sales_channel_id' => $wooIdentity['sales_channel_id'],
                'external_product_id' => $wooIdentity['external_product_id'],
                'repaired_at' => now()->toISOString(),
            ]);
        }

        $attributes['master'] = $master;
        $parent->forceFill(['attributes' => $attributes])->save();
    }

    /**
     * @param  array{sales_channel_id:int, external_product_id:string, parent_mapping_id:int, variations:array<int, string>}  $wooIdentity
     */
    private function recordRelationRepair(ProductRelation $relation, array $wooIdentity): void
    {
        $metadata = (array) $relation->metadata;

        if (data_get($metadata, 'inheritance_repair.revision')
            === LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION
        ) {
            return;
        }

        data_set($metadata, 'inheritance_repair', [
            'revision' => LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION,
            'source' => 'unambiguous_woocommerce_mapping_family',
            'sales_channel_id' => $wooIdentity['sales_channel_id'],
            'external_product_id' => $wooIdentity['external_product_id'],
            'external_variation_id' => $wooIdentity['variations'][(int) $relation->child_product_id],
            'repaired_at' => now()->toISOString(),
        ]);
        $relation->forceFill(['metadata' => $metadata])->save();
    }
};
