<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Products\ProductVariantOptionNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductVariantOptionNormalizerTest extends TestCase
{
    private ProductVariantOptionNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new ProductVariantOptionNormalizer;
    }

    /**
     * @return iterable<string, array{string,string,string}>
     */
    public static function standardSizeProvider(): iterable
    {
        yield 'polish singular' => ['Rozmiar', 's', 'S'];
        yield 'polish plural' => ['Rozmiary', 'xs', 'XS'];
        yield 'english singular' => ['Size', 'xxl', 'XXL'];
        yield 'english plural' => ['Sizes', '2xl', '2XL'];
        yield 'attribute name ignores case and whitespace' => ['  rOzMiAr  ', 'm', 'M'];
        yield 'woocommerce polish taxonomy slug' => ['pa_rozmiar', 'xl', 'XL'];
        yield 'woocommerce english taxonomy slug' => ['PA-SIZE', 'l', 'L'];
        yield 'slash combination' => ['Rozmiar', 's / m', 'S/M'];
        yield 'hyphen combination' => ['Size', 'xs - s', 'XS-S'];
        yield 'multiple combination values' => ['Sizes', 'm/l / xl', 'M/L/XL'];
        yield 'numeric x-size combination' => ['Rozmiary', 'xl-2xl', 'XL-2XL'];
    }

    #[DataProvider('standardSizeProvider')]
    public function test_it_canonicalizes_standard_apparel_sizes(
        string $attributeName,
        string $value,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->normalizer->normalize($attributeName, $value));
    }

    /**
     * @return iterable<string, array{string,string}>
     */
    public static function arbitrarySizeValueProvider(): iterable
    {
        yield 'numeric clothing size' => ['38', '38'];
        yield 'decimal number' => ['38.5', '38.5'];
        yield 'arbitrary label' => ['medium', 'medium'];
        yield 'one size label' => ['One size', 'One size'];
        yield 'pipe-delimited value' => ['s | m', 's | m'];
        yield 'unknown token' => ['abc', 'abc'];
        yield 'unknown value preserves whitespace' => [' medium ', ' medium '];
    }

    #[DataProvider('arbitrarySizeValueProvider')]
    public function test_it_does_not_change_arbitrary_or_numeric_values(string $value, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize('Rozmiar', $value));
    }

    public function test_it_does_not_change_values_of_non_size_attributes(): void
    {
        $this->assertSame('m', $this->normalizer->normalize('Kolor', 'm'));
        $this->assertSame('s / m', $this->normalizer->normalize('System', 's / m'));
        $this->assertSame(' m ', $this->normalizer->normalize('Rozmiar produktu', ' m '));
    }

    public function test_it_provides_case_insensitive_identity_without_mutating_the_source_value(): void
    {
        $this->assertSame(
            $this->normalizer->identity('Rozmiar', 's'),
            $this->normalizer->identity('Rozmiar', ' S '),
        );
        $this->assertSame(
            $this->normalizer->identity('Kolor', 'Czarny'),
            $this->normalizer->identity('Kolor', ' czarny '),
        );
        $this->assertSame('Czarny', $this->normalizer->normalize('Kolor', 'Czarny'));
    }
}
