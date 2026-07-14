<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WooCommerceVariantRelationRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_restores_unambiguous_woocommerce_variant_relations_and_primary_variant_visibility(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $parent = $this->product('ARDEN', 'Komplet ARDEN', true);
        $variantM = $this->product('ARDEN-M', 'Komplet ARDEN - M', true, 20);
        $variantS = $this->product('ARDEN-S', 'Komplet ARDEN - S', true, 10);
        $legacyParent = $this->product('LEGACY-PARENT', 'Stary rodzic');
        $conflictingVariant = $this->product('ARDEN-L', 'Komplet ARDEN - L', true, 30);

        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '700',
            'external_variation_id' => null,
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
        ]);

        foreach ([[$variantM, '702'], [$variantS, '701']] as [$variant, $externalVariationId]) {
            ProductChannelMapping::query()->create([
                'product_id' => $variant->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => '700',
                'external_variation_id' => $externalVariationId,
                'external_sku' => $variant->sku,
                'stock_sync_enabled' => true,
                'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
            ]);
        }
        ProductChannelMapping::query()->create([
            'product_id' => $conflictingVariant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '700',
            'external_variation_id' => '703',
            'external_sku' => $conflictingVariant->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $legacyParent->id,
            'child_product_id' => $conflictingVariant->id,
            'relation_type' => 'variant',
            'sort_order' => 30,
        ]);

        $migration = require database_path('migrations/2026_07_14_000003_repair_woocommerce_variant_relations.php');
        $migration->up();

        $relations = ProductRelation::query()
            ->where('parent_product_id', $parent->id)
            ->where('relation_type', 'variant')
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $relations);
        $this->assertSame([$variantS->id, $variantM->id], $relations->pluck('child_product_id')->all());
        $this->assertSame([10, 20], $relations->pluck('sort_order')->all());
        $this->assertFalse($parent->fresh()->is_translation);
        $this->assertFalse($variantS->fresh()->is_translation);
        $this->assertFalse($variantM->fresh()->is_translation);
        $this->assertCount(2, $parent->fresh()->variantChildren);
        $this->assertFalse(ProductRelation::query()
            ->where('parent_product_id', $parent->id)
            ->where('child_product_id', $conflictingVariant->id)
            ->exists());
        $this->assertTrue(ProductRelation::query()
            ->where('parent_product_id', $legacyParent->id)
            ->where('child_product_id', $conflictingVariant->id)
            ->exists());

        $relationMetadata = $relations->pluck('metadata', 'id')->sortKeys()->all();
        $migration->up();

        $this->assertSame(2, ProductRelation::query()
            ->where('parent_product_id', $parent->id)
            ->where('relation_type', 'variant')
            ->count());
        $this->assertSame($relationMetadata, ProductRelation::query()
            ->whereIn('id', $relations->pluck('id'))
            ->pluck('metadata', 'id')
            ->sortKeys()
            ->all());

        $backfill = require database_path('migrations/2026_07_14_000005_mark_legacy_variant_families_for_woocommerce_backfill.php');
        $backfill->up();

        $this->assertSame('pending', data_get(
            ProductChannelMapping::query()
                ->where('product_id', $parent->id)
                ->whereNull('external_variation_id')
                ->firstOrFail()
                ->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertNull(data_get(
            ProductChannelMapping::query()
                ->where('product_id', $variantS->id)
                ->firstOrFail()
                ->metadata,
            'product_data_export.legacy_variant_backfill',
        ));
    }

    public function test_repaired_relation_promotes_an_old_copied_child_without_changing_its_stock(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-COPY',
            'name' => 'Sklep B2C kopie',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'WC-B2C-COPY',
            'name' => 'WooCommerce B2C kopie',
            'type' => 'virtual',
            'is_active' => true,
        ]);
        $parent = Product::query()->create([
            'sku' => 'COPY-PARENT',
            'name' => 'Szorty MILA',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'publication_status' => 'publish',
                'prices' => ['retail_price_pln' => 459],
                'content' => ['pl' => [
                    'name' => 'Szorty MILA',
                    'description' => '<p>Aktualny opis produktu głównego</p>',
                ]],
                'copy' => ['created_from_product_id' => 800000],
            ]],
        ]);
        $variant = Product::query()->create([
            'sku' => 'COPY-PARENT-SM',
            'name' => 'Spodnie JEANS Jasny róż - s/m (kopia)',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'is_translation' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'prices' => ['retail_price_pln' => 569],
                'content' => ['pl' => ['description' => '']],
                'parameters' => [['name' => 'Rozmiar', 'value' => 's/m', 'variation' => true]],
                'copy' => ['created_from_product_id' => 800001],
            ]],
        ]);
        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $variant->id,
            'quantity_on_hand' => 3,
            'quantity_reserved' => 1,
            'quantity_available' => 2,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '808184',
            'external_variation_id' => null,
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '808184',
            'external_variation_id' => '808187',
            'external_sku' => $variant->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
        ]);

        $relationRepair = require database_path('migrations/2026_07_14_000003_repair_woocommerce_variant_relations.php');
        $relationRepair->up();
        $promotion = require database_path('migrations/2026_07_14_000004_promote_repaired_copied_product_variants.php');
        $promotion->up();

        $variant->refresh();
        $balance = StockBalance::query()->where('product_id', $variant->id)->firstOrFail();

        $this->assertTrue(ProductRelation::query()
            ->where('parent_product_id', $parent->id)
            ->where('child_product_id', $variant->id)
            ->where('relation_type', 'variant')
            ->exists());
        $this->assertSame('Szorty MILA - S/M', $variant->name);
        $this->assertSame('parent', data_get($variant->masterData(), 'inheritance.mode'));
        $this->assertSame($parent->id, data_get($variant->masterData(), 'inheritance.parent_product_id'));
        $this->assertSame('S/M', data_get($variant->masterData(), 'parameters.0.value'));
        $this->assertSame(459, data_get($variant->masterData(), 'prices.retail_price_pln'));
        $this->assertSame('<p>Aktualny opis produktu głównego</p>', data_get($variant->masterData(), 'content.pl.description'));
        $this->assertIsArray(data_get($parent->fresh()->masterData(), 'content.en'));
        $this->assertNotNull(data_get($parent->fresh()->masterData(), 'publication_date'));
        $this->assertSame('3.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('1.0000', (string) $balance->quantity_reserved);
        $this->assertSame('2.0000', (string) $balance->quantity_available);
    }

    private function product(
        string $sku,
        string $name,
        bool $isTranslation = false,
        ?int $menuOrder = null,
    ): Product {
        $attributes = ['master' => ['source' => 'woocommerce_import']];

        if ($menuOrder !== null) {
            $attributes['woocommerce_raw_payload'] = ['menu_order' => $menuOrder];
        }

        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'is_translation' => $isTranslation,
            'attributes' => $attributes,
        ]);
    }
}
