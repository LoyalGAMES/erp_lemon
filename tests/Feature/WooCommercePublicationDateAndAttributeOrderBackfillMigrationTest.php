<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooCommercePublicationDateAndAttributeOrderBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_durably_requeues_every_canonical_mapped_erp_root_for_a_translated_active_store(): void
    {
        Http::fake();
        CarbonImmutable::setTestNow('2026-07-15 12:00:00');

        try {
            [$channel] = $this->createWooIntegration('TRANSLATED-ACTIVE', ['pl', 'en']);
            $simple = $this->createProduct('DATE-ORDER-SIMPLE');
            $variable = $this->createProduct('DATE-ORDER-VARIABLE', [
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
            ]);
            $definition = ProductParameterDefinition::query()->create([
                'name' => 'Rozmiar',
                'name_en' => 'Size',
                'slug' => 'rozmiar',
                'input_type' => 'select',
                'values' => ['M', 'S', 'M/L', 'S/M'],
                'values_en' => ['Medium', 'Small', 'Medium/Large', 'Small/Medium'],
                // This reproduces old production dictionaries: products used
                // Rozmiar as their variant axis, while the shared definition
                // itself was never promoted to the variant flag.
                'is_variant' => false,
                'is_required' => false,
                'sort_order' => 10,
            ]);
            $variants = collect([
                ['option' => 'M', 'order' => 10, 'external_id' => '9202'],
                ['option' => 'S', 'order' => 20, 'external_id' => '9203'],
            ])->map(function (array $row) use ($variable): array {
                $variant = $this->createProduct('DATE-ORDER-VARIABLE-'.$row['option'], [
                    'product_type' => 'variation',
                    'parameters' => [[
                        'name' => 'Rozmiar',
                        'name_en' => 'Size',
                        'value' => $row['option'],
                        'value_en' => $row['option'],
                        'variation' => true,
                    ]],
                ]);
                $relation = ProductRelation::query()->create([
                    'parent_product_id' => $variable->id,
                    'child_product_id' => $variant->id,
                    'relation_type' => 'variant',
                    'sort_order' => $row['order'],
                ]);

                return compact('variant', 'relation') + ['external_id' => $row['external_id']];
            });

            $simpleMapping = $this->createPrimaryMapping($simple, $channel, '9101', [
                'product_data_export' => [
                    'legacy_variant_backfill' => [
                        'status' => 'completed',
                        'reason' => LegacyVariantFamilyBackfillService::REASON,
                        'revision' => LegacyVariantFamilyBackfillService::MISSING_PRODUCT_TRANSLATIONS_REVISION,
                        'completed_at' => now()->subDay()->toISOString(),
                    ],
                ],
            ]);
            $variableMapping = $this->createPrimaryMapping($variable, $channel, '9201');
            $variantMappings = $variants->map(fn (array $row): ProductChannelMapping => ProductChannelMapping::query()->create([
                'product_id' => $row['variant']->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => '9201',
                'external_variation_id' => $row['external_id'],
                'external_sku' => $row['variant']->sku,
                'stock_sync_enabled' => true,
                'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
            ]));

            $this->runMigration();

            foreach ([$simpleMapping, $variableMapping] as $mapping) {
                $backfill = (array) data_get(
                    $mapping->refresh()->metadata,
                    'product_data_export.legacy_variant_backfill',
                );

                $this->assertSame('pending', $backfill['status'] ?? null);
                $this->assertSame(LegacyVariantFamilyBackfillService::REASON, $backfill['reason'] ?? null);
                $this->assertSame(
                    LegacyVariantFamilyBackfillService::PUBLICATION_DATE_AND_ATTRIBUTE_ORDER_REVISION,
                    $backfill['revision'] ?? null,
                );
                $this->assertSame(now()->toISOString(), $backfill['requested_at'] ?? null);
                $this->assertArrayNotHasKey('completed_at', $backfill);
            }

            foreach ($variantMappings as $variantMapping) {
                $this->assertNull(data_get(
                    $variantMapping->refresh()->metadata,
                    'product_data_export.legacy_variant_backfill',
                ));
            }
            $this->assertSame(['S', 'S/M', 'M', 'M/L'], $definition->refresh()->values);
            $this->assertSame(
                ['Small', 'Small/Medium', 'Medium', 'Medium/Large'],
                $definition->values_en,
            );
            $this->assertSame(20, $variants[0]['relation']->refresh()->sort_order);
            $this->assertSame(10, $variants[1]['relation']->refresh()->sort_order);
            $relationUpdatedAt = $variants[0]['relation']->updated_at;

            $requestedAt = data_get(
                $simpleMapping->metadata,
                'product_data_export.legacy_variant_backfill.requested_at',
            );
            CarbonImmutable::setTestNow('2026-07-15 12:10:00');
            $this->runMigration();

            $this->assertSame($requestedAt, data_get(
                $simpleMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill.requested_at',
            ));
            $this->assertTrue(
                $relationUpdatedAt->equalTo($variants[0]['relation']->refresh()->updated_at),
                'An idempotent rerun must not touch unchanged relation timestamps.',
            );
            Http::assertNothingSent();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_migration_skips_non_translated_inactive_non_woocommerce_and_non_canonical_products(): void
    {
        Http::fake();
        [$polishOnlyChannel] = $this->createWooIntegration('POLISH-ONLY', ['pl']);
        [$inactiveChannel] = $this->createWooIntegration('INACTIVE', ['pl', 'en'], false);
        [$nonWooChannel] = $this->createWooIntegration('NON-WOO', ['pl', 'en'], true, 'marketplace');
        [$translatedChannel] = $this->createWooIntegration('CANONICAL-FILTERS', ['pl', 'en']);

        $polishOnlyMapping = $this->createPrimaryMapping(
            $this->createProduct('DATE-ORDER-POLISH-ONLY'),
            $polishOnlyChannel,
            '9301',
        );
        $inactiveMapping = $this->createPrimaryMapping(
            $this->createProduct('DATE-ORDER-INACTIVE'),
            $inactiveChannel,
            '9302',
        );
        $nonWooMapping = $this->createPrimaryMapping(
            $this->createProduct('DATE-ORDER-NON-WOO'),
            $nonWooChannel,
            '9303',
        );
        $nonErpMapping = $this->createPrimaryMapping(
            $this->createProduct('DATE-ORDER-NON-ERP', ['source' => 'woocommerce']),
            $translatedChannel,
            '9304',
        );
        $translation = $this->createProduct('DATE-ORDER-TRANSLATION');
        $translation->forceFill(['is_translation' => true])->save();
        $translationMapping = $this->createPrimaryMapping($translation, $translatedChannel, '9305');

        $this->runMigration();

        foreach ([$polishOnlyMapping, $inactiveMapping, $nonWooMapping, $nonErpMapping, $translationMapping] as $mapping) {
            $this->assertNull(data_get(
                $mapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill',
            ));
        }

        Http::assertNothingSent();
    }

    public function test_migration_does_not_reorder_a_non_size_variant_family_that_has_descriptive_size_parameters(): void
    {
        Http::fake();
        [$channel] = $this->createWooIntegration('COLOR-FAMILY', ['pl', 'en']);
        $parent = $this->createProduct('DATE-ORDER-COLOR', [
            'product_type' => 'variable',
            'variant_attribute' => 'Kolor',
        ]);
        $relations = collect([
            ['color' => 'Czarny', 'size' => 'M', 'order' => 10],
            ['color' => 'Biały', 'size' => 'S', 'order' => 20],
        ])->map(function (array $row) use ($parent): ProductRelation {
            $variant = $this->createProduct('DATE-ORDER-COLOR-'.$row['order'], [
                'product_type' => 'variation',
                'parameters' => [
                    ['name' => 'Kolor', 'value' => $row['color'], 'variation' => true],
                    ['name' => 'Rozmiar', 'value' => $row['size'], 'variation' => false],
                ],
            ]);

            return ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $variant->id,
                'relation_type' => 'variant',
                'sort_order' => $row['order'],
            ]);
        });
        $this->createPrimaryMapping($parent, $channel, '9401');

        $this->runMigration();

        $this->assertSame([10, 20], $relations->map(
            fn (ProductRelation $relation): int => $relation->refresh()->sort_order,
        )->all());
        Http::assertNothingSent();
    }

    /**
     * @param  list<string>  $languages
     * @return array{SalesChannel, WordpressIntegration}
     */
    private function createWooIntegration(
        string $suffix,
        array $languages,
        bool $active = true,
        string $type = 'woocommerce',
    ): array {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-'.$suffix,
            'name' => 'Sklep '.$suffix,
            'type' => $type,
            'is_active' => $active,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo '.$suffix,
            'base_url' => 'https://'.mb_strtolower($suffix).'.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => $languages]],
        ]);

        return [$channel, $integration];
    }

    /** @param array<string, mixed> $masterOverrides */
    private function createProduct(string $sku, array $masterOverrides = []): Product
    {
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
            'quantity_precision' => 0,
            'is_active' => true,
            'is_translation' => false,
            'attributes' => ['master' => $master],
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function createPrimaryMapping(
        Product $product,
        SalesChannel $channel,
        string $externalProductId,
        array $metadata = [],
    ): ProductChannelMapping {
        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => $externalProductId,
            'external_variation_id' => null,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => array_replace_recursive(
                ['mapping_role' => 'primary', 'language' => 'pl'],
                $metadata,
            ),
        ]);
    }

    private function runMigration(): void
    {
        (require database_path(
            'migrations/2026_07_15_000012_requeue_all_size_definitions_for_woocommerce_order.php',
        ))->up();
    }
}
