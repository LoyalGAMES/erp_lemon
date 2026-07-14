<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\ProductParameterDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ProductParameterTranslationService
{
    /** @var Collection<int, ProductParameterDefinition>|null */
    private ?Collection $definitions = null;

    public function __construct(
        private readonly ProductVariantOptionNormalizer $variantOptions,
    ) {}

    /**
     * Synchronize the shared parameter dictionary from one canonical Woo item
     * and its explicitly verified Polylang translations.
     *
     * @param  array<string, mixed>  $item
     * @return array{localized:int,merged:int}
     */
    public function syncFromWooItem(array $item): array
    {
        $primary = $this->attributes($item);
        $englishItem = collect((array) ($item['erp_translations'] ?? []))
            ->first(fn (mixed $translation, mixed $language): bool => is_array($translation)
                && mb_strtolower(trim((string) $language)) === 'en');
        $english = is_array($englishItem) ? $this->attributes($englishItem) : [];
        $localized = 0;
        $merged = 0;

        foreach ($primary as $index => $attribute) {
            $englishAttribute = $this->matchingAttribute($attribute, $english, $index, count($primary));
            $result = $this->syncDefinition($attribute, $englishAttribute);
            $localized += $result['localized'];
            $merged += $result['merged'];
        }

        return ['localized' => $localized, 'merged' => $merged];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array{id:?string,name:string,values:list<string>,variation:bool}>
     */
    private function attributes(array $item): array
    {
        return collect((array) ($item['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(function (array $attribute): ?array {
                $name = trim((string) ($attribute['name'] ?? ''));

                if ($name === '') {
                    return null;
                }

                $values = isset($attribute['options']) && is_array($attribute['options'])
                    ? $attribute['options']
                    : [$attribute['option'] ?? null];

                return [
                    'id' => filled($attribute['id'] ?? null) && (string) $attribute['id'] !== '0'
                        ? trim((string) $attribute['id'])
                        : null,
                    'name' => $name,
                    'values' => collect($values)
                        ->map(fn (mixed $value): string => $this->variantOptions->normalize(
                            $name,
                            trim((string) ($value ?? '')),
                        ))
                        ->filter()
                        ->values()
                        ->all(),
                    'variation' => (bool) ($attribute['variation'] ?? array_key_exists('option', $attribute)),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array{id:?string,name:string,values:list<string>,variation:bool}  $primary
     * @param  list<array{id:?string,name:string,values:list<string>,variation:bool}>  $translated
     * @return array{id:?string,name:string,values:list<string>,variation:bool}|null
     */
    private function matchingAttribute(
        array $primary,
        array $translated,
        int $primaryIndex,
        int $primaryCount,
    ): ?array {
        if ($primary['id'] !== null) {
            $byId = collect($translated)->first(
                fn (array $candidate): bool => $candidate['id'] !== null && $candidate['id'] === $primary['id'],
            );

            if (is_array($byId)) {
                return $byId;
            }
        }

        $byName = collect($translated)->first(
            fn (array $candidate): bool => mb_strtolower($candidate['name']) === mb_strtolower($primary['name']),
        );

        if (is_array($byName)) {
            return $byName;
        }

        // The product translation family is verified before it reaches this
        // service. Woo keeps custom attributes in the same order, so position
        // is a safe final join only when both lists have identical cardinality.
        if ($primaryCount === count($translated) && isset($translated[$primaryIndex])) {
            return $translated[$primaryIndex];
        }

        return null;
    }

    /**
     * @param  array{id:?string,name:string,values:list<string>,variation:bool}  $primary
     * @param  array{id:?string,name:string,values:list<string>,variation:bool}|null  $english
     * @return array{localized:int,merged:int}
     */
    private function syncDefinition(array $primary, ?array $english): array
    {
        $definition = $this->findDefinition($primary['name']);
        $englishDefinition = $english !== null ? $this->findDefinition($english['name']) : null;
        $merged = 0;

        if (! $definition instanceof ProductParameterDefinition && $englishDefinition instanceof ProductParameterDefinition) {
            $definition = $englishDefinition;
            $englishDefinition = null;
            $definition->forceFill([
                'name' => $primary['name'],
                'name_en' => $english['name'] ?? $definition->name,
                // The legacy row contained English values in the PL column.
                // Rebuild both aligned lists from the verified product pair.
                'values' => [],
                'values_en' => null,
            ]);
        }

        if (! $definition instanceof ProductParameterDefinition) {
            $definition = new ProductParameterDefinition([
                'name' => $primary['name'],
                'slug' => $this->uniqueSlug(Str::slug($primary['name']) ?: Str::random(8)),
                'input_type' => 'select',
                'values' => [],
                'values_en' => null,
                'is_variant' => false,
                'is_required' => false,
                'sort_order' => 100,
                'metadata' => [],
            ]);
        }

        if ($englishDefinition instanceof ProductParameterDefinition && ! $englishDefinition->is($definition)) {
            $definition->is_variant = (bool) $definition->is_variant || (bool) $englishDefinition->is_variant;
            $definition->is_required = (bool) $definition->is_required || (bool) $englishDefinition->is_required;
            $definition->sort_order = min((int) ($definition->sort_order ?: 100), (int) ($englishDefinition->sort_order ?: 100));
            $definition->metadata = array_replace_recursive(
                (array) $englishDefinition->metadata,
                (array) $definition->metadata,
            );
            $englishDefinition->delete();
            $this->forgetDefinition($englishDefinition);
            $merged++;
        }

        $beforeName = trim((string) ($definition->name_en ?? ''));
        $beforeValues = (array) $definition->values_en;
        $englishAttributeName = trim((string) ($english['name'] ?? $definition->name_en ?? ''));
        $rawValuesEn = array_values((array) $definition->values_en);
        $values = [];
        $valuesEn = [];
        $valueIndexes = [];

        foreach (array_values((array) $definition->values) as $index => $rawValue) {
            $value = $this->variantOptions->normalize($primary['name'], trim((string) $rawValue));

            if ($value === '') {
                continue;
            }

            $valueEn = $this->variantOptions->normalize(
                $englishAttributeName !== '' ? $englishAttributeName : $primary['name'],
                trim((string) ($rawValuesEn[$index] ?? '')),
            );
            $identity = $this->variantOptions->identity($primary['name'], $value);

            if (array_key_exists($identity, $valueIndexes)) {
                $existingIndex = $valueIndexes[$identity];

                if ($valuesEn[$existingIndex] === '' && $valueEn !== '') {
                    $valuesEn[$existingIndex] = $valueEn;
                }

                continue;
            }

            $valueIndexes[$identity] = count($values);
            $values[] = $value;
            $valuesEn[] = $valueEn;
        }

        $englishValues = $english['values'] ?? [];

        foreach ($primary['values'] as $index => $polishValue) {
            $valueIndex = collect($values)->search(
                fn (string $candidate): bool => mb_strtolower($candidate) === mb_strtolower($polishValue),
            );

            if ($valueIndex === false) {
                $values[] = $polishValue;
                $valuesEn[] = trim((string) ($englishValues[$index] ?? ''));

                continue;
            }

            $valueIndex = (int) $valueIndex;
            $translatedValue = trim((string) ($englishValues[$index] ?? ''));

            if ($translatedValue !== '' && trim((string) ($valuesEn[$valueIndex] ?? '')) === '') {
                $valuesEn[$valueIndex] = $translatedValue;
            }
        }

        $englishName = trim((string) ($english['name'] ?? ''));

        $definition->fill([
            'name' => $primary['name'],
            'name_en' => $englishName !== '' ? $englishName : $definition->name_en,
            'slug' => $definition->slug ?: $this->uniqueSlug(Str::slug($primary['name']) ?: Str::random(8), $definition),
            'input_type' => $definition->input_type ?: 'select',
            'values' => $values,
            'values_en' => collect($valuesEn)->contains(fn (mixed $value): bool => trim((string) $value) !== '')
                ? $valuesEn
                : null,
            'is_variant' => (bool) $definition->is_variant || $primary['variation'],
            'is_required' => (bool) ($definition->is_required ?? false),
            'sort_order' => (int) ($definition->sort_order ?: 100),
            'metadata' => array_replace_recursive((array) $definition->metadata, [
                'source' => 'woocommerce_import',
                'translations' => [
                    'en' => [
                        'source' => $english !== null ? 'polylang_product_pair' : 'fallback_pl',
                    ],
                ],
                'synced_at' => now()->toISOString(),
            ]),
        ]);
        $definition->save();
        $this->rememberDefinition($definition);

        $localized = ($beforeName === '' && filled($definition->name_en))
            || $beforeValues !== (array) $definition->values_en
            ? 1
            : 0;

        return ['localized' => $localized, 'merged' => $merged];
    }

    private function findDefinition(string $name): ?ProductParameterDefinition
    {
        $name = mb_strtolower(trim($name));

        if ($name === '') {
            return null;
        }

        return $this->allDefinitions()->first(function (ProductParameterDefinition $definition) use ($name): bool {
            return collect([$definition->name, $definition->name_en])
                ->filter(fn (mixed $candidate): bool => filled($candidate))
                ->contains(fn (mixed $candidate): bool => mb_strtolower(trim((string) $candidate)) === $name);
        });
    }

    /** @return Collection<int, ProductParameterDefinition> */
    private function allDefinitions(): Collection
    {
        return $this->definitions ??= ProductParameterDefinition::query()->orderBy('id')->get()->keyBy('id');
    }

    private function rememberDefinition(ProductParameterDefinition $definition): void
    {
        $this->allDefinitions()->put($definition->id, $definition);
    }

    private function forgetDefinition(ProductParameterDefinition $definition): void
    {
        $this->allDefinitions()->forget($definition->id);
    }

    private function uniqueSlug(string $slug, ?ProductParameterDefinition $ignore = null): string
    {
        $base = $slug;
        $candidate = $base;
        $suffix = 2;

        while (ProductParameterDefinition::query()
            ->where('slug', $candidate)
            ->when($ignore !== null && $ignore->exists, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists()
        ) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
