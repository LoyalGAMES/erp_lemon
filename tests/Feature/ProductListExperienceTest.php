<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductListExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_shows_only_primary_products_and_all_identifiers(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $polish = Product::query()->create([
            'sku' => 'AVA-PL',
            'name' => 'Koszula AVA kremowa',
            'ean' => '5901234567893',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'woocommerce_translations' => [
                    'en' => ['product_id' => '901'],
                ],
            ],
        ]);
        $english = Product::query()->create([
            'sku' => 'WC-B2C-PARENT-901',
            'name' => 'AVA Cream Shirt',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'is_translation' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $polish->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '900',
            'stock_sync_enabled' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $english->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '901',
            'stock_sync_enabled' => true,
        ]);

        $response = $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Koszula AVA kremowa')
            ->assertDontSee('AVA Cream Shirt')
            ->assertSee('ID Woo:')
            ->assertSee('900')
            ->assertSee('SKU:')
            ->assertSee('AVA-PL')
            ->assertSee('EAN:')
            ->assertSee('5901234567893')
            ->assertSee(route('products.edit', $polish), false)
            ->assertDontSee('>Szczegóły<', false)
            ->assertSee('data-stock-modal-open', false);

        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/<article\b(?=[^>]*\bdata-product-list(?:\s|=|>))[^>]*>/s', $html);
        $this->assertMatchesRegularExpression('/<div\b(?=[^>]*\bdata-product-list-scroll(?:\s|=|>))[^>]*>/s', $html);
        $this->assertMatchesRegularExpression('/<table\b(?=[^>]*\bdata-product-list-table(?:\s|=|>))[^>]*>/s', $html);
        $this->assertMatchesRegularExpression(
            '/<tr\b(?=[^>]*\bdata-product-card="parent")(?=[^>]*\bdata-product-id="'.$polish->id.'")[^>]*>/s',
            $html,
        );

        foreach (['identity', 'price', 'stock', 'channels', 'actions'] as $section) {
            $this->assertMatchesRegularExpression(
                '/<td\b(?=[^>]*\bdata-product-card-section="'.$section.'")[^>]*>/s',
                $html,
            );
        }
        $this->assertSame(5, preg_match_all('/<td\b[^>]*\bdata-product-card-section="[^"]+"[^>]*>/s', $html));

        $this->from(route('products.index'))->post(route('products.favorite.toggle', $polish))
            ->assertRedirect(route('products.index'));

        $this->get(route('products.favorites'))
            ->assertOk()
            ->assertSee('Koszula AVA kremowa')
            ->assertDontSee('AVA Cream Shirt');
    }

    public function test_category_configuration_keeps_english_on_the_polish_record_and_renders_hierarchy(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $parent = ProductCategory::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '10',
            'name' => 'Odzież',
            'path' => 'Odzież',
            'metadata' => [
                'woocommerce_ids' => ['pl' => '10', 'en' => '110'],
                'translations' => ['en' => ['name' => 'Clothing']],
            ],
        ]);
        ProductCategory::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '11',
            'parent_external_id' => $parent->external_id,
            'name' => 'Koszule',
            'path' => 'Odzież > Koszule',
            'metadata' => [
                'woocommerce_ids' => ['pl' => '11', 'en' => '111'],
                'translations' => ['en' => ['name' => 'Shirts']],
            ],
        ]);
        ProductCategory::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '111',
            'name' => 'Shirts duplicate',
            'path' => 'Shirts duplicate',
            'metadata' => ['woocommerce_ids' => ['en' => '111']],
        ]);

        $this->get(route('products.categories.index'))
            ->assertOk()
            ->assertSee('Odzież')
            ->assertSee('Koszule')
            ->assertSee('Clothing')
            ->assertSee('Shirts')
            ->assertSee('↳')
            ->assertDontSee('Shirts duplicate');
    }
}
