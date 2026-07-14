<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class WooCommerceProductTranslationLinkTest extends TestCase
{
    use RefreshDatabase;

    private const CONSUMER_KEY = 'ck_product_translation_test';

    private const CONSUMER_SECRET = 'cs_product_translation_test';

    public function test_it_confirms_the_plugin_and_languages_before_a_backfill_starts(): void
    {
        Http::fake([
            'https://shop.example.test/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities' => Http::response([
                'available' => true,
                'attribute_term_translation_link_available' => true,
                'resource' => 'product_translation_link',
                'languages' => ['pl', 'en'],
                'catalog_contract' => 1,
                'plugin_version' => '0.5.1',
            ]),
        ]);

        $this->assertTrue(app(WooCommerceClient::class)->productTranslationLinkingAvailable(
            $this->integration(),
            ['pl', 'en'],
        ));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://shop.example.test/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities'
            && $request->hasHeader(
                'Authorization',
                'Basic '.base64_encode(self::CONSUMER_KEY.':'.self::CONSUMER_SECRET),
            ));
    }

    public function test_backfill_readiness_is_false_for_an_old_or_missing_plugin_route(): void
    {
        Http::fake([
            'https://shop.example.test/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities' => Http::response([
                'code' => 'rest_no_route',
            ], 404),
        ]);

        $this->assertFalse(app(WooCommerceClient::class)->productTranslationLinkingAvailable(
            $this->integration(),
            ['pl', 'en'],
        ));
    }

    public function test_backfill_readiness_requires_attribute_term_linking_capability(): void
    {
        Http::fake([
            'https://shop.example.test/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities' => Http::response([
                'available' => true,
                'attribute_term_translation_link_available' => false,
                'resource' => 'product_translation_link',
                'languages' => ['pl', 'en'],
                'catalog_contract' => 1,
                'plugin_version' => '0.5.1',
            ]),
        ]);

        $this->assertFalse(app(WooCommerceClient::class)->productTranslationLinkingAvailable(
            $this->integration(),
            ['pl', 'en'],
        ));
    }

    public function test_backfill_readiness_rejects_ambiguous_previous_050_package(): void
    {
        Http::fake([
            'https://shop.example.test/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities' => Http::response([
                'available' => true,
                'resource' => 'product_translation_link',
                'languages' => ['pl', 'en'],
                'catalog_contract' => 1,
                'plugin_version' => '0.5.0',
            ]),
        ]);

        $this->assertFalse(app(WooCommerceClient::class)->productTranslationLinkingAvailable(
            $this->integration(),
            ['pl', 'en'],
        ));
    }

    public function test_it_links_products_with_woocommerce_credentials_and_normalized_payload(): void
    {
        Http::fake([
            'https://shop.example.test/wp-json/wc-lemon-erp/v1/catalog/products/translations' => Http::response([
                'linked' => true,
                'changed' => true,
                'resource' => 'product',
                'translations' => ['en' => 750099, 'pl' => 700143],
                'translation_group' => 'product:700143|750099',
                'plugin_version' => '0.5.1',
            ]),
        ]);

        $result = app(WooCommerceClient::class)->linkProductTranslations(
            $this->integration(),
            ['pl' => 700143, 'en' => '750099'],
        );

        $this->assertTrue($result['linked']);
        $this->assertTrue($result['changed']);
        $this->assertSame(['en' => 750099, 'pl' => 700143], $result['translations']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://shop.example.test/wp-json/wc-lemon-erp/v1/catalog/products/translations'
                && $request['translations'] === ['en' => 750099, 'pl' => 700143]
                && $request->hasHeader(
                    'Authorization',
                    'Basic '.base64_encode(self::CONSUMER_KEY.':'.self::CONSUMER_SECRET),
                );
        });
    }

    public function test_it_rejects_an_incomplete_map_before_sending_a_request(): void
    {
        Http::fake();

        try {
            app(WooCommerceClient::class)->linkProductTranslations(
                $this->integration(),
                ['pl' => 700143],
            );
            $this->fail('An incomplete translation map should have been rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('co najmniej dwóch języków', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_it_reports_that_the_plugin_must_be_updated_when_the_route_is_missing(): void
    {
        Http::fake([
            'https://shop.example.test/wp-json/wc-lemon-erp/v1/catalog/products/translations' => Http::response([
                'code' => 'rest_no_route',
                'message' => 'No route was found matching the URL and request method.',
            ], 404),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('co najmniej 0.5.1');

        app(WooCommerceClient::class)->linkProductTranslations(
            $this->integration(),
            ['pl' => 700143, 'en' => 750099],
        );
    }

    public function test_it_propagates_a_specific_remote_validation_error(): void
    {
        Http::fake([
            'https://shop.example.test/wp-json/wc-lemon-erp/v1/catalog/products/translations' => Http::response([
                'code' => 'lemon_erp_product_translation_conflict',
                'message' => 'Produkt 700143 należy już do innej rodziny tłumaczeń.',
                'data' => ['status' => 409],
            ], 409),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Produkt 700143 należy już do innej rodziny tłumaczeń.');

        app(WooCommerceClient::class)->linkProductTranslations(
            $this->integration(),
            ['pl' => 700143, 'en' => 750099],
        );
    }

    private function integration(): WordpressIntegration
    {
        $channel = SalesChannel::query()->create([
            'code' => 'PRODUCT-TRANSLATIONS',
            'name' => 'Sklep tłumaczeń produktów',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo product translations',
            'base_url' => 'https://shop.example.test',
            'consumer_key_encrypted' => Crypt::encryptString(self::CONSUMER_KEY),
            'consumer_secret_encrypted' => Crypt::encryptString(self::CONSUMER_SECRET),
            'wp_api_username' => null,
            'wp_api_password_encrypted' => null,
            'settings' => [],
        ]);
    }
}
