<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
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

    public function test_unmarked_808184_family_is_promoted_requeued_and_keeps_its_stock(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-808184',
            'name' => 'Sklep B2C 808184',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'WC-B2C-808184',
            'name' => 'WooCommerce B2C 808184',
            'type' => 'virtual',
            'is_active' => true,
        ]);
        $parent = Product::query()->create([
            'sku' => 'SEM-00005450',
            'name' => 'Szorty JEANS MILA Baby blue',
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
                'content' => [
                    'pl' => [
                        'name' => 'Szorty JEANS MILA Baby blue',
                        'description' => '<p>Opis PL szortów MILA</p>',
                    ],
                    'en' => [
                        'name' => 'MILA Denim Shorts Baby Blue',
                        'description' => '<p>MILA shorts EN description</p>',
                    ],
                ],
                'parameters' => [
                    ['name' => 'wariant', 'value' => 'M/L, S/M', 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => 'M/L | S/M', 'variation' => true],
                ],
            ]],
        ]);
        $variantMl = Product::query()->create([
            'sku' => 'SEM-00005451',
            'name' => 'Spodnie JEANS Jasny róż - M/L (kopia)',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'prices' => ['retail_price_pln' => 569],
                'content' => ['pl' => ['description' => ''], 'en' => []],
                'parameters' => [['name' => 'Rozmiar', 'value' => 'm/l', 'variation' => true]],
            ]],
        ]);
        $variantSm = Product::query()->create([
            'sku' => 'SEM-00005452',
            'name' => 'Spodnie JEANS Jasny róż - S/M (kopia)',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'prices' => ['retail_price_pln' => 569],
                'content' => ['pl' => ['description' => ''], 'en' => []],
                'parameters' => [['name' => 'Rozmiar', 'value' => 's/m', 'variation' => true]],
            ]],
        ]);

        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variantMl->id,
            'relation_type' => 'variant',
            'sort_order' => 20,
            'metadata' => [],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variantSm->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
            'metadata' => [],
        ]);

        foreach ([[$variantMl, 2], [$variantSm, 3]] as [$variant, $quantity]) {
            StockBalance::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $variant->id,
                'quantity_on_hand' => $quantity,
                'quantity_reserved' => 0,
                'quantity_available' => $quantity,
            ]);
        }

        $parentMapping = ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '808184',
            'external_variation_id' => null,
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'mapping_role' => 'primary',
                'language' => 'pl',
                'product_data_export' => [
                    'legacy_variant_backfill' => [
                        'status' => 'completed',
                        'reason' => LegacyVariantFamilyBackfillService::REASON,
                        'completed_at' => '2026-07-14T10:00:00+00:00',
                    ],
                ],
            ],
        ]);

        foreach ([[$variantMl, '808185'], [$variantSm, '808187']] as [$variant, $variationId]) {
            ProductChannelMapping::query()->create([
                'product_id' => $variant->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => '808184',
                'external_variation_id' => $variationId,
                'external_sku' => $variant->sku,
                'stock_sync_enabled' => true,
                'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
            ]);
        }

        $this->assertNull(data_get($parent->masterData(), 'copy'));
        $this->assertNull(data_get($variantMl->masterData(), 'copy'));
        $this->assertNull(data_get($variantSm->masterData(), 'copy'));
        $this->assertNull(data_get($variantMl->masterData(), 'inheritance'));
        $this->assertNull(data_get($variantSm->masterData(), 'inheritance'));
        $this->assertSame(569, data_get($variantMl->masterData(), 'prices.retail_price_pln'));
        $this->assertSame(569, data_get($variantSm->masterData(), 'prices.retail_price_pln'));
        $this->assertStringContainsString('(kopia)', $variantMl->name);
        $this->assertStringContainsString('(kopia)', $variantSm->name);

        $migration = require database_path('migrations/2026_07_14_000006_promote_unmarked_woocommerce_variant_families.php');
        $migration->up();

        $variantMl->refresh();
        $variantSm->refresh();
        $parent->refresh();

        $this->assertSame('Szorty JEANS MILA Baby blue - M/L', $variantMl->name);
        $this->assertSame('Szorty JEANS MILA Baby blue - S/M', $variantSm->name);
        $this->assertSame('parent', data_get($variantMl->masterData(), 'inheritance.mode'));
        $this->assertSame('parent', data_get($variantSm->masterData(), 'inheritance.mode'));
        $this->assertSame($parent->id, data_get($variantMl->masterData(), 'inheritance.parent_product_id'));
        $this->assertSame($parent->id, data_get($variantSm->masterData(), 'inheritance.parent_product_id'));
        $this->assertSame(
            LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION,
            data_get($variantMl->masterData(), 'inheritance.promoted_by'),
        );
        $this->assertSame(
            LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION,
            data_get($variantSm->masterData(), 'inheritance.promoted_by'),
        );
        $this->assertSame(459, data_get($variantMl->masterData(), 'prices.retail_price_pln'));
        $this->assertSame(459, data_get($variantSm->masterData(), 'prices.retail_price_pln'));
        $this->assertSame('M/L', data_get($variantMl->masterData(), 'parameters.1.value'));
        $this->assertSame('S/M', data_get($variantSm->masterData(), 'parameters.1.value'));
        $this->assertSame('<p>Opis PL szortów MILA</p>', data_get($variantMl->masterData(), 'content.pl.description'));
        $this->assertSame('<p>MILA shorts EN description</p>', data_get($variantSm->masterData(), 'content.en.description'));
        $this->assertSame('MILA Denim Shorts Baby Blue - M/L', data_get($variantMl->masterData(), 'content.en.name'));
        $this->assertNotNull(data_get($parent->masterData(), 'publication_date'));
        $this->assertSame('2.0000', (string) StockBalance::query()
            ->where('product_id', $variantMl->id)->firstOrFail()->quantity_available);
        $this->assertSame('3.0000', (string) StockBalance::query()
            ->where('product_id', $variantSm->id)->firstOrFail()->quantity_available);

        $repairedRelations = ProductRelation::query()
            ->where('parent_product_id', $parent->id)
            ->where('relation_type', 'variant')
            ->get()
            ->keyBy('child_product_id');
        $this->assertSame('808185', data_get(
            $repairedRelations->get($variantMl->id)?->metadata,
            'inheritance_repair.external_variation_id',
        ));
        $this->assertSame('808187', data_get(
            $repairedRelations->get($variantSm->id)?->metadata,
            'inheritance_repair.external_variation_id',
        ));
        $this->assertSame(
            LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION,
            data_get($parent->masterData(), 'maintenance.legacy_variant_family_promotion.revision'),
        );

        $backfill = data_get(
            $parentMapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill',
        );
        $this->assertSame('pending', data_get($backfill, 'status'));
        $this->assertSame(
            LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION,
            data_get($backfill, 'revision'),
        );
        $this->assertNull(data_get($backfill, 'completed_at'));

        $requestedAt = data_get($backfill, 'requested_at');
        $migration->up();
        $this->assertSame($requestedAt, data_get(
            $parentMapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.requested_at',
        ));
        $this->assertSame(2, ProductRelation::query()
            ->where('parent_product_id', $parent->id)
            ->where('relation_type', 'variant')
            ->count());
    }

    public function test_unmarked_migration_does_not_promote_an_ordinary_mapped_family(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-ORDINARY-FAMILY',
            'name' => 'Sklep B2C ordinary family',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $parent = Product::query()->create([
            'sku' => 'ORDINARY-PARENT',
            'name' => 'Zwykła koszula',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'woocommerce_import',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'prices' => ['retail_price_pln' => 199],
            ]],
        ]);
        $variant = Product::query()->create([
            'sku' => 'ORDINARY-PARENT-M',
            'name' => 'Zwykła koszula - M',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'woocommerce_import',
                'product_type' => 'variation',
                'prices' => ['retail_price_pln' => 179],
                'parameters' => [['name' => 'Rozmiar', 'value' => 'M', 'variation' => true]],
            ]],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
            'metadata' => [],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '900000',
            'external_variation_id' => null,
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '900000',
            'external_variation_id' => '900001',
            'external_sku' => $variant->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
        ]);

        (require database_path('migrations/2026_07_14_000006_promote_unmarked_woocommerce_variant_families.php'))->up();

        $this->assertSame('Zwykła koszula - M', $variant->fresh()->name);
        $this->assertSame(179, data_get($variant->fresh()->masterData(), 'prices.retail_price_pln'));
        $this->assertNull(data_get($variant->fresh()->masterData(), 'inheritance'));
        $this->assertNull(data_get(
            ProductChannelMapping::query()->where('product_id', $parent->id)->firstOrFail()->metadata,
            'product_data_export.legacy_variant_backfill',
        ));
    }

    public function test_previously_bound_legacy_family_with_completed_backfill_is_queued_for_revision_export(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-BOUND-LEGACY',
            'name' => 'Sklep B2C bound legacy',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $parent = Product::query()->create([
            'sku' => 'BOUND-LEGACY-PARENT',
            'name' => 'Komplet historyczny',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'publication_status' => 'publish',
                'publication_date' => '2026-07-14T12:30',
                'prices' => ['retail_price_pln' => 699],
                'content' => [
                    'pl' => ['name' => 'Komplet historyczny', 'description' => '<p>Opis rodzica</p>'],
                    'en' => ['name' => 'Legacy set', 'description' => '<p>Parent description</p>'],
                ],
                'copy' => ['created_from_product_id' => 700000],
            ]],
        ]);
        $variant = Product::query()->create([
            'sku' => 'BOUND-LEGACY-PARENT-S',
            'name' => 'Komplet historyczny - S',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'variant_attribute' => 'Rozmiar',
                'prices' => ['retail_price_pln' => 699],
                'content' => [
                    'pl' => ['name' => 'Komplet historyczny - S', 'description' => '<p>Opis rodzica</p>'],
                    'en' => ['name' => 'Legacy set - S', 'description' => '<p>Parent description</p>'],
                ],
                'parameters' => [['name' => 'Rozmiar', 'value' => 'S', 'variation' => true]],
                'copy' => ['created_from_product_id' => 700001],
                'inheritance' => [
                    'mode' => 'parent',
                    'parent_product_id' => $parent->id,
                    'synced_at' => '2026-07-14T12:30:00+00:00',
                ],
            ]],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
            'metadata' => [
                'copied_from_relation_id' => 700002,
                'copied_at' => '2026-07-14T12:00:00+00:00',
            ],
        ]);
        $parentMapping = ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '818000',
            'external_variation_id' => null,
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'mapping_role' => 'primary',
                'language' => 'pl',
                'product_data_export' => [
                    'legacy_variant_backfill' => [
                        'status' => 'completed',
                        'reason' => LegacyVariantFamilyBackfillService::REASON,
                        'completed_at' => '2026-07-14T12:40:00+00:00',
                    ],
                ],
            ],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '818000',
            'external_variation_id' => '818001',
            'external_sku' => $variant->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
        ]);

        $this->assertSame('parent', data_get($variant->masterData(), 'inheritance.mode'));
        $this->assertSame('completed', data_get(
            $parentMapping->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));

        (require database_path('migrations/2026_07_14_000006_promote_unmarked_woocommerce_variant_families.php'))->up();

        $variant->refresh();

        $this->assertSame('parent', data_get($variant->masterData(), 'inheritance.mode'));
        $this->assertSame($parent->id, data_get($variant->masterData(), 'inheritance.parent_product_id'));
        $this->assertSame('Komplet historyczny - S', $variant->name);
        $this->assertSame(699, data_get($variant->masterData(), 'prices.retail_price_pln'));
        $this->assertSame('pending', data_get(
            $parentMapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertSame(
            LegacyVariantFamilyBackfillService::UNMARKED_FAMILY_PROMOTION_REVISION,
            data_get($parentMapping->metadata, 'product_data_export.legacy_variant_backfill.revision'),
        );
        $this->assertNull(data_get(
            $parentMapping->metadata,
            'product_data_export.legacy_variant_backfill.completed_at',
        ));
        $this->assertSame('818001', data_get(
            ProductRelation::query()
                ->where('parent_product_id', $parent->id)
                ->where('child_product_id', $variant->id)
                ->firstOrFail()
                ->metadata,
            'inheritance_repair.external_variation_id',
        ));
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
