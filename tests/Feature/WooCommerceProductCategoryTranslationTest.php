<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCategoryChannelAlias;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WooCommerceProductCategoryTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_merges_existing_polylang_categories_and_reassigns_all_references_idempotently(): void
    {
        [$channel, $integration] = $this->integration(['pl', 'en']);
        $polish = ProductCategory::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '10',
            'name' => 'Koszule',
            'slug' => 'koszule',
            'path' => 'Koszule',
            'description' => 'Stary opis PL',
        ]);
        $english = ProductCategory::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '11',
            'name' => 'Shirts',
            'slug' => 'shirts',
            'path' => 'Shirts',
            'description' => 'Old EN description',
        ]);
        $child = ProductCategory::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '12',
            'parent_external_id' => '11',
            'name' => 'Dziecko',
            'path' => 'Shirts > Dziecko',
        ]);
        $deepChild = ProductCategory::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '13',
            'parent_external_id' => '12',
            'name' => 'Wnuk',
            'path' => 'Shirts > Dziecko > Wnuk',
        ]);
        $product = Product::query()->create([
            'sku' => 'CATEGORY-MERGE-1',
            'name' => 'Produkt',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'woocommerce_import',
                    'category_id' => $english->id,
                    'category_ids' => [$english->id, $polish->id, $english->id],
                    'category' => 'Shirts',
                    'categories' => ['Shirts', 'Pozostałe'],
                ],
                'woocommerce_categories' => ['Shirts'],
            ],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_contains($url, '/products/categories')) {
                if ((int) ($query['page'] ?? 1) > 1) {
                    return Http::response([]);
                }

                $language = (string) ($query['lang'] ?? 'pl');

                return Http::response([
                    $language === 'en'
                        ? $this->categoryPayload(11, 'en', 'Shirts', 'shirts', 'English description')
                        : $this->categoryPayload(10, 'pl', 'Koszule', 'koszule', 'Polski opis'),
                ]);
            }

            if (str_contains($url, '/products')) {
                return Http::response([]);
            }

            return Http::response([], 404);
        });

        $service = app(WooCommerceImportService::class);
        $service->importProducts($integration);
        $service->importProducts($integration);

        $this->assertSame(3, ProductCategory::query()->count());
        $this->assertDatabaseHas('product_categories', [
            'id' => $polish->id,
            'external_id' => '10',
            'name' => 'Koszule',
            'description' => 'Polski opis',
        ]);
        $this->assertDatabaseMissing('product_categories', ['id' => $english->id]);
        $this->assertSame('11', data_get($polish->fresh()->metadata, 'woocommerce_ids.en'));
        $this->assertSame('Shirts', data_get($polish->fresh()->metadata, 'translations.en.name'));
        $this->assertSame('English description', data_get($polish->fresh()->metadata, 'translations.en.description'));
        $this->assertSame(2, ProductCategoryChannelAlias::query()->count());
        $this->assertDatabaseHas('product_category_channel_aliases', [
            'product_category_id' => $polish->id,
            'sales_channel_id' => $channel->id,
            'external_id' => '10',
            'language' => 'pl',
        ]);
        $this->assertDatabaseHas('product_category_channel_aliases', [
            'product_category_id' => $polish->id,
            'sales_channel_id' => $channel->id,
            'external_id' => '11',
            'language' => 'en',
        ]);

        $product->refresh();
        $this->assertSame($polish->id, data_get($product->attributes, 'master.category_id'));
        $this->assertSame([$polish->id], data_get($product->attributes, 'master.category_ids'));
        $this->assertSame('Koszule', data_get($product->attributes, 'master.category'));
        $this->assertSame(['Koszule', 'Pozostałe'], data_get($product->attributes, 'master.categories'));
        $this->assertSame(['Koszule'], data_get($product->attributes, 'woocommerce_categories'));
        $this->assertSame('10', (string) $child->fresh()->parent_external_id);
        $this->assertSame('Koszule > Dziecko', $child->fresh()->path);
        $this->assertSame('12', (string) $deepChild->fresh()->parent_external_id);
        $this->assertSame('Koszule > Dziecko > Wnuk', $deepChild->fresh()->path);
    }

    public function test_multilingual_category_import_without_explicit_lemon_contract_fails_before_writing(): void
    {
        [, $integration] = $this->integration(['pl', 'en']);

        Http::fake(function ($request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_contains($url, '/products/categories')) {
                if ((int) ($query['page'] ?? 1) > 1) {
                    return Http::response([]);
                }

                return Http::response([[
                    'id' => ($query['lang'] ?? 'pl') === 'en' ? 11 : 10,
                    'name' => ($query['lang'] ?? 'pl') === 'en' ? 'Shirts' : 'Koszule',
                ]]);
            }

            return Http::response([]);
        });

        try {
            app(WooCommerceImportService::class)->importProducts($integration);
            $this->fail('Import kategorii bez jawnego kontraktu powinien zostać zatrzymany.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Import kategorii wielojęzycznych został zatrzymany', $exception->getMessage());
            $this->assertStringContainsString('lemon_erp_translation_group', $exception->getMessage());
            $this->assertStringContainsString('0.2.0', $exception->getMessage());
        }

        $this->assertSame(0, ProductCategory::query()->count());
        $this->assertSame(0, ProductCategoryChannelAlias::query()->count());
    }

    /**
     * @param  list<string>  $languages
     * @return array{SalesChannel, WordpressIntegration}
     */
    private function integration(array $languages): array
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
            'settings' => ['product_import' => ['languages' => $languages]],
        ]);

        return [$channel, $integration];
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryPayload(
        int $id,
        string $language,
        string $name,
        string $slug,
        string $description,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'path' => $name,
            'description' => $description,
            'count' => 3,
            'lemon_erp_catalog_contract' => 1,
            'lemon_erp_language' => $language,
            'lemon_erp_translations' => ['pl' => 10, 'en' => 11],
            'lemon_erp_translation_group' => 'category:10|11',
        ];
    }
}
