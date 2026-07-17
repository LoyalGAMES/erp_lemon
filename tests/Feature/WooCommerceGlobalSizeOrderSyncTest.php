<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ImportWooCommerceProductsJob;
use App\Jobs\SyncWooCommerceGlobalSizeOrderJob;
use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Observers\ProductParameterDefinitionObserver;
use App\Services\WooCommerce\WooCommerceGlobalSizeOrderSyncService;
use App\Services\WooCommerce\WooCommerceSizeDictionaryOrder;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class WooCommerceGlobalSizeOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_canonicalizes_and_orders_existing_polish_terms_when_language_filters_return_every_language(): void
    {
        $this->createSizeDefinition();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-SYNC');
        $attribute = $this->sizeAttribute();
        $terms = $this->allLanguageTerms();
        $englishBefore = collect($terms)->only([110008, 110014])->all();
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);

        $service = app(WooCommerceGlobalSizeOrderSyncService::class);
        $first = $service->sync($integration);

        $this->assertSame([
            'status' => 'synchronized',
            'attribute_id' => 1,
            'languages' => 2,
            'matched_terms' => 4,
            'updated_terms' => 2,
            'renamed_terms' => 2,
        ], $first);
        $this->assertSame('menu_order', $attribute['order_by']);
        $this->assertSame('S/M', $terms[58]['name']);
        $this->assertSame(10, $terms[58]['menu_order']);
        $this->assertSame('M/L', $terms[57]['name']);
        $this->assertSame(20, $terms[57]['menu_order']);
        $this->assertSame($englishBefore, collect($terms)->only([110008, 110014])->all());
        $this->assertSame([
            [
                'method' => 'PUT',
                'path' => '/wp-json/wc/v3/products/attributes/1/terms/58',
                'payload' => ['name' => 'S/M', 'menu_order' => 10],
            ],
            [
                'method' => 'PUT',
                'path' => '/wp-json/wc/v3/products/attributes/1/terms/57',
                'payload' => ['name' => 'M/L', 'menu_order' => 20],
            ],
            [
                'method' => 'PUT',
                'path' => '/wp-json/wc/v3/products/attributes/1',
                'payload' => ['order_by' => 'menu_order'],
            ],
        ], $mutations);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            (string) parse_url($request->url(), PHP_URL_PATH),
            '/products/variations',
        ));

        $requestCount = Http::recorded()->count();
        $second = $service->sync($integration);

        $this->assertSame([
            'status' => 'synchronized',
            'attribute_id' => 1,
            'languages' => 2,
            'matched_terms' => 4,
            'updated_terms' => 0,
            'renamed_terms' => 0,
        ], $second);
        $this->assertCount(3, $mutations);
        $this->assertSame($requestCount + 3, Http::recorded()->count());
    }

    public function test_it_uses_the_exact_erp_dictionary_order_in_both_languages_without_mutating_the_definition(): void
    {
        $definition = ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['M/L', 'Unknown A', 'S/M', 'Unknown B'],
            'values_en' => ['M/L EN', 'Unknown A EN', 'S/M EN', 'Unknown B EN'],
            'is_variant' => true,
            'sort_order' => 10,
        ]);
        $definitionBefore = (array) DB::table('product_parameter_definitions')
            ->where('id', $definition->id)
            ->first();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-PAIR-ORDER');
        $attribute = $this->sizeAttribute();
        $terms = [
            11 => ['id' => 11, 'name' => 'M/L', 'slug' => 'm-l', 'menu_order' => 0],
            12 => ['id' => 12, 'name' => 'Unknown A', 'slug' => 'unknown-a', 'menu_order' => 0],
            13 => ['id' => 13, 'name' => 'S/M', 'slug' => 's-m', 'menu_order' => 0],
            14 => ['id' => 14, 'name' => 'Unknown B', 'slug' => 'unknown-b', 'menu_order' => 0],
            21 => ['id' => 21, 'name' => 'M/L EN', 'slug' => 'm-l-en', 'menu_order' => 0],
            22 => ['id' => 22, 'name' => 'Unknown A EN', 'slug' => 'unknown-a-en', 'menu_order' => 0],
            23 => ['id' => 23, 'name' => 'S/M EN', 'slug' => 's-m-en', 'menu_order' => 0],
            24 => ['id' => 24, 'name' => 'Unknown B EN', 'slug' => 'unknown-b-en', 'menu_order' => 0],
        ];
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);

        $result = app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);

        $this->assertSame('synchronized', $result['status']);
        $this->assertSame(8, $result['matched_terms']);
        foreach ([11 => 10, 12 => 20, 13 => 30, 14 => 40] as $termId => $menuOrder) {
            $this->assertSame($menuOrder, $terms[$termId]['menu_order']);
        }
        foreach ([21 => 10, 22 => 20, 23 => 30, 24 => 40] as $termId => $menuOrder) {
            $this->assertSame($menuOrder, $terms[$termId]['menu_order']);
        }
        $this->assertSame($definitionBefore, (array) DB::table('product_parameter_definitions')
            ->where('id', $definition->id)
            ->first());
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_it_matches_legacy_source_and_translation_aliases_but_writes_the_canonical_direct_spelling(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Imported axis',
            'slug' => 'blvariant',
            'input_type' => 'select',
            'values' => ['s-m', 'm-l'],
            'values_en' => ['Legacy Small/Medium', 'Legacy Medium/Large'],
            'is_variant' => true,
            'sort_order' => 1,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S/M', 'M/L'],
            'values_en' => ['Small/Medium', 'Medium/Large'],
            'is_variant' => true,
            'sort_order' => 999,
        ]);
        $integration = $this->createWooIntegration('GLOBAL-SIZE-LEGACY-ALIASES');
        $attribute = $this->sizeAttribute();
        $terms = [
            11 => ['id' => 11, 'name' => 's-m', 'slug' => 's-m', 'lang' => 'pl', 'menu_order' => 0],
            12 => ['id' => 12, 'name' => 'm-l', 'slug' => 'm-l', 'lang' => 'pl', 'menu_order' => 0],
            21 => ['id' => 21, 'name' => 'Legacy Small/Medium', 'slug' => 'legacy-small-medium-en', 'lang' => 'en', 'menu_order' => 0],
            22 => ['id' => 22, 'name' => 'Legacy Medium/Large', 'slug' => 'legacy-medium-large-en', 'lang' => 'en', 'menu_order' => 0],
        ];
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);

        $result = app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);

        $this->assertSame('synchronized', $result['status']);
        $this->assertSame(4, $result['matched_terms']);
        $this->assertSame('S/M', $terms[11]['name']);
        $this->assertSame('M/L', $terms[12]['name']);
        $this->assertSame('Small/Medium', $terms[21]['name']);
        $this->assertSame('Medium/Large', $terms[22]['name']);
        $this->assertSame(10, $terms[11]['menu_order']);
        $this->assertSame(20, $terms[12]['menu_order']);
        $this->assertSame(10, $terms[21]['menu_order']);
        $this->assertSame(20, $terms[22]['menu_order']);
        $this->assertSame('menu_order', $attribute['order_by']);
    }

    public function test_it_coalesces_a_historical_source_that_translates_to_an_existing_canonical_size(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S/M', 'M/L'],
            'values_en' => ['S/M', 'M/L'],
            'is_variant' => true,
            'sort_order' => 10,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiary',
            'name_en' => 'Sizes',
            'slug' => 'rozmiary',
            'input_type' => 'select',
            'values' => ['Small/Medium', 'Medium/Large'],
            'values_en' => ['S/M', 'M/L'],
            'is_variant' => true,
            'sort_order' => 20,
        ]);

        $entries = app(WooCommerceSizeDictionaryOrder::class)
            ->entries('en');

        $this->assertSame(['S/M', 'M/L'], $entries->pluck('source')->all());
        $this->assertSame(['S/M', 'M/L'], $entries->pluck('localized')->all());
        $this->assertSame([10, 20], $entries->pluck('menu_order')->all());
        $this->assertContains('Small/Medium', $entries->first()['source_aliases']);
        $this->assertContains('Medium/Large', $entries->last()['source_aliases']);
    }

    public function test_colliding_woo_slugs_in_the_size_union_abort_before_the_first_remote_mutation(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['XL', 'XL+'],
            'values_en' => ['XL', 'XL+'],
            'is_variant' => true,
            'sort_order' => 10,
        ]);
        $integration = $this->createWooIntegration('GLOBAL-SIZE-SLUG-COLLISION');
        $attribute = $this->sizeAttribute();
        $terms = $this->allLanguageTerms();
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);

        try {
            app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);
            $this->fail('Kolizja slugów XL i XL+ powinna przerwać synchronizację.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('kolidującym slugu WooCommerce', $exception->getMessage());
        }

        $this->assertSame([], $mutations);
        $this->assertSame('name', $attribute['order_by']);
    }

    public function test_a_used_remote_size_outside_the_erp_union_aborts_before_menu_order_is_enabled(): void
    {
        $this->createSizeDefinition();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-USED-OUTSIDE');
        $attribute = $this->sizeAttribute();
        $terms = $this->allLanguageTerms();
        $terms[99] = [
            'id' => 99,
            'name' => 'Outside dictionary',
            'slug' => 'outside-dictionary',
            'menu_order' => 0,
            'count' => 1,
        ];
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);

        try {
            app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);
            $this->fail('Używany termin spoza słownika powinien przerwać synchronizację.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('używane wartości spoza słownika ERP', $exception->getMessage());
            $this->assertStringContainsString('#99', $exception->getMessage());
        }

        $this->assertSame([], $mutations);
        $this->assertSame('name', $attribute['order_by']);
        Http::assertNotSent(fn (Request $request): bool => in_array(
            $request->method(),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            true,
        ));
    }

    public function test_it_uses_the_definition_matching_the_existing_taxonomy_when_a_historical_size_dictionary_also_exists(): void
    {
        $this->createSizeDefinition();
        ProductParameterDefinition::query()->create([
            'name' => 'Size',
            'slug' => 'size',
            'input_type' => 'select',
            // Deliberately conflicting: this historical English-only row must
            // not override the dictionary behind Woo's pa_rozmiar taxonomy.
            'values' => ['M/L', 'S/M'],
            'is_variant' => false,
            'is_required' => false,
            'sort_order' => 1,
        ]);
        $integration = $this->createWooIntegration('GLOBAL-SIZE-DUPLICATE-DICTIONARY');
        $attribute = $this->sizeAttribute();
        $terms = $this->allLanguageTerms();
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);

        $result = app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);

        $this->assertSame('synchronized', $result['status']);
        $this->assertSame(1, $result['attribute_id']);
        $this->assertSame('S/M', $terms[58]['name']);
        $this->assertSame(10, $terms[58]['menu_order']);
        $this->assertSame('M/L', $terms[57]['name']);
        $this->assertSame(20, $terms[57]['menu_order']);
        $this->assertSame('menu_order', $attribute['order_by']);
    }

    public function test_it_selects_only_the_size_attribute_used_as_the_mapped_variation_axis(): void
    {
        $this->createSizeDefinition();
        $this->createPluralSizeDefinition();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-DUPLICATE-WOO-ATTRIBUTE');
        $parent = $this->createMappedVariantFamily(
            $integration,
            '808184',
            'DUPLICATE-WOO-ATTRIBUTE',
        );
        $this->createParentAlias($integration, $parent, '808192', 'en');
        $attributes = [
            9 => [
                'id' => 9,
                'name' => 'Rozmiary',
                'slug' => 'pa_rozmiary',
                'order_by' => 'name',
            ],
            1 => $this->sizeAttribute(),
        ];
        $terms = $this->allLanguageTerms();
        $mutations = [];
        $this->fakeWooCatalogWithAttributeEvidence(
            $attributes,
            $terms,
            $mutations,
            [[
                'id' => 808184,
                'type' => 'variable',
                'attributes' => [
                    [
                        'id' => 9,
                        'name' => 'Rozmiary',
                        'variation' => false,
                        'options' => ['One size'],
                    ],
                    [
                        'id' => 1,
                        'name' => 'Rozmiar',
                        'variation' => true,
                        'options' => ['S/M', 'M/L'],
                    ],
                ],
            ], [
                'id' => 808192,
                'type' => 'variable',
                'attributes' => [[
                    'id' => 1,
                    'name' => 'Size',
                    'variation' => true,
                    'options' => ['S/M', 'M/L'],
                ]],
            ]],
        );

        $result = app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);

        $this->assertSame('synchronized', $result['status']);
        $this->assertSame(1, $result['attribute_id']);
        $this->assertSame('menu_order', $attributes[1]['order_by']);
        $this->assertSame('name', $attributes[9]['order_by']);
        $this->assertSame('S/M', $terms[58]['name']);
        $this->assertSame(10, $terms[58]['menu_order']);
        $this->assertSame('M/L', $terms[57]['name']);
        $this->assertSame(20, $terms[57]['menu_order']);
        $this->assertNotEmpty($mutations);
        $this->assertTrue(collect($mutations)->every(
            fn (array $mutation): bool => str_starts_with(
                $mutation['path'],
                '/wp-json/wc/v3/products/attributes/1',
            ),
        ));
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            (string) parse_url($request->url(), PHP_URL_PATH),
            '/products/attributes/9/terms',
        ) || ($request->method() !== 'GET'
            && (string) parse_url($request->url(), PHP_URL_PATH)
                === '/wp-json/wc/v3/products/attributes/9'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && (string) parse_url($request->url(), PHP_URL_PATH) === '/wp-json/wc/v3/products'
            && ($request->data()['include'] ?? null) === '808184,808192'
            && ($request->data()['status'] ?? null) === 'any'
            && ($request->data()['_fields'] ?? null) === 'id,type,attributes');
    }

    public function test_canonical_size_taxonomy_wins_over_a_still_used_generic_axis_before_family_repair(): void
    {
        $this->createSizeDefinition();
        ProductParameterDefinition::query()->create([
            'name' => 'wariant',
            'slug' => 'wariant',
            'input_type' => 'select',
            'values' => ['s-m', 'm-l'],
            'values_en' => ['s-m', 'm-l'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 1,
        ]);
        $integration = $this->createWooIntegration('GLOBAL-SIZE-CANONICAL-BEFORE-REPAIR');
        $this->createMappedVariantFamily(
            $integration,
            '808184',
            'CANONICAL-BEFORE-REPAIR',
        );
        $attributes = [
            6 => [
                'id' => 6,
                'name' => 'wariant',
                'slug' => 'pa_wariant',
                'order_by' => 'name',
            ],
            1 => $this->sizeAttribute(),
        ];
        $terms = $this->allLanguageTerms();
        $mutations = [];
        $this->fakeWooCatalogWithAttributeEvidence(
            $attributes,
            $terms,
            $mutations,
            [[
                'id' => 808184,
                'type' => 'variable',
                'attributes' => [[
                    'id' => 6,
                    'name' => 'wariant',
                    'variation' => true,
                    'options' => ['s-m', 'm-l'],
                ]],
            ]],
        );

        $result = app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);

        $this->assertSame('synchronized', $result['status']);
        $this->assertSame(1, $result['attribute_id']);
        $this->assertSame('menu_order', $attributes[1]['order_by']);
        $this->assertSame('name', $attributes[6]['order_by']);
        $this->assertSame(10, $terms[58]['menu_order']);
        $this->assertSame(20, $terms[57]['menu_order']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET'
            && (string) parse_url($request->url(), PHP_URL_PATH) === '/wp-json/wc/v3/products');
    }

    public function test_split_mapped_variation_axes_abort_before_the_first_remote_mutation(): void
    {
        $this->createSizeDefinition();
        $this->createPluralSizeDefinition();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-SPLIT-WOO-ATTRIBUTE');
        $parent = $this->createMappedVariantFamily(
            $integration,
            '808184',
            'SPLIT-WOO-ATTRIBUTE',
        );
        $this->createParentAlias($integration, $parent, '2098', 'en');
        $attributes = [
            9 => [
                'id' => 9,
                'name' => 'Rozmiary',
                'slug' => 'pa_rozmiary',
                'order_by' => 'name',
            ],
            1 => $this->sizeAttribute(),
        ];
        $terms = $this->allLanguageTerms();
        $mutations = [];
        $this->fakeWooCatalogWithAttributeEvidence(
            $attributes,
            $terms,
            $mutations,
            [
                [
                    'id' => 808184,
                    'type' => 'variable',
                    'attributes' => [[
                        'id' => 1,
                        'name' => 'Rozmiar',
                        'variation' => true,
                        'options' => ['S/M', 'M/L'],
                    ]],
                ],
                [
                    'id' => 2098,
                    'type' => 'variable',
                    'attributes' => [[
                        'id' => 9,
                        'name' => 'Rozmiary',
                        'has_variations' => true,
                        'options' => ['One size'],
                    ]],
                ],
            ],
        );

        try {
            app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);
            $this->fail('Podzielona oś rozmiaru powinna przerwać synchronizację.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'używają kilku globalnych atrybutów Rozmiar/Size',
                $exception->getMessage(),
            );
        }

        $this->assertSame([], $mutations);
        Http::assertNotSent(fn (Request $request): bool => in_array(
            $request->method(),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            true,
        ));
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            (string) parse_url($request->url(), PHP_URL_PATH),
            '/terms',
        ));
    }

    public function test_shared_union_syncs_without_an_exact_canonical_rozmiar_definition(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Imported axis',
            'slug' => 'blvariant',
            'input_type' => 'select',
            'values' => ['m-l', 's-m'],
            'values_en' => ['Legacy M/L', 'Legacy S/M'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 1,
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Size',
            'slug' => 'size',
            'input_type' => 'select',
            'values' => ['M/L', 'S/M'],
            'values_en' => ['M/L', 'S/M'],
            'is_variant' => false,
            'is_required' => false,
            'sort_order' => 20,
        ]);
        $integration = $this->createWooIntegration('GLOBAL-SIZE-UNION-NO-CANONICAL-ROW');
        $attribute = $this->sizeAttribute();
        $terms = $this->allLanguageTerms();
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);

        $result = app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);

        $this->assertSame('synchronized', $result['status']);
        $this->assertSame(4, $result['matched_terms']);
        $this->assertSame('S/M', $terms[58]['name']);
        $this->assertSame(20, $terms[58]['menu_order']);
        $this->assertSame('M/L', $terms[57]['name']);
        $this->assertSame(10, $terms[57]['menu_order']);
        $this->assertSame('menu_order', $attribute['order_by']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_an_ambiguous_existing_term_aborts_before_the_first_mutation(): void
    {
        $this->createSizeDefinition();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-AMBIGUOUS');
        $attribute = $this->sizeAttribute();
        $terms = $this->allLanguageTerms();
        $terms[59] = [
            'id' => 59,
            'name' => 'S/M',
            'slug' => 's-m-duplicate',
            'menu_order' => 30,
            'lang' => 'pl',
        ];
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);

        try {
            app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);
            $this->fail('Niejednoznaczna wartość S/M powinna przerwać synchronizację.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('kilka wartości S/M języka PL', $exception->getMessage());
        }

        $this->assertSame('name', $attribute['order_by']);
        $this->assertSame([], $mutations);
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => in_array(
            $request->method(),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            true,
        ));
    }

    public function test_english_terms_without_any_unambiguous_polish_source_abort_before_the_first_mutation(): void
    {
        $this->createSizeDefinition();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-MISSING-PL');
        $attribute = $this->sizeAttribute();
        $terms = collect($this->allLanguageTerms())
            ->only([110008, 110014])
            ->all();
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);

        try {
            app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);
            $this->fail('Brak jednoznacznych polskich terminów powinien przerwać synchronizację.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('dla języka: PL', $exception->getMessage());
        }

        $this->assertSame('name', $attribute['order_by']);
        $this->assertSame([], $mutations);
        Http::assertNotSent(fn (Request $request): bool => in_array(
            $request->method(),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            true,
        ));
    }

    public function test_a_persistent_second_term_failure_never_exposes_partial_order_through_the_taxonomy(): void
    {
        $this->createSizeDefinition();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-SECOND-PUT-FAILURE');
        $attribute = $this->sizeAttribute();
        $terms = $this->allLanguageTerms();
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations, failingTermId: 57);

        try {
            app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);
            $this->fail('Trwałe HTTP 500 drugiego terminu powinno przerwać synchronizację.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('status code 500', $exception->getMessage());
        }

        $this->assertSame('S/M', $terms[58]['name']);
        $this->assertSame(10, $terms[58]['menu_order']);
        $this->assertSame('m-l', $terms[57]['name']);
        $this->assertSame(0, $terms[57]['menu_order']);
        $this->assertSame('name', $attribute['order_by']);
        $this->assertTrue(collect($mutations)->contains(
            fn (array $mutation): bool => $mutation['path']
                === '/wp-json/wc/v3/products/attributes/1/terms/58',
        ));
        $this->assertTrue(collect($mutations)->contains(
            fn (array $mutation): bool => $mutation['path']
                === '/wp-json/wc/v3/products/attributes/1/terms/57',
        ));
        $this->assertFalse(collect($mutations)->contains(
            fn (array $mutation): bool => $mutation['path']
                === '/wp-json/wc/v3/products/attributes/1',
        ));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT'
            && (string) parse_url($request->url(), PHP_URL_PATH)
                === '/wp-json/wc/v3/products/attributes/1');
    }

    public function test_the_job_uses_the_catalog_lock_and_records_a_successful_existing_term_only_sync(): void
    {
        $this->createSizeDefinition();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-JOB');
        $attribute = $this->sizeAttribute(['order_by' => 'menu_order']);
        $terms = $this->allLanguageTerms(canonicalPolish: true);
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);
        $job = new SyncWooCommerceGlobalSizeOrderJob(
            (int) $integration->id,
            'feature_test',
            'dictionary-fingerprint',
        );

        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);
        $this->assertSame(
            ImportWooCommerceProductsJob::catalogLockKey((int) $integration->id),
            $middleware[0]->key,
        );
        $this->assertTrue($middleware[0]->shareKey);
        $this->assertSame(60, $middleware[0]->releaseAfter);
        $this->assertSame(
            ImportWooCommerceProductsJob::CATALOG_LOCK_SECONDS,
            $middleware[0]->expiresAfter,
        );
        $this->assertSame(
            "woocommerce-global-size-order:{$integration->id}:dictionary-fingerprint",
            $job->uniqueId(),
        );

        $job->handle(app(WooCommerceGlobalSizeOrderSyncService::class));

        $this->assertSame([], $mutations);
        $log = IntegrationSyncLog::query()
            ->where('operation', 'sync_woocommerce_global_size_order')
            ->sole();
        $this->assertSame('success', $log->status);
        $this->assertSame('1', $log->external_id);
        $this->assertSame([
            'trigger' => 'feature_test',
            'existing_terms_only' => true,
        ], $log->request_payload);
        $this->assertSame('synchronized', data_get($log->response_payload, 'status'));
        $this->assertSame(4, data_get($log->response_payload, 'matched_terms'));
        $this->assertSame(0, data_get($log->response_payload, 'updated_terms'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_the_migration_queues_only_active_woocommerce_integrations_after_commit(): void
    {
        $this->createSizeDefinition();
        $active = $this->createWooIntegration('GLOBAL-SIZE-MIGRATION-ACTIVE');
        $this->createWooIntegration('GLOBAL-SIZE-MIGRATION-INACTIVE', active: false);
        $this->createWooIntegration('GLOBAL-SIZE-MIGRATION-MARKETPLACE', type: 'marketplace');
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);

        (require database_path(
            'migrations/2026_07_15_000021_sync_existing_woo_size_term_order.php',
        ))->up();

        Bus::assertDispatchedTimes(SyncWooCommerceGlobalSizeOrderJob::class, 1);
        Bus::assertDispatched(
            SyncWooCommerceGlobalSizeOrderJob::class,
            function (SyncWooCommerceGlobalSizeOrderJob $job) use ($active): bool {
                $this->assertSame((int) $active->id, $job->integrationId);
                $this->assertSame(
                    'historical_size_term_order_2026_07_15_000021',
                    $job->trigger,
                );
                $this->assertSame('database', $job->connection);
                $this->assertSame(SyncWooCommerceGlobalSizeOrderJob::QUEUE, $job->queue);
                $this->assertTrue($job->afterCommit);
                $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $job->dictionaryFingerprint);

                return true;
            },
        );
    }

    public function test_the_followup_migration_promotes_only_the_exact_unreserved_size_order_job(): void
    {
        $this->createSizeDefinition();
        $active = $this->createWooIntegration('GLOBAL-SIZE-QUEUE-PROMOTION');
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);
        $timestamp = now()->timestamp;
        $delayedUntil = $timestamp + 3600;
        $waitingId = DB::table('jobs')->insertGetId([
            'queue' => 'woocommerce-critical',
            'payload' => json_encode([
                'displayName' => SyncWooCommerceGlobalSizeOrderJob::class,
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $delayedUntil,
            'created_at' => $timestamp,
        ]);
        $reservedId = DB::table('jobs')->insertGetId([
            'queue' => 'woocommerce-critical',
            'payload' => json_encode([
                'displayName' => SyncWooCommerceGlobalSizeOrderJob::class,
            ], JSON_THROW_ON_ERROR),
            'attempts' => 1,
            'reserved_at' => $timestamp,
            'available_at' => $timestamp,
            'created_at' => $timestamp,
        ]);
        $unrelatedId = DB::table('jobs')->insertGetId([
            'queue' => 'woocommerce-critical',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\ExportWooCommerceProductDataJob',
                'command' => SyncWooCommerceGlobalSizeOrderJob::class,
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $timestamp,
            'created_at' => $timestamp,
        ]);

        (require database_path(
            'migrations/2026_07_16_000022_promote_woo_size_order_sync_queue.php',
        ))->up();

        $this->assertSame(
            SyncWooCommerceGlobalSizeOrderJob::QUEUE,
            DB::table('jobs')->where('id', $waitingId)->value('queue'),
        );
        $this->assertLessThanOrEqual(
            now()->timestamp,
            DB::table('jobs')->where('id', $waitingId)->value('available_at'),
        );
        $this->assertSame(
            'woocommerce-critical',
            DB::table('jobs')->where('id', $reservedId)->value('queue'),
        );
        $this->assertSame(
            'woocommerce-critical',
            DB::table('jobs')->where('id', $unrelatedId)->value('queue'),
        );
        Bus::assertDispatchedTimes(SyncWooCommerceGlobalSizeOrderJob::class, 1);
        Bus::assertDispatched(
            SyncWooCommerceGlobalSizeOrderJob::class,
            function (SyncWooCommerceGlobalSizeOrderJob $job) use ($active): bool {
                $this->assertSame((int) $active->id, $job->integrationId);
                $this->assertSame(
                    'dedicated_size_order_queue_2026_07_16_000022',
                    $job->trigger,
                );
                $this->assertSame('database', $job->connection);
                $this->assertSame(SyncWooCommerceGlobalSizeOrderJob::QUEUE, $job->queue);
                $this->assertTrue($job->afterCommit);

                return true;
            },
        );
    }

    public function test_the_deploy_postcondition_requires_a_fresh_success_for_every_active_integration(): void
    {
        $integration = $this->createWooIntegration('GLOBAL-SIZE-POSTCONDITION');
        $since = now()->subMinute();
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'sync_woocommerce_global_size_order',
            'status' => 'success',
            'external_resource' => 'product_attribute',
            'response_payload' => ['status' => 'synchronized'],
            'attempts' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $exitCode = Artisan::call('erp:verify-woocommerce-global-size-order-sync', [
            '--since' => $since->toIso8601String(),
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'active=1, fresh_success=1, missing=-, pending=0, failed=0',
            Artisan::output(),
        );
    }

    public function test_the_deploy_sync_command_refuses_to_bypass_the_catalog_lock_outside_maintenance(): void
    {
        $this->createSizeDefinition();
        $this->createWooIntegration('GLOBAL-SIZE-DEPLOY-NOT-DOWN');
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);

        $exitCode = Artisan::call('erp:sync-woocommerce-global-size-order-during-maintenance', [
            '--trigger' => 'deploy_abcdef123456-123-1',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'allowed only while the application is in maintenance mode',
            Artisan::output(),
        );
        Bus::assertNotDispatched(SyncWooCommerceGlobalSizeOrderJob::class);
        $this->assertSame(0, IntegrationSyncLog::query()
            ->where('operation', 'sync_woocommerce_global_size_order')
            ->count());
        Http::assertNothingSent();
    }

    public function test_the_deploy_sync_command_runs_each_active_integration_directly_during_maintenance(): void
    {
        $this->createSizeDefinition();
        $active = $this->createWooIntegration('GLOBAL-SIZE-DEPLOY-SYNC');
        $this->createWooIntegration('GLOBAL-SIZE-DEPLOY-SYNC-INACTIVE', active: false);
        $this->createWooIntegration('GLOBAL-SIZE-DEPLOY-SYNC-MARKETPLACE', type: 'marketplace');
        $attribute = $this->sizeAttribute();
        $terms = $this->allLanguageTerms();
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);
        Artisan::call('down', ['--retry' => 60]);

        try {
            $exitCode = Artisan::call('erp:sync-woocommerce-global-size-order-during-maintenance', [
                '--trigger' => 'deploy_abcdef123456-123-1',
            ]);
            $output = Artisan::output();
        } finally {
            Artisan::call('up');
        }

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            "completed for integration {$active->id}",
            $output,
        );
        $this->assertStringContainsString(
            'active=1, succeeded=1, failed=0, trigger=deploy_abcdef123456-123-1',
            $output,
        );
        Bus::assertNotDispatched(SyncWooCommerceGlobalSizeOrderJob::class);
        $log = IntegrationSyncLog::query()
            ->where('operation', 'sync_woocommerce_global_size_order')
            ->sole();
        $this->assertSame((int) $active->id, (int) $log->wordpress_integration_id);
        $this->assertSame('success', $log->status);
        $this->assertSame('deploy_abcdef123456-123-1', data_get($log->request_payload, 'trigger'));
        $this->assertSame('synchronized', data_get($log->response_payload, 'status'));
        $this->assertSame('menu_order', $attribute['order_by']);
        $this->assertSame(10, $terms[58]['menu_order']);
        $this->assertSame(20, $terms[57]['menu_order']);
    }

    public function test_the_deploy_sync_command_rejects_an_empty_audit_trigger(): void
    {
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);

        $exitCode = Artisan::call('erp:sync-woocommerce-global-size-order-during-maintenance', [
            '--trigger' => '   ',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('trigger cannot be empty', Artisan::output());
        Bus::assertNotDispatched(SyncWooCommerceGlobalSizeOrderJob::class);
    }

    public function test_an_exact_deploy_trigger_is_not_satisfied_by_another_success_from_the_same_second(): void
    {
        $integration = $this->createWooIntegration('GLOBAL-SIZE-POSTCONDITION-TRIGGER');
        $since = now()->startOfSecond();
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'sync_woocommerce_global_size_order',
            'status' => 'success',
            'external_resource' => 'product_attribute',
            'request_payload' => ['trigger' => 'deploy_previous-release'],
            'response_payload' => ['status' => 'synchronized'],
            'attempts' => 1,
            'started_at' => $since,
            'finished_at' => $since,
        ]);

        $exitCode = Artisan::call('erp:verify-woocommerce-global-size-order-sync', [
            '--since' => $since->toIso8601String(),
            '--trigger' => 'deploy_current-release',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            "active=1, fresh_success=0, missing={$integration->id}, pending=0, failed=0",
            $output,
        );
        $this->assertStringContainsString('trigger=deploy_current-release', $output);
    }

    public function test_an_exact_deploy_success_supersedes_async_queue_rows_from_the_same_second(): void
    {
        $integration = $this->createWooIntegration('GLOBAL-SIZE-POSTCONDITION-SUPERSEDE');
        $since = now()->startOfSecond();
        $payload = json_encode([
            'displayName' => SyncWooCommerceGlobalSizeOrderJob::class,
        ], JSON_THROW_ON_ERROR);
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'sync_woocommerce_global_size_order',
            'status' => 'success',
            'external_resource' => 'product_attribute',
            'request_payload' => ['trigger' => 'deploy_current-release'],
            'response_payload' => ['status' => 'synchronized'],
            'attempts' => 1,
            'started_at' => $since,
            'finished_at' => $since,
        ]);
        DB::table('jobs')->insert([
            'queue' => SyncWooCommerceGlobalSizeOrderJob::QUEUE,
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $since->timestamp + 60,
            'created_at' => $since->timestamp,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'global-size-order-superseded',
            'connection' => 'database',
            'queue' => SyncWooCommerceGlobalSizeOrderJob::QUEUE,
            'payload' => $payload,
            'exception' => 'superseded fixture',
            'failed_at' => $since,
        ]);

        $exitCode = Artisan::call('erp:verify-woocommerce-global-size-order-sync', [
            '--since' => $since->toIso8601String(),
            '--trigger' => 'deploy_current-release',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'active=1, fresh_success=1, missing=-, pending=0, failed=0',
            Artisan::output(),
        );
    }

    public function test_the_deploy_postcondition_rejects_a_pending_exact_job_and_missing_success(): void
    {
        $integration = $this->createWooIntegration('GLOBAL-SIZE-POSTCONDITION-PENDING');
        $timestamp = now()->timestamp;
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'sync_woocommerce_global_size_order',
            'status' => 'success',
            'external_resource' => 'product_attribute',
            'response_payload' => ['status' => 'skipped_no_size_definition'],
            'attempts' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        DB::table('jobs')->insert([
            'queue' => SyncWooCommerceGlobalSizeOrderJob::QUEUE,
            'payload' => json_encode([
                'displayName' => SyncWooCommerceGlobalSizeOrderJob::class,
            ], JSON_THROW_ON_ERROR),
            'attempts' => 1,
            'reserved_at' => null,
            'available_at' => $timestamp + 60,
            'created_at' => $timestamp,
        ]);

        $exitCode = Artisan::call('erp:verify-woocommerce-global-size-order-sync', [
            '--since' => now()->subMinute()->toIso8601String(),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            "active=1, fresh_success=0, missing={$integration->id}, pending=1, failed=0",
            Artisan::output(),
        );
    }

    public function test_the_postcondition_without_an_exact_trigger_still_rejects_an_old_pending_job(): void
    {
        $integration = $this->createWooIntegration('GLOBAL-SIZE-POSTCONDITION-OLD-PENDING');
        $since = now()->subMinute();
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'sync_woocommerce_global_size_order',
            'status' => 'success',
            'external_resource' => 'product_attribute',
            'response_payload' => ['status' => 'synchronized'],
            'attempts' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        DB::table('jobs')->insert([
            'queue' => SyncWooCommerceGlobalSizeOrderJob::QUEUE,
            'payload' => json_encode([
                'displayName' => SyncWooCommerceGlobalSizeOrderJob::class,
            ], JSON_THROW_ON_ERROR),
            'attempts' => 1,
            'reserved_at' => null,
            'available_at' => now()->timestamp + 60,
            'created_at' => now()->subHours(2)->timestamp,
        ]);

        $exitCode = Artisan::call('erp:verify-woocommerce-global-size-order-sync', [
            '--since' => $since->toIso8601String(),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'active=1, fresh_success=1, missing=-, pending=1, failed=0',
            Artisan::output(),
        );
    }

    public function test_the_observer_queues_a_new_fingerprint_for_size_dictionary_changes_but_not_metadata_only_changes(): void
    {
        $definition = $this->createSizeDefinition();
        $active = $this->createWooIntegration('GLOBAL-SIZE-OBSERVER');
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);
        $observer = app(ProductParameterDefinitionObserver::class);
        $this->assertInstanceOf(ShouldHandleEventsAfterCommit::class, $observer);

        $definition->update([
            'values' => ['XS', 'S/M', 'M/L'],
            'values_en' => ['XS', 'S/M', 'M/L'],
        ]);
        $observer->saved($definition);

        Bus::assertDispatchedTimes(SyncWooCommerceGlobalSizeOrderJob::class, 1);
        Bus::assertDispatched(
            SyncWooCommerceGlobalSizeOrderJob::class,
            function (SyncWooCommerceGlobalSizeOrderJob $job) use ($active): bool {
                $this->assertSame((int) $active->id, $job->integrationId);
                $this->assertSame('erp_size_dictionary_changed', $job->trigger);
                $this->assertSame('database', $job->connection);
                $this->assertSame(SyncWooCommerceGlobalSizeOrderJob::QUEUE, $job->queue);
                $this->assertTrue($job->afterCommit);
                $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $job->dictionaryFingerprint);

                return true;
            },
        );

        $definition->update(['metadata' => ['note' => 'does not affect storefront order']]);
        $observer->saved($definition);

        Bus::assertDispatchedTimes(SyncWooCommerceGlobalSizeOrderJob::class, 1);
    }

    public function test_the_observer_queues_when_a_generic_size_dictionary_changes_to_color(): void
    {
        $active = $this->createWooIntegration('GLOBAL-SIZE-GENERIC-OBSERVER');
        $definition = ProductParameterDefinition::withoutEvents(fn (): ProductParameterDefinition => ProductParameterDefinition::query()->create([
            'name' => 'Imported axis',
            'slug' => 'blvariant',
            'input_type' => 'select',
            'values' => ['S/M', 'M/L'],
            'values_en' => ['Small/Medium', 'Medium/Large'],
            'is_variant' => true,
            'sort_order' => 10,
        ]));
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);
        $observer = app(ProductParameterDefinitionObserver::class);

        $definition->update([
            'values' => ['Black', 'White'],
            'values_en' => ['Black', 'White'],
        ]);
        $observer->saved($definition);

        Bus::assertDispatchedTimes(SyncWooCommerceGlobalSizeOrderJob::class, 1);
        Bus::assertDispatched(
            SyncWooCommerceGlobalSizeOrderJob::class,
            fn (SyncWooCommerceGlobalSizeOrderJob $job): bool => $job->integrationId === (int) $active->id
                && $job->trigger === 'erp_size_dictionary_changed',
        );

    }

    public function test_the_observer_queues_when_the_only_generic_size_dictionary_is_deleted(): void
    {
        $active = $this->createWooIntegration('GLOBAL-SIZE-GENERIC-DELETE-OBSERVER');
        $definition = ProductParameterDefinition::withoutEvents(fn (): ProductParameterDefinition => ProductParameterDefinition::query()->create([
            'name' => 'wariant',
            'slug' => 'wariant',
            'input_type' => 'select',
            'values' => ['S/M', 'M/L'],
            'values_en' => ['Small/Medium', 'Medium/Large'],
            'is_variant' => true,
            'sort_order' => 10,
        ]));
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);
        $observer = app(ProductParameterDefinitionObserver::class);

        ProductParameterDefinition::withoutEvents(fn () => $definition->delete());
        $observer->deleted($definition);

        Bus::assertDispatchedTimes(SyncWooCommerceGlobalSizeOrderJob::class, 1);
        Bus::assertDispatched(
            SyncWooCommerceGlobalSizeOrderJob::class,
            fn (SyncWooCommerceGlobalSizeOrderJob $job): bool => $job->integrationId === (int) $active->id
                && $job->trigger === 'erp_size_dictionary_changed',
        );
    }

    private function createSizeDefinition(): ProductParameterDefinition
    {
        return ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S/M', 'M/L'],
            'values_en' => ['S/M', 'M/L'],
            // Historical production dictionaries can drive a size axis even
            // when this flag was never enabled.
            'is_variant' => false,
            'is_required' => false,
            'sort_order' => 10,
        ]);
    }

    private function createPluralSizeDefinition(): ProductParameterDefinition
    {
        return ProductParameterDefinition::query()->create([
            'name' => 'Rozmiary',
            'name_en' => 'Sizes',
            'slug' => 'rozmiary',
            'input_type' => 'select',
            'values' => ['ONE SIZE'],
            'values_en' => ['ONE SIZE'],
            'is_variant' => false,
            'is_required' => false,
            'sort_order' => 20,
        ]);
    }

    private function createMappedVariantFamily(
        WordpressIntegration $integration,
        string $externalProductId,
        string $skuPrefix,
    ): Product {
        $parent = Product::query()->create([
            'sku' => $skuPrefix.'-PARENT',
            'name' => $skuPrefix.' parent',
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variable',
                ],
            ],
        ]);
        $variant = Product::query()->create([
            'sku' => $skuPrefix.'-VARIANT',
            'name' => $skuPrefix.' variant',
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => 'variation',
                ],
            ],
        ]);
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $variant->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $integration->sales_channel_id,
            'external_product_id' => $externalProductId,
            'external_variation_id' => null,
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'mapping_role' => 'primary',
                'language' => 'pl',
            ],
        ]);

        return $parent;
    }

    private function createParentAlias(
        WordpressIntegration $integration,
        Product $parent,
        string $externalProductId,
        string $language,
    ): void {
        ProductChannelAlias::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $integration->sales_channel_id,
            'source_product_id' => $parent->id,
            'external_product_id' => $externalProductId,
            'external_variation_id' => null,
            'external_sku' => $parent->sku,
            'language' => $language,
            'metadata' => [],
        ]);
    }

    private function createWooIntegration(
        string $code,
        bool $active = true,
        string $type = 'woocommerce',
    ): WordpressIntegration {
        $channel = SalesChannel::query()->create([
            'code' => $code,
            'name' => $code,
            'type' => $type,
            'is_active' => $active,
        ]);

        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => $code,
            'base_url' => 'https://'.mb_strtolower($code).'.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function sizeAttribute(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'Rozmiar',
            'slug' => 'pa_rozmiar',
            'order_by' => 'name',
        ], $overrides);
    }

    /** @return array<int, array<string, mixed>> */
    private function allLanguageTerms(bool $canonicalPolish = false): array
    {
        return [
            57 => [
                'id' => 57,
                'name' => $canonicalPolish ? 'M/L' : 'm-l',
                'slug' => 'm-l',
                'menu_order' => $canonicalPolish ? 20 : 0,
            ],
            58 => [
                'id' => 58,
                'name' => $canonicalPolish ? 'S/M' : 's-m',
                'slug' => 's-m',
                'menu_order' => $canonicalPolish ? 10 : 0,
            ],
            110014 => [
                'id' => 110014,
                'name' => 'M/L',
                'slug' => 'm-l-en',
                'menu_order' => 20,
            ],
            110008 => [
                'id' => 110008,
                'name' => 'S/M',
                'slug' => 's-m-en',
                'menu_order' => 10,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attribute
     * @param  array<int, array<string, mixed>>  $terms
     * @param  list<array{method:string,path:string,payload:array<string,mixed>}>  $mutations
     */
    private function fakeWooCatalog(
        array &$attribute,
        array &$terms,
        array &$mutations,
        ?int $failingTermId = null,
    ): void {
        Http::fake(function (Request $request) use (
            &$attribute,
            &$terms,
            &$mutations,
            $failingTermId,
        ) {
            $method = $request->method();
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($method === 'GET' && $path === '/wp-json/wc/v3/products/attributes') {
                return Http::response([$attribute]);
            }

            if ($method === 'GET' && $path === '/wp-json/wc/v3/products/attributes/1/terms') {
                // Deliberately ignore the lang query just like the affected
                // WooCommerce/Polylang endpoint and return both languages.
                return Http::response(array_values($terms));
            }

            if ($method === 'PUT' && $path === '/wp-json/wc/v3/products/attributes/1') {
                $payload = $request->data();
                $attribute = array_merge($attribute, $payload);
                $mutations[] = compact('method', 'path', 'payload');

                return Http::response($attribute);
            }

            if ($method === 'PUT'
                && preg_match('#^/wp-json/wc/v3/products/attributes/1/terms/(\d+)$#', $path, $matches) === 1
            ) {
                $termId = (int) $matches[1];

                if (! isset($terms[$termId])) {
                    throw new RuntimeException("Test otrzymał aktualizację nieistniejącego terminu #{$termId}.");
                }

                $payload = $request->data();
                $mutations[] = compact('method', 'path', 'payload');

                if ($termId === $failingTermId) {
                    return Http::response(['message' => 'persistent test failure'], 500);
                }

                $terms[$termId] = array_merge($terms[$termId], $payload);

                return Http::response($terms[$termId]);
            }

            throw new RuntimeException("Nieoczekiwane żądanie WooCommerce: {$method} {$path}");
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $attributes
     * @param  array<int, array<string, mixed>>  $terms
     * @param  list<array{method:string,path:string,payload:array<string,mixed>}>  $mutations
     * @param  list<array<string, mixed>>  $products
     */
    private function fakeWooCatalogWithAttributeEvidence(
        array &$attributes,
        array &$terms,
        array &$mutations,
        array $products,
    ): void {
        Http::fake(function (Request $request) use (
            &$attributes,
            &$terms,
            &$mutations,
            $products,
        ) {
            $method = $request->method();
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($method === 'GET' && $path === '/wp-json/wc/v3/products/attributes') {
                return Http::response(array_values($attributes));
            }

            if ($method === 'GET' && $path === '/wp-json/wc/v3/products') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $include = $query['include'] ?? '';
                $requestedProductIds = collect(is_array($include) ? $include : explode(',', $include))
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique();

                return Http::response(collect($products)
                    ->filter(fn (array $product): bool => $requestedProductIds->contains(
                        (int) ($product['id'] ?? 0),
                    ))
                    ->values()
                    ->all());
            }

            if ($method === 'GET' && $path === '/wp-json/wc/v3/products/attributes/1/terms') {
                return Http::response(array_values($terms));
            }

            if ($method === 'PUT' && $path === '/wp-json/wc/v3/products/attributes/1') {
                $payload = $request->data();
                $attributes[1] = array_merge($attributes[1], $payload);
                $mutations[] = compact('method', 'path', 'payload');

                return Http::response($attributes[1]);
            }

            if ($method === 'PUT'
                && preg_match('#^/wp-json/wc/v3/products/attributes/1/terms/(\d+)$#', $path, $matches) === 1
            ) {
                $termId = (int) $matches[1];

                if (! isset($terms[$termId])) {
                    throw new RuntimeException("Test otrzymał aktualizację nieistniejącego terminu #{$termId}.");
                }

                $payload = $request->data();
                $mutations[] = compact('method', 'path', 'payload');
                $terms[$termId] = array_merge($terms[$termId], $payload);

                return Http::response($terms[$termId]);
            }

            throw new RuntimeException("Nieoczekiwane żądanie WooCommerce: {$method} {$path}");
        });
    }
}
