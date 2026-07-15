<?php

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\WordpressIntegration;
use App\Services\Products\LegacySizeVariantAxisResolver;
use App\Services\Products\ProductVariantOptionNormalizer;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const REPAIR_PATH = 'maintenance.legacy_size_variant_axis_recovery';

    private ?Collection $sizeDefinitions = null;

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

        $resolver = app(LegacySizeVariantAxisResolver::class);
        $normalizer = app(ProductVariantOptionNormalizer::class);
        $backfill = app(LegacyVariantFamilyBackfillService::class);
        $visitedProductIds = [];

        ProductChannelMapping::query()
            ->whereIn('sales_channel_id', $translatedWooChannelIds)
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->with(['product.channelMappings', 'product.parentRelations', 'product.variantChildren'])
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use (
                $resolver,
                $normalizer,
                $backfill,
                &$visitedProductIds,
            ): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product
                        || isset($visitedProductIds[$product->id])
                        || $product->variantChildren->isEmpty()
                        || ! $this->isCanonicalErpRoot($product)
                        || $product->channelMappings->pluck('sales_channel_id')->unique()->count() !== 1
                        || ! $this->isPrimaryPolishMapping($mapping)
                    ) {
                        continue;
                    }

                    $visitedProductIds[$product->id] = true;

                    DB::transaction(function () use (
                        $product,
                        $resolver,
                        $normalizer,
                        $backfill,
                    ): void {
                        $parent = Product::query()
                            ->whereKey($product->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $parent instanceof Product) {
                            return;
                        }

                        $parent->load([
                            'parentRelations',
                            'variantChildren.parentRelations',
                        ]);

                        if (! $this->isCanonicalErpRoot($parent)
                            || $parent->variantChildren->isEmpty()
                            || ! $this->hasSoleVariantParentBindings($parent)
                        ) {
                            return;
                        }

                        $declaredAttribute = trim((string) data_get(
                            $parent->masterData(),
                            'variant_attribute',
                            '',
                        ));
                        $recoveredAttribute = $resolver->recover(
                            $parent,
                            $parent->variantChildren,
                        );

                        if ($recoveredAttribute === null
                            && $normalizer->isSizeAttribute($declaredAttribute)
                            && $this->hasLegacyCanonicalSizeEvidence(
                                $parent,
                                $parent->variantChildren,
                                $declaredAttribute,
                                $resolver,
                            )
                        ) {
                            $recoveredAttribute = $declaredAttribute;
                        }

                        if ($recoveredAttribute === null
                            || ! $normalizer->isSizeAttribute($recoveredAttribute)
                        ) {
                            return;
                        }

                        $plan = $this->repairPlan(
                            $parent,
                            $parent->variantChildren,
                            $recoveredAttribute,
                            $resolver,
                            $normalizer,
                        );

                        if ($plan === null) {
                            return;
                        }

                        $this->persistRepair(
                            $parent,
                            $declaredAttribute,
                            $recoveredAttribute,
                            $plan,
                            $resolver,
                        );
                        $backfill->markPendingRevision(
                            $parent,
                            LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION,
                        );
                    });
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. A completed remote repair and its durable queue
        // request must not be rolled back to the invalid dual-axis family.
    }

    /**
     * @param  Collection<int, Product>  $variants
     * @return array{parent_parameter:array<string, mixed>, children:list<array{product:Product, option_parameter:array<string, mixed>, option:string, option_slug:string, sort_order:int}>}|null
     */
    private function repairPlan(
        Product $parent,
        Collection $variants,
        string $recoveredAttribute,
        LegacySizeVariantAxisResolver $resolver,
        ProductVariantOptionNormalizer $normalizer,
    ): ?array {
        $children = [];

        foreach ($variants as $variant) {
            $childPlan = $this->childPlan(
                $parent,
                $variant,
                $recoveredAttribute,
                $resolver,
                $normalizer,
            );

            if ($childPlan === null) {
                return null;
            }

            $children[$variant->id] = $childPlan;
        }

        $familySlugs = collect($children)
            ->pluck('option_slug')
            ->filter()
            ->sort()
            ->values();

        if ($familySlugs->count() !== $variants->count()
            || $familySlugs->unique()->count() !== $familySlugs->count()
        ) {
            return null;
        }

        $parentParameters = collect((array) data_get($parent->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter))
            ->values();
        $canonicalParameters = $parentParameters
            ->filter(fn (array $parameter): bool => $this->sameAttribute(
                (string) ($parameter['name'] ?? ''),
                $recoveredAttribute,
            ))
            ->values();

        if ($canonicalParameters->count() > 1) {
            return null;
        }

        if ($canonicalParameters->count() === 1) {
            $canonicalSlugs = $this->parameterSlugs($canonicalParameters->first());

            if ($canonicalSlugs->isNotEmpty()
                && $canonicalSlugs->sort()->values()->all() !== $familySlugs->all()
            ) {
                return null;
            }
        }

        $children = $this->orderChildren(
            $children,
            $canonicalParameters->first() ?? ['name' => $recoveredAttribute],
            $recoveredAttribute,
            $normalizer,
        );

        foreach ($parentParameters as $parameter) {
            $name = trim((string) ($parameter['name'] ?? ''));

            if ($this->sameAttribute($name, $recoveredAttribute)) {
                continue;
            }

            if ($resolver->isLegacyGeneric($name)) {
                $genericSlugs = $this->parameterSlugs($parameter);

                if ($genericSlugs->isNotEmpty()
                    && $genericSlugs->sort()->values()->all() !== $familySlugs->all()
                ) {
                    return null;
                }

                continue;
            }

            if ((bool) ($parameter['variation'] ?? false)) {
                // A second concrete variation axis makes the family ambiguous.
                return null;
            }
        }

        foreach ($children as $childPlan) {
            $childParameters = collect((array) data_get(
                $childPlan['product']->masterData(),
                'parameters',
                [],
            ))->filter(fn (mixed $parameter): bool => is_array($parameter));

            foreach ($childParameters as $parameter) {
                if (! $resolver->isLegacyGeneric((string) ($parameter['name'] ?? ''))) {
                    continue;
                }

                $genericSlugs = $this->parameterSlugs($parameter)->sort()->values();

                if ($genericSlugs->isNotEmpty()
                    && $genericSlugs->all() !== [$childPlan['option_slug']]
                    && $genericSlugs->all() !== $familySlugs->all()
                ) {
                    return null;
                }
            }
        }

        $parentParameter = $canonicalParameters->first() ?? [];
        $parentParameter['name'] = $recoveredAttribute;
        $parentParameter['value'] = collect($children)
            ->pluck('option')
            ->implode(' | ');
        $parentParameter['variation'] = true;

        foreach (['value_pl', 'value_en'] as $localizedValue) {
            if (array_key_exists($localizedValue, $parentParameter)) {
                $parentParameter[$localizedValue] = $parentParameter['value'];
            }
        }

        foreach ((array) ($parentParameter['translations'] ?? []) as $language => $translation) {
            if (! is_array($translation) || ! array_key_exists('value', $translation)) {
                continue;
            }

            data_set(
                $parentParameter,
                "translations.{$language}.value",
                $parentParameter['value'],
            );
        }

        return [
            'parent_parameter' => $parentParameter,
            'children' => $children,
        ];
    }

    /**
     * @return array{product:Product, option_parameter:array<string, mixed>, option:string, option_slug:string}|null
     */
    private function childPlan(
        Product $parent,
        Product $variant,
        string $recoveredAttribute,
        LegacySizeVariantAxisResolver $resolver,
        ProductVariantOptionNormalizer $normalizer,
    ): ?array {
        $master = $variant->masterData();
        $declaredAttribute = trim((string) data_get($master, 'variant_attribute', ''));

        if ($declaredAttribute !== ''
            && ! $this->sameAttribute($declaredAttribute, $recoveredAttribute)
            && ! $resolver->isLegacyGeneric($declaredAttribute)
        ) {
            return null;
        }

        $parameters = collect((array) data_get($master, 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter))
            ->values();
        $canonicalParameters = $parameters
            ->filter(fn (array $parameter): bool => $this->sameAttribute(
                (string) ($parameter['name'] ?? ''),
                $recoveredAttribute,
            ) && $this->parameterSlugs($parameter)->count() === 1)
            ->values();

        if ($canonicalParameters->count() > 1) {
            return null;
        }

        $sourceWasGeneric = false;
        $optionParameter = $canonicalParameters->first();

        if (! is_array($optionParameter)) {
            $genericParameters = $parameters
                ->filter(fn (array $parameter): bool => $resolver->isLegacyGeneric(
                    (string) ($parameter['name'] ?? ''),
                ) && $this->parameterSlugs($parameter)->count() === 1)
                ->values();
            $genericSlugs = $genericParameters
                ->map(fn (array $parameter): string => $this->parameterSlugs($parameter)->first())
                ->unique()
                ->values();

            if ($genericParameters->isEmpty() || $genericSlugs->count() !== 1) {
                return null;
            }

            $optionParameter = $genericParameters->first();
            $sourceWasGeneric = true;
        }

        foreach ($parameters as $parameter) {
            $name = trim((string) ($parameter['name'] ?? ''));

            if ($this->sameAttribute($name, $recoveredAttribute)
                || $resolver->isLegacyGeneric($name)
            ) {
                continue;
            }

            if ((bool) ($parameter['variation'] ?? false)) {
                return null;
            }
        }

        $rawOption = trim((string) ($optionParameter['value'] ?? ''));
        $option = $normalizer->normalize(
            $recoveredAttribute,
            $resolver->canonicalSizeOption(
                $parent,
                $recoveredAttribute,
                $rawOption,
            ) ?? $rawOption,
        );
        $optionSlug = $this->optionSlug($option);

        if ($optionSlug === '') {
            return null;
        }

        $optionParameter['name'] = $recoveredAttribute;
        $optionParameter['value'] = $option;
        $optionParameter['variation'] = true;

        if ($sourceWasGeneric && array_key_exists('name_en', $optionParameter)) {
            $optionParameter['name_en'] = 'Size';
        }

        foreach (['value_pl', 'value_en'] as $localizedValue) {
            if (array_key_exists($localizedValue, $optionParameter)) {
                $rawLocalizedOption = trim((string) $optionParameter[$localizedValue]);
                $optionParameter[$localizedValue] = $normalizer->normalize(
                    $localizedValue === 'value_en' ? 'Size' : $recoveredAttribute,
                    $resolver->canonicalSizeOption(
                        $parent,
                        $recoveredAttribute,
                        $rawLocalizedOption,
                    ) ?? $rawLocalizedOption,
                );
            }
        }

        foreach (['pl', 'en'] as $language) {
            $translationPath = "translations.{$language}.value";

            if (data_get($optionParameter, $translationPath) !== null) {
                $rawTranslatedOption = trim((string) data_get(
                    $optionParameter,
                    $translationPath,
                ));
                data_set($optionParameter, $translationPath, $normalizer->normalize(
                    $language === 'en' ? 'Size' : $recoveredAttribute,
                    $resolver->canonicalSizeOption(
                        $parent,
                        $recoveredAttribute,
                        $rawTranslatedOption,
                    ) ?? $rawTranslatedOption,
                ));
            }
        }

        return [
            'product' => $variant,
            'option_parameter' => $optionParameter,
            'option' => $option,
            'option_slug' => $optionSlug,
        ];
    }

    /**
     * @param  array{parent_parameter:array<string, mixed>, children:list<array{product:Product, option_parameter:array<string, mixed>, option:string, option_slug:string, sort_order:int}>}  $plan
     */
    private function persistRepair(
        Product $parent,
        string $declaredAttribute,
        string $recoveredAttribute,
        array $plan,
        LegacySizeVariantAxisResolver $resolver,
    ): void {
        $repairedAt = now()->toISOString();
        $parentAttributes = (array) $parent->attributes;
        $parentMaster = $parent->masterData();
        $parentMaster['variant_attribute'] = $recoveredAttribute;
        $parentMaster['parameters'] = collect((array) data_get($parentMaster, 'parameters', []))
            ->filter(fn (mixed $parameter): bool => ! is_array($parameter)
                || (! $resolver->isLegacyGeneric((string) ($parameter['name'] ?? ''))
                    && ! $this->sameAttribute(
                        (string) ($parameter['name'] ?? ''),
                        $recoveredAttribute,
                    )))
            ->values()
            ->push($plan['parent_parameter'])
            ->all();
        data_set($parentMaster, self::REPAIR_PATH, [
            'revision' => LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION,
            'previous_variant_attribute' => $declaredAttribute,
            'variant_attribute' => $recoveredAttribute,
            'repaired_at' => $repairedAt,
        ]);
        $parentAttributes['master'] = $parentMaster;
        $parent->forceFill(['attributes' => $parentAttributes])->save();

        foreach ($plan['children'] as $childPlan) {
            $variant = $childPlan['product'];
            $variantAttributes = (array) $variant->attributes;
            $variantMaster = $variant->masterData();
            $previousVariantAttribute = trim((string) data_get(
                $variantMaster,
                'variant_attribute',
                '',
            ));
            $variantMaster['variant_attribute'] = $recoveredAttribute;
            $variantMaster['parameters'] = collect((array) data_get($variantMaster, 'parameters', []))
                ->filter(fn (mixed $parameter): bool => ! is_array($parameter)
                    || (! $resolver->isLegacyGeneric((string) ($parameter['name'] ?? ''))
                        && ! $this->sameAttribute(
                            (string) ($parameter['name'] ?? ''),
                            $recoveredAttribute,
                        )))
                ->values()
                ->push($childPlan['option_parameter'])
                ->all();
            data_set($variantMaster, self::REPAIR_PATH, [
                'revision' => LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION,
                'parent_product_id' => $parent->id,
                'previous_variant_attribute' => $previousVariantAttribute,
                'variant_attribute' => $recoveredAttribute,
                'repaired_at' => $repairedAt,
            ]);
            $variantAttributes['master'] = $variantMaster;
            $variant->forceFill(['attributes' => $variantAttributes])->save();

            $relation = ProductRelation::query()
                ->where('parent_product_id', $parent->id)
                ->where('child_product_id', $variant->id)
                ->where('relation_type', 'variant')
                ->lockForUpdate()
                ->first();

            if ($relation instanceof ProductRelation) {
                $relationMetadata = (array) $relation->metadata;
                $relationMetadata['variant_attribute'] = $recoveredAttribute;
                $relationMetadata['variant_option'] = $childPlan['option'];
                data_set($relationMetadata, self::REPAIR_PATH, [
                    'revision' => LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION,
                    'repaired_at' => $repairedAt,
                ]);
                $relation->forceFill([
                    'sort_order' => $childPlan['sort_order'],
                    'metadata' => $relationMetadata,
                ])->save();
            }
        }
    }

    /**
     * A previous repair may already have promoted the parent to `Rozmiar`
     * while leaving one or more child snapshots/relations on a slug-shaped or
     * generic axis. Those families no longer pass generic-axis recovery, but
     * still need one canonical export to replace `s-m`/`m-l` in WooCommerce.
     *
     * @param  Collection<int, Product>  $variants
     */
    private function hasLegacyCanonicalSizeEvidence(
        Product $parent,
        Collection $variants,
        string $sizeAttribute,
        LegacySizeVariantAxisResolver $resolver,
    ): bool {
        $hasLegacyParameter = static fn (array $master): bool => collect((array) data_get(
            $master,
            'parameters',
            [],
        ))->contains(fn (mixed $parameter): bool => is_array($parameter)
            && $resolver->isLegacyGeneric((string) ($parameter['name'] ?? '')));

        if ($hasLegacyParameter($parent->masterData())) {
            return true;
        }

        foreach ($variants as $variant) {
            $master = $variant->masterData();
            $relationMetadata = $this->pivotMetadata($variant);

            if ($resolver->isLegacyGeneric((string) data_get($master, 'variant_attribute', ''))
                || $hasLegacyParameter($master)
                || $resolver->isLegacyGeneric((string) data_get(
                    $relationMetadata,
                    'variant_attribute',
                    '',
                ))
            ) {
                return true;
            }

            $parameters = collect((array) data_get($master, 'parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter))
                ->filter(fn (array $parameter): bool => $this->sameAttribute(
                    (string) ($parameter['name'] ?? ''),
                    $sizeAttribute,
                ))
                ->values();

            if ($parameters->contains(function (array $parameter) use (
                $parent,
                $sizeAttribute,
                $resolver,
            ): bool {
                $localizedOptions = collect([
                    $parameter['value'] ?? null,
                    $parameter['value_pl'] ?? null,
                    $parameter['value_en'] ?? null,
                ])->merge(
                    collect((array) ($parameter['translations'] ?? []))
                        ->filter(fn (mixed $translation): bool => is_array($translation))
                        ->pluck('value'),
                );

                return $localizedOptions->contains(function (mixed $raw) use (
                    $parent,
                    $sizeAttribute,
                    $resolver,
                ): bool {
                    $rawOption = trim((string) ($raw ?? ''));
                    $canonicalOption = $resolver->canonicalSizeOption(
                        $parent,
                        $sizeAttribute,
                        $rawOption,
                    );

                    return $canonicalOption !== null && $canonicalOption !== $rawOption;
                });
            })) {
                return true;
            }

            $relationOption = trim((string) data_get(
                $relationMetadata,
                'variant_option',
                '',
            ));
            $canonicalRelationOption = $resolver->canonicalSizeOption(
                $parent,
                $sizeAttribute,
                $relationOption,
            );

            if ($canonicalRelationOption !== null && $canonicalRelationOption !== $relationOption) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{product:Product, option_parameter:array<string, mixed>, option:string, option_slug:string}>  $children
     * @param  array<string, mixed>  $parentParameter
     * @return list<array{product:Product, option_parameter:array<string, mixed>, option:string, option_slug:string, sort_order:int}>
     */
    private function orderChildren(
        array $children,
        array $parentParameter,
        string $sizeAttribute,
        ProductVariantOptionNormalizer $normalizer,
    ): array {
        $dictionaryOrders = $this->sizeDictionaryOrders(
            $parentParameter,
            $sizeAttribute,
            $normalizer,
        );

        return collect($children)
            ->map(function (array $child) use (
                $dictionaryOrders,
                $sizeAttribute,
                $normalizer,
            ): array {
                $identity = $normalizer->identity($sizeAttribute, $child['option']);
                $child['rank'] = $dictionaryOrders[$identity]
                    ?? $this->canonicalSizeRank($child['option']);
                $child['existing_order'] = (int) ($child['product']->pivot?->sort_order ?? 0);

                return $child;
            })
            ->sort(function (array $left, array $right): int {
                if ($left['rank'] === null && $right['rank'] === null) {
                    return $left['existing_order'] <=> $right['existing_order']
                        ?: $left['product']->id <=> $right['product']->id;
                }

                if ($left['rank'] === null) {
                    return 1;
                }

                if ($right['rank'] === null) {
                    return -1;
                }

                return $left['rank'] <=> $right['rank']
                    ?: $left['existing_order'] <=> $right['existing_order']
                    ?: $left['product']->id <=> $right['product']->id;
            })
            ->values()
            ->map(function (array $child, int $index): array {
                unset($child['rank'], $child['existing_order']);
                $child['sort_order'] = ($index + 1) * 10;

                return $child;
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $parentParameter
     * @return array<string, int>
     */
    private function sizeDictionaryOrders(
        array $parentParameter,
        string $sizeAttribute,
        ProductVariantOptionNormalizer $normalizer,
    ): array {
        if (! Schema::hasTable('product_parameter_definitions')) {
            return [];
        }

        $parameterName = mb_strtolower(trim((string) ($parentParameter['name'] ?? $sizeAttribute)));
        $parameterSlug = mb_strtolower(trim((string) ($parentParameter['slug'] ?? '')));
        $definitions = $this->sizeDefinitions ??= ProductParameterDefinition::query()
            ->orderBy('id')
            ->get();
        $definition = $definitions->first(function (ProductParameterDefinition $candidate) use (
            $normalizer,
            $parameterName,
            $parameterSlug,
        ): bool {
            if (! collect([$candidate->name, $candidate->name_en, $candidate->slug])
                ->filter()
                ->contains(fn (mixed $name): bool => $normalizer->isSizeAttribute((string) $name))
            ) {
                return false;
            }

            return ($parameterSlug !== '' && mb_strtolower(trim((string) $candidate->slug)) === $parameterSlug)
                || mb_strtolower(trim((string) $candidate->name)) === $parameterName
                || mb_strtolower(trim((string) $candidate->name_en)) === $parameterName;
        });

        if (! $definition instanceof ProductParameterDefinition) {
            $definition = $definitions->first(fn (ProductParameterDefinition $candidate): bool => collect([
                $candidate->name,
                $candidate->name_en,
                $candidate->slug,
            ])->filter()->contains(
                fn (mixed $name): bool => $normalizer->isSizeAttribute((string) $name),
            ));
        }

        if (! $definition instanceof ProductParameterDefinition) {
            return [];
        }

        return collect((array) $definition->values)
            ->map(fn (mixed $value): string => $normalizer->identity(
                (string) $definition->name,
                $value,
            ))
            ->filter()
            ->unique()
            ->values()
            ->mapWithKeys(fn (string $identity, int $index): array => [
                $identity => ($index + 1) * 10,
            ])
            ->all();
    }

    private function canonicalSizeRank(string $value): ?int
    {
        $value = mb_strtoupper(trim((string) preg_replace('/\s+/u', '', $value)));
        $value = str_replace(['–', '—', '-'], '/', $value);
        $aliases = [
            'ONESIZE' => 0,
            'ONE/SIZE' => 0,
            'UNIWERSALNY' => 0,
            'XXXXS' => 100,
            'XXXS' => 200,
            'XXS' => 300,
            'XXS/XS' => 350,
            'XS' => 400,
            'XS/S' => 450,
            'S' => 500,
            'S/M' => 550,
            'M' => 600,
            'M/L' => 650,
            'L' => 700,
            'L/XL' => 750,
            'XL' => 800,
            'XL/XXL' => 850,
            'XXL' => 900,
            '2XL' => 900,
            'XXXL' => 1000,
            '3XL' => 1000,
            '4XL' => 1100,
            '5XL' => 1200,
            '6XL' => 1300,
        ];

        if (array_key_exists($value, $aliases)) {
            return $aliases[$value];
        }

        if (preg_match('/^(\d+(?:[.,]\d+)?)(?:\/(\d+(?:[.,]\d+)?))?$/', $value, $matches) === 1) {
            $from = (float) str_replace(',', '.', $matches[1]);
            $to = isset($matches[2]) ? (float) str_replace(',', '.', $matches[2]) : $from;

            return 10_000 + (int) round($from * 100) + (int) round($to);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function pivotMetadata(Product $variant): array
    {
        $metadata = $variant->pivot?->getAttribute('metadata');

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function hasSoleVariantParentBindings(Product $parent): bool
    {
        return $parent->variantChildren->every(function (Product $variant) use ($parent): bool {
            if ($variant->is_translation
                || trim((string) $variant->sku) === ''
                || $variant->masterSource() !== 'erp'
                || data_get($variant->masterData(), 'product_type') !== 'variation'
            ) {
                return false;
            }

            $variantParents = $variant->parentRelations
                ->where('relation_type', 'variant')
                ->values();

            return $variantParents->count() === 1
                && (int) $variantParents->first()->parent_product_id === $parent->id;
        });
    }

    /** @return Collection<int, string> */
    private function parameterSlugs(array $parameter): Collection
    {
        return collect(preg_split(
            '/\s*[,;|]\s*/u',
            trim((string) ($parameter['value'] ?? '')),
        ) ?: [])
            ->map(fn (mixed $value): string => $this->optionSlug((string) $value))
            ->filter()
            ->unique()
            ->values();
    }

    private function optionSlug(string $value): string
    {
        $value = (string) preg_replace('/\s*[\/-]\s*/u', '-', trim($value));

        return Str::slug($value);
    }

    private function sameAttribute(string $left, string $right): bool
    {
        return mb_strtolower(trim($left)) === mb_strtolower(trim($right));
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
