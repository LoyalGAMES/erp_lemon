<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductRelation;

final class ProductVariantInheritanceService
{
    public const MODE_PARENT = 'parent';

    public function __construct(
        private readonly ProductVariantOptionNormalizer $variantOptions,
        private readonly LegacySizeVariantAxisResolver $legacySizeAxis,
    ) {}

    public function inheritsFromParent(Product $variant, ?Product $parent = null): bool
    {
        $master = $variant->masterData();

        if (data_get($master, 'inheritance.mode') === self::MODE_PARENT) {
            $boundParentId = $this->boundParentId($master);

            if ($boundParentId !== null) {
                return (! $parent instanceof Product || $parent->id === $boundParentId)
                    && $this->hasVariantRelation($variant, $boundParentId);
            }

            if ($this->hasInvalidParentMarker($master)) {
                return false;
            }

            // Early inheritance markers did not store the parent ID. They are
            // safe to promote only while the variant has one unambiguous
            // variant parent; synchronizeVariant() then persists that binding.
            $legacyParentId = $this->soleVariantParentId($variant);

            return $legacyParentId !== null
                && (! $parent instanceof Product || $parent->id === $legacyParentId);
        }

        return $parent instanceof Product && $this->isLegacyCopiedVariant($parent, $variant);
    }

    public function inheritedParentId(Product $variant): ?int
    {
        if (data_get($variant->masterData(), 'inheritance.mode') !== self::MODE_PARENT) {
            return null;
        }

        return $this->boundParentId($variant->masterData());
    }

    /**
     * Build a variant snapshot from the current parent data. The inheritance
     * marker remains the source of truth: the snapshot only makes the ERP form
     * readable and keeps legacy consumers working.
     *
     * @return array<string, mixed>
     */
    public function masterData(Product $parent, Product $variant): array
    {
        $variantMaster = $variant->masterData();

        if (! $this->inheritsFromParent($variant, $parent)) {
            return $variantMaster;
        }

        return $this->resolve($parent, $variantMaster);
    }

    /**
     * @param  array<string, mixed>  $variantMaster
     * @return array<string, mixed>
     */
    public function inheritedMasterData(Product $parent, array $variantMaster): array
    {
        $boundParentId = $this->boundParentId($variantMaster);

        if (($boundParentId !== null && $boundParentId !== $parent->id)
            || $this->hasInvalidParentMarker($variantMaster)
        ) {
            return $variantMaster;
        }

        return $this->resolve($parent, $variantMaster);
    }

    /**
     * @param  array<string, mixed>  $optionParameter
     * @param  array<string, mixed>  $copyMetadata
     * @return array<string, mixed>
     */
    public function newVariantMasterData(
        Product $parent,
        string $variantAttribute,
        array $optionParameter,
        array $copyMetadata = [],
    ): array {
        $variantMaster = [
            'source' => 'erp',
            'product_type' => 'variation',
            'variant_attribute' => $variantAttribute,
            'parameters' => [$optionParameter],
            'inheritance' => [
                'mode' => self::MODE_PARENT,
                'parent_product_id' => $parent->id,
                'synced_at' => now()->toISOString(),
            ],
        ];

        if ($copyMetadata !== []) {
            $variantMaster['copy'] = $copyMetadata;
        }

        return $this->resolve($parent, $variantMaster);
    }

    public function synchronizeFamily(Product $parent): void
    {
        $parent->loadMissing('variantChildren');

        foreach ($parent->variantChildren as $variant) {
            if (! $this->inheritsFromParent($variant, $parent)) {
                continue;
            }

            $this->synchronizeVariant($parent, $variant);
        }

        $parent->unsetRelation('variantChildren');
    }

    public function synchronizeVariant(Product $parent, Product $variant): bool
    {
        if (! $this->inheritsFromParent($variant, $parent)) {
            return false;
        }

        $master = $this->resolve($parent, $variant->masterData());
        $attributes = (array) $variant->attributes;
        $attributes['master'] = $master;

        return $variant->forceFill([
            'name' => $this->variantName($parent, $master),
            'unit' => $parent->unit,
            'vat_rate' => $parent->vat_rate,
            'weight_kg' => $parent->weight_kg,
            'quantity_precision' => $parent->quantity_precision,
            'attributes' => $attributes,
        ])->save();
    }

    public function isCopiedFamily(Product $parent): bool
    {
        if (filled(data_get($parent->masterData(), 'copy.created_from_product_id'))) {
            return true;
        }

        return ProductRelation::query()
            ->where('parent_product_id', $parent->id)
            ->where('relation_type', 'variant')
            ->get(['metadata'])
            ->contains(fn (ProductRelation $relation): bool => filled(
                data_get($relation->metadata, 'copied_from_relation_id'),
            ) && filled(data_get($relation->metadata, 'copied_at')));
    }

