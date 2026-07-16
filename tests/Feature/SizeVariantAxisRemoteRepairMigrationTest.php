<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SizeVariantAxisRemoteRepairMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_canonical_dictionary_wins_over_plural_without_merging_orders(): void
    {
        Http::fake();
        $canonical = ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['XL', 'XS', 'M/L'],
            'values_en' => ['XL EN', 'XS EN', 'M/L EN'],
            'is_variant' => false,
            'sort_order' => 71,
        ]);
        $plural = ProductParameterDefinition::query()->create([
            'name' => 'Rozmiary',
            'name_en' => 'Sizes',
            'slug' => 'rozmiary',
            'input_type' => 'select',
            'values' => ['XS', 'M/L', 'XL'],
            'values_en' => ['XS plural', 'M/L plural', 'XL plural'],
            'is_variant' => false,
            'sort_order' => 12,
        ]);
        $pluralBefore = $this->rows('product_parameter_definitions', [$plural->id]);

        $this->runMigration();

        $canonical->refresh();
        $this->assertTrue($canonical->is_variant);
        $this->assertSame(['XL', 'XS', 'M/L'], $canonical->values);
        $this->assertSame(['XL EN', 'XS EN', 'M/L EN'], $canonical->values_en);
        $this->assertSame(71, $canonical->sort_order);
        $this->assertSame(
            $pluralBefore,
            $this->rows('product_parameter_definitions', [$plural->id]),
        );
        Http::assertNothingSent();
    }

    public function test_english_only_size_dictionary_is_canonicalized_in_place_without_losing_order(): void
    {
        Http::fake();
        $definition = ProductParameterDefinition::query()->create([
            'name' => 'Sizes',
            'name_en' => 'Size',
            'slug' => 'sizes',
            'input_type' => 'select',
            'values' => ['XL', 'XS', 'M/L'],
            'values_en' => ['XL EN', 'XS EN', 'M/L EN'],
            'is_variant' => false,
            'is_required' => true,
            'sort_order' => 43,
            'metadata' => ['operator_note' => 'preserve english dictionary'],
        ]);
        $id = $definition->id;

        $this->runMigration();

        $definition->refresh();
        $this->assertSame($id, $definition->id);
        $this->assertSame('Rozmiar', $definition->name);
        $this->assertSame('Size', $definition->name_en);
        $this->assertSame('rozmiar', $definition->slug);
        $this->assertTrue($definition->is_variant);
        $this->assertTrue($definition->is_required);
        $this->assertSame(43, $definition->sort_order);
        $this->assertSame(['XL', 'XS', 'M/L'], $definition->values);
        $this->assertSame(['XL EN', 'XS EN', 'M/L EN'], $definition->values_en);
        $this->assertSame('preserve english dictionary', data_get(
            $definition->metadata,
            'operator_note',
        ));
        $this->assertSame(1, ProductParameterDefinition::query()->count());
        Http::assertNothingSent();
    }

    public function test_generic_color_family_and_dictionary_are_not_promoted_to_size(): void
    {
        Http::fake();
        $genericDefinition = ProductParameterDefinition::query()->create([
            'name' => 'BLVariant',
            'name_en' => 'Variant',
            'slug' => 'blvariant',
            'input_type' => 'select',
            'values' => ['Czarny', 'Biały'],
            'values_en' => ['Black', 'White'],
            'is_variant' => true,
            'sort_order' => 18,
        ]);
        $channel = $this->wooChannel();
        $parent = $this->product('COLOR-PARENT', 'erp', 'variable', 'BLVariant', [[
            'name' => 'BLVariant',
            'value' => 'Czarny | Biały',
            'variation' => true,
        ]]);
        $children = collect(['Czarny', 'Biały'])->map(fn (string $color, int $index): Product => $this->product(
            'COLOR-'.($index + 1),
            'erp',
            'variation',
            'BLVariant',
            [[
                'name' => 'BLVariant',
                'value' => $color,
                'variation' => true,
            ]],
        ));
        $relations = $children->map(fn (Product $child, int $index): ProductRelation => ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $child->id,
            'relation_type' => 'variant',
            'sort_order' => ($index + 1) * 10,
            'metadata' => ['variant_attribute' => 'BLVariant', 'variant_option' => $child->masterValue('parameters.0.value')],
        ]));
        $mapping = $this->mapping($parent, $channel, '830000', null, [
            'mapping_role' => 'primary',
            'language' => 'pl',
            'operator_note' => 'leave color untouched',
        ]);
        $definitionBefore = $this->rows('product_parameter_definitions', [$genericDefinition->id]);
        $productsBefore = $this->rows('products', [$parent->id, ...$children->pluck('id')->all()]);
        $relationsBefore = $this->rows('product_relations', $relations->pluck('id')->all());
        $mappingBefore = $this->rows('product_channel_mappings', [$mapping->id]);

        $this->runMigration();

        $this->assertSame($definitionBefore, $this->rows(
            'product_parameter_definitions',
            [$genericDefinition->id],
        ));
        $this->assertFalse(ProductParameterDefinition::query()->where('name', 'Rozmiar')->exists());
        $this->assertSame($productsBefore, $this->rows(
            'products',
            [$parent->id, ...$children->pluck('id')->all()],
        ));
        $this->assertSame($relationsBefore, $this->rows(
            'product_relations',
            $relations->pluck('id')->all(),
        ));
        $this->assertSame($mappingBefore, $this->rows('product_channel_mappings', [$mapping->id]));
        $this->assertNull(data_get(
            $mapping->refresh()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
        ));
        Http::assertNothingSent();
    }

    public function test_concrete_size_plus_blvariant_color_is_rejected_before_pending_or_remote_repair(): void
    {
        Http::fake();
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S/M', 'M/L'],
            'values_en' => ['S/M', 'M/L'],
            'is_variant' => true,
        ]);
        $channel = $this->wooChannel();
        $parent = $this->product(
            'COLOR-WITH-SIZE-PARENT',
            'erp',
            'variable',
            'BLVariant',
            [
                [
                    'name' => 'BLVariant',
                    'value' => 'Czarny | Biały',
                    'variation' => true,
                ],
                [
                    'name' => 'Rozmiar',
                    'value' => 'S/M | M/L',
                    'variation' => true,
                ],
            ],
        );
        $children = collect([
            ['Czarny', 'S/M'],
            ['Biały', 'M/L'],
        ])->map(fn (array $options, int $index): Product => $this->product(
            'COLOR-WITH-SIZE-'.($index + 1),
            'erp',
            'variation',
            'BLVariant',
            [
                [
                    'name' => 'BLVariant',
                    'value' => $options[0],
                    'variation' => true,
                ],
                [
                    'name' => 'Rozmiar',
                    'value' => $options[1],
                    'variation' => true,
                ],
            ],
        ));

        foreach ($children as $index => $child) {
            ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $child->id,
                'relation_type' => 'variant',
                'sort_order' => ($index + 1) * 10,
                'metadata' => [
                    'variant_attribute' => 'BLVariant',
                    'variant_option' => $index === 0 ? 'Czarny' : 'Biały',
                ],
            ]);
        }

        $mapping = $this->mapping($parent, $channel, '835000', null, [
            'mapping_role' => 'primary',
            'language' => 'pl',
        ]);
        $repair = app(WooOwnedVariantAxisRepairService::class);

        $this->assertFalse($repair->isSizeVariantRootCandidate($parent->fresh()));

        $this->runMigration();

        $this->assertNull(data_get(
            $mapping->refresh()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
        ));
        $result = $repair->repair($parent->fresh());
        $this->assertSame('manual_review', $result['status']);
        $this->assertSame(0, $result['targets']);
        $this->assertSame(0, $result['mutations']);
        Http::assertNothingSent();
    }

    public function test_generic_numeric_size_evidence_splits_list_commas_but_preserves_decimal_commas(): void
    {
        Http::fake();
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['38,5', '40'],
            'values_en' => ['38,5', '40'],
            'is_variant' => true,
        ]);
        $channel = $this->wooChannel();
        $parent = $this->product(
            'DECIMAL-SIZE-PARENT',
            'erp',
            'variable',
            'BLVariant',
            [[
                'name' => 'BLVariant',
                'value' => '38,5, 40',
                'variation' => true,
            ]],
        );
        $children = collect(['38,5', '40'])->map(fn (string $option, int $index): Product => $this->product(
            'DECIMAL-SIZE-'.($index + 1),
            'erp',
            'variation',
            'BLVariant',
            [[
                'name' => 'BLVariant',
                'value' => $option,
                'variation' => true,
            ]],
        ));

        foreach ($children as $index => $child) {
            ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $child->id,
                'relation_type' => 'variant',
                'sort_order' => ($index + 1) * 10,
                'metadata' => [
                    'variant_attribute' => 'BLVariant',
                    'variant_option' => $index === 0 ? '38,5' : '40',
                ],
            ]);
        }

        $this->mapping($parent, $channel, '836000', null, [
            'mapping_role' => 'primary',
            'language' => 'pl',
        ]);

        $this->assertTrue(app(WooOwnedVariantAxisRepairService::class)
            ->isSizeVariantRootCandidate($parent->fresh()));
        Http::assertNothingSent();
    }

    public function test_migration_promotes_the_size_dictionary_and_queues_all_safe_aliases_without_local_family_writes(): void
    {
        Http::fake();
        CarbonImmutable::setTestNow('2026-07-16 12:00:00');

        try {
            $definition = ProductParameterDefinition::query()->create([
                'name' => 'Rozmiary',
                'name_en' => 'Sizes',
                'slug' => 'rozmiary',
                'input_type' => 'select',
                // This intentionally is not a lexical/canonical size order.
                // The migration must retain the operator-managed sequence.
                'values' => ['M/L', 'S/M', 'XS'],
                'values_en' => ['M/L EN', 'S/M EN', 'XS EN'],
                'is_variant' => false,
                'is_required' => true,
                'sort_order' => 37,
                'metadata' => ['operator_note' => 'preserve dictionary metadata'],
            ]);
            $channel = $this->wooChannel();
            $warehouse = Warehouse::query()->create([
                'code' => 'SIZE-AXIS-MIGRATION',
                'name' => 'Size axis migration',
                'type' => 'virtual',
                'is_active' => true,
            ]);
            $families = collect([
                ['Rozmiar', 'erp'],
                ['Rozmiary', 'erp'],
                ['wariant', 'erp'],
                ['BLVariant', 'erp'],
            ])->map(fn (array $row, int $index): array => $this->family(
                $channel,
                $warehouse,
                $row[0],
                $row[1],
                810000 + ($index * 10),
            ));
            $oldWoo = $this->family(
                $channel,
                $warehouse,
                'wariant',
                'woocommerce_import',
                820000,
                [
                    'maintenance' => [
                        'woo_owned_variant_axis_repair' => [
                            'revision' => 'woo_owned_size_variant_axis_2026_07_15_000017',
                            'status' => 'completed',
                            'completed_at' => '2026-07-15T20:00:00+00:00',
                        ],
                    ],
                    'operator_note' => 'preserve mapping metadata',
                ],
            );
            $families->push($oldWoo);

            $productIds = $families
                ->flatMap(fn (array $family): array => [
                    $family['parent']->id,
                    ...$family['children']->pluck('id')->all(),
                ])
                ->all();
            $mappingIds = $families
                ->flatMap(fn (array $family): array => [
                    $family['parent_mapping']->id,
                    ...$family['child_mappings']->pluck('id')->all(),
                ])
                ->all();
            $productsBefore = $this->rows('products', $productIds);
            $relationsBefore = $this->rows(
                'product_relations',
                $families->flatMap(fn (array $family): array => $family['relations']->pluck('id')->all())->all(),
            );
            $stocksBefore = $this->rows(
                'stock_balances',
                $families->flatMap(fn (array $family): array => $family['stocks']->pluck('id')->all())->all(),
            );
            $mappingIdentityBefore = ProductChannelMapping::query()
                ->whereIn('id', $mappingIds)
                ->orderBy('id')
                ->get()
                ->map(fn (ProductChannelMapping $mapping): array => $this->mappingIdentity($mapping))
                ->all();

            $this->runMigration();

            $definition->refresh();
            $this->assertSame('Rozmiar', $definition->name);
            $this->assertSame('Size', $definition->name_en);
            $this->assertSame('rozmiar', $definition->slug);
            $this->assertTrue($definition->is_variant);
            $this->assertTrue($definition->is_required);
            $this->assertSame(37, $definition->sort_order);
            $this->assertSame(['M/L', 'S/M', 'XS'], $definition->values);
            $this->assertSame(['M/L EN', 'S/M EN', 'XS EN'], $definition->values_en);
            $this->assertSame(
                'preserve dictionary metadata',
                data_get($definition->metadata, 'operator_note'),
            );

            foreach ($families as $family) {
                $mapping = $family['parent_mapping']->refresh();
                $this->assertSame(
                    WooOwnedVariantAxisRepairService::REVISION,
                    data_get($mapping->metadata, WooOwnedVariantAxisRepairService::STATE_PATH.'.revision'),
                );
                $this->assertSame('pending', data_get(
                    $mapping->metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH.'.status',
                ));
                $this->assertSame(now()->toISOString(), data_get(
                    $mapping->metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH.'.requested_at',
                ));

                foreach ($family['child_mappings'] as $childMapping) {
                    $this->assertNull(data_get(
                        $childMapping->refresh()->metadata,
                        WooOwnedVariantAxisRepairService::STATE_PATH,
                    ));
                }
            }

            $this->assertSame(
                'preserve mapping metadata',
                data_get($oldWoo['parent_mapping']->refresh()->metadata, 'operator_note'),
            );
            $this->assertSame($productsBefore, $this->rows('products', $productIds));
            $this->assertSame($relationsBefore, $this->rows(
                'product_relations',
                $families->flatMap(fn (array $family): array => $family['relations']->pluck('id')->all())->all(),
            ));
            $this->assertSame($stocksBefore, $this->rows(
                'stock_balances',
                $families->flatMap(fn (array $family): array => $family['stocks']->pluck('id')->all())->all(),
            ));
            $this->assertSame($mappingIdentityBefore, ProductChannelMapping::query()
                ->whereIn('id', $mappingIds)
                ->orderBy('id')
                ->get()
                ->map(fn (ProductChannelMapping $mapping): array => $this->mappingIdentity($mapping))
                ->all());

            $definitionUpdatedAt = $definition->updated_at;
            $requestedAt = $families
                ->mapWithKeys(fn (array $family): array => [
                    $family['parent_mapping']->id => data_get(
                        $family['parent_mapping']->refresh()->metadata,
                        WooOwnedVariantAxisRepairService::STATE_PATH.'.requested_at',
                    ),
                ])
                ->all();
            CarbonImmutable::setTestNow('2026-07-16 12:10:00');

            $this->runMigration();

            $this->assertTrue($definitionUpdatedAt->equalTo($definition->refresh()->updated_at));
            foreach ($families as $family) {
                $mapping = $family['parent_mapping']->refresh();
                $this->assertSame($requestedAt[$mapping->id], data_get(
                    $mapping->metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH.'.requested_at',
                ));
            }
            $this->assertSame($productsBefore, $this->rows('products', $productIds));
            $this->assertSame($relationsBefore, $this->rows(
                'product_relations',
                $families->flatMap(fn (array $family): array => $family['relations']->pluck('id')->all())->all(),
            ));
            $this->assertSame($stocksBefore, $this->rows(
                'stock_balances',
                $families->flatMap(fn (array $family): array => $family['stocks']->pluck('id')->all())->all(),
            ));
            Http::assertNothingSent();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_erp_only_family_is_atomically_unified_without_changing_identity_stock_price_or_relation_order(): void
    {
        Http::fake();
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S/M', 'M/L'],
            'values_en' => ['S/M', 'M/L'],
            'is_variant' => true,
            'sort_order' => 44,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'LOCAL-SIZE-FAMILY',
            'name' => 'Local size family',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $parent = $this->product(
            'LOCAL-SIZE-PARENT',
            'erp',
            'variable',
            'wariant',
            [
                [
                    'name' => 'wariant',
                    'value' => 'M/L | S/M',
                    'variation' => true,
                    'metadata' => ['legacy_axis_id' => 81],
                ],
                [
                    'name' => 'Rozmiary',
                    'name_en' => 'Sizes',
                    'value' => 'M/L | S/M',
                    'value_en' => 'M/L | S/M',
                    'variation' => false,
                    'metadata' => ['canonical_source' => 'operator'],
                ],
                [
                    'name' => 'Skład',
                    'value' => 'wełna',
                    'variation' => false,
                ],
            ],
            [
                'master' => [
                    'prices' => [
                        'retail_price_pln' => 899.99,
                        'sale_price_pln' => 799.99,
                    ],
                ],
                'woocommerce_attributes' => [
                    ['id' => 6, 'name' => 'wariant', 'options' => ['M/L', 'S/M']],
                ],
            ],
        );
        $children = collect([
            ['s-m', 'wariant', 73],
            ['M/L', 'BLVariant', 11],
        ])->map(function (array $row, int $index) use ($parent): array {
            [$option, $alias, $sortOrder] = $row;
            $child = $this->product(
                'LOCAL-SIZE-'.($index + 1),
                'erp',
                'variation',
                $alias,
                [[
                    'name' => $alias,
                    'value' => $option,
                    'variation' => true,
                    'metadata' => ['legacy_child_axis' => $alias],
                ]],
                [
                    'master' => [
                        'family_parent_sku' => $parent->sku,
                        'prices' => [
                            'retail_price_pln' => 899.99 + $index,
                            'sale_price_pln' => 799.99 + $index,
                        ],
                    ],
                    'woocommerce_variation' => [
                        'id' => 3962 + $index,
                        'attributes' => [['name' => $alias, 'option' => $option]],
                    ],
                ],
            );
            $relation = ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $child->id,
                'relation_type' => 'variant',
                'sort_order' => $sortOrder,
                'metadata' => [
                    'variant_attribute' => $alias,
                    'variant_option' => $option,
                    'operator_note' => 'keep relation metadata '.$index,
                ],
            ]);

            return compact('child', 'relation');
        });
        $stocks = collect([$parent, ...$children->pluck('child')->all()])->map(
            fn (Product $product, int $index): StockBalance => StockBalance::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity_on_hand' => 17 + $index,
                'quantity_reserved' => $index,
                'quantity_available' => 17,
            ]),
        );
        $productIds = [$parent->id, ...$children->pluck('child.id')->all()];
        $relationIds = $children->pluck('relation.id')->all();
        $stockBefore = $this->rows('stock_balances', $stocks->pluck('id')->all());
        $identityBefore = $this->rowsWithout('products', $productIds, ['attributes']);
        $relationIdentityBefore = $this->rowsWithout(
            'product_relations',
            $relationIds,
            ['metadata'],
        );
        $rawParentWoo = data_get($parent->attributes, 'woocommerce_attributes');
        $rawChildWoo = $children->map(fn (array $row): mixed => data_get(
            $row['child']->attributes,
            'woocommerce_variation',
        ))->all();

        $this->runMigration();

        $parent->refresh();
        $parentParameters = collect((array) data_get($parent->attributes, 'master.parameters'));
        $sizeParameters = $parentParameters->where('name', 'Rozmiar')->values();
        $this->assertCount(1, $sizeParameters);
        $this->assertSame('S/M | M/L', $sizeParameters->first()['value']);
        $this->assertSame('S/M | M/L', $sizeParameters->first()['value_en']);
        $this->assertTrue($sizeParameters->first()['variation']);
        $this->assertSame(81, data_get($sizeParameters->first(), 'metadata.legacy_axis_id'));
        $this->assertSame('operator', data_get(
            $sizeParameters->first(),
            'metadata.canonical_source',
        ));
        $this->assertSame('Rozmiar', data_get($parent->attributes, 'master.variant_attribute'));
        $this->assertSame(899.99, data_get($parent->attributes, 'master.prices.retail_price_pln'));
        $this->assertSame(799.99, data_get($parent->attributes, 'master.prices.sale_price_pln'));
        $this->assertSame($rawParentWoo, data_get($parent->attributes, 'woocommerce_attributes'));
        $this->assertSame('wełna', $parentParameters->firstWhere('name', 'Skład')['value']);

        foreach ($children->values() as $index => $row) {
            $child = $row['child']->refresh();
            $relation = $row['relation']->refresh();
            $expectedOption = $index === 0 ? 'S/M' : 'M/L';
            $parameters = collect((array) data_get($child->attributes, 'master.parameters'));

            $this->assertSame('Rozmiar', data_get($child->attributes, 'master.variant_attribute'));
            $this->assertCount(1, $parameters);
            $this->assertSame('Rozmiar', $parameters->first()['name']);
            $this->assertSame($expectedOption, $parameters->first()['value']);
            $this->assertSame('Rozmiar', data_get($relation->metadata, 'variant_attribute'));
            $this->assertSame($expectedOption, data_get($relation->metadata, 'variant_option'));
            $this->assertSame(
                'keep relation metadata '.$index,
                data_get($relation->metadata, 'operator_note'),
            );
            $this->assertSame($rawChildWoo[$index], data_get(
                $child->attributes,
                'woocommerce_variation',
            ));
            $this->assertSame(899.99 + $index, data_get(
                $child->attributes,
                'master.prices.retail_price_pln',
            ));
            $this->assertSame(799.99 + $index, data_get(
                $child->attributes,
                'master.prices.sale_price_pln',
            ));
        }

        $this->assertSame($stockBefore, $this->rows(
            'stock_balances',
            $stocks->pluck('id')->all(),
        ));
        $this->assertSame(
            $identityBefore,
            $this->rowsWithout('products', $productIds, ['attributes']),
        );
        $this->assertSame(
            $relationIdentityBefore,
            $this->rowsWithout('product_relations', $relationIds, ['metadata']),
        );

        $productsAfterFirstRun = $this->rows('products', $productIds);
        $relationsAfterFirstRun = $this->rows('product_relations', $relationIds);
        $this->runMigration();
        $this->assertSame($productsAfterFirstRun, $this->rows('products', $productIds));
        $this->assertSame($relationsAfterFirstRun, $this->rows('product_relations', $relationIds));
        Http::assertNothingSent();
    }

    public function test_marketplace_only_family_and_explicit_standalone_are_normalized_but_simple_information_is_not(): void
    {
        Http::fake();
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['Mały', 'Duży'],
            'values_en' => ['Small', 'Large'],
            'is_variant' => true,
        ]);
        $channel = SalesChannel::query()->create([
            'code' => 'MARKETPLACE-SIZE-MIGRATION',
            'name' => 'Marketplace size migration',
            'type' => 'marketplace',
            'is_active' => true,
        ]);
        $parent = $this->product(
            'MARKETPLACE-SIZE-PARENT',
            'erp',
            'variable',
            'Rozmiary',
            [[
                'name' => 'Rozmiary',
                'name_en' => 'Sizes',
                'value' => 'Large | Small',
                'value_en' => 'Large | Small',
                'variation' => true,
            ]],
        );
        $children = collect(['Small', 'Large'])->map(function (string $option, int $index) use ($parent): array {
            $child = $this->product(
                'MARKETPLACE-SIZE-'.($index + 1),
                'erp',
                'variation',
                'Sizes',
                [[
                    'name' => 'Sizes',
                    'name_en' => 'Size',
                    'value' => $option,
                    'value_en' => $option,
                    'variation' => true,
                ]],
            );
            $relation = ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $child->id,
                'relation_type' => 'variant',
                'sort_order' => 95 - ($index * 40),
                'metadata' => [
                    'variant_attribute' => 'Sizes',
                    'variant_option' => $option,
                ],
            ]);

            return compact('child', 'relation');
        });
        $standalone = $this->product(
            'MARKETPLACE-STANDALONE-SIZE',
            'erp',
            'variable',
            'Size',
            [[
                'name' => 'Size',
                'value' => 'Large | Small',
                'value_en' => 'Large | Small',
                'variation' => true,
            ]],
        );
        $informational = $this->product(
            'MARKETPLACE-SIMPLE-INFORMATIONAL-SIZE',
            'erp',
            'simple',
            '',
            [[
                'name' => 'Rozmiary',
                'value' => 'Mały | Duży',
                'variation' => false,
            ]],
        );
        $allProducts = collect([$parent, ...$children->pluck('child')->all(), $standalone, $informational]);
        $mappings = $allProducts->values()->map(fn (Product $product, int $index): ProductChannelMapping => $this->mapping(
            $product,
            $channel,
            'MARKETPLACE-'.(9000 + $index),
            $index > 0 && $index <= $children->count() ? (string) (9100 + $index) : null,
            ['operator_note' => 'preserve marketplace mapping '.$index],
        ));
        $mappingBefore = $this->rows('product_channel_mappings', $mappings->pluck('id')->all());
        $informationalBefore = $this->rows('products', [$informational->id]);
        $relationOrders = $children->mapWithKeys(fn (array $row): array => [
            $row['relation']->id => $row['relation']->sort_order,
        ])->all();

        $this->runMigration();

        $parent->refresh();
        $parentSize = collect((array) data_get($parent->attributes, 'master.parameters'))
            ->firstWhere('name', 'Rozmiar');
        $this->assertSame('Rozmiar', data_get($parent->attributes, 'master.variant_attribute'));
        $this->assertSame('Mały | Duży', $parentSize['value']);
        $this->assertSame('Small | Large', $parentSize['value_en']);

        foreach ($children->values() as $index => $row) {
            $expectedPl = $index === 0 ? 'Mały' : 'Duży';
            $expectedEn = $index === 0 ? 'Small' : 'Large';
            $child = $row['child']->refresh();
            $parameter = collect((array) data_get($child->attributes, 'master.parameters'))->first();

            $this->assertSame('Rozmiar', data_get($child->attributes, 'master.variant_attribute'));
            $this->assertSame($expectedPl, $parameter['value']);
            $this->assertSame($expectedEn, $parameter['value_en']);
            $this->assertSame($expectedPl, data_get(
                $row['relation']->refresh()->metadata,
                'variant_option',
            ));
            $this->assertSame(
                $relationOrders[$row['relation']->id],
                $row['relation']->sort_order,
            );
        }

        $standalone->refresh();
        $standaloneParameter = collect((array) data_get(
            $standalone->attributes,
            'master.parameters',
        ))->first();
        $this->assertSame('Rozmiar', data_get(
            $standalone->attributes,
            'master.variant_attribute',
        ));
        $this->assertSame('Mały | Duży', $standaloneParameter['value']);
        $this->assertSame('Small | Large', $standaloneParameter['value_en']);
        $this->assertSame($informationalBefore, $this->rows('products', [$informational->id]));
        $this->assertSame($mappingBefore, $this->rows(
            'product_channel_mappings',
            $mappings->pluck('id')->all(),
        ));
        Http::assertNothingSent();
    }

    public function test_numeric_blvariant_and_2xs_generic_axes_are_proven_without_a_dictionary(): void
    {
        Http::fake();
        $parent = $this->product(
            'NUMERIC-BLVARIANT-PARENT',
            'erp',
            'variable',
            'BLVariant',
            [[
                'name' => 'BLVariant',
                'value' => '38,5, 40',
                'variation' => true,
            ]],
        );
        $children = collect(['38,5', '40'])->map(function (string $option, int $index) use ($parent): Product {
            $child = $this->product(
                'NUMERIC-BLVARIANT-'.($index + 1),
                'erp',
                'variation',
                'BLVariant',
                [[
                    'name' => 'BLVariant',
                    'value' => $option,
                    'variation' => true,
                ]],
            );
            ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $child->id,
                'relation_type' => 'variant',
                'sort_order' => 31 + $index,
                'metadata' => [
                    'variant_attribute' => 'BLVariant',
                    'variant_option' => $option,
                ],
            ]);

            return $child;
        });
        $twoXs = $this->product(
            'GENERIC-2XS-STANDALONE',
            'erp',
            'variable',
            'wariant',
            [[
                'name' => 'wariant',
                'value' => '2XS',
                'variation' => true,
            ]],
        );

        $this->runMigration();

        $parent->refresh();
        $this->assertSame('Rozmiar', data_get($parent->attributes, 'master.variant_attribute'));
        $this->assertSame('38,5 | 40', data_get(
            $parent->attributes,
            'master.parameters.0.value',
        ));
        foreach ($children as $index => $child) {
            $expected = $index === 0 ? '38,5' : '40';
            $child->refresh();
            $this->assertSame('Rozmiar', data_get($child->attributes, 'master.variant_attribute'));
            $this->assertSame($expected, data_get($child->attributes, 'master.parameters.0.value'));
            $this->assertSame($expected, data_get(
                $child->parentRelations()->first()?->metadata,
                'variant_option',
            ));
        }
        $twoXs->refresh();
        $this->assertSame('Rozmiar', data_get($twoXs->attributes, 'master.variant_attribute'));
        $this->assertSame('2XS', data_get($twoXs->attributes, 'master.parameters.0.value'));
        $this->assertTrue(ProductParameterDefinition::query()
            ->where('name', 'Rozmiar')
            ->where('is_variant', true)
            ->exists());
        Http::assertNothingSent();
    }

    public function test_local_color_mixed_and_woo_alias_owned_families_remain_byte_for_byte_unchanged(): void
    {
        Http::fake();
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S', 'M'],
            'values_en' => ['S', 'M'],
            'is_variant' => true,
        ]);
        $colorParent = $this->product(
            'LOCAL-COLOR-PARENT',
            'erp',
            'variable',
            'BLVariant',
            [[
                'name' => 'BLVariant',
                'value' => 'Czarny | Biały',
                'variation' => true,
            ]],
        );
        $mixedParent = $this->product(
            'LOCAL-MIXED-PARENT',
            'erp',
            'variable',
            'BLVariant',
            [
                [
                    'name' => 'BLVariant',
                    'value' => 'Czarny | Biały',
                    'variation' => true,
                ],
                [
                    'name' => 'Rozmiar',
                    'value' => 'S | M',
                    'variation' => true,
                ],
            ],
        );
        $families = collect([
            [$colorParent, [['Czarny'], ['Biały']]],
            [$mixedParent, [['Czarny', 'S'], ['Biały', 'M']]],
        ])->map(function (array $family, int $familyIndex): array {
            [$parent, $options] = $family;
            $children = collect($options)->map(function (array $values, int $index) use (
                $parent,
                $familyIndex,
            ): array {
                $parameters = [[
                    'name' => 'BLVariant',
                    'value' => $values[0],
                    'variation' => true,
                ]];

                if (isset($values[1])) {
                    $parameters[] = [
                        'name' => 'Rozmiar',
                        'value' => $values[1],
                        'variation' => true,
                    ];
                }

                $child = $this->product(
                    'LOCAL-AMBIGUOUS-'.$familyIndex.'-'.$index,
                    'erp',
                    'variation',
                    'BLVariant',
                    $parameters,
                );
                $relation = ProductRelation::query()->create([
                    'parent_product_id' => $parent->id,
                    'child_product_id' => $child->id,
                    'relation_type' => 'variant',
                    'sort_order' => 50 + $index,
                    'metadata' => [
                        'variant_attribute' => 'BLVariant',
                        'variant_option' => $values[0],
                    ],
                ]);

                return compact('child', 'relation');
            });

            return compact('parent', 'children');
        });
        $wooChannel = $this->wooChannel();
        $wooAliasParent = $this->product(
            'WOO-ALIAS-OWNED-PARENT',
            'erp',
            'variable',
            'Rozmiary',
            [[
                'name' => 'Rozmiary',
                'value' => 'S | M',
                'variation' => true,
            ]],
        );
        $wooAliasChildren = collect(['S', 'M'])->map(function (string $option, int $index) use ($wooAliasParent): array {
            $child = $this->product(
                'WOO-ALIAS-OWNED-'.($index + 1),
                'erp',
                'variation',
                'Rozmiary',
                [[
                    'name' => 'Rozmiary',
                    'value' => $option,
                    'variation' => true,
                ]],
            );
            $relation = ProductRelation::query()->create([
                'parent_product_id' => $wooAliasParent->id,
                'child_product_id' => $child->id,
                'relation_type' => 'variant',
                'sort_order' => 70 + $index,
                'metadata' => [
                    'variant_attribute' => 'Rozmiary',
                    'variant_option' => $option,
                ],
            ]);

            return compact('child', 'relation');
        });
        $translation = $this->product(
            'WOO-ALIAS-TRANSLATION',
            'woocommerce_import',
            'simple',
            '',
            [],
        );
        ProductChannelAlias::query()->create([
            'product_id' => $translation->id,
            'source_product_id' => $wooAliasParent->id,
            'sales_channel_id' => $wooChannel->id,
            'external_product_id' => '975000',
            'external_variation_id' => null,
            'external_sku' => $wooAliasParent->sku,
            'language' => 'en',
            'metadata' => ['operator_note' => 'Woo ownership without mapping'],
        ]);
        $families->push(['parent' => $wooAliasParent, 'children' => $wooAliasChildren]);
        $productIds = $families->flatMap(fn (array $family): array => [
            $family['parent']->id,
            ...$family['children']->pluck('child.id')->all(),
        ])->all();
        $relationIds = $families->flatMap(
            fn (array $family): array => $family['children']->pluck('relation.id')->all(),
        )->all();
        $productsBefore = $this->rows('products', $productIds);
        $relationsBefore = $this->rows('product_relations', $relationIds);

        $this->runMigration();

        $this->assertSame($productsBefore, $this->rows('products', $productIds));
        $this->assertSame($relationsBefore, $this->rows('product_relations', $relationIds));
        Http::assertNothingSent();
    }

    private function wooChannel(): SalesChannel
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-SIZE-AXIS-MIGRATION',
            'name' => 'Size axis migration',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Size axis migration Woo',
            'base_url' => 'https://size-axis-migration.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $parentMappingMetadata
     * @return array{
     *   parent:Product,
     *   children:Collection<int,Product>,
     *   relations:Collection<int,ProductRelation>,
     *   parent_mapping:ProductChannelMapping,
     *   child_mappings:Collection<int,ProductChannelMapping>,
     *   stocks:Collection<int,StockBalance>
     * }
     */
    private function family(
        SalesChannel $channel,
        Warehouse $warehouse,
        string $alias,
        string $source,
        int $externalId,
        array $parentMappingMetadata = [],
    ): array {
        $sku = strtoupper($source.'-'.$alias.'-'.$externalId);
        $generic = in_array($alias, ['wariant', 'BLVariant'], true);
        $parentParameters = [[
            'name' => $alias,
            'value' => 'M/L | S/M',
            'variation' => true,
        ]];

        if ($generic) {
            $parentParameters[] = [
                'name' => 'Rozmiar',
                'name_en' => 'Size',
                'value' => 'M/L | S/M',
                'variation' => false,
            ];
        }

        $parent = $this->product($sku, $source, 'variable', $alias, $parentParameters);
        $children = collect(['M/L', 'S/M'])->map(function (string $option, int $index) use (
            $alias,
            $generic,
            $parent,
            $sku,
            $source,
        ): Product {
            $parameters = [[
                'name' => $alias,
                'value' => $option,
                'variation' => true,
            ]];

            if ($generic) {
                $parameters[] = [
                    'name' => 'Rozmiar',
                    'name_en' => 'Size',
                    'value' => $option,
                    'variation' => false,
                ];
            }

            return $this->product(
                $sku.'-'.($index + 1),
                $source,
                'variation',
                $alias,
                $parameters,
                ['family_parent_sku' => $parent->sku],
            );
        });
        $relations = $children->map(fn (Product $child, int $index): ProductRelation => ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $child->id,
            'relation_type' => 'variant',
            // Deliberately keep the stale local order until remote verification.
            'sort_order' => $index === 0 ? 20 : 10,
            'metadata' => [
                'variant_attribute' => $alias,
                'variant_option' => $index === 0 ? 'M/L' : 'S/M',
                'operator_note' => 'preserve relation metadata',
            ],
        ]));
        $parentMapping = $this->mapping(
            $parent,
            $channel,
            (string) $externalId,
            null,
            array_replace_recursive([
                'mapping_role' => 'primary',
                'language' => 'pl',
            ], $parentMappingMetadata),
        );
        $childMappings = $children->map(fn (Product $child, int $index): ProductChannelMapping => $this->mapping(
            $child,
            $channel,
            (string) $externalId,
            (string) ($externalId + $index + 1),
            ['language' => 'pl'],
        ));
        $stocks = collect([$parent, ...$children])->map(
            fn (Product $product, int $index): StockBalance => StockBalance::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity_on_hand' => 11 + $index,
                'quantity_reserved' => $index,
                'quantity_available' => 11,
            ]),
        );

        return [
            'parent' => $parent,
            'children' => $children,
            'relations' => $relations,
            'parent_mapping' => $parentMapping,
            'child_mappings' => $childMappings,
            'stocks' => $stocks,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $parameters
     * @param  array<string, mixed>  $extraAttributes
     */
    private function product(
        string $sku,
        string $source,
        string $productType,
        string $variantAttribute,
        array $parameters,
        array $extraAttributes = [],
    ): Product {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'weight_kg' => 0,
            'quantity_precision' => 0,
            'is_active' => true,
            'is_translation' => false,
            'attributes' => array_replace_recursive([
                'master' => [
                    'source' => $source,
                    'product_type' => $productType,
                    'variant_attribute' => $variantAttribute,
                    'parameters' => $parameters,
                    'content' => [
                        'pl' => ['name' => 'Produkt '.$sku],
                        'en' => ['name' => 'Product '.$sku],
                    ],
                ],
                'operator_note' => 'preserve product snapshot',
            ], $extraAttributes),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function mapping(
        Product $product,
        SalesChannel $channel,
        string $externalProductId,
        ?string $externalVariationId,
        array $metadata,
    ): ProductChannelMapping {
        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => $externalProductId,
            'external_variation_id' => $externalVariationId,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => $metadata,
        ]);
    }

    /** @param list<int> $ids */
    private function rows(string $table, array $ids): array
    {
        return DB::table($table)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /** @param list<int> $ids @param list<string> $columns */
    private function rowsWithout(string $table, array $ids, array $columns): array
    {
        return collect($this->rows($table, $ids))
            ->map(function (array $row) use ($columns): array {
                foreach ($columns as $column) {
                    unset($row[$column]);
                }

                return $row;
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function mappingIdentity(ProductChannelMapping $mapping): array
    {
        return $mapping->only([
            'id',
            'product_id',
            'sales_channel_id',
            'external_product_id',
            'external_variation_id',
            'external_identity_key',
            'external_sku',
            'stock_sync_enabled',
        ]);
    }

    private function runMigration(): void
    {
        (require database_path(
            'migrations/2026_07_16_000024_queue_size_axes_for_remote_repair.php',
        ))->up();
    }
}
