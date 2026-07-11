<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProductParameterDefinition;
use App\Services\Products\ProductParameterTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductParameterTranslationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_localizes_and_merges_legacy_english_parameter_definitions_idempotently(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Kolor',
            'slug' => 'kolor',
            'input_type' => 'select',
            'values' => ['Beżowy'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 10,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Color',
            'slug' => 'color',
            'input_type' => 'select',
            'values' => ['Beige'],
            'is_variant' => false,
            'is_required' => true,
            'sort_order' => 20,
        ]);

        $item = [
            'attributes' => [[
                'id' => 7,
                'name' => 'Kolor',
                'options' => ['Beżowy', 'Czerwony'],
                'variation' => true,
            ]],
            'erp_translations' => [
                'en' => [
                    'attributes' => [[
                        'id' => 7,
                        'name' => 'Color',
                        'options' => ['Beige', 'Red'],
                        'variation' => true,
                    ]],
                ],
            ],
        ];

        $service = app(ProductParameterTranslationService::class);
        $first = $service->syncFromWooItem($item);
        $second = $service->syncFromWooItem($item);

        $this->assertSame(['localized' => 1, 'merged' => 1], $first);
        $this->assertSame(['localized' => 0, 'merged' => 0], $second);
        $this->assertDatabaseCount('product_parameter_definitions', 1);

        $definition = ProductParameterDefinition::query()->sole();
        $this->assertSame('Kolor', $definition->name);
        $this->assertSame('Color', $definition->name_en);
        $this->assertSame(['Beżowy', 'Czerwony'], $definition->values);
        $this->assertSame(['Beige', 'Red'], $definition->values_en);
        $this->assertTrue($definition->is_variant);
        $this->assertTrue($definition->is_required);
        $this->assertSame(10, $definition->sort_order);
    }

    public function test_it_reclassifies_an_english_only_legacy_definition_from_a_verified_pair(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Material',
            'slug' => 'material',
            'input_type' => 'select',
            'values' => ['Cotton'],
            'is_variant' => false,
            'is_required' => false,
            'sort_order' => 100,
        ]);

        app(ProductParameterTranslationService::class)->syncFromWooItem([
            'attributes' => [[
                'name' => 'Materiał',
                'options' => ['Bawełna'],
            ]],
            'erp_translations' => [
                'en' => [
                    'attributes' => [[
                        'name' => 'Material',
                        'options' => ['Cotton'],
                    ]],
                ],
            ],
        ]);

        $definition = ProductParameterDefinition::query()->sole();
        $this->assertSame('Materiał', $definition->name);
        $this->assertSame('Material', $definition->name_en);
        $this->assertSame(['Bawełna'], $definition->values);
        $this->assertSame(['Cotton'], $definition->values_en);
    }

    public function test_it_preserves_a_polish_only_definition_when_no_translation_exists(): void
    {
        $result = app(ProductParameterTranslationService::class)->syncFromWooItem([
            'attributes' => [[
                'name' => 'Producent',
                'options' => ['Polska'],
            ]],
        ]);

        $definition = ProductParameterDefinition::query()->sole();
        $this->assertSame(['localized' => 0, 'merged' => 0], $result);
        $this->assertSame('Producent', $definition->name);
        $this->assertNull($definition->name_en);
        $this->assertSame(['Polska'], $definition->values);
        $this->assertNull($definition->values_en);
    }
}