    /**
     * @param  array<string, mixed>  $variantMaster
     * @return array<string, mixed>
     */
    private function resolve(Product $parent, array $variantMaster): array
    {
        $master = $parent->masterData();
        $parentVariantAttribute = trim((string) data_get($master, 'variant_attribute', ''));
        $declaredVariantAttribute = trim((string) (
            data_get($variantMaster, 'variant_attribute')
            ?: $parentVariantAttribute
            ?: 'Rozmiar'
        ));

        if ($this->legacySizeAxis->isLegacyGeneric($declaredVariantAttribute)
            && $this->variantOptions->isSizeAttribute($parentVariantAttribute)
        ) {
            $declaredVariantAttribute = $parentVariantAttribute;
        }

        // Axis recovery must consider the complete family. Recovering from a
        // single snapshot could convert only one sibling and bypass the
        // ambiguity guards used by the family migration/export path.
        $variantAttribute = $declaredVariantAttribute;
        $optionParameter = $this->optionParameter($variantMaster, $variantAttribute);

        if ($optionParameter !== null) {
            $optionWasLegacyGeneric = $this->legacySizeAxis->isLegacyGeneric(
                (string) ($optionParameter['name'] ?? ''),
            );
            $optionParameter['name'] = $variantAttribute;

            if ($optionWasLegacyGeneric
                && $this->variantOptions->isSizeAttribute($variantAttribute)
                && array_key_exists('name_en', $optionParameter)
            ) {
                $optionParameter['name_en'] = 'Size';
            }

            $optionParameter['value'] = $this->canonicalVariantOption(
                $parent,
                $variantAttribute,
                $optionParameter['value'] ?? '',
            );

            foreach (['value_en', 'value_pl'] as $localizedValue) {
                if (array_key_exists($localizedValue, $optionParameter)) {
                    $normalizationAttribute = $localizedValue === 'value_en'
                        && $this->variantOptions->isSizeAttribute($variantAttribute)
                            ? 'Size'
                            : $variantAttribute;
                    $optionParameter[$localizedValue] = $this->canonicalVariantOption(
                        $parent,
                        $variantAttribute,
                        $optionParameter[$localizedValue],
                        $normalizationAttribute,
                    );
                }
            }

            foreach ((array) data_get($optionParameter, 'translations', []) as $language => $translation) {
                if (! is_array($translation) || ! array_key_exists('value', $translation)) {
                    continue;
                }

                $normalizationAttribute = $language === 'en'
                    && $this->variantOptions->isSizeAttribute($variantAttribute)
                        ? 'Size'
                        : $variantAttribute;
                data_set(
                    $optionParameter,
                    "translations.{$language}.value",
                    $this->canonicalVariantOption(
                        $parent,
                        $variantAttribute,
                        $translation['value'],
                        $normalizationAttribute,
                    ),
                );
            }

            $optionParameter['variation'] = true;
        }
        $parentParameters = collect((array) data_get($master, 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter))
            ->reject(fn (array $parameter): bool => mb_strtolower(trim((string) ($parameter['name'] ?? ''))) === mb_strtolower($variantAttribute))
            ->map(fn (array $parameter): array => array_merge($parameter, ['variation' => false]))
            ->values();

        if ($optionParameter !== null) {
            $parentParameters->push($optionParameter);
        }

        $master['source'] = 'erp';
        $master['product_type'] = 'variation';
        $master['variant_attribute'] = $variantAttribute;
        $master['parameters'] = $parentParameters->all();
        $master['media'] = [];
        unset($master['media_updated_at']);

        foreach (['copy', 'gs1'] as $preservedKey) {
            if (array_key_exists($preservedKey, $variantMaster)) {
                $master[$preservedKey] = $variantMaster[$preservedKey];
            }
        }

        $inheritance = (array) data_get($variantMaster, 'inheritance', []);
        $master['inheritance'] = array_merge($inheritance, [
            'mode' => self::MODE_PARENT,
            'parent_product_id' => $parent->id,
            'synced_at' => now()->toISOString(),
        ]);

        foreach (['pl', 'en'] as $language) {
            $parentName = trim((string) data_get($master, "content.{$language}.name", ''));
            $option = $optionParameter === null
                ? ''
                : $this->optionForLanguage($optionParameter, $language);

            if ($parentName !== '' && $option !== '') {
                data_set($master, "content.{$language}.name", mb_substr($parentName.' - '.$option, 0, 255));
            }
        }

        return $master;
    }

    private function canonicalVariantOption(
        Product $parent,
        string $variantAttribute,
        mixed $value,
        ?string $normalizationAttribute = null,
    ): string {
        $rawValue = $this->variantOptions->normalize('', $value);
        $canonical = $this->legacySizeAxis->canonicalSizeOption(
            $parent,
            $variantAttribute,
            $rawValue,
        ) ?? $rawValue;

        return $this->variantOptions->normalize(
            $normalizationAttribute ?? $variantAttribute,
            $canonical,
        );
    }

