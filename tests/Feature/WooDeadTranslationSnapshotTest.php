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
 * suppress translation creation (a dead entry kept reporting the translation
 * as existing, so the export never rebuilt EN), but pruning has hard safety
 * rails: only a definitive deleted-post 404 prunes, only the exported
 * languages of a single-channel product are verified, and the rewrite runs
 * locked on a fresh row.
 */
class WooDeadTranslationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Product,1:ProductDataExportService,2:WordpressIntegration} */
    private function familyWithSnapshot(): array
    {
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
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-SNAPSHOT',
            'name' => 'Produkt ze snapshotem',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'content' => ['en' => ['name' => 'Snapshot product']],
                ],
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

        return [$product, app(ProductDataExportService::class), $integration];
    }

    public function test_prune_removes_an_entry_with_a_definitive_deleted_post_404(): void
    {
        [$product, $exporter, $integration] = $this->familyWithSnapshot();
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/555*' => Http::response([
                'code' => 'woocommerce_rest_product_invalid_id',
                'message' => 'Nieprawidłowy identyfikator.',
                'data' => ['status' => 404],
            ], 404),
        ]);

        $exporter->pruneDeadLegacyTranslationSnapshot($product, $integration);

        $this->assertNull(data_get($product->fresh()->attributes, 'woocommerce_translations.en'));
        $this->assertNull(data_get($product->fresh()->attributes, 'woocommerce_translations_verified_at'));
    }

    public function test_namespace_404_without_deletion_code_is_treated_as_transient(): void
    {
        [$product, $exporter, $integration] = $this->familyWithSnapshot();
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/555*' => Http::response([
                'code' => 'rest_no_route',
                'message' => 'Nie znaleziono routingu.',
                'data' => ['status' => 404],
            ], 404),
        ]);

        try {
            $exporter->pruneDeadLegacyTranslationSnapshot($product, $integration);
            $this->fail('Oczekiwano wyjątku dla 404 bez kodu skasowanego posta.');
        } catch (RequestException) {
            // expected: a deactivated plugin/WAF 404 must never erase a reference
        }

        $this->assertSame(
            '555',
            (string) data_get($product->fresh()->attributes, 'woocommerce_translations.en.product_id'),
        );
    }

    public function test_transient_error_does_not_prune(): void
    {
        [$product, $exporter, $integration] = $this->familyWithSnapshot();
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/555*' => Http::response(['message' => 'awaria'], 500),
        ]);

        try {
            $exporter->pruneDeadLegacyTranslationSnapshot($product, $integration);
            $this->fail('Oczekiwano wyjątku przy błędzie przejściowym.');
        } catch (RequestException) {
            // expected
        }

        $this->assertSame(
            '555',
            (string) data_get($product->fresh()->attributes, 'woocommerce_translations.en.product_id'),
        );
    }

    public function test_multi_channel_product_snapshot_is_never_verified_or_pruned(): void
    {
        [$product, $exporter, $integration] = $this->familyWithSnapshot();
        $second = SalesChannel::query()->create([
            'code' => 'B2B',
            'name' => 'Sklep B2B',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $second->id,
            'external_product_id' => '777',
            'external_sku' => 'SKU-SNAPSHOT',
            'stock_sync_enabled' => true,
        ]);
        Http::fake();

        $exporter->pruneDeadLegacyTranslationSnapshot($product, $integration);

        Http::assertNothingSent();
        $this->assertSame(
            '555',
            (string) data_get($product->fresh()->attributes, 'woocommerce_translations.en.product_id'),
        );
    }

    public function test_languages_outside_the_export_set_are_not_verified(): void
    {
        [$product, $exporter, $integration] = $this->familyWithSnapshot();
        $attributes = (array) $product->attributes;
        $attributes['woocommerce_translations'] = [
            'de' => ['product_id' => '888', 'variation_id' => null, 'sku' => 'SKU-SNAPSHOT'],
        ];
        $product->forceFill(['attributes' => $attributes])->save();
        Http::fake();

        $exporter->pruneDeadLegacyTranslationSnapshot($product->fresh(), $integration);

        Http::assertNothingSent();
        $this->assertSame(
            '888',
            (string) data_get($product->fresh()->attributes, 'woocommerce_translations.de.product_id'),
        );
    }

    public function test_full_export_runs_the_preflight_and_remembers_an_alive_verification(): void
    {
        [$product, $exporter] = $this->familyWithSnapshot();
        Http::fake(function ($request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            if (str_ends_with($path, '/catalog/products/translations/capabilities')) {
                return Http::response([
                    'available' => true,
                    'plugin_version' => '0.5.3',
                    'languages' => ['pl', 'en'],
                    'attribute_term_translation_link_available' => true,
                    'variation_translation_link_available' => true,
                    'variation_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations',
                ]);
            }

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products/555') {
                return Http::response(['id' => 555, 'sku' => 'SKU-SNAPSHOT'], 200);
            }

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products' && str_contains($url, 'lang=en')) {
                return Http::response([[
                    'id' => 555,
                    'sku' => 'SKU-SNAPSHOT',
                    'lang' => 'en',
                    'translations' => ['pl' => 123, 'en' => 555],
                ]]);
            }

            return Http::response(['id' => 123, 'sku' => 'SKU-SNAPSHOT'], 200);
        });

        $exporter->export($product);
        $exporter->export($product->fresh());

        // Only the FIRST export paid the verification GET; the alive marker
        // short-circuits the preflight for a week afterwards.
        $verificationCalls = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->method() === 'GET'
                && (string) parse_url($pair[0]->url(), PHP_URL_PATH) === '/wp-json/wc/v3/products/555'
                && str_contains($pair[0]->url(), '_lemon_erp_no_cache'))
            ->count();
        $this->assertSame(1, $verificationCalls);
        $this->assertNotNull(data_get($product->fresh()->attributes, 'woocommerce_translations_verified_at'));
    }
}
