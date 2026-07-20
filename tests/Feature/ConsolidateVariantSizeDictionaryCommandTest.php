<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProductParameterDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConsolidateVariantSizeDictionaryCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedDuplicateSizeDictionaries(): void
    {
        Queue::fake();

        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'slug' => 'rozmiar',
            'input_type' => 'list',
            'values' => ['36', '37'],
            'values_en' => ['36', '37'],
            'is_variant' => true,
            'sort_order' => 100,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiary',
            'slug' => 'rozmiary',
            'input_type' => 'list',
            'values' => ['37', '38', '39'],
            'values_en' => ['37', '38', '39'],
            'is_variant' => true,
            'sort_order' => 100,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Kolor',
            'slug' => 'kolor',
            'input_type' => 'list',
            'values' => ['Beżowy', 'Czarny'],
            'is_variant' => true,
            'sort_order' => 100,
        ]);
    }

    public function test_it_merges_the_duplicate_size_dictionary_into_canonical_rozmiar(): void
    {
        $this->seedDuplicateSizeDictionaries();

        $this->artisan('erp:consolidate-variant-size-dictionary')->assertExitCode(0);

        $canonical = ProductParameterDefinition::query()->where('slug', 'rozmiar')->firstOrFail();
        // Union preserves the canonical order and appends only the new values.
        $this->assertSame(['36', '37', '38', '39'], array_values((array) $canonical->values));
        $this->assertTrue((bool) $canonical->is_variant);

        // The duplicate is removed so the PIM and the variant-attribute select
        // offer a single size axis.
        $this->assertNull(ProductParameterDefinition::query()->where('slug', 'rozmiary')->first());

        // Unrelated dictionaries are untouched.
        $this->assertNotNull(ProductParameterDefinition::query()->where('slug', 'kolor')->first());
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $this->seedDuplicateSizeDictionaries();

        $this->artisan('erp:consolidate-variant-size-dictionary', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNotNull(ProductParameterDefinition::query()->where('slug', 'rozmiary')->first());
        $this->assertSame(
            ['36', '37'],
            array_values((array) ProductParameterDefinition::query()->where('slug', 'rozmiar')->firstOrFail()->values),
        );
    }

    public function test_it_is_idempotent_when_already_consolidated(): void
    {
        Queue::fake();
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'slug' => 'rozmiar',
            'input_type' => 'list',
            'values' => ['36', '37'],
            'is_variant' => true,
            'sort_order' => 100,
        ]);

        $this->artisan('erp:consolidate-variant-size-dictionary')->assertExitCode(0);

        $this->assertSame(1, ProductParameterDefinition::query()->count());
    }
}
