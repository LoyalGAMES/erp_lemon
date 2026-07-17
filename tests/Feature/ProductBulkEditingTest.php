<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Services\Products\ProductVariantInheritanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProductBulkEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_exposes_bulk_selection_controls_counter_and_selective_edit_modal(): void
    {
        $first = $this->product('BULK-VIEW-1', 'Produkt pierwszy');
        $second = $this->product('BULK-VIEW-2', 'Produkt drugi');

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('data-product-bulk-toolbar', false)
            ->assertSee('Zaznacz wszystkie na tej stronie')
            ->assertSee('Zaznacz wszystkie na wszystkich stronach (2)')
            ->assertSee('data-product-bulk-modal', false)
            ->assertSee('name="apply[category_ids]"', false)
            ->assertSee('name="apply[retail_price_pln]"', false)
            ->assertSee('name="apply[lemon_preorder]"', false)
            ->assertSee('Produkt dostępny w przedsprzedaży')
            ->assertSee('value="'.$first->id.'" data-product-select', false)
            ->assertSee('value="'.$second->id.'" data-product-select', false);
    }

    public function test_bulk_update_changes_only_applied_fields_for_selected_products_and_queues_mapped_products(): void
    {
        Bus::fake();

        $category = ProductCategory::query()->create([
            'external_id' => 'bulk-category',
            'name' => 'Nowa kategoria',
            'slug' => 'nowa-kategoria',
            'path' => 'Katalog > Nowa kategoria',
        ]);
        $first = $this->product('BULK-SELECTED-1', 'Produkt pierwszy', [
            'prices' => [
                'retail_price_pln' => 100.0,
                'sale_price_pln' => 75.0,
            ],
            'custom_label' => [
                'pl' => 'Stara PL',
                'en' => 'Keep EN',
                'bg_color' => '#111111',
                'text_color' => '#ffffff',
            ],
            'shipping' => [
                'days' => 3,
                'text' => 'Zachowaj tekst: {date}',
                'preorder' => true,
            ],
            'inventory' => ['backorders' => 'no'],
            'publication_status' => 'publish',
        ]);
        $second = $this->product('BULK-SELECTED-2', 'Produkt drugi', [
            'prices' => [
                'retail_price_pln' => 150.0,
                'sale_price_pln' => null,
            ],
            'custom_label' => ['en' => 'Second EN'],
            'shipping' => ['text' => 'Drugi tekst'],
        ]);
        $untouched = $this->product('BULK-UNTOUCHED', 'Produkt bez zmian', [
            'prices' => ['retail_price_pln' => 90.0],
            'custom_label' => ['pl' => 'Nie zmieniaj'],
            'shipping' => ['preorder' => true],
        ]);
        $channel = SalesChannel::query()->create([
            'code' => 'BULK-WOO',
            'name' => 'Sklep grupowy',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $first->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '501',
            'external_sku' => $first->sku,
            'stock_sync_enabled' => true,
        ]);

        $this->put(route('products.bulk.update'), [
            'selection_mode' => 'selected',
            'product_ids' => [$first->id, $second->id],
            'apply' => [
                'category_ids' => '1',
                'retail_price_pln' => '1',
                'is_active' => '1',
                'publication_status' => '1',
                'backorders' => '1',
                'custom_label_pl' => '1',
                'lemon_shipping_days' => '1',
                'lemon_preorder' => '1',
            ],
            'changes' => [
                'category_ids' => [$category->id],
                'retail_price_pln' => '200.00',
                'is_active' => '0',
                'publication_status' => 'draft',
                'backorders' => 'yes',
                'custom_label_pl' => 'Wspólna etykieta',
                'lemon_shipping_days' => '11',
                'lemon_preorder' => '0',
            ],
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Zmieniono grupowo 2 produkty. Synchronizację WooCommerce uruchomiono dla 1 zmapowanego produktu.');

        $first->refresh();
        $second->refresh();
        $untouched->refresh();

        foreach ([$first, $second] as $product) {
            $this->assertFalse($product->is_active);
            $this->assertSame([$category->id], data_get($product->masterData(), 'category_ids'));
            $this->assertSame(['Katalog > Nowa kategoria'], data_get($product->masterData(), 'categories'));
            $this->assertSame(200.0, (float) data_get($product->masterData(), 'prices.retail_price_pln'));
            $this->assertSame('draft', data_get($product->masterData(), 'publication_status'));
            $this->assertSame('yes', data_get($product->masterData(), 'inventory.backorders'));
            $this->assertSame('Wspólna etykieta', data_get($product->masterData(), 'custom_label.pl'));
            $this->assertSame(11, data_get($product->masterData(), 'shipping.days'));
            $this->assertFalse(data_get($product->masterData(), 'shipping.preorder'));
        }

        $this->assertSame(75.0, (float) data_get($first->masterData(), 'prices.sale_price_pln'));
        $this->assertSame('Keep EN', data_get($first->masterData(), 'custom_label.en'));
        $this->assertSame('Zachowaj tekst: {date}', data_get($first->masterData(), 'shipping.text'));
        $this->assertSame('Second EN', data_get($second->masterData(), 'custom_label.en'));
        $this->assertSame('Drugi tekst', data_get($second->masterData(), 'shipping.text'));
        $this->assertTrue($untouched->is_active);
        $this->assertSame(90.0, (float) data_get($untouched->masterData(), 'prices.retail_price_pln'));
        $this->assertSame('Nie zmieniaj', data_get($untouched->masterData(), 'custom_label.pl'));
        $this->assertTrue(data_get($untouched->masterData(), 'shipping.preorder'));
        $this->assertSame(2, AuditLog::query()->where('action', 'product.bulk_updated')->count());

        Bus::assertDispatchedAfterResponse(ExportWooCommerceProductDataJob::class, 1);
        $this->assertCount(1, Bus::dispatched(ExportWooCommerceProductDataJob::class));
    }

    public function test_bulk_update_can_apply_to_all_filtered_products_except_explicit_exclusions(): void
    {
        Bus::fake();

        $included = $this->product('FILTER-MATCH-1', 'Kolekcja lato pierwsza', [
            'shipping' => ['days' => 2],
        ]);
        $excluded = $this->product('FILTER-MATCH-2', 'Kolekcja lato druga', [
            'shipping' => ['days' => 3],
        ]);
        $outsideFilter = $this->product('FILTER-OTHER', 'Kolekcja zima', [
            'shipping' => ['days' => 4],
        ]);

        $this->put(route('products.bulk.update'), [
            'selection_mode' => 'all_filtered',
            'excluded_ids' => [$excluded->id],
            'filters' => ['q' => 'Kolekcja lato'],
            'apply' => [
                'lemon_shipping_days' => '1',
                'lemon_shipping_text' => '1',
            ],
            'changes' => [
                'lemon_shipping_days' => '14',
                'lemon_shipping_text' => 'Wysyłka za {days} dni: {date}',
            ],
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Zmieniono grupowo 1 produkt. Synchronizację WooCommerce uruchomiono dla 0 zmapowanych produktów.');

        $included->refresh();
        $excluded->refresh();
        $outsideFilter->refresh();

        $this->assertSame(14, data_get($included->masterData(), 'shipping.days'));
        $this->assertSame('Wysyłka za {days} dni: {date}', data_get($included->masterData(), 'shipping.text'));
        $this->assertSame(3, data_get($excluded->masterData(), 'shipping.days'));
        $this->assertSame(4, data_get($outsideFilter->masterData(), 'shipping.days'));
        Bus::assertNotDispatched(ExportWooCommerceProductDataJob::class);
    }

    public function test_bulk_update_supports_publication_sale_label_and_category_fields_including_clearing_values(): void
    {
        $product = $this->product('BULK-ALL-FIELDS', 'Komplet pól grupowych', [
            'category_ids' => [999],
            'categories' => ['Stara kategoria'],
            'category' => 'Stara kategoria',
            'catalog_visibility' => 'visible',
            'publication_status' => 'publish',
            'publication_date' => '2026-01-01T10:00',
            'prices' => [
                'retail_price_pln' => 100.0,
                'sale_price_pln' => 80.0,
                'sale_price_starts_at' => '2026-01-01',
                'sale_price_ends_at' => '2026-01-10',
            ],
            'custom_label' => [
                'pl' => 'Stara PL',
                'en' => 'Old EN',
                'bg_color' => '#111111',
                'text_color' => '#ffffff',
            ],
            'shipping' => ['text' => 'Stary termin'],
        ]);

        $this->put(route('products.bulk.update'), [
            'selection_mode' => 'selected',
            'product_ids' => [$product->id],
            'apply' => [
                'category_ids' => '1',
                'sale_price_pln' => '1',
                'catalog_visibility' => '1',
                'publication_date' => '1',
                'sale_price_starts_at' => '1',
                'sale_price_ends_at' => '1',
                'custom_label_en' => '1',
                'custom_label_bg_color' => '1',
                'custom_label_text_color' => '1',
                'lemon_shipping_text' => '1',
            ],
            'changes' => [
                'category_ids' => [],
                'sale_price_pln' => '',
                'catalog_visibility' => 'hidden',
                'publication_date' => '2026-08-20T09:30',
                'sale_price_starts_at' => '2026-08-21',
                'sale_price_ends_at' => '2026-08-31',
                'custom_label_en' => 'New EN',
                'custom_label_bg_color' => '#123456',
                'custom_label_text_color' => '#fedcba',
                'lemon_shipping_text' => '',
            ],
        ])->assertRedirect();

        $master = $product->fresh()->masterData();

        $this->assertSame([], data_get($master, 'category_ids'));
        $this->assertSame([], data_get($master, 'categories'));
        $this->assertNull(data_get($master, 'category'));
        $this->assertNull(data_get($master, 'prices.sale_price_pln'));
        $this->assertSame(100.0, (float) data_get($master, 'prices.retail_price_pln'));
        $this->assertSame('hidden', data_get($master, 'catalog_visibility'));
        $this->assertSame('publish', data_get($master, 'publication_status'));
        $this->assertSame('2026-08-20T09:30', data_get($master, 'publication_date'));
        $this->assertSame('2026-08-21', data_get($master, 'prices.sale_price_starts_at'));
        $this->assertSame('2026-08-31', data_get($master, 'prices.sale_price_ends_at'));
        $this->assertSame('Stara PL', data_get($master, 'custom_label.pl'));
        $this->assertSame('New EN', data_get($master, 'custom_label.en'));
        $this->assertSame('#123456', data_get($master, 'custom_label.bg_color'));
        $this->assertSame('#fedcba', data_get($master, 'custom_label.text_color'));
        $this->assertNull(data_get($master, 'shipping.text'));
    }

    public function test_bulk_update_of_parent_refreshes_variants_that_inherit_product_configuration(): void
    {
        $parent = $this->product('BULK-PARENT', 'Produkt wariantowy', [
            'product_type' => 'variable',
            'variant_attribute' => 'Rozmiar',
            'shipping' => ['days' => 3, 'preorder' => false],
        ]);
        $variantMaster = app(ProductVariantInheritanceService::class)->newVariantMasterData(
            $parent,
            'Rozmiar',
            ['name' => 'Rozmiar', 'value' => 'M', 'variation' => true],
        );
        $variant = $this->product('BULK-PARENT-M', 'Produkt wariantowy - M', $variantMaster);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 1,
        ]);

        $this->put(route('products.bulk.update'), [
            'selection_mode' => 'selected',
            'product_ids' => [$parent->id],
            'apply' => [
                'lemon_shipping_days' => '1',
                'lemon_preorder' => '1',
            ],
            'changes' => [
                'lemon_shipping_days' => '21',
                'lemon_preorder' => '1',
            ],
        ])->assertRedirect();

        $variant->refresh();
        $this->assertSame(21, data_get($variant->masterData(), 'shipping.days'));
        $this->assertTrue(data_get($variant->masterData(), 'shipping.preorder'));
        $this->assertSame('M', data_get($variant->masterData(), 'parameters.0.value'));
    }

    public function test_bulk_update_rolls_back_all_products_when_resulting_price_is_invalid(): void
    {
        $first = $this->product('BULK-VALID-PRICE', 'Pierwsza cena', [
            'prices' => ['retail_price_pln' => 100.0, 'sale_price_pln' => 20.0],
        ]);
        $second = $this->product('BULK-INVALID-PRICE', 'Druga cena', [
            'prices' => ['retail_price_pln' => 50.0, 'sale_price_pln' => 10.0],
        ]);

        $this->from(route('products.index'))->put(route('products.bulk.update'), [
            'selection_mode' => 'selected',
            'product_ids' => [$first->id, $second->id],
            'apply' => ['sale_price_pln' => '1'],
            'changes' => ['sale_price_pln' => '60'],
        ])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasErrors('changes.sale_price_pln', null, 'bulk');

        $this->assertSame(20.0, (float) data_get($first->fresh()->masterData(), 'prices.sale_price_pln'));
        $this->assertSame(10.0, (float) data_get($second->fresh()->masterData(), 'prices.sale_price_pln'));
        $this->assertSame(0, AuditLog::query()->where('action', 'product.bulk_updated')->count());
    }

    /**
     * @param  array<string, mixed>  $master
     */
    private function product(string $sku, string $name, array $master = []): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => array_replace_recursive([
                    'source' => 'erp',
                    'product_type' => 'simple',
                ], $master),
            ],
        ]);
    }
}