    /**
     * @param  array<string, mixed>  $variantMaster
     * @return array<string, mixed>|null
     */
    private function optionParameter(array $variantMaster, string $variantAttribute): ?array
    {
        $parameters = collect((array) data_get($variantMaster, 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter));

        $matching = $parameters->filter(
            fn (array $parameter): bool => mb_strtolower(trim((string) ($parameter['name'] ?? ''))) === mb_strtolower($variantAttribute),
        )->values();
        $matchingVariant = $matching->first(fn (array $parameter): bool => (bool) ($parameter['variation'] ?? false)
            && ! $this->isAggregateVariantOption($parameter['value'] ?? null));

        if (is_array($matchingVariant)) {
            return $matchingVariant;
        }

        $variantParameters = $parameters
            ->filter(fn (array $parameter): bool => (bool) ($parameter['variation'] ?? false))
            ->values();

        $matchingSingleOption = $matching->first(fn (array $parameter): bool => ! $this->isAggregateVariantOption(
            $parameter['value'] ?? null,
        ));

        if (is_array($matchingSingleOption)) {
            return $matchingSingleOption;
        }

        if ($this->variantOptions->isSizeAttribute($variantAttribute)) {
            $sizeParameters = $variantParameters
                ->filter(fn (array $parameter): bool => $this->variantOptions->isSizeAttribute(
                    (string) ($parameter['name'] ?? ''),
                ) && ! $this->isAggregateVariantOption($parameter['value'] ?? null))
                ->values();

            if ($sizeParameters->count() === 1) {
                return $sizeParameters->first();
            }
        }

        if ($variantParameters->count() === 1) {
            $candidate = $variantParameters->first();

            return ! $this->isAggregateVariantOption($candidate['value'] ?? null) ? $candidate : null;
        }

        return null;
    }

    private function isAggregateVariantOption(mixed $value): bool
    {
        return preg_match('/[,;|]/u', trim((string) ($value ?? ''))) === 1;
    }

    /**
     * @param  array<string, mixed>  $master
     */
    private function variantName(Product $parent, array $master): string
    {
        $variantAttribute = trim((string) data_get($master, 'variant_attribute', ''));
        $optionParameter = $this->optionParameter($master, $variantAttribute);
        $option = $optionParameter === null ? '' : trim((string) ($optionParameter['value'] ?? ''));

        return $option === ''
            ? $parent->name
            : mb_substr($parent->name.' - '.$option, 0, 255);
    }

    /**
     * @param  array<string, mixed>  $parameter
     */
    private function optionForLanguage(array $parameter, string $language): string
    {
        if ($language === 'pl') {
            return trim((string) ($parameter['value'] ?? ''));
        }

        return trim((string) (
            $parameter["value_{$language}"]
            ?? data_get($parameter, "translations.{$language}.value")
            ?? $parameter['value']
            ?? ''
        ));
    }

    private function isLegacyCopiedVariant(Product $parent, Product $variant): bool
    {
        $relations = ProductRelation::query()
            ->where('child_product_id', $variant->id)
            ->where('relation_type', 'variant')
            ->get(['parent_product_id', 'metadata']);

        if ($relations->count() !== 1) {
            return false;
        }

        $relation = $relations->first();

        if (! $relation instanceof ProductRelation
            || (int) $relation->parent_product_id !== $parent->id
        ) {
            return false;
        }

        $relationCarriesCopyMarker = filled(data_get($relation->metadata, 'copied_from_relation_id'))
            && filled(data_get($relation->metadata, 'copied_at'));
        $productsCarryCopyMarkers = filled(data_get($parent->masterData(), 'copy.created_from_product_id'))
            && filled(data_get($variant->masterData(), 'copy.created_from_product_id'));

        return $relationCarriesCopyMarker || $productsCarryCopyMarkers;
    }

    /**
     * @param  array<string, mixed>  $master
     */
    private function boundParentId(array $master): ?int
    {
        $rawParentId = data_get($master, 'inheritance.parent_product_id');

        if (! is_int($rawParentId) && ! is_string($rawParentId)) {
            return null;
        }

        $rawParentId = trim((string) $rawParentId);

        if (preg_match('/^[1-9]\d*$/', $rawParentId) !== 1
            || (string) ((int) $rawParentId) !== $rawParentId
        ) {
            return null;
        }

        return (int) $rawParentId;
    }

    /**
     * @param  array<string, mixed>  $master
     */
    private function hasInvalidParentMarker(array $master): bool
    {
        $inheritance = data_get($master, 'inheritance');

        if (! is_array($inheritance) || ! array_key_exists('parent_product_id', $inheritance)) {
            return false;
        }

        $rawParentId = $inheritance['parent_product_id'];

        if ($rawParentId === null || (is_string($rawParentId) && trim($rawParentId) === '')) {
            return false;
        }

        return $this->boundParentId($master) === null;
    }

    private function soleVariantParentId(Product $variant): ?int
    {
        $parentIds = ProductRelation::query()
            ->where('child_product_id', $variant->id)
            ->where('relation_type', 'variant')
            ->pluck('parent_product_id');

        return $parentIds->count() === 1 ? (int) $parentIds->first() : null;
    }

    private function hasVariantRelation(Product $variant, int $parentId): bool
    {
        return ProductRelation::query()
            ->where('parent_product_id', $parentId)
            ->where('child_product_id', $variant->id)
            ->where('relation_type', 'variant')
            ->exists();
    }
}
