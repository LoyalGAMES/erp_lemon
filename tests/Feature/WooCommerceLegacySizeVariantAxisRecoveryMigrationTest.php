<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\WordpressIntegration;
use App\Services\Products\LegacySizeVariantAxisResolver;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooCommerceLegacySizeVariantAxisRecoveryMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_surgically_repairs_an_unambiguous_legacy_size_family_and_is_idempotent(): void
    {
        Http::fake();
        CarbonImmutable::setTestNow('2026-07-15 16:00:00');

        try {
            $channel = $this->createIntegration('AXIS-RECOVERY');
            ProductParameterDefinition::query()->create([
                'name' => 'Rozmiar',
                'name_en' => 'Size',
                'slug' => 'rozmiar',
                'input_type' => 'select',
                'values' => ['S/M', 'M/L'],
                'values_en' => ['S/M', 'M/L'],
                'is_variant' => true,
            ]);
            $warehouse = Warehouse::query()->create([
                'code' => 'AXIS-RECOVERY',
                'name' => 'Axis recovery warehouse',
                'type' => 'virtual',
                'is_active' => true,
            ]);
            $parent = $this->product('LEGACY-SIZE', [
                'product_type' => 'variable',
                'variant_attribute' => 'BLVariant',
                'publication_status' => 'publish',
                'publication_date' => '2026-07-14T13:47',
                'content' => [
                    'pl' => [
                        'name' => 'Historyczna rodzina',
                        'description_html' => '<p>Treść PL pozostaje bez zmian.</p>',
                    ],
                    'en' => [
                        'name' => 'Historical family',
                        'description_html' => '<p>EN content must stay unchanged.</p>',
                    ],
                ],
                'prices' => ['regular' => '539.00'],
                'parameters' => [
                    ['name' => 'Skład', 'value' => '100% Bawełna', 'variation' => false],
                    ['name' => 'BLVariant', 'value' => 'm-l, s-m', 'variation' => true],
                    ['name' => 'wariant', 'value' => 'S/M | M/L', 'variation' => true],
                    [
                        'name' => 'Rozmiar',
                        'name_en' => 'Size',
                        'value' => 'M/L | S/M',
                        'value_pl' => 'M/L | S/M',
                        'value_en' => 'm-l | s-m',
                        'translations' => [
                            'pl' => ['value' => 'M/L | S/M'],
                            'en' => ['value' => 'm-l | s-m'],
                        ],
                        'variation' => true,
                    ],
                ],
            ], [
                'integration_note' => 'parent attributes must survive',
            ]);
            $small = $this->product('LEGACY-SIZE-SM', [
                'product_type' => 'variation',
                'variant_attribute' => 'BLVariant',
                'publication_date' => '2026-07-14T13:47',
                'content' => [
                    'pl' => ['name' => 'Wariant historyczny S/M', 'description_html' => '<p>PL S/M</p>'],
                    'en' => ['name' => 'Historical variant S/M', 'description_html' => '<p>EN S/M</p>'],
                ],
                'parameters' => [
                    ['name' => 'Skład', 'value' => '100% Bawełna', 'variation' => false],
                    [
                        'name' => 'BLVariant',
                        'name_en' => 'Variant',
                        'value' => 's-m',
                        'value_en' => 's-m',
                        'translations' => ['en' => ['value' => 's-m']],
                        'variation' => true,
                    ],
                ],
            ], ['integration_note' => 'small attributes must survive']);
            $large = $this->product('LEGACY-SIZE-ML', [
                'product_type' => 'variation',
                'variant_attribute' => 'wariant',
                'publication_date' => '2026-07-14T13:47',
                'content' => [
                    'pl' => ['name' => 'Wariant historyczny M/L', 'description_html' => '<p>PL M/L</p>'],
                    'en' => ['name' => 'Historical variant M/L', 'description_html' => '<p>EN M/L</p>'],
                ],
                'parameters' => [
                    ['name' => 'Skład', 'value' => '100% Bawełna', 'variation' => false],
                    ['name' => 'wariant', 'value' => 'm-l', 'variation' => true],
                    ['name' => 'Rozmiar', 'name_en' => 'Size', 'value' => 'm/l', 'variation' => false],
                ],
            ], ['integration_note' => 'large attributes must survive']);
            // The historical generic axis was imported in the wrong M/L, S/M
            // order. Recovery must apply the current shared size dictionary.
            $smallRelation = $this->relation($parent, $small, 20, ['source' => 'legacy-import']);
            $largeRelation = $this->relation($parent, $large, 10, ['source' => 'legacy-import']);
            $parentMapping = $this->mapping($parent, $channel, '808184', null, [
                'operator_note' => 'mapping metadata must survive',
            ]);
            $smallMapping = $this->mapping($small, $channel, '808184', '808185');
            $largeMapping = $this->mapping($large, $channel, '808184', '808187');
            $balances = collect([
                [$parent, 5],
                [$small, 2],
                [$large, 3],
            ])->map(fn (array $row): StockBalance => StockBalance::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $row[0]->id,
                'quantity_on_hand' => $row[1],
                'quantity_reserved' => 0,
                'quantity_available' => $row[1],
            ]));
            $preserved = [
                'parent_id' => $parent->id,
                'parent_sku' => $parent->sku,
                'parent_name' => $parent->name,
                'parent_content' => data_get($parent->masterData(), 'content'),
                'parent_date' => data_get($parent->masterData(), 'publication_date'),
                'parent_prices' => data_get($parent->masterData(), 'prices'),
                'small_id' => $small->id,
                'small_sku' => $small->sku,
                'small_name' => $small->name,
                'small_content' => data_get($small->masterData(), 'content'),
                'small_date' => data_get($small->masterData(), 'publication_date'),
                'large_content' => data_get($large->masterData(), 'content'),
                'large_date' => data_get($large->masterData(), 'publication_date'),
            ];

            $parent->load('variantChildren');
            $this->assertSame('Rozmiar', app(LegacySizeVariantAxisResolver::class)->recover(
                $parent,
                $parent->variantChildren,
            ));

            $this->runMigration();

            $parent->refresh();
            $small->refresh();
            $large->refresh();
            $this->assertSame('Rozmiar', data_get($parent->masterData(), 'variant_attribute'));
            $this->assertSame(
                ['Skład', 'Rozmiar'],
                collect((array) data_get($parent->masterData(), 'parameters'))->pluck('name')->all(),
            );
            $parentSize = collect((array) data_get($parent->masterData(), 'parameters'))
                ->firstWhere('name', 'Rozmiar');
            $this->assertSame('S/M | M/L', $parentSize['value'] ?? null);
            $this->assertSame('S/M | M/L', $parentSize['value_pl'] ?? null);
            $this->assertSame('S/M | M/L', $parentSize['value_en'] ?? null);
            $this->assertSame('S/M | M/L', data_get($parentSize, 'translations.pl.value'));
            $this->assertSame('S/M | M/L', data_get($parentSize, 'translations.en.value'));
            $this->assertTrue((bool) ($parentSize['variation'] ?? false));

            foreach ([[$small, 'S/M'], [$large, 'M/L']] as [$variant, $option]) {
                $this->assertSame('Rozmiar', data_get($variant->masterData(), 'variant_attribute'));
                $parameters = collect((array) data_get($variant->masterData(), 'parameters'));
                $this->assertSame(['Skład', 'Rozmiar'], $parameters->pluck('name')->all());
                $variationParameters = $parameters
                    ->filter(fn (array $parameter): bool => (bool) ($parameter['variation'] ?? false))
                    ->values();
                $this->assertCount(1, $variationParameters);
                $this->assertSame('Rozmiar', $variationParameters->first()['name'] ?? null);
                $this->assertSame($option, $variationParameters->first()['value'] ?? null);

                if ($variant->is($small)) {
                    $this->assertSame('Size', $variationParameters->first()['name_en'] ?? null);
                    $this->assertSame('S/M', $variationParameters->first()['value_en'] ?? null);
                    $this->assertSame(
                        'S/M',
                        data_get($variationParameters->first(), 'translations.en.value'),
                    );
                }
            }

            $this->assertSame($preserved['parent_id'], $parent->id);
            $this->assertSame($preserved['parent_sku'], $parent->sku);
            $this->assertSame($preserved['parent_name'], $parent->name);
            $this->assertSame($preserved['parent_content'], data_get($parent->masterData(), 'content'));
            $this->assertSame($preserved['parent_date'], data_get($parent->masterData(), 'publication_date'));
            $this->assertSame($preserved['parent_prices'], data_get($parent->masterData(), 'prices'));
            $this->assertSame('parent attributes must survive', data_get($parent->attributes, 'integration_note'));
            $this->assertSame($preserved['small_id'], $small->id);
            $this->assertSame($preserved['small_sku'], $small->sku);
            $this->assertSame($preserved['small_name'], $small->name);
            $this->assertSame($preserved['small_content'], data_get($small->masterData(), 'content'));
            $this->assertSame($preserved['small_date'], data_get($small->masterData(), 'publication_date'));
            $this->assertSame($preserved['large_content'], data_get($large->masterData(), 'content'));
            $this->assertSame($preserved['large_date'], data_get($large->masterData(), 'publication_date'));
            $this->assertSame('small attributes must survive', data_get($small->attributes, 'integration_note'));
            $this->assertSame('large attributes must survive', data_get($large->attributes, 'integration_note'));
            $this->assertSame('legacy-import', data_get($smallRelation->refresh()->metadata, 'source'));
            $this->assertSame('legacy-import', data_get($largeRelation->refresh()->metadata, 'source'));
            $this->assertSame('Rozmiar', data_get($smallRelation->metadata, 'variant_attribute'));
            $this->assertSame('S/M', data_get($smallRelation->metadata, 'variant_option'));
            $this->assertSame('Rozmiar', data_get($largeRelation->metadata, 'variant_attribute'));
            $this->assertSame('M/L', data_get($largeRelation->metadata, 'variant_option'));
            $this->assertSame(10, $smallRelation->sort_order);
            $this->assertSame(20, $largeRelation->sort_order);
            $this->assertSame([5, 2, 3], $balances->map(
                fn (StockBalance $balance): int => (int) $balance->refresh()->quantity_on_hand,
            )->all());
            $this->assertSame('808184', $parentMapping->refresh()->external_product_id);
            $this->assertSame('mapping metadata must survive', data_get(
                $parentMapping->metadata,
                'operator_note',
            ));
            $this->assertSame('808185', $smallMapping->refresh()->external_variation_id);
            $this->assertSame('808187', $largeMapping->refresh()->external_variation_id);
            $this->assertSame(
                LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION,
                data_get(
                    $parentMapping->metadata,
                    'product_data_export.legacy_variant_backfill.revision',
                ),
            );
            $this->assertSame('pending', data_get(
                $parentMapping->metadata,
                'product_data_export.legacy_variant_backfill.status',
            ));
            $this->assertNull(data_get(
                $smallMapping->metadata,
                'product_data_export.legacy_variant_backfill',
            ));
            $this->assertSame(
                'BLVariant',
                data_get(
                    $parent->masterData(),
                    'maintenance.legacy_size_variant_axis_recovery.previous_variant_attribute',
                ),
            );

            $parentAttributes = $parent->attributes;
            $smallAttributes = $small->attributes;
            $largeAttributes = $large->attributes;
            $parentUpdatedAt = $parent->updated_at;
            $smallUpdatedAt = $small->updated_at;
            $requestedAt = data_get(
                $parentMapping->metadata,
                'product_data_export.legacy_variant_backfill.requested_at',
            );
            CarbonImmutable::setTestNow('2026-07-15 16:10:00');

            $this->runMigration();

            $this->assertSame($parentAttributes, $parent->refresh()->attributes);
            $this->assertSame($smallAttributes, $small->refresh()->attributes);
            $this->assertSame($largeAttributes, $large->refresh()->attributes);
            $this->assertTrue($parentUpdatedAt->equalTo($parent->updated_at));
            $this->assertTrue($smallUpdatedAt->equalTo($small->updated_at));
            $this->assertSame($requestedAt, data_get(
                $parentMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill.requested_at',
            ));
            Http::assertNothingSent();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_migration_requeues_a_canonical_size_parent_when_only_english_child_values_are_legacy_slugs(): void
    {
        Http::fake();
        $channel = $this->createIntegration('CANONICAL-PARENT-LEGACY-CHILDREN');
        $parent = $this->product('CANONICAL-SIZE', [
            'product_type' => 'variable',
            'variant_attribute' => 'Rozmiar',
            'parameters' => [[
                'name' => 'Rozmiar',
                'name_en' => 'Size',
                'value' => 'S/M | M/L',
                'variation' => true,
            ]],
        ]);
        $small = $this->product('CANONICAL-SIZE-SM', [
            'product_type' => 'variation',
            'variant_attribute' => 'Rozmiar',
            'parameters' => [[
                'name' => 'Rozmiar',
                'name_en' => 'Size',
                // The Polish/base snapshot is already canonical. The family
                // still needs a repair export solely for its stale EN value.
                'value' => 'S/M',
                'value_en' => 's-m',
                'translations' => ['en' => ['value' => 's-m']],
                'variation' => true,
            ]],
        ]);
        $large = $this->product('CANONICAL-SIZE-ML', [
            'product_type' => 'variation',
            'variant_attribute' => 'Rozmiar',
            'parameters' => [[
                'name' => 'Rozmiar',
                'name_en' => 'Size',
                'value' => 'M/L',
                'value_en' => 'M/L',
                'translations' => ['en' => ['value' => 'M/L']],
                'variation' => true,
            ]],
        ]);
        $smallRelation = $this->relation($parent, $small, 20, [
            'variant_attribute' => 'Rozmiar',
            'variant_option' => 'S/M',
        ]);
        $largeRelation = $this->relation($parent, $large, 10, [
            'variant_attribute' => 'Rozmiar',
            'variant_option' => 'M/L',
        ]);
        $parentMapping = $this->mapping($parent, $channel, '910100');
        $this->mapping($small, $channel, '910100', '910101');
        $this->mapping($large, $channel, '910100', '910102');

        $this->runMigration();

        $parent->refresh();
        $small->refresh();
        $large->refresh();
        $this->assertSame('Rozmiar', data_get($parent->masterData(), 'variant_attribute'));
        $this->assertSame(
            'S/M | M/L',
            data_get($parent->masterData(), 'parameters.0.value'),
        );

        foreach ([[$small, 'S/M'], [$large, 'M/L']] as [$variant, $option]) {
            $this->assertSame('Rozmiar', data_get($variant->masterData(), 'variant_attribute'));
            $this->assertSame('Rozmiar', data_get($variant->masterData(), 'parameters.0.name'));
            $this->assertSame($option, data_get($variant->masterData(), 'parameters.0.value'));
            $this->assertSame($option, data_get($variant->masterData(), 'parameters.0.value_en'));
            $this->assertSame(
                $option,
                data_get($variant->masterData(), 'parameters.0.translations.en.value'),
            );
        }

        $this->assertSame(10, $smallRelation->refresh()->sort_order);
        $this->assertSame('Rozmiar', data_get($smallRelation->metadata, 'variant_attribute'));
        $this->assertSame('S/M', data_get($smallRelation->metadata, 'variant_option'));
        $this->assertSame(20, $largeRelation->refresh()->sort_order);
        $this->assertSame('Rozmiar', data_get($largeRelation->metadata, 'variant_attribute'));
        $this->assertSame('M/L', data_get($largeRelation->metadata, 'variant_option'));
        $this->assertSame(
            LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION,
            data_get(
                $parentMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill.revision',
            ),
        );
        $this->assertSame('pending', data_get(
            $parentMapping->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));

        $parentAttributes = $parent->attributes;
        $smallAttributes = $small->attributes;
        $largeAttributes = $large->attributes;
        $requestedAt = data_get(
            $parentMapping->metadata,
            'product_data_export.legacy_variant_backfill.requested_at',
        );

        $this->runMigration();

        $this->assertSame($parentAttributes, $parent->refresh()->attributes);
        $this->assertSame($smallAttributes, $small->refresh()->attributes);
        $this->assertSame($largeAttributes, $large->refresh()->attributes);
        $this->assertSame($requestedAt, data_get(
            $parentMapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.requested_at',
        ));
        Http::assertNothingSent();
    }

    public function test_migration_skips_a_real_color_axis_and_an_ambiguous_legacy_family(): void
    {
        Http::fake();
        $channel = $this->createIntegration('AXIS-GUARDS');
        $colorParent = $this->product('COLOR-FAMILY', [
            'product_type' => 'variable',
            // A generic historical axis is not necessarily a size. These
            // options really describe color; Rozmiar is informational only.
            'variant_attribute' => 'BLVariant',
            'parameters' => [
                ['name' => 'BLVariant', 'value' => 'Czarny | Biały', 'variation' => true],
                ['name' => 'Rozmiar', 'value' => 'S/M | M/L', 'variation' => false],
            ],
        ]);
        foreach ([['Czarny', 'S/M'], ['Biały', 'M/L']] as $index => [$color, $size]) {
            $variant = $this->product('COLOR-FAMILY-'.$index, [
                'product_type' => 'variation',
                'variant_attribute' => 'BLVariant',
                'parameters' => [
                    ['name' => 'BLVariant', 'value' => $color, 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => $size, 'variation' => false],
                ],
            ]);
            $this->relation($colorParent, $variant, ($index + 1) * 10);
        }
        $colorMapping = $this->mapping($colorParent, $channel, '900100');

        $ambiguousParent = $this->product('AMBIGUOUS-FAMILY', [
            'product_type' => 'variable',
            'variant_attribute' => 'wariant',
            'parameters' => [
                ['name' => 'wariant', 'value' => 'S/M | M/L', 'variation' => true],
                ['name' => 'Rozmiar', 'value' => 'S/M | M/L', 'variation' => false],
                ['name' => 'Size', 'value' => 'S/M | M/L', 'variation' => false],
            ],
        ]);
        foreach (['S/M', 'M/L'] as $index => $option) {
            $variant = $this->product('AMBIGUOUS-FAMILY-'.$index, [
                'product_type' => 'variation',
                'variant_attribute' => 'wariant',
                'parameters' => [
                    ['name' => 'wariant', 'value' => $option, 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => $option, 'variation' => false],
                    ['name' => 'Size', 'value' => $option, 'variation' => false],
                ],
            ]);
            $this->relation($ambiguousParent, $variant, ($index + 1) * 10);
        }
        $ambiguousMapping = $this->mapping($ambiguousParent, $channel, '900200');
        $colorAttributes = $colorParent->attributes;
        $ambiguousAttributes = $ambiguousParent->attributes;

        $this->runMigration();

        $this->assertSame($colorAttributes, $colorParent->refresh()->attributes);
        $this->assertSame($ambiguousAttributes, $ambiguousParent->refresh()->attributes);
        $this->assertNull(data_get(
            $colorMapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill',
        ));
        $this->assertNull(data_get(
            $ambiguousMapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill',
        ));
        Http::assertNothingSent();
    }

    private function createIntegration(string $suffix): SalesChannel
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-'.$suffix,
            'name' => 'Woo '.$suffix,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Integration '.$suffix,
            'base_url' => 'https://'.mb_strtolower($suffix).'.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $masterOverrides
     * @param  array<string, mixed>  $attributeOverrides
     */
    private function product(
        string $sku,
        array $masterOverrides,
        array $attributeOverrides = [],
    ): Product {
        $master = array_replace_recursive([
            'source' => 'erp',
            'product_type' => 'simple',
            'content' => [
                'pl' => ['name' => 'Produkt '.$sku],
                'en' => ['name' => 'Product '.$sku],
            ],
        ], $masterOverrides);

        return Product::query()->create([
            'sku' => $sku,
            'name' => (string) data_get($master, 'content.pl.name'),
            'unit' => 'szt',
            'vat_rate' => 23,
            'weight_kg' => 0,
            'quantity_precision' => 0,
            'is_active' => true,
            'is_translation' => false,
            'attributes' => array_replace_recursive(
                ['master' => $master],
                $attributeOverrides,
            ),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function relation(
        Product $parent,
        Product $variant,
        int $sortOrder,
        array $metadata = [],
    ): ProductRelation {
        return ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => $sortOrder,
            'metadata' => $metadata,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function mapping(
        Product $product,
        SalesChannel $channel,
        string $externalProductId,
        ?string $externalVariationId = null,
        array $metadata = [],
    ): ProductChannelMapping {
        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => $externalProductId,
            'external_variation_id' => $externalVariationId,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => array_replace_recursive([
                'mapping_role' => 'primary',
                'language' => 'pl',
            ], $metadata),
        ]);
    }

    private function runMigration(): void
    {
        (require database_path(
            'migrations/2026_07_15_000015_recover_legacy_size_variant_axes.php',
        ))->up();
    }
}
