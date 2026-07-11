<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductRelation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductListPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_list_paginates_families_before_loading_rows(): void
    {
        $products = collect(range(1, 35))->map(fn (int $index): Product => Product::query()->create([
            'sku' => sprintf('SKU-LIST-%02d', $index),
            'name' => sprintf('Produkt listy %02d', $index),
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]));

        $parent = $products->last();
        $variant = Product::query()->create([
            'sku' => 'SKU-LIST-35-BLUE',
            'name' => 'Produkt listy 35 — niebieski wariant',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);

        $productQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$productQueries): void {
            if (str_contains($query->sql, 'from "products"')) {
                $productQueries[] = $query->sql;
            }
        });

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Produkt listy 35')
            ->assertSee('Produkt listy 35 — niebieski wariant')
            ->assertSee('Warianty: 1')
            ->assertDontSee('>Produkt listy 05</a>', false);

        $this->assertTrue(
            collect($productQueries)->contains(fn (string $sql): bool => str_contains(mb_strtolower($sql), 'limit 30')),
            'Lista produktów powinna pobierać stronę z limitem SQL, a nie wszystkie produkty.',
        );

        $this->get(route('products.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Produkt listy 05')
            ->assertDontSee('>Produkt listy 35</a>', false);
    }

    public function test_product_filters_match_a_variant_but_keep_its_parent_family(): void
    {
        $parent = Product::query()->create([
            'sku' => 'SKU-FAMILY-PARENT',
            'name' => 'Sukienka rodzinna',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $variant = Product::query()->create([
            'sku' => 'SKU-FAMILY-MINT',
            'name' => 'Sukienka miętowa',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);

        Product::query()->create([
            'sku' => 'SKU-OTHER',
            'name' => 'Inny produkt',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->get(route('products.index', ['q' => 'miętowa']))
            ->assertOk()
            ->assertSee('Sukienka rodzinna')
            ->assertSee('Sukienka miętowa')
            ->assertDontSee('>Inny produkt</a>', false);
    }

    public function test_product_lookup_is_loaded_only_after_the_user_starts_searching(): void
    {
        Product::query()->create([
            'sku' => 'SKU-LOOKUP-ALFA',
            'name' => 'Produkt do szybkiego wyszukania',
            'ean' => '5900000000123',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->getJson(route('products.lookup', ['q' => 'alfa']))
            ->assertOk()
            ->assertJsonFragment([
                'sku' => 'SKU-LOOKUP-ALFA',
                'label' => 'SKU-LOOKUP-ALFA | Produkt do szybkiego wyszukania',
            ]);

        $this->getJson(route('products.lookup', ['q' => 'a']))
            ->assertOk()
            ->assertExactJson([]);
    }
}
