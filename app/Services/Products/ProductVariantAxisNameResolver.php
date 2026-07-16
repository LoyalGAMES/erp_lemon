<?php

declare(strict_types=1);

namespace App\Services\Products;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Stringable;

/**
 * Resolves names accepted by the ERP product editor to the one canonical size
 * axis. Generic legacy names are converted only when their values prove that
 * the edited family is a size family.
 */
final class ProductVariantAxisNameResolver
{
    public const SIZE = 'Rozmiar';

    /** @var list<string> */
    private const DIRECT_SIZE_ALIASES = [
        'rozmiar',
        'rozmiary',
        'size',
        'sizes',
    ];

    /** @var list<string> */
    private const GENERIC_SIZE_ALIASES = [
        'wariant',
        'variant',
        'blvariant',
        'bl-variant',
        'bl-wariant',
    ];

    public function resolve(
        string $attributeName,
        iterable $options = [],
        iterable $knownSizeOptions = [],
    ): string {
        $attributeName = trim($attributeName);

        if ($attributeName === '') {
            return '';
        }

        if ($this->isDirectSizeAlias($attributeName)) {
            return self::SIZE;
        }

        if (! $this->isGenericSizeAlias($attributeName)) {
            return $attributeName;
        }

        $options = $this->optionTokens($options);

        if ($options->isEmpty()) {
            return $attributeName;
        }

        $knownSizeOptions = $this->optionTokens($knownSizeOptions)
            ->map(fn (string $option): string => $this->optionIdentity($option))
            ->filter()
            ->flip();

        return $options->every(
            fn (string $option): bool => $this->looksLikeSizeOption($option)
                || $knownSizeOptions->has($this->optionIdentity($option)),
        ) ? self::SIZE : $attributeName;
    }

    public function isDirectSizeAlias(string $attributeName): bool
    {
        return in_array($this->attributeSlug($attributeName), self::DIRECT_SIZE_ALIASES, true);
    }

    public function isGenericSizeAlias(string $attributeName): bool
    {
        return in_array($this->attributeSlug($attributeName), self::GENERIC_SIZE_ALIASES, true);
    }

    public function isLegacyPluralSizeAlias(string $attributeName): bool
    {
        return in_array($this->attributeSlug($attributeName), ['rozmiary', 'sizes'], true);
    }

    /**
     * @return Collection<int, string>
     */
    public function optionTokens(iterable $options): Collection
    {
        return collect($options)
            ->flatMap(function (mixed $option): array {
                if (is_array($option)) {
                    return $this->optionTokens($option)->all();
                }

                if (! is_scalar($option) && ! $option instanceof Stringable) {
                    return [];
                }

                return preg_split('/(?<!\d),|,(?!\d)|[\r\n;|]+/u', (string) $option) ?: [];
            })
            ->map(fn (mixed $option): string => trim((string) $option))
            ->filter()
            ->unique(fn (string $option): string => $this->optionIdentity($option))
            ->values();
    }

    private function looksLikeSizeOption(string $option): bool
    {
        $option = trim($option);

        if (preg_match(
            '/^(?:[2-9]xl|[2-6]xs|x{1,6}[sl]|[sml])(?:\s*(?:\/|-)\s*(?:[2-9]xl|[2-6]xs|x{1,6}[sl]|[sml]))*$/iu',
            $option,
        ) === 1) {
            return true;
        }

        return in_array(
            Str::slug($option),
            ['one-size', 'onesize', 'uni', 'uniwersalny', 'uniwersalna'],
            true,
        );
    }

    private function attributeSlug(string $attributeName): string
    {
        $slug = Str::slug(trim($attributeName));

        return str_starts_with($slug, 'pa-') ? substr($slug, 3) : $slug;
    }

    private function optionIdentity(string $option): string
    {
        $option = (string) preg_replace('/\s*(\/|-)\s*/u', '$1', trim($option));
        $option = (string) preg_replace('/\s+/u', ' ', $option);

        return mb_strtolower($option, 'UTF-8');
    }
}
