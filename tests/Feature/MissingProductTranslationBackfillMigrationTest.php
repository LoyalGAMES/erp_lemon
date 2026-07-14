<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

final class MissingProductTranslationBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_marks_a_simple_mapped_product_missing_english_and_is_idempotent(): void
    {
        [$channel] = $this->createWooIntegration('SIMPLE', ['pl', 'en']);
        $product = $this->createProduct('MISSING-EN-SIMPLE');
        $mapping = $this->createPrimaryMapping($product, $channel, '1001');

        $this->runBackfillMigration();

        $backfill = (array) data_get(
            $mapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill',
        );

        $this->assertSame('pending', $backfill['status'] ?? null);
        $this->assertSame(LegacyVariantFamilyBackfillService::REASON, $backfill['reason'] ?? null);
        $this->assertSame(
            LegacyVariantFamilyBackfillService::MISSING_PRODUCT_TRANSLATIONS_REVISION,
            $backfill['revision'] ?? null,
        );
        $this->assertNotEmpty($backfill['requested_at'] ?? null);

        $requestedAt = $backfill['requested_at'];
        $this->runBackfillMigration();

        $this->assertSame($requestedAt, data_get(
            $mapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.requested_at',
        ));
    }

    public function test_migration_skips_a_product_with_an_existing_channel_scoped_english_alias(): void
    {
        [$channel] = $this->createWooIntegration('EXISTING-EN', ['pl', 'en']);
        $product = $this->createProduct('EXISTING-EN-SIMPLE');
        $mapping = $this->createPrimaryMapping($product, $channel, '1101');

        ProductChannelAlias::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '2101',
            'external_variation_id' => null,
            'external_key' => ProductChannelAlias::externalKey('2101', null),
            'external_sku' => $product->sku,
            'language' => 'en',
        ]);

        $this->runBackfillMigration();

        $this->assertNull(data_get(
            $mapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill',
        ));
    }

    public function test_migration_marks_an_existing_english_alias_when_translation_linking_is_pending(): void
    {
        [$channel] = $this->createWooIntegration('PENDING-EN-LINK', ['pl', 'en']);
        $product = $this->createProduct('PENDING-EN-LINK-SIMPLE');
        $mapping = $this->createPrimaryMapping($product, $channel, '1121');
        $metadata = (array) $mapping->metadata;
        data_set($metadata, 'product_translation_link.pending', true);
        $mapping->forceFill(['metadata' => $metadata])->save();

        ProductChannelAlias::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '2121',
            'external_variation_id' => null,
            'external_key' => ProductChannelAlias::externalKey('2121', null),
            'external_sku' => $product->sku,
            'language' => 'en',
        ]);

        $this->runBackfillMigration();

        $this->assertTrue((bool) data_get(
            $mapping->refresh()->metadata,
            'product_translation_link.pending',
        ));
        $this->assertSame('pending', data_get(
            $mapping->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertSame(
            LegacyVariantFamilyBackfillService::MISSING_PRODUCT_TRANSLATIONS_REVISION,
            data_get($mapping->metadata, 'product_data_export.legacy_variant_backfill.revision'),
        );
    }

    public function test_migration_skips_a_valid_legacy_english_reference_for_a_single_channel(): void
    {
        [$channel] = $this->createWooIntegration('LEGACY-EN', ['pl', 'en']);
        $product = $this->createProduct('LEGACY-EN-SIMPLE');
        $attributes = (array) $product->attributes;
        data_set($attributes, 'woocommerce_translations.en', [
            'product_id' => '2201',
            'sku' => $product->sku,
        ]);
        $product->forceFill(['attributes' => $attributes])->save();
        $mapping = $this->createPrimaryMapping($product, $channel, '1151');

        $this->runBackfillMigration();

        $this->assertNull(data_get(
            $mapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill',
        ));
    }

    public function test_migration_marks_only_the_variable_parent_and_not_its_variant_mapping(): void
    {
        [$channel] = $this->createWooIntegration('VARIABLE', ['pl', 'en']);
        $parent = $this->createProduct('MISSING-EN-PARENT', [
            'product_type' => 'variable',
            'variant_attribute' => 'Rozmiar',
        ]);
        $variant = $this->createProduct('MISSING-EN-PARENT-S', [
            'product_type' => 'variation',
            'parameters' => [[
                'name' => 'Rozmiar',
                'name_en' => 'Size',
                'value' => 'S',
                'value_en' => 'S',
                'variation' => true,
            ]],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        $parentMapping = $this->createPrimaryMapping($parent, $channel, '1201');
        $variantMapping = ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '1201',
            'external_variation_id' => '1202',
            'external_sku' => $variant->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
        ]);

        $this->runBackfillMigration();

        $this->assertSame('pending', data_get(
            $parentMapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertNull(data_get(
            $variantMapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill',
        ));
        $this->assertSame(1, ProductChannelMapping::query()
            ->whereIn('id', [$parentMapping->id, $variantMapping->id])
            ->get()
            ->filter(fn (ProductChannelMapping $candidate): bool => data_get(
                $candidate->metadata,
                'product_data_export.legacy_variant_backfill.status',
            ) === 'pending')
            ->count());
    }

    public function test_migration_skips_an_integration_that_does_not_export_english(): void
    {
        [$channel] = $this->createWooIntegration('POLISH-ONLY', ['pl']);
        $product = $this->createProduct('POLISH-ONLY-PRODUCT');
        $mapping = $this->createPrimaryMapping($product, $channel, '1301');

        $this->runBackfillMigration();

        $this->assertNull(data_get(
            $mapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill',
        ));
    }

    public function test_migration_skips_non_erp_products_and_variation_root_candidates(): void
    {
        [$channel] = $this->createWooIntegration('NON-CANONICAL', ['pl', 'en']);
        $nonErp = $this->createProduct('NON-ERP-SIMPLE', ['source' => 'woocommerce']);
        $variationRoot = $this->createProduct('ORPHAN-VARIATION-ROOT', [
            'product_type' => 'variation',
            'parameters' => [[
                'name' => 'Rozmiar',
                'name_en' => 'Size',
                'value' => 'M',
                'value_en' => 'M',
                'variation' => true,
            ]],
        ]);
        $relationParent = $this->createProduct('RELATION-PARENT');
        $relationChildWithRootMapping = $this->createProduct('RELATION-CHILD-ROOT-MAPPING');
        ProductRelation::query()->create([
            'parent_product_id' => $relationParent->id,
            'child_product_id' => $relationChildWithRootMapping->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        $nonErpMapping = $this->createPrimaryMapping($nonErp, $channel, '1401');
        $variationRootMapping = $this->createPrimaryMapping($variationRoot, $channel, '1402');
        $relationChildMapping = $this->createPrimaryMapping(
            $relationChildWithRootMapping,
            $channel,
            '1403',
        );

        $this->runBackfillMigration();

        foreach ([$nonErpMapping, $variationRootMapping, $relationChildMapping] as $mapping) {
            $this->assertNull(data_get(
                $mapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill',
            ));
        }
    }

    public function test_migration_skips_a_non_numeric_external_product_id(): void
    {
        [$channel] = $this->createWooIntegration('INVALID-ID', ['pl', 'en']);
        $product = $this->createProduct('INVALID-EXTERNAL-ID');
        $mapping = $this->createPrimaryMapping($product, $channel, 'not-a-woocommerce-id');

        $this->runBackfillMigration();

        $this->assertNull(data_get(
            $mapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill',
        ));
    }

    /**
     * @param  list<string>  $languages
     * @return array{SalesChannel, WordpressIntegration}
     */
    private function createWooIntegration(string $suffix, array $languages): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-'.$suffix,
            'name' => 'Sklep '.$suffix,
            'type' => 'woocommerce',
            'is_active' => true,
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

    /**
     * @param  array<string, mixed>  $masterOverrides
     */
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

    private function createPrimaryMapping(
        Product $product,
        SalesChannel $channel,
        string $externalProductId,
    ): ProductChannelMapping {
        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => $externalProductId,
            'external_variation_id' => null,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
        ]);
    }

    private function runBackfillMigration(): void
    {
        (require database_path(
            'migrations/2026_07_14_000007_mark_mapped_products_missing_translations_for_woocommerce_backfill.php',
        ))->up();
    }
}
