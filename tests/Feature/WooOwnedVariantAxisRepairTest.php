<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Jobs\ImportWooCommerceProductsJob;
use App\Jobs\RepairWooOwnedVariantAxisJob;
use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\ProductDataExportService;
use App\Services\WooCommerce\WooCommerceImportService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooOwnedVariantAxisRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_and_axis_repair_share_the_same_integration_catalog_lock(): void
    {
        [$parent] = $this->family();
        $channelId = (int) ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->value('sales_channel_id');
        $integrationId = (int) WordpressIntegration::query()
            ->where('sales_channel_id', $channelId)
            ->value('id');

        $importMiddleware = (new ImportWooCommerceProductsJob($integrationId, 1))->middleware();
        $repairMiddleware = (new RepairWooOwnedVariantAxisJob($parent->id, 'repair-token'))->middleware();

        $this->assertCount(1, $importMiddleware);
        $this->assertCount(2, $repairMiddleware);
        $this->assertSame(70, (new ImportWooCommerceProductsJob($integrationId, 1))->tries);
        $this->assertSame(2, (new ImportWooCommerceProductsJob($integrationId, 1))->maxExceptions);
        $this->assertSame(
            ImportWooCommerceProductsJob::catalogLockKey($integrationId),
            $importMiddleware[0]->key,
        );
        $this->assertTrue(collect($repairMiddleware)->contains(
            fn (object $middleware): bool => ($middleware->key ?? null)
                === ImportWooCommerceProductsJob::catalogLockKey($integrationId),
        ));
        $this->assertTrue($importMiddleware[0]->shareKey);
    }

    public function test_it_repairs_only_the_global_size_axis_for_existing_polish_and_english_products(): void
    {
        Bus::fake([ImportWooCommerceProductsJob::class]);
        [$parent, $catalog] = $this->family();
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('repaired', $result['status']);
        $this->assertSame(2, $result['targets']);
        $this->assertSame(6, $result['mutations']);
        $this->assertSame(['pl', 'en'], $result['languages']);
        Bus::assertNotDispatched(ImportWooCommerceProductsJob::class);

        foreach ([123, 223] as $parentId) {
            $attributes = collect($catalog->products[$parentId]['attributes']);
            $this->assertFalse($attributes->contains(
                fn (array $attribute): bool => in_array(
                    mb_strtolower((string) ($attribute['name'] ?? '')),
                    ['wariant', 'variant', 'blvariant'],
                    true,
                ),
            ));
            $size = $attributes->firstWhere('id', 1);
            $this->assertTrue((bool) ($size['variation'] ?? false));
            $this->assertSame(['S/M', 'M/L'], $size['options'] ?? null);
            $this->assertSame([[
                'id' => 1,
                'option' => 'M/L',
            ]], $catalog->products[$parentId]['default_attributes']);
        }

        foreach ([123 => [124 => 'S/M', 125 => 'M/L'], 223 => [224 => 'S/M', 225 => 'M/L']] as $parentId => $variants) {
            foreach ($variants as $variationId => $option) {
                $this->assertSame([[
                    'id' => 1,
                    'option' => $option,
                ]], $catalog->variations[$parentId][$variationId]['attributes']);
                $this->assertSame(
                    $option === 'S/M' ? 10 : 20,
                    $catalog->variations[$parentId][$variationId]['menu_order'],
                );
                $this->assertSame(3, $catalog->variations[$parentId][$variationId]['stock_quantity']);
                $this->assertSame('instock', $catalog->variations[$parentId][$variationId]['stock_status']);
            }
        }

        $freshParent = $parent->fresh('variantChildren');
        $this->assertSame('Rozmiar', data_get($freshParent->masterData(), 'variant_attribute'));
        $this->assertSame(
            ['Rozmiar'],
            collect((array) data_get($freshParent->masterData(), 'parameters', []))
                ->filter(fn (mixed $row): bool => is_array($row) && (bool) ($row['variation'] ?? false))
                ->pluck('name')
                ->values()
                ->all(),
        );
        $this->assertSame('S/M | M/L', collect((array) data_get($freshParent->masterData(), 'parameters', []))
            ->firstWhere('name', 'Rozmiar')['value']);

        foreach ($freshParent->variantChildren as $variant) {
            $option = str_ends_with($variant->sku, '-SM') ? 'S/M' : 'M/L';
            $this->assertSame('Rozmiar', data_get($variant->masterData(), 'variant_attribute'));
            $this->assertSame($option, collect((array) data_get($variant->masterData(), 'parameters', []))
                ->firstWhere('name', 'Rozmiar')['value']);
            $this->assertSame([['id' => 1, 'option' => $option]], data_get(
                $variant->attributes,
                'woocommerce_variation_attributes',
            ));
            $this->assertSame($option === 'S/M' ? 10 : 20, (int) $variant->pivot->sort_order);
        }

        $putRequests = Http::recorded()
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => $request->method() === 'PUT')
            ->values();
        $this->assertCount(6, $putRequests);
        $protected = [
            'name', 'description', 'short_description', 'sku', 'status', 'date_created',
            'regular_price', 'sale_price', 'price', 'manage_stock', 'stock_quantity',
            'stock_status', 'backorders', 'images', 'image', 'categories',
        ];

        foreach ($putRequests as $request) {
            $keys = array_keys($request->data());
            $isVariation = str_contains((string) parse_url($request->url(), PHP_URL_PATH), '/variations/');
            $this->assertEqualsCanonicalizing(
                $isVariation ? ['attributes', 'menu_order'] : ['attributes', 'default_attributes'],
                $keys,
            );

            foreach ($protected as $field) {
                $this->assertArrayNotHasKey($field, $request->data());
            }
        }

        $putsAfterFirstRepair = $putRequests->count();
        $second = app(WooOwnedVariantAxisRepairService::class)->repair($parent->fresh());
        $this->assertSame('already_canonical', $second['status']);
        $this->assertSame(0, $second['mutations']);
        $this->assertCount(
            $putsAfterFirstRepair,
            Http::recorded()
                ->map(fn (array $record): Request => $record[0])
                ->filter(fn (Request $request): bool => $request->method() === 'PUT'),
        );
    }

    public function test_a_mid_family_put_failure_rolls_the_language_back_to_its_exact_original_axis(): void
    {
        [$parent, $catalog] = $this->family();
        $originalParentAttributes = $catalog->products[123]['attributes'];
        $originalParentDefaults = $catalog->products[123]['default_attributes'];
        $originalEnglish = unserialize(serialize([
            'parent' => $catalog->products[223],
            'variations' => $catalog->variations[223],
        ]));

        $this->fakeCatalog($catalog, static function (Request $request): mixed {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $attributes = (array) data_get($request->data(), 'attributes', []);

            // The first Polish child has already been converted. Fail every
            // retry of the second forward PUT, while allowing its legacy-axis
            // rollback payload (id=6) through.
            if ($request->method() === 'PUT'
                && $path === '/wp-json/wc/v3/products/123/variations/125'
                && (int) data_get($attributes, '0.id', 0) === 1
            ) {
                return Http::response(['message' => 'forced mid-family failure'], 500);
            }

            return null;
        });

        try {
            app(WooOwnedVariantAxisRepairService::class)->repair($parent);
            $this->fail('Naprawa powinna zgłosić wymuszony błąd PUT.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('status code 500', $exception->getMessage());
        }

        $this->assertSame($originalParentAttributes, $catalog->products[123]['attributes']);
        $this->assertSame($originalParentDefaults, $catalog->products[123]['default_attributes']);

        foreach ([124 => ['s-m', 20], 125 => ['m-l', 10]] as $variationId => [$option, $menuOrder]) {
            $this->assertSame([[
                'id' => 6,
                'option' => $option,
            ]], collect($catalog->variations[123][$variationId]['attributes'])
                ->map(fn (array $attribute): array => [
                    'id' => (int) ($attribute['id'] ?? 0),
                    'option' => (string) ($attribute['option'] ?? ''),
                ])
                ->all());
            $this->assertSame($menuOrder, $catalog->variations[123][$variationId]['menu_order']);
        }

        // PL failed before EN started; an already coherent language is never
        // touched as collateral damage by another language's rollback.
        $this->assertSame($originalEnglish['parent'], $catalog->products[223]);
        $this->assertSame($originalEnglish['variations'], $catalog->variations[223]);
    }

    public function test_it_aborts_the_whole_pl_en_family_before_any_write_when_one_language_has_color_axis(): void
    {
        Bus::fake([ImportWooCommerceProductsJob::class]);
        [$parent, $catalog] = $this->family();
        $catalog->products[223]['attributes'][] = [
            'id' => 3,
            'name' => 'Color',
            'slug' => 'pa_color',
            'position' => 3,
            'visible' => true,
            'variation' => true,
            'options' => ['Black', 'White'],
        ];
        $this->fakeCatalog($catalog, function (Request $request, object $liveCatalog) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/wp-json/wc/v3/products/categories') {
                return Http::response([]);
            }

            if ($path !== '/wp-json/wc/v3/products' || $request->method() !== 'GET') {
                return null;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) ($query['page'] ?? 1) === 1
                ? Http::response([$liveCatalog->products[123]])
                : Http::response([]);
        });

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('manual_review', $result['status']);
        $this->assertStringContainsString('drugą oś wariantową', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
        Bus::assertNotDispatched(ImportWooCommerceProductsJob::class);
    }

    public function test_it_converts_a_custom_text_size_to_the_existing_global_cloud_taxonomy(): void
    {
        Bus::fake([ImportWooCommerceProductsJob::class]);
        [$parent, $catalog] = $this->family();

        foreach ([123, 223] as $parentId) {
            foreach ($catalog->products[$parentId]['attributes'] as &$attribute) {
                if ((int) ($attribute['id'] ?? 0) !== 1) {
                    continue;
                }

                $attribute['id'] = 0;
                unset($attribute['slug']);
            }
            unset($attribute);
        }

        $this->fakeCatalog($catalog);
        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('repaired', $result['status']);
        $this->assertSame(6, $result['mutations']);

        foreach ([123, 223] as $parentId) {
            $attributes = collect($catalog->products[$parentId]['attributes']);
            $this->assertCount(1, $attributes->where('variation', true));
            $this->assertSame(['S/M', 'M/L'], $attributes->firstWhere('id', 1)['options']);
        }

        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET'
            && str_contains($request->url(), '/products/attributes'));
    }

    public function test_custom_text_retry_accepts_children_already_partly_converted_to_the_global_axis(): void
    {
        [$parent, $catalog] = $this->family();

        foreach ([123, 223] as $parentId) {
            foreach ($catalog->products[$parentId]['attributes'] as &$attribute) {
                if ((int) ($attribute['id'] ?? 0) === 1) {
                    $attribute['id'] = 0;
                    unset($attribute['slug']);
                }
            }
            unset($attribute);

            $firstId = $parentId === 123 ? 124 : 224;
            $catalog->variations[$parentId][$firstId]['attributes'] = [[
                'id' => 1,
                'option' => 'S/M',
            ]];
        }

        $this->fakeCatalog($catalog);
        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('repaired', $result['status']);
        $this->assertSame([['id' => 1, 'option' => 'S/M']], $catalog->variations[123][124]['attributes']);
        $this->assertSame([['id' => 1, 'option' => 'M/L']], $catalog->variations[223][225]['attributes']);
    }

    public function test_it_finishes_a_partially_repaired_parent_when_children_still_use_generic_variant(): void
    {
        [$parent, $catalog] = $this->family();

        foreach ([123, 223] as $parentId) {
            $catalog->products[$parentId]['attributes'] = collect($catalog->products[$parentId]['attributes'])
                ->reject(fn (array $attribute): bool => in_array(
                    mb_strtolower((string) ($attribute['name'] ?? '')),
                    ['wariant', 'variant', 'blvariant'],
                    true,
                ))
                ->map(function (array $attribute): array {
                    if ((int) ($attribute['id'] ?? 0) === 1) {
                        $attribute['variation'] = true;
                    }

                    return $attribute;
                })
                ->values()
                ->all();
        }

        $this->fakeCatalog($catalog);
        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('repaired', $result['status']);
        $this->assertSame([['id' => 1, 'option' => 'S/M']], $catalog->variations[123][124]['attributes']);
        $this->assertSame([['id' => 1, 'option' => 'M/L']], $catalog->variations[223][225]['attributes']);
    }

    public function test_it_deduplicates_empty_and_translated_size_terms_when_children_form_one_bijection(): void
    {
        [$parent, $catalog] = $this->family();

        foreach ([123, 223] as $parentId) {
            foreach ($catalog->products[$parentId]['attributes'] as &$attribute) {
                if ((int) ($attribute['id'] ?? 0) === 1) {
                    $attribute['options'] = ['M/L', '', 'S/M', 's-m', '  '];
                }
            }
            unset($attribute);

            foreach ($catalog->variations[$parentId] as &$variation) {
                $legacyOption = (string) data_get($variation, 'attributes.0.option');
                $variation['attributes'][] = [
                    'id' => 1,
                    'name' => $parentId === 123 ? 'Rozmiar' : 'Size',
                    'option' => $legacyOption === 's-m' ? 'S/M' : 'M/L',
                ];
            }
            unset($variation);
        }

        $this->fakeCatalog($catalog);
        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('repaired', $result['status']);

        foreach ([123 => [124 => 'S/M', 125 => 'M/L'], 223 => [224 => 'S/M', 225 => 'M/L']] as $parentId => $variants) {
            $this->assertSame(
                ['S/M', 'M/L'],
                collect($catalog->products[$parentId]['attributes'])->firstWhere('id', 1)['options'],
            );

            foreach ($variants as $variationId => $option) {
                $this->assertSame([['id' => 1, 'option' => $option]], $catalog->variations[$parentId][$variationId]['attributes']);
                $this->assertSame($option === 'S/M' ? 10 : 20, $catalog->variations[$parentId][$variationId]['menu_order']);
            }
        }
    }

    public function test_it_recovers_an_erased_remote_child_option_only_from_the_exact_local_sku_bijection(): void
    {
        [$parent, $catalog] = $this->family();

        foreach ([123, 223] as $parentId) {
            foreach ($catalog->variations[$parentId] as &$variation) {
                $variation['attributes'] = [];
            }
            unset($variation);
        }

        $this->fakeCatalog($catalog);
        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('repaired', $result['status']);
        $this->assertSame([['id' => 1, 'option' => 'S/M']], $catalog->variations[123][124]['attributes']);
        $this->assertSame([['id' => 1, 'option' => 'M/L']], $catalog->variations[123][125]['attributes']);
        $this->assertSame([['id' => 1, 'option' => 'S/M']], $catalog->variations[223][224]['attributes']);
        $this->assertSame([['id' => 1, 'option' => 'M/L']], $catalog->variations[223][225]['attributes']);
    }

    public function test_it_rejects_conflicting_generic_and_global_child_options_before_any_write(): void
    {
        [$parent, $catalog] = $this->family();
        $catalog->variations[123][124]['attributes'][] = [
            'id' => 1,
            'name' => 'Rozmiar',
            'option' => 'M/L',
        ];
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('manual_review', $result['status']);
        $this->assertStringContainsString('nie mapuje się 1:1', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_it_rejects_empty_parent_size_terms_before_any_write(): void
    {
        [$parent, $catalog] = $this->family();

        foreach ($catalog->products[123]['attributes'] as &$attribute) {
            if ((int) ($attribute['id'] ?? 0) === 1) {
                $attribute['options'] = ['', '  '];
            }
        }
        unset($attribute);
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('manual_review', $result['status']);
        $this->assertStringContainsString('nie zawiera żadnej jednoznacznej wartości', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_it_aborts_before_writing_when_legacy_and_size_defaults_conflict(): void
    {
        [$parent, $catalog] = $this->family();
        $catalog->products[223]['default_attributes'][] = [
            'id' => 1,
            'name' => 'Size',
            'option' => 'S/M',
        ];
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('manual_review', $result['status']);
        $this->assertStringContainsString('sprzeczne wartości domyślne', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_it_rejects_a_stale_translation_alias_when_live_sku_family_does_not_match(): void
    {
        [$parent, $catalog] = $this->family();
        $catalog->products[223]['sku'] = 'ANOTHER-PRODUCT';
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('manual_review', $result['status']);
        $this->assertStringContainsString('SKU rodzica', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_it_rejects_a_same_sku_stale_alias_when_the_lemon_translation_contract_does_not_match(): void
    {
        [$parent, $catalog] = $this->family();
        $this->applyLemonContract($catalog);
        $catalog->products[223]['lemon_erp_translations'] = ['pl' => 123, 'en' => 999];
        $catalog->products[223]['lemon_erp_translation_group'] = 'product:123|999';
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('manual_review', $result['status']);
        $this->assertStringContainsString('Mapa tłumaczeń rodzica', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_catalog_contract_marker_never_falls_back_to_legacy_sku_identity(): void
    {
        [$parent, $catalog] = $this->family();
        $catalog->products[223]['lemon_erp_catalog_contract'] = 1;
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('manual_review', $result['status']);
        $this->assertStringContainsString('niepełny kontrakt', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_parent_contract_fields_never_fall_back_to_legacy_sku_identity(): void
    {
        [$parent, $catalog] = $this->family();
        $catalog->variations[223][224]['lemon_erp_parent_translation_group'] = 'product:123|223';
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('manual_review', $result['status']);
        $this->assertStringContainsString('niepełny kontrakt', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_the_complete_lemon_contract_authorizes_the_exact_translation_family_even_with_empty_en_skus(): void
    {
        [$parent, $catalog] = $this->family();
        $this->applyLemonContract($catalog);
        $catalog->products[223]['sku'] = '';
        $catalog->variations[223][224]['sku'] = '';
        $catalog->variations[223][225]['sku'] = '';
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('repaired', $result['status']);
        $this->assertSame(['S/M', 'M/L'], collect($catalog->products[223]['attributes'])
            ->firstWhere('id', 1)['options']);
        $this->assertSame([['id' => 1, 'option' => 'S/M']], $catalog->variations[223][224]['attributes']);
    }

    public function test_the_primary_catalog_contract_discovers_and_persists_missing_english_family_aliases(): void
    {
        [$parent, $catalog] = $this->family();
        $this->applyLemonContract($catalog);
        ProductChannelAlias::query()->where('language', 'en')->delete();
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent->fresh());

        $this->assertSame('repaired', $result['status'], (string) ($result['reason'] ?? ''));
        $this->assertSame(2, $result['targets']);
        $this->assertSame(6, $result['mutations']);

        $aliases = ProductChannelAlias::query()
            ->where('language', 'en')
            ->orderByRaw('external_variation_id IS NOT NULL')
            ->orderBy('external_variation_id')
            ->get();
        $this->assertCount(3, $aliases);
        $this->assertSame('223', (string) $aliases[0]->external_product_id);
        $this->assertNull($aliases[0]->external_variation_id);
        $this->assertSame($parent->id, $aliases[0]->product_id);
        $this->assertSame(
            ['224', '225'],
            $aliases->skip(1)->pluck('external_variation_id')->map(fn ($id): string => (string) $id)->all(),
        );
        $this->assertTrue($aliases->every(fn (ProductChannelAlias $alias): bool => data_get(
            $alias->metadata,
            'source',
        ) === 'woo_axis_repair_contract_discovery'));

        $putCount = Http::recorded()
            ->map(fn (array $record): Request => $record[0])
            ->where(fn (Request $request): bool => $request->method() === 'PUT')
            ->count();
        $second = app(WooOwnedVariantAxisRepairService::class)->repair($parent->fresh());
        $this->assertSame('already_canonical', $second['status']);
        $this->assertSame($putCount, Http::recorded()
            ->map(fn (array $record): Request => $record[0])
            ->where(fn (Request $request): bool => $request->method() === 'PUT')
            ->count());
    }

    public function test_contract_discovery_does_not_write_an_english_axis_that_is_already_canonical(): void
    {
        [$parent, $catalog] = $this->family();
        $this->applyLemonContract($catalog);
        ProductChannelAlias::query()->where('language', 'en')->delete();
        $catalog->products[223]['attributes'] = collect($catalog->products[223]['attributes'])
            ->reject(fn (array $attribute): bool => (int) ($attribute['id'] ?? 0) === 8)
            ->map(function (array $attribute): array {
                if ((int) ($attribute['id'] ?? 0) === 1) {
                    $attribute['variation'] = true;
                    $attribute['options'] = ['S/M', 'M/L'];
                }

                return $attribute;
            })
            ->values()
            ->all();
        $catalog->products[223]['default_attributes'] = [[
            'id' => 1,
            'name' => 'Size',
            'option' => 'M/L',
        ]];
        $catalog->variations[223][224]['attributes'] = [['id' => 1, 'name' => 'Size', 'option' => 'S/M']];
        $catalog->variations[223][224]['menu_order'] = 10;
        $catalog->variations[223][225]['attributes'] = [['id' => 1, 'name' => 'Size', 'option' => 'M/L']];
        $catalog->variations[223][225]['menu_order'] = 20;
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent->fresh());

        $this->assertSame('repaired', $result['status']);
        $this->assertSame(3, $result['mutations']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains((string) parse_url($request->url(), PHP_URL_PATH), '/products/223'));
        $this->assertSame([['id' => 1, 'name' => 'Size', 'option' => 'S/M']], $catalog->variations[223][224]['attributes']);
        $this->assertSame([['id' => 1, 'name' => 'Size', 'option' => 'M/L']], $catalog->variations[223][225]['attributes']);
    }

    public function test_contract_discovery_rejects_a_non_reciprocal_english_family_before_any_write(): void
    {
        [$parent, $catalog] = $this->family();
        $this->applyLemonContract($catalog);
        ProductChannelAlias::query()->where('language', 'en')->delete();
        $catalog->products[223]['lemon_erp_translations'] = ['pl' => 999, 'en' => 223];
        $catalog->products[223]['lemon_erp_translation_group'] = 'product:223|999';
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent->fresh());

        $this->assertSame('manual_review', $result['status']);
        $this->assertStringContainsString('Mapa tłumaczeń rodzica', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
        $this->assertFalse(ProductChannelAlias::query()->where('language', 'en')->exists());
    }

    public function test_contract_discovery_rejects_an_english_identity_mapped_to_another_local_product(): void
    {
        [$parent, $catalog] = $this->family();
        $this->applyLemonContract($catalog);
        ProductChannelAlias::query()->where('language', 'en')->delete();
        $channelId = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->value('sales_channel_id');
        $foreign = $this->product('FOREIGN-EN', 'Obce mapowanie EN', [
            'master' => ['source' => 'woocommerce_import', 'product_type' => 'simple'],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $foreign->id,
            'sales_channel_id' => $channelId,
            'external_product_id' => '223',
            'external_variation_id' => null,
            'external_sku' => 'FOREIGN-EN',
            'stock_sync_enabled' => true,
            'metadata' => ['source' => 'woocommerce_import', 'language' => 'en'],
        ]);
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent->fresh());

        $this->assertSame('manual_review', $result['status']);
        $this->assertStringContainsString('mapowanie do innego produktu ERP', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
        $this->assertFalse(ProductChannelAlias::query()->where('language', 'en')->exists());
    }

    public function test_contract_preflight_repairs_missing_child_aliases_when_the_english_parent_alias_already_exists(): void
    {
        [$parent, $catalog] = $this->family();
        $this->applyLemonContract($catalog);
        $parentAlias = ProductChannelAlias::query()
            ->where('product_id', $parent->id)
            ->where('language', 'en')
            ->firstOrFail();
        $parentAlias->forceFill([
            'language' => null,
            'metadata' => ['source' => 'existing_verified_import', 'keep' => true],
        ])->save();
        $parentAliasMetadata = $parentAlias->metadata;
        ProductChannelAlias::query()
            ->where('language', 'en')
            ->where('product_id', '!=', $parent->id)
            ->delete();
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent->fresh());

        $this->assertSame('repaired', $result['status']);
        $childIds = $parent->variantChildren()->pluck('products.id');
        $childAliases = ProductChannelAlias::query()
            ->whereIn('product_id', $childIds)
            ->where('language', 'en')
            ->orderBy('external_variation_id')
            ->get();
        $this->assertSame(['224', '225'], $childAliases
            ->pluck('external_variation_id')
            ->map(fn ($id): string => (string) $id)
            ->all());
        $this->assertSame($parentAliasMetadata, $parentAlias->refresh()->metadata);
    }

    public function test_contract_preflight_disables_stale_outbound_aliases_but_preserves_merge_routing(): void
    {
        [$parent, $catalog] = $this->family();
        $this->applyLemonContract($catalog);
        $child = $parent->variantChildren()->orderBy('products.sku')->firstOrFail();
        $channelId = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->value('sales_channel_id');
        $stale = ProductChannelAlias::query()->create([
            'product_id' => $child->id,
            'sales_channel_id' => $channelId,
            'external_product_id' => '999',
            'external_variation_id' => '998',
            'external_sku' => $child->sku,
            'language' => 'en',
            'metadata' => ['source' => 'historical_stale_alias'],
        ]);
        $staleParent = ProductChannelAlias::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channelId,
            'external_product_id' => '999',
            'external_variation_id' => null,
            'external_sku' => $parent->sku,
            'language' => 'en',
            'metadata' => ['source' => 'historical_stale_parent_alias'],
        ]);
        $german = ProductChannelAlias::query()->create([
            'product_id' => $child->id,
            'sales_channel_id' => $channelId,
            'external_product_id' => '777',
            'external_variation_id' => '778',
            'external_sku' => $child->sku,
            'language' => 'de',
            'metadata' => ['source' => 'other_language'],
        ]);
        $mergedSource = $this->product('MERGED-HISTORY', 'Scalony historyczny wariant', []);
        $merged = ProductChannelAlias::query()->create([
            'product_id' => $child->id,
            'source_product_id' => $mergedSource->id,
            'sales_channel_id' => $channelId,
            'external_product_id' => '666',
            'external_variation_id' => '667',
            'external_sku' => $child->sku,
            'language' => 'en',
            'metadata' => [
                'source' => 'ProductTranslationMergeService',
                'product_merge' => ['merged_from_product_id' => $mergedSource->id],
            ],
        ]);
        $canonicalChildAlias = ProductChannelAlias::query()
            ->where('product_id', $child->id)
            ->where('sales_channel_id', $channelId)
            ->where('external_product_id', '223')
            ->where('language', 'en')
            ->firstOrFail();
        $translationReferences = new \ReflectionMethod(
            ProductDataExportService::class,
            'translationReferences',
        );
        $translationReferences->setAccessible(true);
        $referencesBeforeRepair = $translationReferences->invoke(
            app(ProductDataExportService::class),
            $child,
            $channelId,
        );
        $this->assertSame('223', data_get($referencesBeforeRepair, 'en.product_id'));
        $this->assertSame(
            (string) $canonicalChildAlias->external_variation_id,
            data_get($referencesBeforeRepair, 'en.variation_id'),
        );
        $checkedBeforePut = false;
        $this->fakeCatalog($catalog, function (Request $request) use ($stale, &$checkedBeforePut) {
            if ($request->method() === 'PUT' && ! $checkedBeforePut) {
                $this->assertFalse($stale->refresh()->isOutboundSyncEnabled());
                $checkedBeforePut = true;
            }

            return null;
        });

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent->fresh());

        $this->assertSame('repaired', $result['status']);
        $this->assertTrue($checkedBeforePut);
        $this->assertDatabaseHas('product_channel_aliases', ['id' => $stale->id]);
        $this->assertFalse($stale->refresh()->isOutboundSyncEnabled());
        $this->assertFalse($staleParent->refresh()->isOutboundSyncEnabled());
        $this->assertDatabaseHas('product_channel_aliases', ['id' => $german->id]);
        $this->assertFalse($german->refresh()->isOutboundSyncEnabled());
        $this->assertDatabaseHas('product_channel_aliases', ['id' => $merged->id]);
        $this->assertFalse($merged->refresh()->isOutboundSyncEnabled());
        $this->assertSame(1, ProductChannelAlias::query()
            ->where('product_id', $child->id)
            ->where('sales_channel_id', $channelId)
            ->where('language', 'en')
            ->get()
            ->filter(fn (ProductChannelAlias $alias): bool => $alias->isOutboundSyncEnabled())
            ->count());
        $referencesAfterRepair = $translationReferences->invoke(
            app(ProductDataExportService::class),
            $child->fresh(),
            $channelId,
        );
        $this->assertSame('223', data_get($referencesAfterRepair, 'en.product_id'));
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            $request->url(),
            '/products/999',
        ) || str_contains($request->url(), '/products/666')
            || str_contains($request->url(), '/products/777'));
    }

    public function test_contract_alias_preflight_rejects_swapped_primary_variant_mapping_owners(): void
    {
        [$parent, $catalog] = $this->family();
        $this->applyLemonContract($catalog);
        ProductChannelAlias::query()->where('language', 'en')->delete();
        $children = $parent->variantChildren()->orderBy('products.sku')->get();
        $mappings = ProductChannelMapping::query()
            ->whereIn('product_id', $children->pluck('id'))
            ->orderBy('external_variation_id')
            ->get();
        $firstId = $mappings[0]->external_variation_id;
        $secondId = $mappings[1]->external_variation_id;
        $mappings[0]->update(['external_variation_id' => '999999999']);
        $mappings[1]->update(['external_variation_id' => $firstId]);
        $mappings[0]->update(['external_variation_id' => $secondId]);
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent->fresh());

        $this->assertSame('manual_review', $result['status']);
        $this->assertStringContainsString('jednoznacznie przypisać wariantu en', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
        $this->assertFalse(ProductChannelAlias::query()->where('language', 'en')->exists());
    }

    public function test_contract_discovery_rejects_a_stale_alias_language_for_the_same_remote_id(): void
    {
        [$parent, $catalog] = $this->family();
        $this->applyLemonContract($catalog);
        $parentAlias = ProductChannelAlias::query()
            ->where('product_id', $parent->id)
            ->where('language', 'en')
            ->firstOrFail();
        $parentAlias->update(['language' => 'de']);
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent->fresh());

        $this->assertSame('manual_review', $result['status']);
        $this->assertFalse((bool) ($result['allow_full_export'] ?? false));
        $this->assertStringContainsString('sprzeczne lokalne języki aliasu i kontraktu', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_contract_discovery_rejects_an_unsupported_catalog_contract_version(): void
    {
        [$parent, $catalog] = $this->family();
        $this->applyLemonContract($catalog);
        ProductChannelAlias::query()->where('language', 'en')->delete();
        $catalog->products[123]['lemon_erp_catalog_contract'] = 2;
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent->fresh());

        $this->assertSame('manual_review', $result['status']);
        $this->assertStringContainsString('nieobsługiwaną wersję kontraktu', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_custom_text_repair_rejects_a_different_positive_global_size_id_with_the_same_name(): void
    {
        [$parent, $catalog] = $this->family();

        foreach ([123, 223] as $parentId) {
            foreach ($catalog->products[$parentId]['attributes'] as &$attribute) {
                if ((int) ($attribute['id'] ?? 0) === 1) {
                    $attribute['id'] = 0;
                    unset($attribute['slug']);
                }
            }
            unset($attribute);
        }

        $catalog->variations[123][124]['attributes'] = [[
            'id' => 999,
            'name' => 'Rozmiar',
            'option' => 'S/M',
        ]];
        $this->fakeCatalog($catalog);

        $result = app(WooOwnedVariantAxisRepairService::class)->repair($parent);

        $this->assertSame('manual_review', $result['status']);
        $this->assertStringContainsString('dodatkową albo brakującą oś', $result['reason']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_already_canonical_remote_synchronously_replaces_a_stale_local_axis_before_unblocking(): void
    {
        [$parent, $catalog] = $this->family();
        $this->fakeCatalog($catalog);
        $repair = app(WooOwnedVariantAxisRepairService::class);
        $first = $repair->repair($parent);
        $this->assertSame('repaired', $first['status']);

        $staleAttributes = (array) $parent->attributes;
        $parent->forceFill(['attributes' => $staleAttributes])->save();
        $staleChildren = $parent->variantChildren()->get();

        foreach ($staleChildren as $variant) {
            $attributes = (array) $variant->attributes;
            $option = str_ends_with($variant->sku, '-SM') ? 's-m' : 'm-l';
            data_set($attributes, 'master.variant_attribute', 'wariant');
            data_set($attributes, 'master.parameters', [[
                'name' => 'wariant',
                'value' => $option,
                'variation' => true,
            ]]);
            data_set($attributes, 'woocommerce_variation_attributes', [[
                'id' => 6,
                'name' => 'wariant',
                'option' => $option,
            ]]);
            $variant->forceFill(['attributes' => $attributes])->save();
        }

        $second = $repair->repair($parent->fresh());
        $this->assertSame('already_canonical', $second['status']);
        $this->assertSame('Rozmiar', data_get($parent->fresh()->masterData(), 'variant_attribute'));

        $mapping = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->firstOrFail();
        $metadata = (array) $mapping->metadata;
        data_set($metadata, WooOwnedVariantAxisRepairService::STATE_PATH, [
            'revision' => WooOwnedVariantAxisRepairService::REVISION,
            'status' => 'queued',
            'pending_token' => 'token-1',
        ]);
        $mapping->update(['metadata' => $metadata]);
        $this->assertTrue($repair->blocksFullExport($parent));
        $repair->completeReservation($parent->id, 'token-1', $second);
        $this->assertSame(
            'completed',
            data_get($mapping->fresh()->metadata, WooOwnedVariantAxisRepairService::STATE_PATH.'.status'),
        );
        $this->assertFalse($repair->blocksFullExport($parent));
    }

    public function test_manual_full_export_is_blocked_while_a_historical_family_is_pending_or_requires_review(): void
    {
        [$parent] = $this->family();
        $mapping = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->firstOrFail();

        foreach (['pending', 'manual_review'] as $status) {
            $metadata = (array) $mapping->metadata;
            data_set($metadata, WooOwnedVariantAxisRepairService::STATE_PATH, [
                'revision' => WooOwnedVariantAxisRepairService::REVISION,
                'status' => $status,
            ]);
            $mapping->forceFill(['metadata' => $metadata])->save();
            Http::fake();

            $this->post(route('products.woocommerce.export', $parent))
                ->assertRedirect()
                ->assertSessionHas('error');

            Http::assertNothingSent();
        }
    }

    public function test_missing_english_alias_allows_the_full_export_that_will_create_it(): void
    {
        [$parent, $catalog] = $this->family();
        ProductChannelAlias::query()
            ->where('product_id', $parent->id)
            ->where('language', 'en')
            ->delete();
        $this->fakeCatalog($catalog);
        $repair = app(WooOwnedVariantAxisRepairService::class);
        $result = $repair->repair($parent->fresh());

        $this->assertSame('deferred', $result['status']);
        $this->assertTrue($result['allow_full_export']);

        $mapping = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->firstOrFail();
        $metadata = (array) $mapping->metadata;
        data_set($metadata, WooOwnedVariantAxisRepairService::STATE_PATH, [
            'revision' => WooOwnedVariantAxisRepairService::REVISION,
            'status' => 'queued',
            'pending_token' => 'missing-en-token',
        ]);
        $mapping->update(['metadata' => $metadata]);
        $repair->completeReservation($parent->id, 'missing-en-token', $result);

        $this->assertSame('pending', data_get(
            $mapping->fresh()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH.'.status',
        ));
        $this->assertFalse($repair->blocksFullExport($parent));
    }

    public function test_missing_english_alias_automatically_queues_the_full_export(): void
    {
        Bus::fake([ExportWooCommerceProductDataJob::class]);
        [$parent, $catalog] = $this->family();
        ProductChannelAlias::query()
            ->where('product_id', $parent->id)
            ->where('language', 'en')
            ->delete();
        $mapping = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->firstOrFail();
        $repair = app(WooOwnedVariantAxisRepairService::class);
        $token = 'missing-en-auto-token';
        $metadata = (array) $mapping->metadata;
        data_set($metadata, WooOwnedVariantAxisRepairService::STATE_PATH, [
            'revision' => WooOwnedVariantAxisRepairService::REVISION,
            'status' => 'queued',
            'pending_token' => $token,
        ]);
        $mapping->update(['metadata' => $metadata]);

        $this->fakeCatalog($catalog, function (Request $request): mixed {
            if ($request->method() === 'GET'
                && str_ends_with(
                    $request->url(),
                    '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities',
                )
            ) {
                return Http::response([
                    'available' => true,
                    'attribute_term_translation_link_available' => true,
                    'variation_translation_link_available' => true,
                    'variation_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations',
                    'languages' => ['pl', 'en'],
                    'plugin_version' => '0.5.3',
                ]);
            }

            return null;
        });

        app(RepairWooOwnedVariantAxisJob::class, [
            'productId' => $parent->id,
            'token' => $token,
        ])->handle($repair, app(LegacyVariantFamilyBackfillService::class));

        $freshMapping = $mapping->fresh();
        $exportToken = data_get($freshMapping->metadata, 'product_data_export.pending_token');
        $this->assertNotNull($exportToken);
        $this->assertSame('queued', data_get(
            $freshMapping->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertStringStartsWith(
            WooOwnedVariantAxisRepairService::REVISION.':missing-translation:',
            (string) data_get(
                $freshMapping->metadata,
                'product_data_export.legacy_variant_backfill.revision',
            ),
        );
        $this->assertSame('dispatched', data_get(
            $freshMapping->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH.'.result.full_export_queue',
        ));
        $this->assertFalse($repair->blocksFullExport($parent));
        $revision = (string) data_get(
            $freshMapping->metadata,
            'product_data_export.legacy_variant_backfill.revision',
        );
        $this->assertSame('active', app(LegacyVariantFamilyBackfillService::class)
            ->queueProductRevision($parent, $revision));
        Bus::assertDispatchedTimes(ExportWooCommerceProductDataJob::class, 1);
    }

    public function test_stale_product_import_cannot_restore_the_legacy_axis_after_repair(): void
    {
        [$parent, $catalog] = $this->family();
        $legacyParent = unserialize(serialize($catalog->products[123]));
        $legacyVariations = unserialize(serialize($catalog->variations[123]));
        $this->fakeCatalog($catalog, function (Request $request, object $liveCatalog) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/wp-json/wc/v3/products/categories') {
                return Http::response([]);
            }

            if ($path !== '/wp-json/wc/v3/products' || $request->method() !== 'GET') {
                return null;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) ($query['page'] ?? 1) === 1
                ? Http::response([$liveCatalog->products[123]])
                : Http::response([]);
        });
        $repair = app(WooOwnedVariantAxisRepairService::class);

        $this->assertSame('repaired', $repair->repair($parent)['status']);

        $catalog->products[123] = $legacyParent;
        $catalog->variations[123] = $legacyVariations;
        $integration = WordpressIntegration::query()->firstOrFail();
        $settings = (array) $integration->settings;
        data_set($settings, 'product_import.languages', ['pl']);
        $integration->update(['settings' => $settings]);

        app(WooCommerceImportService::class)->importProducts($integration->fresh());

        $freshParent = $parent->fresh('variantChildren');
        $this->assertSame('Rozmiar', data_get($freshParent->masterData(), 'variant_attribute'));
        $this->assertSame(
            [1],
            collect((array) data_get($freshParent->attributes, 'woocommerce_attributes', []))
                ->where('variation', true)
                ->pluck('id')
                ->values()
                ->all(),
        );

        foreach ($freshParent->variantChildren as $variant) {
            $option = str_ends_with($variant->sku, '-SM') ? 'S/M' : 'M/L';
            $this->assertSame([['id' => 1, 'option' => $option]], data_get(
                $variant->attributes,
                'woocommerce_variation_attributes',
            ));
            $this->assertSame($option === 'S/M' ? 10 : 20, (int) $variant->pivot->sort_order);
        }
    }

    public function test_migration_does_not_mark_a_color_only_woo_family_for_size_repair(): void
    {
        [$parent] = $this->family();
        $attributes = (array) $parent->attributes;
        data_set($attributes, 'master.variant_attribute', 'Color');
        data_set($attributes, 'master.parameters', [[
            'name' => 'Color',
            'value' => 'Black | White',
            'variation' => true,
        ]]);
        data_set($attributes, 'woocommerce_attributes', [[
            'id' => 3,
            'name' => 'Color',
            'slug' => 'pa_color',
            'variation' => true,
            'options' => ['Black', 'White'],
        ]]);
        $parent->update(['attributes' => $attributes]);

        foreach ($parent->variantChildren()->get() as $index => $variant) {
            $variantAttributes = (array) $variant->attributes;
            $option = $index === 0 ? 'Black' : 'White';
            data_set($variantAttributes, 'master.variant_attribute', 'Color');
            data_set($variantAttributes, 'master.parameters', [[
                'name' => 'Color',
                'value' => $option,
                'variation' => true,
            ]]);
            data_set($variantAttributes, 'woocommerce_variation_attributes', [[
                'id' => 3,
                'name' => 'Color',
                'option' => $option,
            ]]);
            $variant->update(['attributes' => $variantAttributes]);
        }

        $migration = require database_path(
            'migrations/2026_07_15_000016_mark_woo_owned_size_axes_for_remote_repair.php',
        );
        $migration->up();

        $this->assertNull(data_get(
            ProductChannelMapping::query()->where('product_id', $parent->id)->firstOrFail()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH.'.revision',
        ));
    }

    public function test_migration_does_not_treat_a_generic_color_axis_as_size_evidence(): void
    {
        [$parent] = $this->family();
        $attributes = (array) $parent->attributes;
        data_set($attributes, 'master.variant_attribute', 'wariant');
        data_set($attributes, 'master.parameters', [[
            'name' => 'wariant',
            'value' => 'Black | White',
            'variation' => true,
        ]]);
        data_set($attributes, 'woocommerce_attributes', [[
            'id' => 6,
            'name' => 'wariant',
            'slug' => 'pa_wariant',
            'variation' => true,
            'options' => ['Black', 'White'],
        ]]);
        $parent->update(['attributes' => $attributes]);

        foreach ($parent->variantChildren()->get() as $index => $variant) {
            $option = $index === 0 ? 'Black' : 'White';
            $variantAttributes = (array) $variant->attributes;
            data_set($variantAttributes, 'master.variant_attribute', 'wariant');
            data_set($variantAttributes, 'master.parameters', [[
                'name' => 'wariant',
                'value' => $option,
                'variation' => true,
            ]]);
            data_set($variantAttributes, 'woocommerce_variation_attributes', [[
                'id' => 6,
                'name' => 'wariant',
                'option' => $option,
            ]]);
            $variant->update(['attributes' => $variantAttributes]);
        }

        $migration = require database_path(
            'migrations/2026_07_15_000016_mark_woo_owned_size_axes_for_remote_repair.php',
        );
        $migration->up();

        $this->assertNull(data_get(
            ProductChannelMapping::query()->where('product_id', $parent->id)->firstOrFail()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH.'.revision',
        ));
    }

    public function test_migration_only_marks_the_woo_owned_candidate_and_dispatches_the_dedicated_job(): void
    {
        Bus::fake([RepairWooOwnedVariantAxisJob::class]);
        [$parent] = $this->family();
        $customTextAttributes = collect((array) data_get($parent->attributes, 'woocommerce_attributes', []))
            ->map(function (array $attribute): array {
                if ((int) ($attribute['id'] ?? 0) === 1) {
                    $attribute['id'] = 0;
                    unset($attribute['slug']);
                }

                return $attribute;
            })
            ->all();
        $attributes = (array) $parent->attributes;
        data_set($attributes, 'woocommerce_attributes', $customTextAttributes);
        $parent->update(['attributes' => $attributes]);
        $erp = Product::query()->create([
            'sku' => 'ERP-SHOULD-STAY-OUT',
            'name' => 'ERP family',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variable',
                ],
                'woocommerce_attributes' => $parent->attributes['woocommerce_attributes'],
            ],
        ]);

        $migration = require database_path(
            'migrations/2026_07_15_000016_mark_woo_owned_size_axes_for_remote_repair.php',
        );
        $migration->up();

        $mapping = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->firstOrFail();
        $this->assertSame(
            WooOwnedVariantAxisRepairService::REVISION,
            data_get(
                $mapping->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.revision',
            ),
        );
        $this->assertSame(
            'pending',
            data_get(
                $mapping->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.status',
            ),
        );
        $this->assertFalse(ProductChannelMapping::query()->where('product_id', $erp->id)->exists());
        Http::assertNothingSent();

        $result = app(WooOwnedVariantAxisRepairService::class)->dispatchPending(10, 120);
        $this->assertSame(1, $result['dispatched']);
        Bus::assertDispatched(
            RepairWooOwnedVariantAxisJob::class,
            fn (RepairWooOwnedVariantAxisJob $job): bool => $job->queue
                === WooOwnedVariantAxisRepairService::REPAIR_QUEUE,
        );
        Bus::assertDispatched(RepairWooOwnedVariantAxisJob::class, 1);
    }

    public function test_followup_migration_requeues_previous_safe_parser_manual_review_with_the_new_revision(): void
    {
        [$parent] = $this->family();
        $mapping = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->firstOrFail();
        $metadata = (array) $mapping->metadata;
        data_set($metadata, WooOwnedVariantAxisRepairService::STATE_PATH, [
            'revision' => 'woo_owned_size_variant_axis_2026_07_15_000016',
            'status' => 'manual_review',
            'pending_token' => 'obsolete-token',
        ]);
        data_set($metadata, 'product_data_export.pending_token', 'active-full-export');
        $mapping->update(['metadata' => $metadata]);

        $migration = require database_path(
            'migrations/2026_07_15_000017_requeue_historical_size_axis_repairs.php',
        );
        $migration->up();

        $state = (array) data_get(
            $mapping->fresh()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
            [],
        );
        $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, $state['revision']);
        $this->assertSame('pending', $state['status']);
        $this->assertArrayNotHasKey('pending_token', $state);
        $this->assertSame('active-full-export', data_get(
            $mapping->fresh()->metadata,
            'product_data_export.pending_token',
        ));
    }

    public function test_followup_migration_prioritizes_the_full_export_of_an_erp_family_repaired_by_000015(): void
    {
        [$parent] = $this->family();

        foreach (collect([$parent, ...$parent->variantChildren()->get()]) as $product) {
            $attributes = (array) $product->attributes;
            data_set($attributes, 'master.source', 'erp');
            $product->update(['attributes' => $attributes]);
        }

        $localRepair = require database_path(
            'migrations/2026_07_15_000015_recover_legacy_size_variant_axes.php',
        );
        $localRepair->up();

        $locallyRepaired = $parent->fresh(['parentRelations', 'variantChildren']);
        $this->assertSame('erp', $locallyRepaired->masterSource());
        $this->assertSame(
            LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION,
            data_get(
                $locallyRepaired->masterData(),
                'maintenance.legacy_size_variant_axis_recovery.revision',
            ),
        );

        foreach ($locallyRepaired->variantChildren as $variant) {
            $this->assertSame(
                LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION,
                data_get(
                    $variant->masterData(),
                    'maintenance.legacy_size_variant_axis_recovery.revision',
                ),
            );
            $this->assertSame(
                LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION,
                data_get(
                    ProductRelation::query()->findOrFail($variant->pivot?->id)->metadata,
                    'maintenance.legacy_size_variant_axis_recovery.revision',
                ),
            );
        }

        $followup = require database_path(
            'migrations/2026_07_15_000017_requeue_historical_size_axis_repairs.php',
        );
        $followup->up();

        $mapping = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->firstOrFail();
        $this->assertSame(
            LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_FOLLOWUP_REVISION,
            data_get(
                $mapping->metadata,
                'product_data_export.legacy_variant_backfill.revision',
            ),
        );
        $this->assertSame('pending', data_get(
            $mapping->metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
    }

    public function test_parent_term_order_followup_requeues_completed_000017_and_preserves_an_active_export(): void
    {
        [$parent] = $this->family();

        foreach (collect([$parent, ...$parent->variantChildren()->get()]) as $product) {
            $attributes = (array) $product->attributes;
            data_set($attributes, 'master.source', 'erp');
            $product->update(['attributes' => $attributes]);
        }

        (require database_path(
            'migrations/2026_07_15_000015_recover_legacy_size_variant_axes.php',
        ))->up();
        (require database_path(
            'migrations/2026_07_15_000017_requeue_historical_size_axis_repairs.php',
        ))->up();

        $mapping = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->firstOrFail();
        $metadata = (array) $mapping->metadata;
        data_set($metadata, 'product_data_export.pending_token', 'older-active-export');
        data_set($metadata, 'product_data_export.requested_at', now()->toISOString());
        data_set($metadata, 'product_data_export.legacy_variant_backfill.status', 'completed');
        data_set(
            $metadata,
            'product_data_export.legacy_variant_backfill.revision',
            LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_FOLLOWUP_REVISION,
        );
        data_set(
            $metadata,
            'product_data_export.legacy_variant_backfill.queued_revision',
            LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_FOLLOWUP_REVISION,
        );
        $mapping->update(['metadata' => $metadata]);
        $queuedExportId = DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => ExportWooCommerceProductDataJob::class,
                'data' => ['command' => 'serialized:older-active-export'],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        (require database_path(
            'migrations/2026_07_15_000018_requeue_erp_size_parent_term_order.php',
        ))->up();

        $metadata = $mapping->fresh()->metadata;
        $this->assertSame(
            LegacyVariantFamilyBackfillService::LEGACY_SIZE_PARENT_TERM_ORDER_FOLLOWUP_REVISION,
            data_get($metadata, 'product_data_export.legacy_variant_backfill.revision'),
        );
        $this->assertSame('pending', data_get(
            $metadata,
            'product_data_export.legacy_variant_backfill.status',
        ));
        $this->assertSame('older-active-export', data_get(
            $metadata,
            'product_data_export.pending_token',
        ));
        $this->assertSame(
            LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_FOLLOWUP_REVISION,
            data_get($metadata, 'product_data_export.legacy_variant_backfill.queued_revision'),
        );
        $this->assertSame(
            LegacyVariantFamilyBackfillService::CRITICAL_EXPORT_QUEUE,
            DB::table('jobs')->where('id', $queuedExportId)->value('queue'),
        );
    }

    public function test_parent_term_order_followup_skips_a_family_with_partial_recovery_markers(): void
    {
        [$parent] = $this->family();

        foreach (collect([$parent, ...$parent->variantChildren()->get()]) as $product) {
            $attributes = (array) $product->attributes;
            data_set($attributes, 'master.source', 'erp');
            $product->update(['attributes' => $attributes]);
        }

        (require database_path(
            'migrations/2026_07_15_000015_recover_legacy_size_variant_axes.php',
        ))->up();
        (require database_path(
            'migrations/2026_07_15_000017_requeue_historical_size_axis_repairs.php',
        ))->up();

        $relation = ProductRelation::query()
            ->where('parent_product_id', $parent->id)
            ->where('relation_type', 'variant')
            ->firstOrFail();
        $relationMetadata = (array) $relation->metadata;
        data_forget($relationMetadata, 'maintenance.legacy_size_variant_axis_recovery.revision');
        $relation->update(['metadata' => $relationMetadata]);
        $mapping = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->firstOrFail();

        (require database_path(
            'migrations/2026_07_15_000018_requeue_erp_size_parent_term_order.php',
        ))->up();

        $this->assertSame(
            LegacyVariantFamilyBackfillService::LEGACY_SIZE_VARIANT_AXIS_FOLLOWUP_REVISION,
            data_get(
                $mapping->fresh()->metadata,
                'product_data_export.legacy_variant_backfill.revision',
            ),
        );
    }

    public function test_parent_term_order_followup_does_not_overwrite_an_unrelated_newer_revision(): void
    {
        [$parent] = $this->family();

        foreach (collect([$parent, ...$parent->variantChildren()->get()]) as $product) {
            $attributes = (array) $product->attributes;
            data_set($attributes, 'master.source', 'erp');
            $product->update(['attributes' => $attributes]);
        }

        (require database_path(
            'migrations/2026_07_15_000015_recover_legacy_size_variant_axes.php',
        ))->up();
        (require database_path(
            'migrations/2026_07_15_000017_requeue_historical_size_axis_repairs.php',
        ))->up();

        $mapping = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->firstOrFail();
        $metadata = (array) $mapping->metadata;
        data_set($metadata, 'product_data_export.legacy_variant_backfill.revision', 'unrelated-newer-revision');
        $mapping->update(['metadata' => $metadata]);

        (require database_path(
            'migrations/2026_07_15_000018_requeue_erp_size_parent_term_order.php',
        ))->up();

        $this->assertSame('unrelated-newer-revision', data_get(
            $mapping->fresh()->metadata,
            'product_data_export.legacy_variant_backfill.revision',
        ));
    }

    public function test_parent_term_order_migration_rehomes_only_unreserved_axis_jobs(): void
    {
        $now = now()->timestamp;
        $axisJobId = DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => RepairWooOwnedVariantAxisJob::class]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now,
            'created_at' => $now,
        ]);
        $unrelatedJobId = DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => ExportWooCommerceProductDataJob::class]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now,
            'created_at' => $now,
        ]);
        $reservedAxisJobId = DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => RepairWooOwnedVariantAxisJob::class]),
            'attempts' => 1,
            'reserved_at' => $now,
            'available_at' => $now,
            'created_at' => $now,
        ]);

        (require database_path(
            'migrations/2026_07_15_000018_requeue_erp_size_parent_term_order.php',
        ))->up();

        $this->assertSame(
            WooOwnedVariantAxisRepairService::REPAIR_QUEUE,
            DB::table('jobs')->where('id', $axisJobId)->value('queue'),
        );
        $this->assertSame('default', DB::table('jobs')->where('id', $unrelatedJobId)->value('queue'));
        $this->assertSame('default', DB::table('jobs')->where('id', $reservedAxisJobId)->value('queue'));
    }

    public function test_followup_migration_does_not_queue_an_unmarked_erp_family(): void
    {
        [$parent] = $this->family();

        foreach (collect([$parent, ...$parent->variantChildren()->get()]) as $product) {
            $attributes = (array) $product->attributes;
            data_set($attributes, 'master.source', 'erp');
            $product->update(['attributes' => $attributes]);
        }

        $migration = require database_path(
            'migrations/2026_07_15_000017_requeue_historical_size_axis_repairs.php',
        );
        $migration->up();

        $mapping = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->firstOrFail();
        $this->assertNull(data_get(
            $mapping->metadata,
            'product_data_export.legacy_variant_backfill.revision',
        ));
    }

    public function test_migration_still_preflights_an_already_canonical_local_family_against_live_woo(): void
    {
        [$parent] = $this->family();
        $attributes = (array) $parent->attributes;
        data_set($attributes, 'woocommerce_attributes', [[
            'id' => 1,
            'name' => 'Rozmiar',
            'slug' => 'pa_rozmiar',
            'position' => 1,
            'visible' => true,
            'variation' => true,
            'options' => ['S/M', 'M/L'],
        ]]);
        data_set($attributes, 'woocommerce_default_attributes', [[
            'id' => 1,
            'name' => 'Rozmiar',
            'option' => 'M/L',
        ]]);
        $parent->update(['attributes' => $attributes]);

        foreach ($parent->variantChildren()->get() as $variant) {
            $variantAttributes = (array) $variant->attributes;
            $option = str_contains((string) $variant->sku, '-SM') ? 'S/M' : 'M/L';
            data_set($variantAttributes, 'woocommerce_variation_attributes', [[
                'id' => 1,
                'name' => 'Rozmiar',
                'option' => $option,
            ]]);
            $variant->update(['attributes' => $variantAttributes]);
            ProductRelation::query()
                ->where('parent_product_id', $parent->id)
                ->where('child_product_id', $variant->id)
                ->update(['sort_order' => $option === 'S/M' ? 10 : 20]);
        }

        $migration = require database_path(
            'migrations/2026_07_15_000016_mark_woo_owned_size_axes_for_remote_repair.php',
        );
        $migration->up();

        $mapping = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->firstOrFail();
        $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, data_get(
            $mapping->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH.'.revision',
        ));
    }

    /**
     * @return array{0:Product,1:object{products:array<int,array<string,mixed>>,variations:array<int,array<int,array<string,mixed>>>}}
     */
    private function family(): array
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['ONE SIZE', 'XS', 'S', 'S/M', 'M', 'M/L', 'L', 'XL'],
            'values_en' => ['ONE SIZE', 'XS', 'S', 'S/M', 'M', 'M/L', 'L', 'XL'],
            'is_variant' => true,
        ]);
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-WOO-OWNED',
            'name' => 'B2C Woo owned',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo owned test',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => [
                'product_import' => ['languages' => ['pl', 'en']],
                'product_export' => ['languages' => ['pl', 'en']],
            ],
        ]);
        $parentAttributes = $this->legacyParentAttributes('Rozmiar');
        $parent = $this->product('HISTORICAL-PARENT', 'Historyczne spodnie', [
            'master' => [
                'source' => 'woocommerce_import',
                'product_type' => 'variable',
                'variant_attribute' => 'wariant',
                'parameters' => [
                    ['name' => 'wariant', 'name_en' => 'variant', 'value' => 'm-l | s-m', 'variation' => true],
                    ['name' => 'Rozmiar', 'name_en' => 'Size', 'value' => 'M/L | S/M', 'variation' => false],
                ],
            ],
            'woocommerce_product_id' => '123',
            'woocommerce_attributes' => $parentAttributes,
            'woocommerce_default_attributes' => [[
                'id' => 6,
                'name' => 'wariant',
                'option' => 'm-l',
            ]],
        ]);
        $small = $this->product('HISTORICAL-SM', 'Historyczne spodnie - S/M', [
            'master' => [
                'source' => 'woocommerce_import',
                'product_type' => 'variation',
                'variant_attribute' => 'wariant',
                'parameters' => [
                    ['name' => 'wariant', 'name_en' => 'variant', 'value' => 's-m', 'variation' => true],
                    ['name' => 'Rozmiar', 'name_en' => 'Size', 'value' => 'S/M', 'variation' => false],
                ],
            ],
            'woocommerce_product_id' => '123',
            'woocommerce_variation_id' => '124',
            'woocommerce_variation_attributes' => [[
                'id' => 6,
                'name' => 'wariant',
                'option' => 's-m',
            ]],
        ]);
        $large = $this->product('HISTORICAL-ML', 'Historyczne spodnie - M/L', [
            'master' => [
                'source' => 'woocommerce_import',
                'product_type' => 'variation',
                'variant_attribute' => 'wariant',
                'parameters' => [
                    ['name' => 'wariant', 'name_en' => 'variant', 'value' => 'm-l', 'variation' => true],
                    ['name' => 'Rozmiar', 'name_en' => 'Size', 'value' => 'M/L', 'variation' => false],
                ],
            ],
            'woocommerce_product_id' => '123',
            'woocommerce_variation_id' => '125',
            'woocommerce_variation_attributes' => [[
                'id' => 6,
                'name' => 'wariant',
                'option' => 'm-l',
            ]],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $small->id,
            'relation_type' => 'variant',
            'sort_order' => 20,
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $large->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '123',
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'source' => 'woocommerce_import',
                'language' => 'pl',
                'mapping_role' => 'primary',
            ],
        ]);

        foreach ([[$small, '124'], [$large, '125']] as [$variant, $variationId]) {
            ProductChannelMapping::query()->create([
                'product_id' => $variant->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => '123',
                'external_variation_id' => $variationId,
                'external_sku' => $variant->sku,
                'stock_sync_enabled' => true,
                'metadata' => ['source' => 'woocommerce_import', 'language' => 'pl'],
            ]);
        }

        ProductChannelAlias::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '223',
            'external_variation_id' => null,
            'external_sku' => $parent->sku,
            'language' => 'en',
            'metadata' => ['source' => 'woocommerce_polylang_import'],
        ]);
        ProductChannelAlias::query()->create([
            'product_id' => $small->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '223',
            'external_variation_id' => '224',
            'external_sku' => $small->sku,
            'language' => 'en',
            'metadata' => ['source' => 'woocommerce_polylang_import'],
        ]);
        ProductChannelAlias::query()->create([
            'product_id' => $large->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '223',
            'external_variation_id' => '225',
            'external_sku' => $large->sku,
            'language' => 'en',
            'metadata' => ['source' => 'woocommerce_polylang_import'],
        ]);

        $catalog = (object) [
            'products' => [
                123 => $this->remoteParent(123, 'Historyczne spodnie', 'Rozmiar', 6),
                223 => $this->remoteParent(223, 'Historical trousers', 'Size', 8),
            ],
            'variations' => [
                123 => [
                    124 => $this->remoteVariation(124, 'HISTORICAL-SM', 6, 'wariant', 's-m', 20),
                    125 => $this->remoteVariation(125, 'HISTORICAL-ML', 6, 'wariant', 'm-l', 10),
                ],
                223 => [
                    224 => $this->remoteVariation(224, 'HISTORICAL-SM', 8, 'variant', 's-m', 20),
                    225 => $this->remoteVariation(225, 'HISTORICAL-ML', 8, 'variant', 'm-l', 10),
                ],
            ],
        ];

        return [$parent->fresh(), $catalog];
    }

    private function product(string $sku, string $name, array $attributes): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => $attributes,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function legacyParentAttributes(string $sizeName): array
    {
        return [
            [
                'id' => 4,
                'name' => 'Skład',
                'slug' => 'pa_sklad',
                'position' => 0,
                'visible' => true,
                'variation' => false,
                'options' => ['100% Bawełna'],
            ],
            [
                'id' => 6,
                'name' => 'wariant',
                'slug' => 'pa_wariant',
                'position' => 1,
                'visible' => true,
                'variation' => true,
                'options' => ['m-l', 's-m'],
            ],
            [
                'id' => 1,
                'name' => $sizeName,
                'slug' => 'pa_rozmiar',
                'position' => 2,
                'visible' => true,
                'variation' => false,
                'options' => ['M/L', 'S/M'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function remoteParent(int $id, string $name, string $sizeName, int $genericId): array
    {
        $attributes = $this->legacyParentAttributes($sizeName);
        $attributes[1]['id'] = $genericId;
        $attributes[1]['name'] = $sizeName === 'Size' ? 'variant' : 'wariant';
        $attributes[1]['slug'] = $sizeName === 'Size' ? 'pa_variant' : 'pa_wariant';

        return [
            'id' => $id,
            'type' => 'variable',
            'name' => $name,
            'description' => '<p>Protected description</p>',
            'short_description' => '<p>Protected short description</p>',
            'sku' => 'HISTORICAL-PARENT',
            'status' => 'publish',
            'date_created' => '2026-07-01T10:00:00',
            'regular_price' => '539.00',
            'sale_price' => '',
            'price' => '539.00',
            'manage_stock' => false,
            'stock_quantity' => null,
            'stock_status' => 'instock',
            'backorders' => 'no',
            'images' => [['id' => 99, 'src' => 'https://shop.test/image.jpg']],
            'categories' => [['id' => 7, 'name' => 'Jeans']],
            'attributes' => $attributes,
            'default_attributes' => [[
                'id' => $genericId,
                'name' => $sizeName === 'Size' ? 'variant' : 'wariant',
                'option' => 'm-l',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function remoteVariation(
        int $id,
        string $sku,
        int $genericId,
        string $genericName,
        string $option,
        int $menuOrder,
    ): array {
        return [
            'id' => $id,
            'sku' => $sku,
            'status' => 'publish',
            'date_created' => '2026-07-01T10:00:00',
            'regular_price' => '539.00',
            'sale_price' => '',
            'price' => '539.00',
            'manage_stock' => true,
            'stock_quantity' => 3,
            'stock_status' => 'instock',
            'backorders' => 'no',
            'image' => ['id' => 99, 'src' => 'https://shop.test/image.jpg'],
            'menu_order' => $menuOrder,
            'attributes' => [[
                'id' => $genericId,
                'name' => $genericName,
                'option' => $option,
            ]],
        ];
    }

    private function applyLemonContract(object $catalog): void
    {
        foreach ([123 => 'pl', 223 => 'en'] as $parentId => $language) {
            $catalog->products[$parentId]['lemon_erp_catalog_contract'] = 1;
            $catalog->products[$parentId]['lemon_erp_language'] = $language;
            $catalog->products[$parentId]['lemon_erp_translations'] = ['pl' => 123, 'en' => 223];
            $catalog->products[$parentId]['lemon_erp_translation_group'] = 'product:123|223';
        }

        foreach ([[123, 124, 'pl', 124, 224], [223, 224, 'en', 124, 224]] as [$parentId, $variationId, $language, $plId, $enId]) {
            $catalog->variations[$parentId][$variationId]['lemon_erp_catalog_contract'] = 1;
            $catalog->variations[$parentId][$variationId]['lemon_erp_language'] = $language;
            $catalog->variations[$parentId][$variationId]['lemon_erp_translations'] = ['pl' => $plId, 'en' => $enId];
            $catalog->variations[$parentId][$variationId]['lemon_erp_translation_group'] = "variation:{$plId}|{$enId}";
            $catalog->variations[$parentId][$variationId]['lemon_erp_parent_translations'] = ['pl' => 123, 'en' => 223];
            $catalog->variations[$parentId][$variationId]['lemon_erp_parent_translation_group'] = 'product:123|223';
        }

        foreach ([[123, 125, 'pl', 125, 225], [223, 225, 'en', 125, 225]] as [$parentId, $variationId, $language, $plId, $enId]) {
            $catalog->variations[$parentId][$variationId]['lemon_erp_catalog_contract'] = 1;
            $catalog->variations[$parentId][$variationId]['lemon_erp_language'] = $language;
            $catalog->variations[$parentId][$variationId]['lemon_erp_translations'] = ['pl' => $plId, 'en' => $enId];
            $catalog->variations[$parentId][$variationId]['lemon_erp_translation_group'] = "variation:{$plId}|{$enId}";
            $catalog->variations[$parentId][$variationId]['lemon_erp_parent_translations'] = ['pl' => 123, 'en' => 223];
            $catalog->variations[$parentId][$variationId]['lemon_erp_parent_translation_group'] = 'product:123|223';
        }
    }

    private function fakeCatalog(object $catalog, ?callable $intercept = null): void
    {
        Http::fake(function (Request $request) use ($catalog, $intercept) {
            if (is_callable($intercept)) {
                $response = $intercept($request, $catalog);

                if ($response !== null) {
                    return $response;
                }
            }

            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/wp-json/wc/v3/products/attributes'
                && $request->method() === 'GET'
            ) {
                return Http::response([[
                    'id' => 1,
                    'name' => 'Rozmiar',
                    'slug' => 'pa_rozmiar',
                    'type' => 'select',
                    'order_by' => 'menu_order',
                ]]);
            }

            if ($path === '/wp-json/wc/v3/products/attributes/1/terms'
                && $request->method() === 'GET'
            ) {
                return Http::response([
                    ['id' => 11, 'name' => 'S/M', 'slug' => 's-m', 'menu_order' => 10],
                    ['id' => 12, 'name' => 'M/L', 'slug' => 'm-l', 'menu_order' => 20],
                ]);
            }

            if (preg_match('#/wc/v3/products/(\d+)/variations/(\d+)$#', $path, $matches) === 1) {
                $parentId = (int) $matches[1];
                $variationId = (int) $matches[2];

                if ($request->method() === 'PUT') {
                    $catalog->variations[$parentId][$variationId] = array_replace(
                        $catalog->variations[$parentId][$variationId],
                        $request->data(),
                    );
                }

                return Http::response($catalog->variations[$parentId][$variationId] ?? [],
                    isset($catalog->variations[$parentId][$variationId]) ? 200 : 404);
            }

            if (preg_match('#/wc/v3/products/(\d+)/variations$#', $path, $matches) === 1
                && $request->method() === 'GET'
            ) {
                return Http::response(array_values($catalog->variations[(int) $matches[1]] ?? []));
            }

            if (preg_match('#/wc/v3/products/(\d+)$#', $path, $matches) === 1) {
                $parentId = (int) $matches[1];

                if ($request->method() === 'PUT') {
                    $payload = $request->data();
                    $originalById = collect($catalog->products[$parentId]['attributes'])
                        ->keyBy(fn (array $attribute): int => (int) ($attribute['id'] ?? 0));
                    $payload['attributes'] = collect($payload['attributes'])
                        ->map(function (array $attribute) use ($originalById): array {
                            $original = (array) $originalById->get((int) ($attribute['id'] ?? 0), []);

                            if ((int) ($attribute['id'] ?? 0) === 1 && $original === []) {
                                $original = [
                                    'id' => 1,
                                    'name' => 'Rozmiar',
                                    'slug' => 'pa_rozmiar',
                                ];
                            }

                            return array_replace($original, $attribute);
                        })
                        ->all();
                    $catalog->products[$parentId] = array_replace(
                        $catalog->products[$parentId],
                        $payload,
                    );
                }

                return Http::response($catalog->products[$parentId] ?? [],
                    isset($catalog->products[$parentId]) ? 200 : 404);
            }

            return Http::response([], 404);
        });
    }
}
