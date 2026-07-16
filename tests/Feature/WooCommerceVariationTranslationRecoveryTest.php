<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\WarehouseChannelRoute;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\ProductDataExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class WooCommerceVariationTranslationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambiguous_english_variation_post_recovers_by_token_without_a_duplicate(): void
    {
        $family = $this->family('AMBIGUOUS');
        $retry = false;
        $creationToken = null;
        $temporarySku = null;

        Http::fake(function (Request $request) use (&$retry, &$creationToken, &$temporarySku) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities') {
                return Http::response($this->readyCapabilities());
            }

            if ($request->method() === 'GET'
                && $path === '/wp-json/wc/v3/products/124/variations'
            ) {
                if (filled($request['sku'] ?? null)) {
                    return $retry ? Http::response([[
                        'id' => 457,
                        'sku' => $temporarySku,
                        'meta_data' => [[
                            'key' => '_sempre_erp_variation_translation_creation_token',
                            'value' => $creationToken,
                        ]],
                    ]]) : Http::response([]);
                }

                return Http::response([]);
            }

            if ($request->method() === 'POST'
                && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124/variations?lang=en'
            ) {
                $temporarySku = (string) $request['sku'];
                $creationToken = (string) data_get(
                    collect((array) $request['meta_data'])->firstWhere(
                        'key',
                        '_sempre_erp_variation_translation_creation_token',
                    ),
                    'value',
                );

                // Woo committed the draft, but the gateway lost its response.
                return Http::response(['message' => 'Gateway timeout'], 504);
            }

            return $this->successfulFamilyResponse($request, $retry);
        });

        try {
            app(ProductDataExportService::class)->export($family['parent']);
            $this->fail('The first ambiguous variation export should stay pending.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('HTTP 504', $exception->getMessage());
        }

        $this->assertNotSame('', $creationToken);
        $this->assertMatchesRegularExpression('/^LEMON-VTR-[a-f0-9]{40}$/', (string) $temporarySku);
        $this->assertDatabaseMissing('product_channel_aliases', [
            'product_id' => $family['variant']->id,
            'sales_channel_id' => $family['channel']->id,
            'language' => 'en',
        ]);
        $this->assertTrue((bool) data_get(
            $family['variant_mapping']->refresh()->metadata,
            'variation_translation_creation.en.pending',
        ));

        $retry = true;
        app(ProductDataExportService::class)->export($family['parent']->fresh());

        $this->assertSame(1, $this->englishVariationCreateRequests()->count());
        $this->assertDatabaseHas('product_channel_aliases', [
            'product_id' => $family['variant']->id,
            'sales_channel_id' => $family['channel']->id,
            'external_product_id' => '124',
            'external_variation_id' => '457',
            'external_sku' => $family['variant']->sku,
            'language' => 'en',
        ]);
        $state = (array) data_get(
            $family['variant_mapping']->refresh()->metadata,
            'variation_translation_creation.en',
        );
        $this->assertFalse((bool) ($state['pending'] ?? true));
        $this->assertSame('457', $state['external_variation_id'] ?? null);

        $temporaryCreate = $this->englishVariationCreateRequests()->sole();
        $this->assertSame('draft', $temporaryCreate['status']);
        $this->assertSame('699.00', $temporaryCreate['regular_price']);
        $this->assertSame(0, $temporaryCreate['stock_quantity']);
        $this->assertSame('outofstock', $temporaryCreate['stock_status']);
        $this->assertArrayNotHasKey('global_unique_id', $temporaryCreate->data());
        $this->assertFalse(collect((array) $temporaryCreate['meta_data'])->contains(
            fn (array $meta): bool => in_array($meta['key'] ?? null, ['_ean', '_sempre_erp_ean'], true),
        ));

        $finalUpdate = $this->englishVariationUpdateRequests()->sole();
        $this->assertSame($family['variant']->sku, $finalUpdate['sku']);
        $this->assertSame($family['variant']->ean, $finalUpdate['global_unique_id']);
        $this->assertSame('publish', $finalUpdate['status']);
        $this->assertTrue($finalUpdate['manage_stock']);
        $this->assertSame('699.00', $finalUpdate['regular_price']);
        $this->assertSame(4, $finalUpdate['stock_quantity']);
        $this->assertSame('instock', $finalUpdate['stock_status']);
        $this->assertSame(20, $finalUpdate['menu_order']);
        $this->assertArrayNotHasKey('date_created', $finalUpdate->data());
        $this->assertTrue(collect((array) $finalUpdate['meta_data'])->contains(
            fn (array $meta): bool => ($meta['key'] ?? null) === '_ean'
                && ($meta['value'] ?? null) === $family['variant']->ean,
        ));
        $this->assertTrue(collect((array) $finalUpdate['meta_data'])->contains(
            fn (array $meta): bool => $meta['key'] === '_sempre_erp_publication_date'
                && $meta['value'] === '2026-07-15T08:30:00',
        ));

        $primaryUpdate = $this->primaryVariationUpdateRequests()->last();
        $this->assertInstanceOf(Request::class, $primaryUpdate);
        $this->assertTrue($primaryUpdate['manage_stock']);
        $this->assertSame(4, $primaryUpdate['stock_quantity']);
        $this->assertSame('instock', $primaryUpdate['stock_status']);

        $this->assertLinkPrecedesFinalUpdate();
    }

    public function test_final_put_failure_keeps_allocated_child_private_and_retry_reuses_it(): void
    {
        $family = $this->family('FINAL-PUT');
        $retry = false;

        Http::fake(function (Request $request) use (&$retry) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities') {
                return Http::response($this->readyCapabilities());
            }

            if ($request->method() === 'GET'
                && $path === '/wp-json/wc/v3/products/124/variations'
            ) {
                return Http::response([]);
            }

            if ($request->method() === 'POST'
                && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124/variations?lang=en'
            ) {
                return Http::response(['id' => 457, 'sku' => $request['sku']], 201);
            }

            if ($request->method() === 'PUT'
                && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124/variations/457?lang=en'
            ) {
                return $retry
                    ? Http::response(['id' => 457, 'sku' => $request['sku']])
                    : Http::response(['message' => 'Final write failed'], 500);
            }

            return $this->successfulFamilyResponse($request, true);
        });

        try {
            app(ProductDataExportService::class)->export($family['parent']);
            $this->fail('The failed canonical PUT should leave the allocation pending.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('500', $exception->getMessage());
        }

        $state = (array) data_get(
            $family['variant_mapping']->refresh()->metadata,
            'variation_translation_creation.en',
        );
        $this->assertTrue((bool) ($state['pending'] ?? false));
        $this->assertSame('457', $state['external_variation_id'] ?? null);
        $this->assertDatabaseMissing('product_channel_aliases', [
            'product_id' => $family['variant']->id,
            'sales_channel_id' => $family['channel']->id,
            'language' => 'en',
        ]);

        $retry = true;
        app(ProductDataExportService::class)->export($family['parent']->fresh());

        $this->assertSame(1, $this->englishVariationCreateRequests()->count());
        $this->assertSame(2, $this->variationLinkRequests()->count());
        $this->assertDatabaseHas('product_channel_aliases', [
            'product_id' => $family['variant']->id,
            'sales_channel_id' => $family['channel']->id,
            'external_variation_id' => '457',
            'language' => 'en',
        ]);
        $this->assertFalse((bool) data_get(
            $family['variant_mapping']->refresh()->metadata,
            'variation_translation_creation.en.pending',
            true,
        ));
    }

    public function test_discovered_existing_child_is_linked_before_its_canonical_update(): void
    {
        $family = $this->family('DISCOVERED');

        Http::fake(function (Request $request) use ($family) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities') {
                return Http::response($this->readyCapabilities());
            }

            if ($request->method() === 'GET'
                && $path === '/wp-json/wc/v3/products/124/variations'
            ) {
                return Http::response([[
                    'id' => 457,
                    'sku' => $family['variant']->sku,
                    'attributes' => [],
                ]]);
            }

            return $this->successfulFamilyResponse($request, true);
        });

        app(ProductDataExportService::class)->export($family['parent']);

        $this->assertCount(0, $this->englishVariationCreateRequests());
        $this->assertCount(1, $this->variationLinkRequests());
        $this->assertCount(1, $this->englishVariationUpdateRequests());
        $this->assertLinkPrecedesFinalUpdate();
        $this->assertDatabaseHas('product_channel_aliases', [
            'product_id' => $family['variant']->id,
            'sales_channel_id' => $family['channel']->id,
            'external_variation_id' => '457',
            'language' => 'en',
        ]);
    }

    /**
     * @return array{channel:SalesChannel,integration:WordpressIntegration,parent:Product,variant:Product,variant_mapping:ProductChannelMapping}
     */
    private function family(string $suffix): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-'.$suffix,
            'name' => 'B2C '.$suffix,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo '.$suffix,
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);
        $parent = Product::query()->create([
            'sku' => 'PARENT-'.$suffix,
            'name' => 'Rodzina '.$suffix,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
                'publication_status' => 'publish',
                'publication_date' => '2026-07-15T08:30',
                // Variable parents intentionally do not manage stock in Woo;
                // their sellable quantities live on the child SKUs.
                'inventory' => ['manage_stock' => false],
                'content' => [
                    'pl' => ['name' => 'Rodzina '.$suffix],
                    'en' => ['name' => 'Family '.$suffix],
                ],
            ]],
        ]);
        $variant = Product::query()->create([
            'sku' => 'VARIANT-'.$suffix,
            'ean' => '5901234500'.str_pad((string) strlen($suffix), 3, '0', STR_PAD_LEFT),
            'name' => 'Wariant '.$suffix,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'variation',
                'inheritance' => [
                    'mode' => 'parent',
                    'parent_product_id' => $parent->id,
                ],
                'parameters' => [[
                    'name' => 'Rozmiar',
                    'name_en' => 'Size',
                    'value' => 'S',
                    'value_en' => 'S',
                    'variation' => true,
                ]],
            ]],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 20,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
        ]);
        $variantMapping = ProductChannelMapping::query()->create([
            'product_id' => $variant->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_variation_id' => '456',
            'external_sku' => $variant->sku,
            'stock_sync_enabled' => true,
        ]);
        ProductChannelAlias::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '124',
            'external_key' => ProductChannelAlias::externalKey('124', null),
            'external_sku' => $parent->sku,
            'language' => 'en',
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'WH-'.$suffix,
            'name' => 'Warehouse '.$suffix,
            'type' => 'own',
            'allow_negative_stock' => false,
            'is_active' => true,
        ]);
        WarehouseChannelRoute::query()->create([
            'warehouse_id' => $warehouse->id,
            'sales_channel_id' => $channel->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 10,
        ]);
        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $variant->id,
            'quantity_on_hand' => 4,
            'quantity_reserved' => 0,
            'quantity_available' => 4,
        ]);
        $unroutedWarehouse = Warehouse::query()->create([
            'code' => 'UNROUTED-'.$suffix,
            'name' => 'Unrouted warehouse '.$suffix,
            'type' => 'own',
            'allow_negative_stock' => false,
            'is_active' => true,
        ]);
        StockBalance::query()->create([
            'warehouse_id' => $unroutedWarehouse->id,
            'product_id' => $variant->id,
            'quantity_on_hand' => 20,
            'quantity_reserved' => 0,
            'quantity_available' => 20,
        ]);

        return compact('channel', 'integration', 'parent', 'variant') + [
            'variant_mapping' => $variantMapping,
        ];
    }

    /** @return array<string, mixed> */
    private function readyCapabilities(): array
    {
        return [
            'available' => true,
            'plugin_version' => '0.5.3',
            'languages' => ['pl', 'en'],
            'attribute_term_translation_link_available' => true,
            'variation_translation_link_available' => true,
            'variation_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations',
        ];
    }

    private function successfulFamilyResponse(Request $request, bool $allowEnglishUpdate): mixed
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($request->method() === 'GET'
            && $path === '/wp-json/wc/v3/products/attributes'
        ) {
            return Http::response([[
                'id' => 70,
                'name' => 'Rozmiar',
                'slug' => 'pa_rozmiar',
                'order_by' => 'menu_order',
            ]]);
        }

        if ($request->method() === 'GET'
            && $path === '/wp-json/wc/v3/products/attributes/70/terms'
        ) {
            $language = (string) ($request['lang'] ?? 'pl');
            $id = $language === 'en' ? 801 : 701;

            return Http::response([[
                'id' => $id,
                'name' => 'S',
                'slug' => $language === 'en' ? 's-en' : 's-pl',
                'lang' => $language,
                'translations' => ['pl' => 701, 'en' => 801],
                'menu_order' => 10,
                'count' => 1,
            ]]);
        }

        if ($request->method() === 'POST'
            && $path === '/wp-json/wc-lemon-erp/v1/catalog/products/attributes/70/terms/translations'
        ) {
            return Http::response([
                'linked' => true,
                'attribute_id' => 70,
                'translations' => $request['translations'],
            ]);
        }

        if ($request->method() === 'POST'
            && $url === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations'
        ) {
            return Http::response([
                'linked' => true,
                'translations' => $request['translations'],
                'parents' => $request['parents'],
                'translation_group' => 'variation:456|457',
            ]);
        }

        if ($request->method() === 'PUT'
            && $url === 'https://shop.test/wp-json/wc/v3/products/124/variations/457?lang=en'
            && $allowEnglishUpdate
        ) {
            return Http::response(['id' => 457, 'sku' => $request['sku']]);
        }

        return match ([$request->method(), $url]) {
            ['PUT', 'https://shop.test/wp-json/wc/v3/products/123'] => Http::response(['id' => 123, 'sku' => $request['sku']]),
            ['PUT', 'https://shop.test/wp-json/wc/v3/products/124?lang=en'] => Http::response(['id' => 124, 'sku' => $request['sku']]),
            ['PUT', 'https://shop.test/wp-json/wc/v3/products/123/variations/456'] => Http::response([
                'id' => 456,
                'sku' => $request['sku'],
                'regular_price' => '699.00',
            ]),
            default => throw new RuntimeException('Unexpected request: '.$request->method().' '.$url),
        };
    }

    private function englishVariationCreateRequests()
    {
        return Http::recorded()
            ->map(fn (array $record) => $record[0])
            ->filter(fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124/variations?lang=en')
            ->values();
    }

    private function englishVariationUpdateRequests()
    {
        return Http::recorded()
            ->map(fn (array $record) => $record[0])
            ->filter(fn (Request $request): bool => $request->method() === 'PUT'
                && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124/variations/457?lang=en')
            ->values();
    }

    private function primaryVariationUpdateRequests()
    {
        return Http::recorded()
            ->map(fn (array $record) => $record[0])
            ->filter(fn (Request $request): bool => $request->method() === 'PUT'
                && $request->url() === 'https://shop.test/wp-json/wc/v3/products/123/variations/456')
            ->values();
    }

    private function variationLinkRequests()
    {
        return Http::recorded()
            ->map(fn (array $record) => $record[0])
            ->filter(fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations')
            ->values();
    }

    private function assertLinkPrecedesFinalUpdate(): void
    {
        $requests = Http::recorded()->map(fn (array $record) => $record[0])->values();
        $linkIndex = $requests->search(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations');
        $updateIndex = $requests->search(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/124/variations/457?lang=en');

        $this->assertIsInt($linkIndex);
        $this->assertIsInt($updateIndex);
        $this->assertLessThan($updateIndex, $linkIndex);
    }
}
