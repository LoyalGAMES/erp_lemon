<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooVariationMappingRelinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Creating a primary-language variation whose canonical SKU is still owned by
 * another Woo post (typically the family's translated sibling after the PL
 * variations were deleted by hand) must resolve instead of failing the whole
 * export with a bare "HTTP 400".
 */
class WooVariationSkuConflictTest extends TestCase
{
    use RefreshDatabase;

    private function channel(): SalesChannel
    {
        return SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
    }

    private function integration(SalesChannel $channel): WordpressIntegration
    {
        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
        ]);
    }

    private function duplicateSkuResponse(): array
    {
        return [
            'code' => 'product_invalid_sku',
            'message' => 'Nieprawidłowy lub zduplikowany SKU.',
            'data' => ['status' => 400],
        ];
    }

    public function test_create_retries_without_sku_when_the_family_translation_owns_it(): void
    {
        $channel = $this->channel();
        $integration = $this->integration($channel);
        $root = Product::query()->create([
            'sku' => 'BLS-HEROS',
            'name' => 'Klapki HEROS Beżowe',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        // The EN translation of the family parent, as recorded by ERP.
        ProductChannelAlias::query()->create([
            'product_id' => $root->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '750093',
            'external_variation_id' => null,
            'external_sku' => 'BLS-HEROS',
            'language' => 'en',
        ]);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'POST' && $path === '/wp-json/wc/v3/products/700137/variations') {
                if (array_key_exists('sku', $request->data())) {
                    return Http::response($this->duplicateSkuResponse(), 400);
                }

                return Http::response(['id' => 999, 'sku' => ''], 201);
            }

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products') {
                // The canonical SKU currently lives on the EN sibling variation.
                return Http::response([[
                    'id' => 770149,
                    'sku' => 'BLS-HEROS-36',
                    'type' => 'variation',
                    'parent_id' => 750093,
                ]]);
            }

            return Http::response([], 200);
        });

        $result = app(WooVariationMappingRelinker::class)->createVariationResolvingSkuConflict(
            $integration,
            (int) $channel->id,
            '700137',
            ['sku' => 'BLS-HEROS-36', 'regular_price' => '589.00'],
        );

        $this->assertSame('created_without_sku', $result['resolution']);
        $this->assertSame(999, $result['response']['id']);
    }

    public function test_create_adopts_a_live_variation_under_the_same_parent(): void
    {
        $channel = $this->channel();
        $integration = $this->integration($channel);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'POST' && $path === '/wp-json/wc/v3/products/700137/variations') {
                return Http::response($this->duplicateSkuResponse(), 400);
            }

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products') {
                return Http::response([[
                    'id' => 555,
                    'sku' => 'BLS-HEROS-36',
                    'type' => 'variation',
                    'parent_id' => 700137,
                ]]);
            }

            if ($request->method() === 'PUT' && $path === '/wp-json/wc/v3/products/700137/variations/555') {
                return Http::response(['id' => 555, 'sku' => 'BLS-HEROS-36'], 200);
            }

            return Http::response([], 200);
        });

        $result = app(WooVariationMappingRelinker::class)->createVariationResolvingSkuConflict(
            $integration,
            (int) $channel->id,
            '700137',
            ['sku' => 'BLS-HEROS-36', 'regular_price' => '589.00'],
        );

        $this->assertSame('adopted_same_parent', $result['resolution']);
        $this->assertSame(555, $result['response']['id']);
    }

    public function test_create_rethrows_with_owner_details_for_a_foreign_sku_owner(): void
    {
        $channel = $this->channel();
        $integration = $this->integration($channel);
        // No alias rows: the owner's parent is not one of this family's posts.

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'POST' && $path === '/wp-json/wc/v3/products/700137/variations') {
                return Http::response($this->duplicateSkuResponse(), 400);
            }

            if ($request->method() === 'GET' && $path === '/wp-json/wc/v3/products') {
                return Http::response([[
                    'id' => 313,
                    'sku' => 'BLS-HEROS-36',
                    'type' => 'variation',
                    'parent_id' => 999888,
                ]]);
            }

            return Http::response([], 200);
        });

        try {
            app(WooVariationMappingRelinker::class)->createVariationResolvingSkuConflict(
                $integration,
                (int) $channel->id,
                '700137',
                ['sku' => 'BLS-HEROS-36'],
            );
            $this->fail('Oczekiwano wyjątku dla obcego właściciela SKU.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('product_invalid_sku', $exception->getMessage());
            $this->assertStringContainsString('#313', $exception->getMessage());
            $this->assertStringContainsString('rodzic #999888', $exception->getMessage());
        }
    }
}
