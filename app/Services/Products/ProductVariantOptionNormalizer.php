<?php

declare(strict_types=1);

namespace App\Services\Products;

use Illuminate\Support\Str;
use Stringable;

final class ProductVariantOptionNormalizer
{
    /** @var list<string> */
    private const SIZE_ATTRIBUTE_SLUGS = [
        'rozmiar',
        'rozmiary',
        'size',
        'sizes',
    ];

    private const SIZE_TOKEN_PATTERN = '(?:[2-9]xl|x{1,6}[sl]|[sml])';

    public function normalize(string $attributeName, mixed $value): string
    {
        $source = $this->stringValue($value);

        if (! $this->isSizeAttribute($attributeName)) {
            return $source;
        }

        $candidate = trim($source);

        if ($candidate === '' || preg_match(
            '/^'.self::SIZE_TOKEN_PATTERN.'(?:\s*(?:\/|-)\s*'.self::SIZE_TOKEN_PATTERN.')*$/iu',
            $candidate,
        ) !== 1) {
            return $source;
        }

        $candidate = (string) preg_replace('/\s*([\/-])\s*/u', '$1', $candidate);

        return mb_strtoupper($candidate, 'UTF-8');
    }

    public function identity(string $attributeName, mixed $value): string
    {
        $normalized = $this->normalize($attributeName, $value);
        $normalized = (string) preg_replace('/\s+/u', ' ', trim($normalized));

        return mb_strtolower($normalized, 'UTF-8');
    }

    public function isSizeAttribute(string $attributeName): bool
    {
        $slug = Str::slug(trim($attributeName));

        if (str_starts_with($slug, 'pa-')) {
            $slug = substr($slug, 3);
        }

        return in_array($slug, self::SIZE_ATTRIBUTE_SLUGS, true);
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        return '';
    }
}
