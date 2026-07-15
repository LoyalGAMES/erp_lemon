<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooCommerceVariationTranslationBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_requeues_only_the_canonical_parent_of_a_translated_variable_family_and_is_idempotent(): void
    {
        Http::fake();
        CarbonImmutable::setTestNow('2026-07-15 13:00:00');

        try {
            $channel = $this->createIntegration('TRANSLATED', ['pl', 'en']);
            $parent = $this->product('HISTORICAL-VARIABLE', 'variable');
            $variant = $this->product('HISTORICAL-VARIABLE-S', 'variation');
            ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $variant->id,
                'relation_type' => 'variant',
                'sort_order' => 10,
            ]);
            $parentMapping = $this->mapping($parent, $channel, '8100');
            $variantMapping = ProductChannelMapping::query()->create([
                'product_id' => $variant->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => '8100',
                'external_variation_id' => '8101',
                'external_sku' => $variant->sku,
                'stock_sync_enabled' => true,
                'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
            ]);

            $this->runMigration();

            $backfill = (array) data_get(
                $parentMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill',
            );
            $this->assertSame('pending', $backfill['status'] ?? null);
            $this->assertSame(LegacyVariantFamilyBackfillService::REASON, $backfill['reason'] ?? null);
            $this->assertSame(
                LegacyVariantFamilyBackfillService::VARIATION_TRANSLATION_LINK_RECOVERY_REVISION,
                $backfill['revision'] ?? null,
            );
            $this->assertSame(now()->toISOString(), $backfill['requested_at'] ?? null);
            $this->assertNull(data_get(
                $variantMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill',
            ));

            $requestedAt = $backfill['requested_at'];
            CarbonImmutable::setTestNow('2026-07-15 13:10:00');
            $this->runMigration();

            $this->assertSame($requestedAt, data_get(
                $parentMapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill.requested_at',
            ));
            Http::assertNothingSent();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_migration_skips_simple_polish_only_inactive_and_non_erp_families(): void
    {
        Http::fake();
        $translated = $this->createIntegration('FILTERS', ['pl', 'en']);
        $polishOnly = $this->createIntegration('POLISH', ['pl']);
        $inactive = $this->createIntegration('INACTIVE', ['pl', 'en'], false);

        $simpleMapping = $this->mapping($this->product('SIMPLE', 'simple'), $translated, '8200');
        $polishMapping = $this->familyMapping('POLISH-FAMILY', $polishOnly, '8300');
        $inactiveMapping = $this->familyMapping('INACTIVE-FAMILY', $inactive, '8400');
        $nonErpMapping = $this->familyMapping('NON-ERP-FAMILY', $translated, '8500', 'woocommerce');

        $this->runMigration();

        foreach ([$simpleMapping, $polishMapping, $inactiveMapping, $nonErpMapping] as $mapping) {
            $this->assertNull(data_get(
                $mapping->refresh()->metadata,
                'product_data_export.legacy_variant_backfill',
            ));
        }
        Http::assertNothingSent();
    }

    /** @param list<string> $languages */
    private function createIntegration(string $suffix, array $languages, bool $active = true): SalesChannel
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-'.$suffix,
            'name' => 'Woo '.$suffix,
            'type' => 'woocommerce',
            'is_active' => $active,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Integration '.$suffix,
            'base_url' => 'https://'.mb_strtolower($suffix).'.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => $languages]],
        ]);

        return $channel;
    }

    private function product(string $sku, string $type, string $source = 'erp'): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => $source,
                'product_type' => $type,
                'content' => [
                    'pl' => ['name' => $sku],
                    'en' => ['name' => $sku.' EN'],
                ],
            ]],
        ]);
    }

    private function familyMapping(
        string $sku,
        SalesChannel $channel,
        string $externalId,
        string $source = 'erp',
    ): ProductChannelMapping {
        $parent = $this->product($sku, 'variable', $source);
        $variant = $this->product($sku.'-S', 'variation', $source);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);

        return $this->mapping($parent, $channel, $externalId);
    }

    private function mapping(Product $product, SalesChannel $channel, string $externalId): ProductChannelMapping
    {
        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => $externalId,
            'external_variation_id' => null,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
        ]);
    }

    private function runMigration(): void
    {
        (require database_path(
            'migrations/2026_07_15_000013_requeue_translated_variable_families_for_variation_linking.php',
        ))->up();
    }
}
