<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ImportWooCommerceProductsJob;
use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WooCommerceProductImportDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_import_log_identifies_each_distinct_woo_product_sharing_a_sku(): void
    {
        $firstProduct = [
            'id' => 777,
            'sku' => 'DUPLICATE-SKU',
            'name' => 'Koszula PL',
            'type' => 'simple',
            'status' => 'publish',
            'permalink' => 'https://shop.test/produkt/koszula-pl',
            'description' => 'Treść produktu nie powinna trafić do diagnostyki.',
            'meta_data' => [['key' => 'private_note', 'value' => 'DO-NOT-LOG']],
        ];
        $secondProduct = [
            'id' => 778,
            'sku' => 'duplicate-sku',
            'name' => 'Koszula EN',
            'type' => 'simple',
            'status' => 'publish',
            'permalink' => 'https://shop.test/product/shirt-en',
        ];

        Http::fake(function ($request) use ($firstProduct, $secondProduct) {
            $url = $request->url();

            if (str_contains($url, '/products/categories')) {
                return Http::response([]);
            }

            if (str_contains($url, '/products')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return match ((int) ($query['page'] ?? 1)) {
                    1 => Http::response([$firstProduct, $secondProduct]),
                    // Simulate a moving WooCommerce result set: the same source
                    // identity returned again must not inflate the duplicate count.
                    2 => Http::response([$firstProduct]),
                    default => Http::response([]),
                };
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);
        $log = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_products',
            'status' => 'queued',
            'attempts' => 0,
        ]);

        (new ImportWooCommerceProductsJob($integration->id, $log->id))
            ->handle(app(WooCommerceImportService::class));

        $payload = $log->fresh()->response_payload;
        $group = $payload['duplicate_sku_groups'][0];
        $firstErpProduct = ProductChannelMapping::query()
            ->where('sales_channel_id', $channel->id)
            ->where('external_product_id', '777')
            ->whereNull('external_variation_id')
            ->firstOrFail()
            ->product;
        $secondErpProduct = ProductChannelMapping::query()
            ->where('sales_channel_id', $channel->id)
            ->where('external_product_id', '778')
            ->whereNull('external_variation_id')
            ->firstOrFail()
            ->product;

        $this->assertSame('success', $log->fresh()->status);
        $this->assertSame(3, $payload['source_items']);
        $this->assertSame(1, $payload['unique_skus_seen']);
        $this->assertSame(1, $payload['duplicate_sku_items']);
        $this->assertSame(1, $payload['duplicate_sku_groups_count']);
        $this->assertSame('DUPLICATE-SKU', $group['sku']);
        $this->assertSame('product', $group['entity_kind']);
        $this->assertSame(2, $group['occurrences']);
        $this->assertCount(2, $group['items']);
        $this->assertSame([
            [
                'erp_product_id' => $firstErpProduct->id,
                'woo_product_id' => '777',
                'woo_variation_id' => null,
                'name' => 'Koszula PL',
                'language' => 'pl',
                'permalink' => 'https://shop.test/produkt/koszula-pl',
            ],
            [
                'erp_product_id' => $secondErpProduct->id,
                'woo_product_id' => '778',
                'woo_variation_id' => null,
                'name' => 'Koszula EN',
                'language' => 'pl',
                'permalink' => 'https://shop.test/product/shirt-en',
            ],
        ], $group['items']);
        $this->assertNotSame($firstErpProduct->id, $secondErpProduct->id);
        $this->assertSame(2, Product::query()->count());
        $this->assertStringNotContainsString('DO-NOT-LOG', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Treść produktu', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
