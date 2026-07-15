<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductRelation;
use App\Services\Products\ProductVariantInheritanceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductVariantInheritanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_parent_variant_only_inherits_from_its_explicitly_bound_parent(): void
    {
        $parentA = $this->parent('BOUND-PARENT-A', 'Rodzic A', '<p>Opis rodzica A</p>');
        $parentB = $this->parent('BOUND-PARENT-B', 'Rodzic B', '<p>Opis rodzica B</p>');
        $variant = $this->variant('BOUND-CHILD', [
            'mode' => ProductVariantInheritanceService::MODE_PARENT,
            'parent_product_id' => $parentA->id,
        ]);
        $this->relation($parentA, $variant);
        $this->relation($parentB, $variant);

        $inheritance = app(ProductVariantInheritanceService::class);

        $this->assertTrue($inheritance->inheritsFromParent($variant, $parentA));
        $this->assertFalse($inheritance->inheritsFromParent($variant, $parentB));
        $this->assertFalse($inheritance->synchronizeVariant($parentB, $variant));
        $this->assertSame('<p>Własny opis wariantu</p>', data_get($variant->refresh()->masterData(), 'content.pl.description'));

        $this->assertTrue($inheritance->synchronizeVariant($parentA, $variant));
        $variant->refresh();
        $this->assertSame('<p>Opis rodzica A</p>', data_get($variant->masterData(), 'content.pl.description'));
        $this->assertSame($parentA->id, data_get($variant->masterData(), 'inheritance.parent_product_id'));

        $resolved = $inheritance->inheritedMasterData($parentA, $variant->masterData());
        $this->assertSame('<p>Opis rodzica A</p>', data_get($resolved, 'content.pl.description'));
    }

    public function test_bound_parent_must_have_an_actual_variant_relation(): void
    {
        $relatedParent = $this->parent('ACTUAL-PARENT', 'Powiązany rodzic', '<p>Powiązany</p>');
        $unrelatedParent = $this->parent('FORGED-PARENT', 'Niepowiązany rodzic', '<p>Niepowiązany</p>');
        $variant = $this->variant('FORGED-CHILD', [
            'mode' => ProductVariantInheritanceService::MODE_PARENT,
            'parent_product_id' => $unrelatedParent->id,
        ]);
        $this->relation($relatedParent, $variant);

        $inheritance = app(ProductVariantInheritanceService::class);

        $this->assertFalse($inheritance->inheritsFromParent($variant, $relatedParent));
        $this->assertFalse($inheritance->inheritsFromParent($variant, $unrelatedParent));
        $this->assertFalse($inheritance->synchronizeVariant($relatedParent, $variant));
        $this->assertFalse($inheritance->synchronizeVariant($unrelatedParent, $variant));
        $this->assertSame('<p>Własny opis wariantu</p>', data_get($variant->refresh()->masterData(), 'content.pl.description'));
    }

    public function test_ambiguous_legacy_marker_is_not_synchronized_by_service_or_migration(): void
    {
        $parentA = $this->parent('LEGACY-PARENT-A', 'Stary rodzic A', '<p>Stary A</p>');
        $parentB = $this->parent('LEGACY-PARENT-B', 'Stary rodzic B', '<p>Stary B</p>');
        $variant = $this->variant('LEGACY-AMBIGUOUS-CHILD', [
            'mode' => ProductVariantInheritanceService::MODE_PARENT,
        ]);
        $metadata = [
            'copied_from_relation_id' => 900,
            'copied_at' => '2026-07-13T10:00:00+00:00',
        ];
        $this->relation($parentA, $variant, $metadata);
        $this->relation($parentB, $variant, $metadata);

        $inheritance = app(ProductVariantInheritanceService::class);

        $this->assertFalse($inheritance->inheritsFromParent($variant, $parentA));
        $this->assertFalse($inheritance->inheritsFromParent($variant, $parentB));
        $inheritance->synchronizeFamily($parentA);
        $inheritance->synchronizeFamily($parentB);
        $this->runLegacyPromotionMigration();

        $variant->refresh();
        $this->assertSame('<p>Własny opis wariantu</p>', data_get($variant->masterData(), 'content.pl.description'));
        $this->assertNull(data_get($variant->masterData(), 'inheritance.parent_product_id'));
    }

    public function test_migration_binds_only_unambiguous_legacy_inheritance_to_its_parent(): void
    {
        $markedParent = $this->parent('LEGACY-MARKED-PARENT', 'Rodzic starego markera', '<p>Treść markera</p>');
        $markedVariant = $this->variant('LEGACY-MARKED-CHILD', [
            'mode' => ProductVariantInheritanceService::MODE_PARENT,
        ]);
        $this->relation($markedParent, $markedVariant);

        $copiedParent = $this->parent('LEGACY-COPY-PARENT-2', 'Rodzic starej kopii', '<p>Treść kopii</p>');
        $copiedVariant = $this->variant('LEGACY-COPY-CHILD-2');
        $this->relation($copiedParent, $copiedVariant, [
            'copied_from_relation_id' => 901,
            'copied_at' => '2026-07-13T10:00:00+00:00',
        ]);

        $this->runLegacyPromotionMigration();

        $markedVariant->refresh();
        $this->assertSame($markedParent->id, data_get($markedVariant->masterData(), 'inheritance.parent_product_id'));
        $this->assertSame('<p>Treść markera</p>', data_get($markedVariant->masterData(), 'content.pl.description'));

        $copiedVariant->refresh();
        $this->assertSame($copiedParent->id, data_get($copiedVariant->masterData(), 'inheritance.parent_product_id'));
        $this->assertSame('<p>Treść kopii</p>', data_get($copiedVariant->masterData(), 'content.pl.description'));
    }

    public function test_inherited_size_axis_canonicalizes_every_localized_legacy_option(): void
    {
        $parent = Product::query()->create([
            'sku' => 'LOCALIZED-SIZE-PARENT',
            'name' => 'Produkt rozmiarowy',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'parameters' => [
                    ['name' => 'Rozmiar', 'value' => 'S/M | M/L', 'variation' => true],
                ],
                'content' => [
                    'pl' => ['name' => 'Produkt rozmiarowy'],
                    'en' => ['name' => 'Sized product'],
                ],
            ]],
        ]);
        $variantMaster = [
            'source' => 'erp',
            'product_type' => 'variation',
            'variant_attribute' => 'BLVariant',
            'parameters' => [[
                'name' => 'BLVariant',
                'name_en' => 'Variant',
                'value' => 's-m',
                'value_pl' => 's-m',
                'value_en' => 's-m',
                'translations' => [
                    'pl' => ['value' => 's-m'],
                    'en' => ['value' => 's-m'],
                    'de' => ['value' => 's-m'],
                ],
                'variation' => true,
            ]],
            'inheritance' => [
                'mode' => ProductVariantInheritanceService::MODE_PARENT,
                'parent_product_id' => $parent->id,
            ],
        ];

        $resolved = app(ProductVariantInheritanceService::class)
            ->inheritedMasterData($parent, $variantMaster);
        $option = collect((array) data_get($resolved, 'parameters'))
            ->firstWhere('variation', true);

        $this->assertSame('Rozmiar', data_get($resolved, 'variant_attribute'));
        $this->assertSame('Rozmiar', data_get($option, 'name'));
        $this->assertSame('Size', data_get($option, 'name_en'));
        $this->assertSame('S/M', data_get($option, 'value'));
        $this->assertSame('S/M', data_get($option, 'value_pl'));
        $this->assertSame('S/M', data_get($option, 'value_en'));
        $this->assertSame('S/M', data_get($option, 'translations.pl.value'));
        $this->assertSame('S/M', data_get($option, 'translations.en.value'));
        $this->assertSame('S/M', data_get($option, 'translations.de.value'));
        $this->assertSame('Produkt rozmiarowy - S/M', data_get($resolved, 'content.pl.name'));
        $this->assertSame('Sized product - S/M', data_get($resolved, 'content.en.name'));
    }

    public function test_inheritance_does_not_recover_a_generic_axis_from_one_variant_snapshot(): void
    {
        $parent = Product::query()->create([
            'sku' => 'GENERIC-AXIS-PARENT',
            'name' => 'Rodzina starej osi',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'BLVariant',
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => 's-m | m-l', 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => 'S/M | M/L', 'variation' => false],
                ],
                'content' => ['pl' => ['name' => 'Rodzina starej osi']],
            ]],
        ]);
        $variantMaster = [
            'source' => 'erp',
            'product_type' => 'variation',
            'variant_attribute' => 'BLVariant',
            'parameters' => [
                ['name' => 'BLVariant', 'value' => 's-m', 'variation' => true],
                ['name' => 'Rozmiar', 'value' => 'S/M', 'variation' => false],
            ],
            'inheritance' => [
                'mode' => ProductVariantInheritanceService::MODE_PARENT,
                'parent_product_id' => $parent->id,
            ],
        ];

        $resolved = app(ProductVariantInheritanceService::class)
            ->inheritedMasterData($parent, $variantMaster);

        $this->assertSame('BLVariant', data_get($resolved, 'variant_attribute'));
        $this->assertSame('s-m', data_get(
            collect((array) data_get($resolved, 'parameters'))->firstWhere('variation', true),
            'value',
        ));
    }

    private function parent(string $sku, string $name, string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'publication_date' => '2026-07-14T12:00',
                'content' => ['pl' => [
                    'name' => $name,
                    'description' => $description,
                ]],
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $inheritance
     */
    private function variant(string $sku, ?array $inheritance = null): Product
    {
        $master = [
            'source' => 'erp',
            'product_type' => 'variation',
            'variant_attribute' => 'Rozmiar',
            'parameters' => [
                ['name' => 'Rozmiar', 'value' => 'S', 'variation' => true],
            ],
            'content' => ['pl' => [
                'name' => 'Stary wariant S',
                'description' => '<p>Własny opis wariantu</p>',
            ]],
        ];

        if ($inheritance !== null) {
            $master['inheritance'] = $inheritance;
        }

        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Stary wariant S',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => $master],
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function relation(Product $parent, Product $variant, array $metadata = []): ProductRelation
    {
        return ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
            'metadata' => $metadata,
        ]);
    }

    private function runLegacyPromotionMigration(): void
    {
        $migration = require database_path('migrations/2026_07_14_000002_promote_legacy_copied_product_variants.php');

        $this->assertInstanceOf(Migration::class, $migration);
        $migration->up();
    }
}
