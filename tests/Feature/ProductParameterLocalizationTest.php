<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProductParameterDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductParameterLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_manage_polish_and_english_parameter_content(): void
    {
        $this->post(route('products.parameters.store'), [
            'name' => 'Kolor',
            'name_en' => 'Color',
            'slug' => 'kolor',
            'input_type' => 'select',
            'values_text' => "Beżowy\nCzerwony\nNiebieski",
            'values_text_en' => "Beige\n\nBlue",
            'is_variant' => '1',
            'sort_order' => '10',
        ])->assertRedirect();

        $definition = ProductParameterDefinition::query()->sole();

        $this->assertSame('Kolor', $definition->name);
        $this->assertSame('Color', $definition->name_en);
        $this->assertSame(['Beżowy', 'Czerwony', 'Niebieski'], $definition->values);
        $this->assertSame(['Beige', '', 'Blue'], $definition->values_en);
        $this->assertSame('Color', $definition->nameForLanguage('en'));
        $this->assertSame(['Beige', 'Czerwony', 'Blue'], $definition->valuesForLanguage('en'));
        $this->assertSame('Czerwony', $definition->valueForLanguage('Czerwony', 'en'));
        $this->assertSame('Blue', $definition->valueForLanguage('Niebieski', 'en'));

        $this->get(route('products.parameters.index'))
            ->assertOk()
            ->assertSee('Nazwa (PL)')
            ->assertSee('Nazwa (EN)')
            ->assertSee('Dozwolone wartości (PL)')
            ->assertSee('Dozwolone wartości (EN)')
            ->assertSee('Color')
            ->assertSee('Beige');

        $this->put(route('products.parameters.update', $definition), [
            'name' => 'Kolor produktu',
            'name_en' => 'Product color',
            'slug' => 'kolor',
            'input_type' => 'select',
            'values_text' => "Czarny\nBiały",
            'values_text_en' => "Black\nWhite",
            'is_required' => '1',
            'sort_order' => '20',
        ])->assertRedirect();

        $definition->refresh();
        $this->assertSame('Kolor produktu', $definition->name);
        $this->assertSame('Product color', $definition->name_en);
        $this->assertSame(['Czarny', 'Biały'], $definition->values);
        $this->assertSame(['Black', 'White'], $definition->values_en);
        $this->assertTrue($definition->is_required);
        $this->assertFalse($definition->is_variant);

        $this->delete(route('products.parameters.destroy', $definition))->assertRedirect();
        $this->assertDatabaseMissing('product_parameter_definitions', ['id' => $definition->id]);
    }

    public function test_english_values_keep_positional_blanks_and_reject_values_without_polish_counterparts(): void
    {
        $response = $this->from(route('products.parameters.index'))->post(route('products.parameters.store'), [
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'input_type' => 'select',
            'values_text' => "Mały\nDuży",
            'values_text_en' => "Small\nLarge\nExtra large",
        ]);

        $response
            ->assertRedirect(route('products.parameters.index'))
            ->assertSessionHasErrors('values_text_en');
        $this->assertDatabaseCount('product_parameter_definitions', 0);

        $this->post(route('products.parameters.store'), [
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'input_type' => 'select',
            'values_text' => "Mały\nDuży\nUniwersalny",
            'values_text_en' => "Small\nLarge",
        ])->assertRedirect();

        $definition = ProductParameterDefinition::query()->sole();
        $this->assertSame(['Small', 'Large', ''], $definition->values_en);
        $this->assertSame(['Small', 'Large', 'Uniwersalny'], $definition->valuesForLanguage('en'));
    }

    public function test_localized_names_cannot_create_a_second_definition_and_legacy_polish_data_is_preserved(): void
    {
        $legacyDefinition = ProductParameterDefinition::query()->create([
            'name' => 'Materiał',
            'slug' => 'material',
            'input_type' => 'select',
            'values' => ['Bawełna', 'Len'],
            'is_variant' => false,
            'is_required' => false,
            'sort_order' => 100,
        ]);

        $this->assertNull($legacyDefinition->name_en);
        $this->assertNull($legacyDefinition->values_en);
        $this->assertSame('Materiał', $legacyDefinition->nameForLanguage('en'));
        $this->assertSame(['Bawełna', 'Len'], $legacyDefinition->valuesForLanguage('en'));

        $this->put(route('products.parameters.update', $legacyDefinition), [
            'name' => 'Materiał',
            'name_en' => 'Material',
            'slug' => 'material',
            'input_type' => 'select',
            'values_text' => "Bawełna\nLen",
            'values_text_en' => "Cotton\nLinen",
        ])->assertRedirect();

        $response = $this->from(route('products.parameters.index'))->post(route('products.parameters.store'), [
            'name' => 'Material',
            'input_type' => 'text',
        ]);

        $response
            ->assertRedirect(route('products.parameters.index'))
            ->assertSessionHasErrors('name');
        $this->assertDatabaseCount('product_parameter_definitions', 1);
    }
}
