<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\ProductDataExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The imported `woocommerce_translations` snapshot must not permanently
 * suppress translation creation. After an operator permanently deletes the
 * translated Woo post, the stale snapshot said "translation exists", so the
 * export never rebuilt EN (reported on Buty KESJA Czarne: translations []
 * forever). A full export now verifies snapshot entries and prunes the dead.
 */
class WooDeadTranslationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Product,1:ProductDataExportService} */
    private function familyWithSnapshot(): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl']]],
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-SNAPSHOT',
            'name' => 'Produkt ze snapshotem',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => ['source' => 'erp'],
                'woocommerce_translations' => [
                    'en' => ['product_id' => '555', 'variation_id' => null, 'sku' => 'SKU-SNAPSHOT'],
                ],
            ],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => 'SKU-SNAPSHOT',
            'stock_sync_enabled' => true,
        ]);

        return [$product, app(ProductDataExportService::class)];
    }

    public function test_full_export_prunes_a_snapshot_entry_pointing_at_a_deleted_post(): void
    {
        [$product, $exporter] = $this->familyWithSnapshot();
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/555') {
                return Http::response([
                    'code' => 'woocommerce_rest_product_invalid_id',
                    'message' => 'Nieprawidłowy identyfikator.',
                    'data' => ['status' => 404],
                ], 404);
            }

            return Http::response(['id' => 123, 'sku' => 'SKU-SNAPSHOT'], 200);
        });

        $exporter->export($product);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/wp-json/wc/v3/products/555'));
        $this->assertSame(
            [],
            (array) data_get($product->fresh()->attributes, 'woocommerce_translations'),
        );
    }

    public function test_full_export_keeps_a_snapshot_entry_whose_post_is_alive(): void
    {
        [$product, $exporter] = $this->familyWithSnapshot();
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/555') {
                return Http::response(['id' => 555, 'sku' => 'SKU-SNAPSHOT'], 200);
            }

            return Http::response(['id' => 123, 'sku' => 'SKU-SNAPSHOT'], 200);
        });

        $exporter->export($product);

        $this->assertSame(
            '555',
            (string) data_get($product->fresh()->attributes, 'woocommerce_translations.en.product_id'),
        );
    }

    public function test_transient_error_does_not_prune_and_fails_the_export(): void
    {
        [$product, $exporter] = $this->familyWithSnapshot();
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/555') {
                return Http::response(['message' => 'awaria'], 500);
            }

            return Http::response(['id' => 123, 'sku' => 'SKU-SNAPSHOT'], 200);
        });

        try {
            $exporter->export($product);
            $this->fail('Oczekiwano wyjątku przy błędzie przejściowym.');
        } catch (RequestException) {
            // expected: a 5xx must never be mistaken for a deleted post
        }

        $this->assertSame(
            '555',
            (string) data_get($product->fresh()->attributes, 'woocommerce_translations.en.product_id'),
        );
    }
}
