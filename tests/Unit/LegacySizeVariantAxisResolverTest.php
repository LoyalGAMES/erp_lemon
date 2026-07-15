<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Products\LegacySizeVariantAxisResolver;
use App\Services\Products\ProductVariantOptionNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacySizeVariantAxisResolverTest extends TestCase
{
    private LegacySizeVariantAxisResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new LegacySizeVariantAxisResolver(
            new ProductVariantOptionNormalizer,
        );
    }

    public function test_it_recovers_size_from_matching_legacy_and_concrete_child_axes(): void
    {
        $parent = $this->product([
            'variant_attribute' => 'BLVariant',
        ]);

        $result = $this->resolver->recoverFromMasters($parent, [
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 's-m', 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => 'S/M', 'variation' => false],
                ],
            ],
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 'm-l', 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => 'M/L', 'variation' => false],
                ],
            ],
        ]);

        $this->assertSame('Rozmiar', $result);
    }

    public function test_it_recovers_size_from_parent_aggregate_when_children_only_have_legacy_axis(): void
    {
        $parent = $this->product([
            'variant_attribute' => 'BLVariant',
            'parameters' => [
                ['name' => 'Rozmiar', 'value' => 'S/M | M/L', 'variation' => false],
            ],
        ]);

        $result = $this->resolver->recoverFromMasters($parent, [
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 's-m', 'variation' => true],
                ],
            ],
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 'm-l', 'variation' => true],
                ],
            ],
        ]);

        $this->assertSame('Rozmiar', $result);
    }

    public function test_it_does_not_replace_a_real_color_axis_with_informational_size(): void
    {
        $parent = $this->product([
            'variant_attribute' => 'Color',
            'parameters' => [
                ['name' => 'Rozmiar', 'value' => 'ONE SIZE', 'variation' => false],
            ],
        ]);

        $result = $this->resolver->recoverFromMasters($parent, [
            [
                'parameters' => [
                    ['name' => 'Color', 'value' => 'Black', 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => 'ONE SIZE', 'variation' => false],
                ],
            ],
            [
                'parameters' => [
                    ['name' => 'Color', 'value' => 'Blue', 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => 'ONE SIZE', 'variation' => false],
                ],
            ],
        ]);

        $this->assertNull($result);
    }

    public function test_it_rejects_legacy_size_recovery_when_color_is_also_a_variation_axis(): void
    {
        $parent = $this->product([
            'variant_attribute' => 'BLVariant',
            'parameters' => [
                ['name' => 'Rozmiar', 'value' => 'S/M | M/L', 'variation' => false],
            ],
        ]);

        $result = $this->resolver->recoverFromMasters($parent, [
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 's-m', 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => 'S/M', 'variation' => false],
                    ['name' => 'Color', 'value' => 'Black', 'variation' => true],
                ],
            ],
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 'm-l', 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => 'M/L', 'variation' => false],
                    ['name' => 'Color', 'value' => 'White', 'variation' => true],
                ],
            ],
        ]);

        $this->assertNull($result);
    }

    public function test_it_rejects_legacy_size_recovery_when_parent_declares_another_variation_axis(): void
    {
        $parent = $this->product([
            'variant_attribute' => 'BLVariant',
            'parameters' => [
                ['name' => 'BLVariant', 'value' => 's-m | m-l', 'variation' => true],
                ['name' => 'Rozmiar', 'value' => 'S/M | M/L', 'variation' => false],
                ['name' => 'Color', 'value' => 'Black | White', 'variation' => true],
            ],
        ]);

        $result = $this->resolver->recoverFromMasters($parent, [
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 's-m', 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => 'S/M', 'variation' => false],
                    ['name' => 'Color', 'value' => 'Black', 'variation' => false],
                ],
            ],
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 'm-l', 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => 'M/L', 'variation' => false],
                    ['name' => 'Color', 'value' => 'White', 'variation' => false],
                ],
            ],
        ]);

        $this->assertNull($result);
    }

    public function test_it_rejects_parent_only_color_axis_when_size_exists_only_as_parent_aggregate(): void
    {
        $parent = $this->product([
            'variant_attribute' => 'BLVariant',
            'parameters' => [
                ['name' => 'BLVariant', 'value' => 's-m | m-l', 'variation' => true],
                ['name' => 'Rozmiar', 'value' => 'S/M | M/L', 'variation' => false],
                ['name' => 'Color', 'value' => 'Black | White', 'variation' => true],
            ],
        ]);

        $result = $this->resolver->recoverFromMasters($parent, [
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 's-m', 'variation' => true],
                ],
            ],
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 'm-l', 'variation' => true],
                ],
            ],
        ]);

        $this->assertNull($result);
    }

    public function test_it_rejects_duplicate_child_options(): void
    {
        $parent = $this->product([
            'variant_attribute' => 'BLVariant',
            'parameters' => [
                ['name' => 'Rozmiar', 'value' => 'S/M | M/L', 'variation' => false],
            ],
        ]);

        $result = $this->resolver->recoverFromMasters($parent, [
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 's-m', 'variation' => true],
                ],
            ],
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 's-m', 'variation' => true],
                ],
            ],
        ]);

        $this->assertNull($result);
    }

    public function test_it_rejects_more_than_one_matching_size_axis(): void
    {
        $parent = $this->product([
            'variant_attribute' => 'BLVariant',
            'parameters' => [
                ['name' => 'Rozmiar', 'value' => 'S/M | M/L', 'variation' => false],
                ['name' => 'Size', 'value' => 'S/M | M/L', 'variation' => false],
            ],
        ]);

        $result = $this->resolver->recoverFromMasters($parent, [
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 's-m', 'variation' => true],
                ],
            ],
            [
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 'm-l', 'variation' => true],
                ],
            ],
        ]);

        $this->assertNull($result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function legacyGenericAttributeProvider(): iterable
    {
        yield 'Polish wariant' => ['wariant'];
        yield 'English variant' => ['variant'];
        yield 'BaseLinker BLVariant' => ['BLVariant'];
    }

    #[DataProvider('legacyGenericAttributeProvider')]
    public function test_it_recognizes_legacy_generic_axis_names(string $attribute): void
    {
        $this->assertTrue($this->resolver->isLegacyGeneric($attribute));
    }

    /** @param array<string, mixed> $master */
    private function product(array $master): Product
    {
        return new Product([
            'attributes' => ['master' => $master],
        ]);
    }
}
