<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductEditorConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_shipping_fields_are_visible_for_an_existing_editor_configuration(): void
    {
        AppSetting::query()->create([
            'key' => 'product_edit_visible_fields',
            'value' => ['visible_fields' => ['name', 'unit', 'vat_rate']],
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-LEGACY-CONFIG',
            'name' => 'Produkt ze starą konfiguracją',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('name="lemon_shipping_days"', false)
            ->assertSee('name="lemon_shipping_text"', false)
            ->assertSee('name="lemon_shipping_text_en"', false)
            ->assertSee('name="lemon_preorder"', false);
    }

    public function test_product_field_visibility_is_configurable_and_hidden_values_are_preserved(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-CONFIGURED',
            'name' => 'Produkt konfigurowany',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'tags' => ['ukryty-tag'],
                    'related_products' => [
                        'upsell_skus' => ['SKU-UPSELL'],
                        'cross_sell_skus' => ['SKU-CROSS'],
                    ],
                    'shipping' => [
                        'days' => 11,
                        'text' => 'Planowana wysyłka: {date}',
                        'text_en' => 'Planned shipping: {date}',
                        'preorder' => true,
                    ],
                ],
            ],
        ]);

        $this->get(route('settings.products'))
            ->assertOk()
            ->assertSee('Widoczne pola w edycji produktu')
            ->assertSee('Tagi')
            ->assertSee('Nazwa dostawcy')
            ->assertSee('Dni do wysyłki')
            ->assertSee('Przedsprzedaż');

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('Wybierz sprzedaż dodatkową')
            ->assertSee('Wybierz sprzedaż krzyżową');

        $this->put(route('settings.products.update'), [
            'visible_fields' => ['name', 'unit', 'vat_rate'],
        ])->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame([
            'name',
            'unit',
            'vat_rate',
        ], AppSetting::query()->where('key', 'product_edit_visible_fields')->value('value')['visible_fields']);

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertDontSee('Wybierz sprzedaż dodatkową')
            ->assertDontSee('Wybierz sprzedaż krzyżową')
            ->assertSee('product-edit-field-hidden', false);

        $this->put(route('products.update', $product), [
            'name' => 'Produkt po zmianie',
            'unit' => 'szt',
            'vat_rate' => 23,
        ])->assertRedirect(route('products.edit', $product));

        $product->refresh();

        $this->assertSame('Produkt po zmianie', $product->name);
        $this->assertSame(['ukryty-tag'], data_get($product->attributes, 'master.tags'));
        $this->assertSame(['SKU-UPSELL'], data_get($product->attributes, 'master.related_products.upsell_skus'));
        $this->assertSame(['SKU-CROSS'], data_get($product->attributes, 'master.related_products.cross_sell_skus'));
        $this->assertSame(11, data_get($product->attributes, 'master.shipping.days'));
        $this->assertSame('Planowana wysyłka: {date}', data_get($product->attributes, 'master.shipping.text'));
        $this->assertSame('Planned shipping: {date}', data_get($product->attributes, 'master.shipping.text_en'));
        $this->assertTrue(data_get($product->attributes, 'master.shipping.preorder'));
    }
}
