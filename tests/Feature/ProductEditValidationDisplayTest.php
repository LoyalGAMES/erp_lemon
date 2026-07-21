<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A failed save must tell the operator WHICH fields were rejected. The old
 * banner claimed fields were "marked by validation" while the form marked
 * nothing, leaving no way to find the culprit.
 */
class ProductEditValidationDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_save_lists_the_specific_validation_messages(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-VAL',
            'name' => 'Produkt walidowany',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $page = $this->from(route('products.edit', $product))
            ->followingRedirects()
            ->put(route('products.update', $product), [
                'sku' => 'SKU-VAL',
                'name' => 'Produkt walidowany',
                'unit' => 'szt',
                'vat_rate' => 23,
                'ean' => 'nie-ean',
                'retail_price_pln' => -5,
            ]);

        $page->assertOk();
        $page->assertSee('Nie zapisano produktu. Popraw poniższe pola:');
        // The banner lists each validator message instead of a generic promise.
        $page->assertSee('ean');
        $page->assertSee('retail price pln');
    }
}
