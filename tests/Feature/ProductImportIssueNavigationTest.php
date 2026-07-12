<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ProductImportIssueNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_warning_links_to_exact_products_and_expands_only_affected_variants(): void
    {
        [$integration, $channel] = $this->createIntegration();
        $parent = $this->createProduct('FAMILY-PARENT', 'Rodzina produktu diagnostycznego');
        $affectedVariant = $this->createProduct('DUPLICATE-VARIANT', 'Wariant dotknięty błędem');
        $safeVariant = $this->createProduct('SAFE-VARIANT', 'Wariant bez błędu');
        $affectedSimple = $this->createProduct('DUPLICATE-SIMPLE', 'Produkt prosty dotknięty błędem');
        $safeSimple = $this->createProduct('SAFE-SIMPLE', 'Produkt prosty bez błędu');

        $this->attachVariant($parent, $affectedVariant, 10);
        $this->attachVariant($parent, $safeVariant, 20);

        $log = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_products',
            'status' => 'success',
            'response_payload' => [
                'source_items' => 5,
                'duplicate_sku_items' => 2,
                'duplicate_sku_groups_count' => 2,
                'duplicate_sku_groups' => [
                    [
                        'sku' => 'DUPLICATE-SIMPLE',
                        'entity_kind' => 'product',
                        'occurrences' => 2,
                        'items' => [
                            [
                                'woo_product_id' => '501',
                                'woo_variation_id' => null,
                                'erp_product_id' => $affectedSimple->id,
                                'name' => 'Produkt prosty PL',
                                'language' => 'pl',
                                'permalink' => 'https://shop.test/product-pl',
                            ],
                            [
                                'woo_product_id' => '601',
                                'woo_variation_id' => null,
                                'erp_product_id' => $affectedSimple->id,
                                'name' => 'Product simple EN',
                                'language' => 'en',
                                'permalink' => 'javascript:alert(1)',
                            ],
                        ],
                    ],
                    [
                        'sku' => 'DUPLICATE-VARIANT',
                        'entity_kind' => 'variation',
                        'occurrences' => 2,
                        'items' => [
                            [
                                'woo_product_id' => '502',
                                'woo_variation_id' => '503',
                                'erp_product_id' => $affectedVariant->id,
                                'name' => 'Wariant Woo PL',
                                'language' => 'pl',
                            ],
                            [
                                'woo_product_id' => '602',
                                'woo_variation_id' => '603',
                                'erp_product_id' => $affectedVariant->id,
                                'name' => 'Variation Woo EN',
                                'language' => 'en',
                            ],
                        ],
                    ],
                ],
            ],
            'attempts' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $issueUrl = route('products.index', ['import_issue' => $log->id]);

        $this->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('Wykryto powielone SKU (dodatkowe wystąpienia: 2).')
            ->assertSee('Pokaż produkty z powtórzonym SKU')
            ->assertSee($issueUrl, false)
            ->assertDontSee('duplicate_sku_groups')
            ->assertDontSee('Product simple EN');

        $response = $this->get($issueUrl)->assertOk();
        $this->assertSame(
            ['DUPLICATE-SIMPLE', 'FAMILY-PARENT'],
            $response->viewData('productRows')->getCollection()->pluck('product.sku')->all(),
        );
        $response
            ->assertSee('Produkty wymagające sprawdzenia po imporcie')
            ->assertSee('DUPLICATE-SIMPLE')
            ->assertSee('DUPLICATE-VARIANT')
            ->assertSee('Produkt prosty PL')
            ->assertSee('Variation Woo EN')
            ->assertSee(route('products.edit', $affectedSimple), false)
            ->assertSee(route('products.edit', $affectedVariant), false)
            ->assertSee('Rodzina produktu diagnostycznego')
            ->assertSee('Wariant dotknięty błędem')
            ->assertSee('Produkt prosty dotknięty błędem')
            ->assertDontSee('Wariant bez błędu')
            ->assertDontSee('Produkt prosty bez błędu')
            ->assertDontSee('javascript:alert(1)', false);

        $this->assertMatchesRegularExpression(
            '/class="variant-row product-import-issue-row" data-variant-parent="product-'.$parent->id.'"(?![^>]* hidden)/',
            $response->getContent(),
        );

        $filteredResponse = $this->get(route('products.index', ['import_issue' => $log->id, 'q' => 'prosty']))
            ->assertOk()
            ->assertSee('Produkt prosty dotknięty błędem');
        $this->assertSame(
            ['DUPLICATE-SIMPLE'],
            $filteredResponse->viewData('productRows')->getCollection()->pluck('product.sku')->all(),
        );
    }

    public function test_old_log_reconstructs_duplicate_mappings_by_entity_kind_and_ignores_aliases(): void
    {
        [$integration, $channel] = $this->createIntegration();
        $simpleA = $this->createProduct('ERP-SIMPLE-A', 'Prosty duplikat A');
        $simpleB = $this->createProduct('ERP-SIMPLE-B', 'Prosty duplikat B');
        $parent = $this->createProduct('ERP-VARIANTS', 'Rodzina wariantów z duplikatem');
        $variantA = $this->createProduct('ERP-VARIANT-A', 'Wariant duplikat A');
        $variantB = $this->createProduct('ERP-VARIANT-B', 'Wariant duplikat B');
        $safeSibling = $this->createProduct('ERP-VARIANT-SAFE', 'Wariant bez konfliktu');
        $crossProduct = $this->createProduct('ERP-CROSS-PRODUCT', 'Produkt tylko z tym samym SKU co wariant');
        $crossParent = $this->createProduct('ERP-CROSS-PARENT', 'Rodzina kontrolna');
        $crossVariant = $this->createProduct('ERP-CROSS-VARIANT', 'Wariant tylko z tym samym SKU co produkt');

        $this->attachVariant($parent, $variantA, 10);
        $this->attachVariant($parent, $variantB, 20);
        $this->attachVariant($parent, $safeSibling, 30);
        $this->attachVariant($crossParent, $crossVariant, 10);

        $this->createMapping($simpleA, $channel, '101', null, 'DUPLICATE');
        $this->createMapping($simpleB, $channel, '102', null, 'duplicate');
        $this->createMapping($variantA, $channel, '201', '211', 'DUPLICATE');
        $this->createMapping($variantB, $channel, '202', '212', 'duplicate');
        $this->createMapping($safeSibling, $channel, '203', '213', 'SAFE');
        $this->createMapping($crossProduct, $channel, '301', null, 'CROSS-KIND');
        $this->createMapping($crossVariant, $channel, '302', '312', 'CROSS-KIND');

        ProductChannelAlias::query()->create([
            'product_id' => $simpleA->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '901',
            'external_sku' => 'ALIAS-ONLY',
            'language' => 'pl',
        ]);
        ProductChannelAlias::query()->create([
            'product_id' => $simpleB->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '902',
            'external_sku' => 'ALIAS-ONLY',
            'language' => 'en',
        ]);

        $log = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_products',
            'status' => 'success',
            'response_payload' => [
                'source_items' => 9,
                'duplicate_sku_items' => 2,
            ],
            'attempts' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $response = $this->get(route('products.index', ['import_issue' => $log->id]))->assertOk();
        $this->assertSame(
            ['ERP-VARIANTS', 'ERP-SIMPLE-B', 'ERP-SIMPLE-A'],
            $response->viewData('productRows')->getCollection()->pluck('product.sku')->all(),
        );
        $response
            ->assertSee('Ten starszy log nie zawierał jeszcze listy pozycji.')
            ->assertSee('Prosty duplikat A')
            ->assertSee('Prosty duplikat B')
            ->assertSee('Rodzina wariantów z duplikatem')
            ->assertSee('Wariant duplikat A')
            ->assertSee('Wariant duplikat B')
            ->assertSee(route('products.edit', $simpleA), false)
            ->assertSee(route('products.edit', $variantA), false)
            ->assertDontSee('Wariant bez konfliktu')
            ->assertDontSee('Produkt tylko z tym samym SKU co wariant')
            ->assertDontSee('Wariant tylko z tym samym SKU co produkt')
            ->assertDontSee('CROSS-KIND')
            ->assertDontSee('ALIAS-ONLY');

        $this->assertSame(2, substr_count($response->getContent(), '<strong>DUPLICATE</strong>'));
        $this->assertStringContainsString('>Produkt<', $response->getContent());
        $this->assertStringContainsString('>Wariant<', $response->getContent());
    }

    public function test_import_issue_filter_rejects_a_log_from_another_operation(): void
    {
        [$integration, $channel] = $this->createIntegration();
        $log = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_orders',
            'status' => 'success',
            'attempts' => 1,
        ]);

        $this->get(route('products.index', ['import_issue' => $log->id]))->assertNotFound();
    }

    /** @return array{0:WordpressIntegration,1:SalesChannel} */
    private function createIntegration(): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
            'invoice_upload_enabled' => true,
        ]);

        return [$integration, $channel];
    }

    private function createProduct(string $sku, string $name): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
    }

    private function attachVariant(Product $parent, Product $variant, int $sortOrder): void
    {
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => $sortOrder,
        ]);
    }

    private function createMapping(
        Product $product,
        SalesChannel $channel,
        string $externalProductId,
        ?string $externalVariationId,
        string $externalSku,
    ): void {
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => $externalProductId,
            'external_variation_id' => $externalVariationId,
            'external_sku' => $externalSku,
            'stock_sync_enabled' => true,
            'metadata' => ['language' => 'pl'],
        ]);
    }
}
