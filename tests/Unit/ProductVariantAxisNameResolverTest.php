<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Products\ProductVariantAxisNameResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductVariantAxisNameResolverTest extends TestCase
{
    private ProductVariantAxisNameResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new ProductVariantAxisNameResolver;
    }

    /**
     * @return iterable<string, array{string,list<string>}>
     */
    public static function sizeAliasProvider(): iterable
    {
        yield 'canonical singular' => ['Rozmiar', []];
        yield 'legacy plural' => ['Rozmiary', []];
        yield 'English singular' => ['Size', []];
        yield 'English plural' => ['Sizes', []];
        yield 'Woo taxonomy English plural' => ['pa_sizes', []];
        yield 'generic Polish with size evidence' => ['wariant', ['XS', 'S/M']];
        yield 'generic English with size evidence' => ['Variant', ['XS', 'S/M']];
        yield 'BaseLinker with size evidence' => ['BLVariant', ['M/L', 'XL']];
        yield 'hyphenated BaseLinker with size evidence' => ['BL-Variant', ['M/L', 'XL']];
        yield 'hyphenated Polish BaseLinker with size evidence' => ['BL-Wariant', ['M/L', 'XL']];
    }

    #[DataProvider('sizeAliasProvider')]
    public function test_it_resolves_all_erp_size_inputs_to_one_name(string $name, array $options): void
    {
        $this->assertSame('Rozmiar', $this->resolver->resolve($name, $options));
    }

    public function test_it_keeps_generic_color_axes_unchanged(): void
    {
        $this->assertSame(
            'BLVariant',
            $this->resolver->resolve('BLVariant', ['Czarny', 'Biały']),
        );
        $this->assertSame(
            'wariant',
            $this->resolver->resolve('wariant', []),
        );
    }

    public function test_known_size_dictionary_is_required_for_numeric_generic_options(): void
    {
        $this->assertSame('BLVariant', $this->resolver->resolve('BLVariant', ['36', '38']));
        $this->assertSame(
            'Rozmiar',
            $this->resolver->resolve('BLVariant', ['36', '38'], ['34', '36', '38', '40']),
        );
    }

    public function test_decimal_comma_size_is_kept_as_one_token_and_requires_dictionary_evidence(): void
    {
        $this->assertSame(['38,5'], $this->resolver->optionTokens(['38,5'])->all());
        $this->assertSame(['38,5', '40'], $this->resolver->optionTokens(['38,5, 40'])->all());
        $this->assertSame(['XS', 'S/M'], $this->resolver->optionTokens(['XS,S/M'])->all());
        $this->assertSame('BLVariant', $this->resolver->resolve('BLVariant', ['38,5']));
        $this->assertSame(
            'Rozmiar',
            $this->resolver->resolve('BLVariant', ['38,5'], ['36', '38,5', '40']),
        );
    }
}
