<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class LegacySizeVariantAxisResolver
{
    public function __construct(
        private readonly ProductVariantOptionNormalizer $variantOptions,
    ) {}

    /**
     * Recover the concrete size axis from an old Woo family that declared a
     * generic `wariant`/`BLVariant` axis. A recovery is allowed only when the
     * size options and child options identify the same unique family.
     *
     * @param  iterable<int, Product>  $variants
     */
    public function recover(Product $parent, iterable $variants): ?string
    {
        return $this->recoverFromMasters(
            $parent,
            collect($variants)->map(fn (Product $variant): array => $variant->masterData()),
        );
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $variantMasters
     */
    public function recoverFromMasters(
        Product $parent,
        iterable $variantMasters,
        ?string $declaredAttribute = null,
    ): ?string {
        $declaredAttribute = trim((string) (
            $declaredAttribute
            ?? data_get($parent->masterData(), 'variant_attribute', '')
        ));

        if (! $this->isLegacyGeneric($declaredAttribute)) {
            return null;
        }

        $masters = collect($variantMasters)
            ->filter(fn (mixed $master): bool => is_array($master))
            ->values();

        if ($masters->isEmpty()) {
            return null;
        }

        $childSizeAxis = $this->commonConcreteChildSizeAxis($masters, $declaredAttribute);

        if ($childSizeAxis !== null) {
            if ($this->hasOtherConcreteParentVariationAxis(
                $parent,
                $declaredAttribute,
                $childSizeAxis,
            ) || $this->hasOtherConcreteVariationAxis(
                $masters,
                $declaredAttribute,
                $childSizeAxis,
            )) {
                return null;
            }

            return $childSizeAxis;
        }

        if ($this->hasOtherConcreteVariationAxis($masters, $declaredAttribute)) {
            return null;
        }

        $childOptions = $masters
            ->map(fn (array $master): ?string => $this->concreteChildOption(
                $master,
                $declaredAttribute,
            ));

        if ($childOptions->contains(null)
            || $childOptions->contains(fn (?string $option): bool => trim((string) $option) === '')
        ) {
            return null;
        }

        $childSlugs = $childOptions
            ->map(fn (?string $option): string => $this->optionSlug((string) $option))
            ->filter()
            ->values();

        if ($childSlugs->count() !== $masters->count()
            || $childSlugs->unique()->count() !== $childSlugs->count()
        ) {
            return null;
        }

        $candidateNames = collect((array) data_get($parent->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter)
                && $this->variantOptions->isSizeAttribute((string) ($parameter['name'] ?? '')))
            ->filter(function (array $parameter) use ($childSlugs): bool {
                $parentSlugs = $this->aggregateOptions($parameter['value'] ?? null)
                    ->map(fn (string $option): string => $this->optionSlug($option))
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values();

                return $parentSlugs->isNotEmpty()
                    && $parentSlugs->all() === $childSlugs->unique()->sort()->values()->all();
            })
            ->map(fn (array $parameter): string => trim((string) ($parameter['name'] ?? '')))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values();

        if ($candidateNames->count() !== 1) {
            return null;
        }

        $candidateName = $candidateNames->first();

        return $this->hasOtherConcreteParentVariationAxis(
            $parent,
            $declaredAttribute,
            $candidateName,
        ) ? null : $candidateName;
    }

    public function isLegacyGeneric(string $attribute): bool
    {
        $slug = Str::slug(trim($attribute));

        if (str_starts_with($slug, 'pa-')) {
            $slug = substr($slug, 3);
        }

        return in_array($slug, [
            'wariant',
            'variant',
            'blvariant',
            'bl-variant',
            'bl-wariant',
        ], true);
    }

    /**
     * Return the canonical parent spelling for a recovered size option. This
     * turns legacy slug-shaped values such as `s-m` back into `S/M` without
     * guessing when the parent contains no unique matching option.
     */
    public function canonicalSizeOption(
        Product $parent,
        string $attribute,
        string $option,
    ): ?string {
        if (! $this->variantOptions->isSizeAttribute($attribute)) {
            return null;
        }

        $optionSlug = $this->optionSlug($option);

        if ($optionSlug === '') {
            return null;
        }

        $matches = collect((array) data_get($parent->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter)
                && mb_strtolower(trim((string) ($parameter['name'] ?? '')))
                    === mb_strtolower(trim($attribute)))
            ->flatMap(fn (array $parameter): Collection => $this->aggregateOptions(
                $parameter['value'] ?? null,
            ))
            ->filter(fn (string $candidate): bool => $this->optionSlug($candidate) === $optionSlug)
            ->unique(fn (string $candidate): string => $this->optionSlug($candidate))
            ->values();

        return $matches->count() === 1
            ? $this->variantOptions->normalize($attribute, $matches->first())
            : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $masters
     */
    private function commonConcreteChildSizeAxis(
        Collection $masters,
        string $declaredAttribute,
    ): ?string {
        $rows = $masters->map(function (array $master): Collection {
            return collect((array) data_get($master, 'parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter)
                    && $this->variantOptions->isSizeAttribute((string) ($parameter['name'] ?? ''))
                    && $this->isConcrete($parameter['value'] ?? null))
                ->values();
        });
        $names = $rows
            ->flatMap(fn (Collection $parameters): Collection => $parameters->pluck('name'))
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values();
        $matches = $names->filter(function (string $name) use (
            $rows,
            $masters,
            $declaredAttribute,
        ): bool {
            $values = $rows->map(function (Collection $parameters) use ($name): ?string {
                $matches = $parameters
                    ->filter(fn (array $parameter): bool => mb_strtolower(trim((string) ($parameter['name'] ?? '')))
                        === mb_strtolower($name))
                    ->values();

                return $matches->count() === 1
                    ? trim((string) ($matches->first()['value'] ?? ''))
                    : null;
            });

            if ($values->contains(null)
                || $values->unique(fn (?string $value): string => $this->optionSlug((string) $value))->count()
                    !== $values->count()
            ) {
                return false;
            }

            $declaredValues = $masters->map(function (array $master) use ($declaredAttribute): ?string {
                $matches = collect((array) data_get($master, 'parameters', []))
                    ->filter(fn (mixed $parameter): bool => is_array($parameter)
                        && mb_strtolower(trim((string) ($parameter['name'] ?? '')))
                            === mb_strtolower($declaredAttribute)
                        && $this->isConcrete($parameter['value'] ?? null))
                    ->values();

                return $matches->count() === 1
                    ? trim((string) ($matches->first()['value'] ?? ''))
                    : null;
            });

            if (! $declaredValues->contains(null)) {
                return $values->map(fn (?string $value): string => $this->optionSlug((string) $value))->all()
                    === $declaredValues
                        ->map(fn (?string $value): string => $this->optionSlug((string) $value))
                        ->all();
            }

            // When the generic parameter is absent from every child, a size
            // axis is safe only if it is their sole concrete variation axis.
            if ($declaredValues->filter()->isNotEmpty()) {
                return false;
            }

            return $masters->every(function (array $master) use ($name): bool {
                $variationParameters = collect((array) data_get($master, 'parameters', []))
                    ->filter(fn (mixed $parameter): bool => is_array($parameter)
                        && (bool) ($parameter['variation'] ?? false)
                        && $this->isConcrete($parameter['value'] ?? null))
                    ->values();

                return $variationParameters->count() === 1
                    && mb_strtolower(trim((string) ($variationParameters->first()['name'] ?? '')))
                        === mb_strtolower($name);
            });
        })->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $masters
     */
    private function hasOtherConcreteVariationAxis(
        Collection $masters,
        string $declaredAttribute,
        ?string $allowedSizeAxis = null,
    ): bool {
        $declaredAttribute = mb_strtolower(trim($declaredAttribute));
        $allowedSizeAxis = $allowedSizeAxis === null
            ? null
            : mb_strtolower(trim($allowedSizeAxis));

        return $masters->contains(function (array $master) use (
            $declaredAttribute,
            $allowedSizeAxis,
        ): bool {
            return collect((array) data_get($master, 'parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter)
                    && (bool) ($parameter['variation'] ?? false)
                    && $this->isConcrete($parameter['value'] ?? null))
                ->contains(function (array $parameter) use (
                    $declaredAttribute,
                    $allowedSizeAxis,
                ): bool {
                    $name = trim((string) ($parameter['name'] ?? ''));
                    $normalizedName = mb_strtolower($name);

                    if ($normalizedName === $declaredAttribute
                        || $this->isLegacyGeneric($name)
                    ) {
                        return false;
                    }

                    return $allowedSizeAxis === null
                        || $normalizedName !== $allowedSizeAxis;
                });
        });
    }

    private function hasOtherConcreteParentVariationAxis(
        Product $parent,
        string $declaredAttribute,
        string $allowedSizeAxis,
    ): bool {
        $declaredAttribute = mb_strtolower(trim($declaredAttribute));
        $allowedSizeAxis = mb_strtolower(trim($allowedSizeAxis));

        return collect((array) data_get($parent->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter)
                && (bool) ($parameter['variation'] ?? false)
                && trim((string) ($parameter['value'] ?? '')) !== '')
            ->contains(function (array $parameter) use (
                $declaredAttribute,
                $allowedSizeAxis,
            ): bool {
                $name = trim((string) ($parameter['name'] ?? ''));
                $normalizedName = mb_strtolower($name);

                if ($normalizedName === $declaredAttribute
                    || $this->isLegacyGeneric($name)
                ) {
                    return false;
                }

                return $normalizedName !== $allowedSizeAxis;
            });
    }

    /** @param array<string, mixed> $master */
    private function concreteChildOption(array $master, string $declaredAttribute): ?string
    {
        $parameters = collect((array) data_get($master, 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter)
                && $this->isConcrete($parameter['value'] ?? null))
            ->values();
        $declared = $parameters
            ->filter(fn (array $parameter): bool => mb_strtolower(trim((string) ($parameter['name'] ?? '')))
                === mb_strtolower($declaredAttribute))
            ->values();

        if ($declared->count() === 1) {
            return trim((string) ($declared->first()['value'] ?? ''));
        }

        $variantParameters = $parameters
            ->filter(fn (array $parameter): bool => (bool) ($parameter['variation'] ?? false))
            ->values();

        return $variantParameters->count() === 1
            ? trim((string) ($variantParameters->first()['value'] ?? ''))
            : null;
    }

    private function isConcrete(mixed $value): bool
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' && preg_match('/[,;|]/u', $value) !== 1;
    }

    /** @return Collection<int, string> */
    private function aggregateOptions(mixed $value): Collection
    {
        return collect(preg_split('/\s*[,;|]\s*/u', trim((string) ($value ?? ''))) ?: [])
            ->map(fn (mixed $option): string => trim((string) $option))
            ->filter()
            ->values();
    }

    private function optionSlug(string $value): string
    {
        // Woo/WordPress stores both `S/M` and the historical `s-m` under the
        // same size-term slug. Laravel drops a slash during slugification, so
        // make the size separator explicit before comparing the two forms.
        $value = (string) preg_replace('/\s*[\/]\s*/u', '-', trim($value));

        return Str::slug($value);
    }
}
