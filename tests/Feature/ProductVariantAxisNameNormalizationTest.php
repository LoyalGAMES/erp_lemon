<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Services\Products\ProductVariantAxisNameResolver;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProductVariantAxisNameNormalizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{string,string}>
     */
    public static function sizeAxisInputProvider(): iterable
    {
        yield 'canonical' => ['Rozmiar', 'CANONICAL'];
        yield 'plural' => ['Rozmiary', 'PLURAL'];
        yield 'generic Polish' => ['wariant', 'GENERIC'];
        yield 'BaseLinker' => ['BLVariant', 'BASELINKER'];
    }

    #[DataProvider('sizeAxisInputProvider')]
    public function test_product_creation_persists_one_canonical_size_axis(
        string $submittedAxis,
        string $sku,
    ): void {
        $this->post(route('products.store'), [
            'name' => 'Rodzina '.$sku,
            'sku' => $sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => 1,
            'product_type' => 'variable',
            'variant_attribute' => $submittedAxis,
            'new_variant_values' => ['s', 'm/l'],
            'parameters' => [
                'name' => [$submittedAxis],
                'value' => ['s | m/l'],
                'variation' => ['1'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $parent = Product::query()->where('sku', $sku)->firstOrFail();
        $parent->load('variantChildren');

        $this->assertSame('Rozmiar', data_get($parent->masterData(), 'variant_attribute'));
        $this->assertSame(['Rozmiar'], collect((array) data_get($parent->masterData(), 'parameters', []))
            ->pluck('name')->unique()->values()->all());
        $this->assertCount(2, $parent->variantChildren);

        foreach ($parent->variantChildren as $variant) {
            $this->assertSame('Rozmiar', data_get($variant->masterData(), 'variant_attribute'));
            $this->assertSame('Rozmiar', data_get($variant->masterData(), 'parameters.0.name'));
            $relation = ProductRelation::query()->findOrFail($variant->pivot->id);
            $this->assertSame('Rozmiar', data_get($relation->metadata, 'variant_attribute'));
        }

        $this->assertDatabaseHas('product_parameter_definitions', [
            'name' => 'Rozmiar',
            'is_variant' => true,
        ]);
        $this->assertFalse(ProductParameterDefinition::query()
            ->whereIn('name', ['Rozmiary', 'wariant', 'BLVariant'])
            ->exists());
    }

    public function test_generic_color_family_is_not_reclassified_as_size(): void
    {
        $this->post(route('products.store'), [
            'name' => 'Rodzina kolorystyczna',
            'sku' => 'COLOR-FAMILY',
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => 1,
            'product_type' => 'variable',
            'variant_attribute' => 'BLVariant',
            'new_variant_values' => ['Czarny', 'Biały'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $parent = Product::query()->where('sku', 'COLOR-FAMILY')->firstOrFail();
        $parent->load('variantChildren');

        $this->assertSame('BLVariant', data_get($parent->masterData(), 'variant_attribute'));
        $this->assertSame(['BLVariant'], $parent->variantChildren
            ->map(fn (Product $variant): mixed => data_get($variant->masterData(), 'variant_attribute'))
            ->unique()
            ->values()
            ->all());
        $this->assertDatabaseHas('product_parameter_definitions', ['name' => 'BLVariant']);
        $this->assertDatabaseMissing('product_parameter_definitions', ['name' => 'Rozmiar']);
    }

    public function test_editor_uses_canonical_size_dictionary_order_before_legacy_alias_values(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiary',
            'slug' => 'rozmiary',
            'input_type' => 'select',
            'values' => ['XL', 'M', 'S'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 1,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S', 'M', 'L'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 999,
        ]);
        $product = Product::query()->create([
            'sku' => 'CANONICAL-DICTIONARY-ORDER',
            'name' => 'Canonical dictionary order',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'simple',
            ]],
        ]);

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertViewHas('parameterOptions', function ($options): bool {
                $size = collect($options)->firstWhere('name', 'Rozmiar');

                return is_array($size)
                    && $size['values'] === ['S', 'M', 'L', 'XL']
                    && $size['input_type'] === 'select'
                    && $size['is_variant'] === true;
            });
    }

    public function test_editing_legacy_size_family_normalizes_master_and_relation_metadata(): void
    {
        $parent = Product::query()->create([
            'sku' => 'LEGACY-EDIT-PARENT',
            'name' => 'Legacy edit parent',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'BLVariant',
                'parameters' => [[
                    'name' => 'BLVariant',
                    'value' => 'S | M',
                    'variation' => true,
                ]],
            ]],
        ]);
        $child = Product::query()->create([
            'sku' => 'LEGACY-EDIT-S',
            'name' => 'Legacy edit S',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'variant_attribute' => 'BLVariant',
                'parameters' => [[
                    'name' => 'BLVariant',
                    'value' => 'S',
                    'variation' => true,
                ]],
            ]],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $child->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
            'metadata' => ['variant_attribute' => 'BLVariant', 'variant_option' => 'S'],
        ]);

        $this->put(route('products.update', $parent), [
            'name' => $parent->name,
            'sku' => $parent->sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => 1,
            'product_type' => 'variable',
            'variant_attribute' => 'BLVariant',
            'variant_skus' => [0 => $child->sku],
            'variant_sort_order' => [0 => 10],
            'parameters' => [
                'name' => ['BLVariant'],
                'value' => ['S | M'],
                'variation' => ['1'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $parent->refresh();
        $relation = ProductRelation::query()
            ->where('parent_product_id', $parent->id)
            ->where('child_product_id', $child->id)
            ->firstOrFail();

        $this->assertSame('Rozmiar', data_get($parent->masterData(), 'variant_attribute'));
        $this->assertSame('Rozmiar', data_get($parent->masterData(), 'parameters.0.name'));
        $this->assertSame('Rozmiar', data_get($relation->metadata, 'variant_attribute'));
        $this->assertSame('Rozmiar', data_get($child->fresh()->masterData(), 'variant_attribute'));
        $this->assertSame('Rozmiar', data_get($child->fresh()->masterData(), 'parameters.0.name'));
    }

    public function test_canonical_editor_axis_merges_direct_and_proven_generic_duplicates_but_keeps_color(): void
    {
        $product = Product::query()->create([
            'sku' => 'CANONICAL-ALIAS-MERGE',
            'name' => 'Canonical alias merge',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'parameters' => [],
            ]],
        ]);

        $this->put(route('products.update', $product), [
            'name' => $product->name,
            'sku' => $product->sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => 1,
            'product_type' => 'variable',
            'variant_attribute' => 'Rozmiar',
            'parameters' => [
                'name' => ['Rozmiary', 'Rozmiar', 'Size', 'wariant', 'BLVariant'],
                'value' => ['XL | M', 'S | M', 'L', 'XS', 'Czarny | Biały'],
                'variation' => ['1', '1', '1', '1', '0'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $master = $product->fresh()->masterData();
        $parameters = collect((array) data_get($master, 'parameters', []));
        $this->assertSame('Rozmiar', data_get($master, 'variant_attribute'));
        $this->assertSame(1, $parameters->where('name', 'Rozmiar')->count());
        $this->assertSame(
            ['XL', 'M', 'S', 'L', 'XS'],
            app(ProductVariantAxisNameResolver::class)
                ->optionTokens([$parameters->firstWhere('name', 'Rozmiar')['value'] ?? null])
                ->all(),
        );
        $this->assertSame('Czarny | Biały', $parameters->firstWhere('name', 'BLVariant')['value'] ?? null);
        $this->assertFalse($parameters->contains(
            fn (array $parameter): bool => in_array($parameter['name'] ?? null, ['Rozmiary', 'Size', 'wariant'], true),
        ));
    }

    public function test_copy_merges_all_size_aliases_without_consuming_blvariant_color(): void
    {
        $source = Product::query()->create([
            'sku' => 'COPY-SIZE-ALIASES',
            'name' => 'Copy size aliases',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiary',
                'content' => ['pl' => ['name' => 'Copy size aliases']],
                'parameters' => [
                    ['name' => 'Rozmiary', 'value' => 'XL | M', 'variation' => true],
                    ['name' => 'Rozmiar', 'name_en' => 'Size', 'value' => 'S | M', 'variation' => true],
                    ['name' => 'Size', 'value' => 'L', 'variation' => true],
                    ['name' => 'wariant', 'value' => 'XS', 'variation' => true],
                    ['name' => 'BLVariant', 'value' => 'Czarny | Biały', 'variation' => false],
                ],
            ]],
        ]);
        $variant = Product::query()->create([
            'sku' => 'COPY-SIZE-ALIASES-M',
            'name' => 'Copy size aliases M',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'variant_attribute' => 'Sizes',
                'parameters' => [[
                    'name' => 'Sizes',
                    'value' => 'M',
                    'variation' => true,
                ]],
            ]],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $source->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
            'metadata' => ['variant_attribute' => 'Rozmiary', 'variant_option' => 'M'],
        ]);

        $this->post(route('products.duplicate', $source))->assertRedirect();

        $copy = Product::query()->get()->first(
            fn (Product $candidate): bool => (int) data_get(
                $candidate->masterData(),
                'copy.created_from_product_id',
            ) === $source->id,
        );
        $this->assertInstanceOf(Product::class, $copy);
        $parameters = collect((array) data_get($copy->masterData(), 'parameters', []));
        $this->assertSame('Rozmiar', data_get($copy->masterData(), 'variant_attribute'));
        $this->assertSame(1, $parameters->where('name', 'Rozmiar')->count());
        $this->assertSame('Size', $parameters->firstWhere('name', 'Rozmiar')['name_en'] ?? null);
        $this->assertSame(
            ['S', 'M', 'L', 'XL', 'XS'],
            app(ProductVariantAxisNameResolver::class)
                ->optionTokens([$parameters->firstWhere('name', 'Rozmiar')['value'] ?? null])
                ->all(),
        );
        $this->assertSame('Czarny | Biały', $parameters->firstWhere('name', 'BLVariant')['value'] ?? null);
        $this->assertFalse($parameters->contains(
            fn (array $parameter): bool => in_array($parameter['name'] ?? null, ['Rozmiary', 'Size', 'Sizes', 'wariant'], true),
        ));
        $copy->load('variantChildren');
        $variantCopy = $copy->variantChildren->sole();
        $this->assertSame('Rozmiar', data_get($variantCopy->masterData(), 'variant_attribute'));
        $copiedRelation = ProductRelation::query()->findOrFail($variantCopy->pivot?->id);
        $this->assertSame('Rozmiar', data_get($copiedRelation->metadata, 'variant_attribute'));
    }

    public function test_marketplace_mapping_does_not_block_child_size_axis_normalization(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'MARKETPLACE-ONLY',
            'name' => 'Marketplace only',
            'type' => 'marketplace',
            'is_active' => true,
        ]);
        $parent = Product::query()->create([
            'sku' => 'UNMAPPED-PARENT',
            'name' => 'Unmapped parent',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'simple',
            ]],
        ]);
        $child = Product::query()->create([
            'sku' => 'MARKETPLACE-LEGACY-S',
            'name' => 'Marketplace legacy S',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'variant_attribute' => 'Rozmiary',
                'parameters' => [[
                    'name' => 'Rozmiary',
                    'value' => 'S',
                    'variation' => true,
                ]],
            ]],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $child->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => 'BL-CHILD-S',
            'external_sku' => $child->sku,
            'stock_sync_enabled' => true,
        ]);

        $this->post(route('products.relations.store', $parent), [
            'relation_type' => 'variant',
            'child_sku' => $child->sku,
            'variant_attribute' => 'Rozmiar',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $relation = ProductRelation::query()
            ->where('parent_product_id', $parent->id)
            ->where('child_product_id', $child->id)
            ->firstOrFail();
        $child->refresh();
        $this->assertSame('Rozmiar', data_get($parent->fresh()->masterData(), 'variant_attribute'));
        $this->assertSame('Rozmiar', data_get($child->masterData(), 'variant_attribute'));
        $this->assertSame('Rozmiar', data_get($child->masterData(), 'parameters.0.name'));
        $this->assertSame('Rozmiar', data_get($relation->metadata, 'variant_attribute'));
    }

    public function test_direct_edit_of_mapped_woo_child_waits_for_synchronized_repair_revision(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'WOO-CHILD-GUARD',
            'name' => 'Woo child guard',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $child = Product::query()->create([
            'sku' => 'WOO-MAPPED-LEGACY-CHILD',
            'name' => 'Woo mapped legacy child',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'variant_attribute' => 'BLVariant',
                'parameters' => [[
                    'name' => 'BLVariant',
                    'value' => 'S',
                    'variation' => true,
                ]],
            ]],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $child->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '701',
            'external_variation_id' => '702',
            'external_sku' => $child->sku,
            'stock_sync_enabled' => true,
        ]);
        $payload = [
            'name' => $child->name,
            'sku' => $child->sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => 1,
            'product_type' => 'variation',
            'variant_attribute' => 'Rozmiar',
            'parameters' => [
                'name' => ['Rozmiar'],
                'value' => ['S'],
                'variation' => ['1'],
            ],
        ];

        $this->put(route('products.update', $child), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $child->refresh();
        $this->assertSame('BLVariant', data_get($child->masterData(), 'variant_attribute'));
        $this->assertSame('BLVariant', data_get($child->masterData(), 'parameters.0.name'));

        $attributes = (array) $child->attributes;
        data_set(
            $attributes,
            'master.'.WooOwnedVariantAxisRepairService::STATE_PATH.'.revision',
            WooOwnedVariantAxisRepairService::REVISION,
        );
        $child->forceFill(['attributes' => $attributes])->save();

        $this->put(route('products.update', $child), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $child->refresh();
        $this->assertSame('Rozmiar', data_get($child->masterData(), 'variant_attribute'));
        $this->assertSame('Rozmiar', data_get($child->masterData(), 'parameters.0.name'));
    }

    public function test_mapped_legacy_editor_keeps_the_informational_size_row_until_remote_repair(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'WOO-PARALLEL-SIZE-GUARD',
            'name' => 'Woo parallel size guard',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $parent = Product::query()->create([
            'sku' => 'WOO-PARALLEL-SIZE-PARENT',
            'name' => 'Woo parallel size parent',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'wariant',
                'parameters' => [
                    ['name' => 'wariant', 'value' => 'S/M | M/L', 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => 'S/M | M/L', 'variation' => false],
                ],
            ]],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '801',
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
        ]);

        $this->put(route('products.update', $parent), [
            'name' => $parent->name,
            'sku' => $parent->sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => 1,
            'product_type' => 'variable',
            // The selector presents the canonical label even though the
            // remote-first guard must retain the old live axis for now.
            'variant_attribute' => 'Rozmiar',
            'parameters' => [
                'name' => ['wariant', 'Rozmiar'],
                'value' => ['S/M | M/L', 'S/M | M/L'],
                'variation' => [0 => '1'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $parent->fresh();
        $parameters = collect((array) data_get($fresh->masterData(), 'parameters', []));
        $this->assertSame('wariant', data_get($fresh->masterData(), 'variant_attribute'));
        $this->assertCount(2, $parameters);
        $this->assertTrue((bool) ($parameters->firstWhere('name', 'wariant')['variation'] ?? false));
        $this->assertFalse((bool) ($parameters->firstWhere('name', 'Rozmiar')['variation'] ?? true));
        $this->assertSame('S/M | M/L', $parameters->firstWhere('name', 'Rozmiar')['value'] ?? null);
    }

    public function test_color_generic_child_is_rejected_without_partial_relation_or_editor_write(): void
    {
        $parent = Product::query()->create([
            'sku' => 'SIZE-PARENT-FAIL-CLOSED',
            'name' => 'Size parent fail closed',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'parameters' => [[
                    'name' => 'Rozmiar',
                    'value' => 'S | M',
                    'variation' => true,
                ]],
            ]],
        ]);
        $child = Product::query()->create([
            'sku' => 'GENERIC-COLOR-CHILD',
            'name' => 'Generic color child',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'variant_attribute' => 'BLVariant',
                'parameters' => [[
                    'name' => 'BLVariant',
                    'value' => 'Czarny | Biały',
                    'variation' => true,
                ]],
            ]],
        ]);
        $parentAttributes = $parent->attributes;
        $childAttributes = $child->attributes;

        $this->post(route('products.relations.store', $parent), [
            'relation_type' => 'variant',
            'child_sku' => $child->sku,
            'variant_attribute' => 'Rozmiar',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseMissing('product_relations', [
            'parent_product_id' => $parent->id,
            'child_product_id' => $child->id,
            'relation_type' => 'variant',
        ]);
        $this->assertSame($parentAttributes, $parent->fresh()->attributes);
        $this->assertSame($childAttributes, $child->fresh()->attributes);

        $this->put(route('products.update', $parent), [
            'name' => 'This name must roll back',
            'sku' => $parent->sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => 1,
            'product_type' => 'variable',
            'variant_attribute' => 'Rozmiar',
            'variant_skus' => [0 => $child->sku],
            'variant_sort_order' => [0 => 10],
            'parameters' => [
                'name' => ['Rozmiar'],
                'value' => ['S | M'],
                'variation' => ['1'],
            ],
        ])->assertRedirect()->assertSessionHasErrors('variant_skus');

        $this->assertDatabaseMissing('product_relations', [
            'parent_product_id' => $parent->id,
            'child_product_id' => $child->id,
            'relation_type' => 'variant',
        ]);
        $parent->refresh();
        $child->refresh();
        $this->assertSame('Size parent fail closed', $parent->name);
        $this->assertSame($parentAttributes, $parent->attributes);
        $this->assertSame($childAttributes, $child->attributes);
    }

    public function test_mapped_legacy_family_waits_for_remote_repair_before_editor_canonicalization(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'WOO-REMOTE-FIRST',
            'name' => 'Woo remote first',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $parent = Product::query()->create([
            'sku' => 'MAPPED-LEGACY-PARENT',
            'name' => 'Mapped legacy parent',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'BLVariant',
                'parameters' => [[
                    'name' => 'BLVariant',
                    'value' => 'S | M',
                    'variation' => true,
                ]],
            ]],
        ]);
        $child = Product::query()->create([
            'sku' => 'MAPPED-LEGACY-S',
            'name' => 'Mapped legacy S',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'variant_attribute' => 'BLVariant',
                'parameters' => [[
                    'name' => 'BLVariant',
                    'value' => 'S',
                    'variation' => true,
                ]],
            ]],
        ]);
        $relation = ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $child->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
            'metadata' => ['variant_attribute' => 'BLVariant', 'variant_option' => 'S'],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '501',
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $child->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '501',
            'external_variation_id' => '502',
            'external_sku' => $child->sku,
            'stock_sync_enabled' => true,
        ]);

        $this->put(route('products.update', $parent), [
            'name' => $parent->name,
            'sku' => $parent->sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => 1,
            'product_type' => 'variable',
            'variant_attribute' => 'Rozmiar',
            'variant_skus' => [0 => $child->sku],
            'variant_sort_order' => [0 => 10],
            'parameters' => [
                'name' => ['Rozmiar'],
                'value' => ['S | M'],
                'variation' => ['1'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $parent->refresh();
        $child->refresh();
        $relation->refresh();
        $this->assertSame('BLVariant', data_get($parent->masterData(), 'variant_attribute'));
        $this->assertSame('BLVariant', data_get($parent->masterData(), 'parameters.0.name'));
        $this->assertSame('BLVariant', data_get($child->masterData(), 'variant_attribute'));
        $this->assertSame('BLVariant', data_get($child->masterData(), 'parameters.0.name'));
        $this->assertSame('BLVariant', data_get($relation->metadata, 'variant_attribute'));

        $canonicalChild = Product::query()->create([
            'sku' => 'UNMAPPED-CANONICAL-M',
            'name' => 'Unmapped canonical M',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'variant_attribute' => 'Rozmiar',
                'parameters' => [[
                    'name' => 'Rozmiar',
                    'value' => 'M',
                    'variation' => true,
                ]],
            ]],
        ]);

        $this->put(route('products.update', $parent), [
            'name' => $parent->name,
            'sku' => $parent->sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'is_active' => 1,
            'product_type' => 'variable',
            'variant_attribute' => 'Rozmiar',
            'variant_skus' => [0 => $child->sku, 1 => $canonicalChild->sku],
            'variant_sort_order' => [0 => 10, 1 => 20],
            'parameters' => [
                'name' => ['Rozmiar'],
                'value' => ['S | M'],
                'variation' => ['1'],
            ],
        ])->assertRedirect()->assertSessionHasErrors('variant_skus');

        $this->assertDatabaseMissing('product_relations', [
            'parent_product_id' => $parent->id,
            'child_product_id' => $canonicalChild->id,
            'relation_type' => 'variant',
        ]);
        $this->assertSame('BLVariant', data_get($parent->fresh()->masterData(), 'variant_attribute'));

        $this->post(route('products.relations.store', $parent), [
            'relation_type' => 'variant',
            'child_sku' => $canonicalChild->sku,
            'variant_attribute' => 'Rozmiar',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseMissing('product_relations', [
            'parent_product_id' => $parent->id,
            'child_product_id' => $canonicalChild->id,
            'relation_type' => 'variant',
        ]);
        $this->assertSame('BLVariant', data_get($parent->fresh()->masterData(), 'variant_attribute'));
        $this->assertSame('Rozmiar', data_get($canonicalChild->fresh()->masterData(), 'variant_attribute'));
    }
}
