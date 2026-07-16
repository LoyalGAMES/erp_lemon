<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceImportService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooCommerceVariantAxisNameImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_size_dictionary_lookup_is_memoized_only_for_the_current_import_service_run(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S', 'M/L'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 10,
        ]);
        $definitionQueries = 0;
        DB::listen(function ($query) use (&$definitionQueries): void {
            if (str_contains(mb_strtolower($query->sql), 'product_parameter_definitions')) {
                $definitionQueries++;
            }
        });

        $service = app(WooCommerceImportService::class);
        $knownSizeOptions = new \ReflectionMethod($service, 'knownSizeOptions');

        $this->assertSame(['S', 'M/L'], $knownSizeOptions->invoke($service)->all());
        $this->assertSame(['S', 'M/L'], $knownSizeOptions->invoke($service)->all());
        $this->assertSame(1, $definitionQueries);
    }

    public function test_new_import_canonicalizes_only_proven_size_aliases(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_contains($path, '/products/categories')) {
                return Http::response([]);
            }

            if (preg_match('#/products/(\d+)/variations$#', $path, $matches) === 1) {
                if ((int) ($query['page'] ?? 1) !== 1) {
                    return Http::response([]);
                }

                $rows = [
                    9101 => [['IMPORT-PLURAL-S', 'Rozmiary', 'S'], ['IMPORT-PLURAL-ML', 'Rozmiary', 'M/L']],
                    9102 => [['IMPORT-BL-SIZE-XS', 'BLVariant', 'XS'], ['IMPORT-BL-SIZE-SM', 'BLVariant', 'S/M']],
                    9103 => [['IMPORT-BL-COLOR-BLACK', 'BLVariant', 'Czarny'], ['IMPORT-BL-COLOR-WHITE', 'BLVariant', 'Biały']],
                    9104 => [['IMPORT-EN-VARIANT-S', 'Variant', 'S'], ['IMPORT-EN-VARIANT-ML', 'Variant', 'M/L']],
                    9105 => [['IMPORT-DIRECT-DECIMAL-385', 'Rozmiar', '38,5'], ['IMPORT-DIRECT-DECIMAL-40', 'Rozmiar', '40']],
                    9106 => [['IMPORT-BL-DECIMAL-385', 'BLVariant', '38,5'], ['IMPORT-BL-DECIMAL-40', 'BLVariant', '40']],
                    9107 => [['IMPORT-EN-DICT-SMALL', 'Variant', 'Small'], ['IMPORT-EN-DICT-LARGE', 'Variant', 'Large']],
                ];
                $familyRows = $rows[(int) $matches[1]] ?? [];

                return Http::response(collect($familyRows)
                    ->map(fn (array $row, int $index): array => [
                        'id' => (int) $matches[1] + 100 + $index,
                        'sku' => $row[0],
                        'name' => $row[0],
                        'status' => 'publish',
                        'attributes' => [['name' => $row[1], 'option' => $row[2]]],
                    ])
                    ->all());
            }

            if ((int) ($query['page'] ?? 1) !== 1) {
                return Http::response([]);
            }

            if ($path === '/wp-json/wc/v3/products') {
                return Http::response([
                    $this->wooVariableProduct(9101, 'IMPORT-PLURAL', 'Rozmiary', ['S', 'M/L'], 'Sizes'),
                    $this->wooVariableProduct(9102, 'IMPORT-BL-SIZE', 'BLVariant', ['XS', 'S/M']),
                    $this->wooVariableProduct(9103, 'IMPORT-BL-COLOR', 'BLVariant', ['Czarny', 'Biały']),
                    $this->wooVariableProduct(9104, 'IMPORT-EN-VARIANT-SIZE', 'Variant', ['S', 'M/L']),
                    $this->wooVariableProduct(9105, 'IMPORT-DIRECT-DECIMAL', 'Rozmiar', ['38,5', '40']),
                    $this->wooVariableProduct(9106, 'IMPORT-BL-DECIMAL', 'BLVariant', ['38,5', '40']),
                    $this->wooVariableProduct(9107, 'IMPORT-EN-DICTIONARY', 'Variant', ['Small', 'Large']),
                ]);
            }

            return Http::response([], 404);
        });

        $channel = SalesChannel::query()->create([
            'code' => 'B2C-AXIS-IMPORT',
            'name' => 'B2C axis import',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo axis import',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_import' => ['languages' => ['pl']]],
        ]);
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['Mały', 'Duży'],
            'values_en' => ['Small', 'Large'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 10,
        ]);

        app(WooCommerceImportService::class)->importProducts($integration);

        foreach (['IMPORT-PLURAL', 'IMPORT-BL-SIZE', 'IMPORT-EN-VARIANT-SIZE', 'IMPORT-DIRECT-DECIMAL', 'IMPORT-BL-DECIMAL', 'IMPORT-EN-DICTIONARY'] as $sku) {
            $product = Product::query()->where('sku', $sku)->firstOrFail();
            $this->assertSame('Rozmiar', data_get($product->masterData(), 'variant_attribute'));
            $this->assertSame('Rozmiar', data_get($product->masterData(), 'parameters.0.name'));
        }

        $color = Product::query()->where('sku', 'IMPORT-BL-COLOR')->firstOrFail();
        $this->assertSame('BLVariant', data_get($color->masterData(), 'variant_attribute'));
        $this->assertSame('BLVariant', data_get($color->masterData(), 'parameters.0.name'));
        $this->assertDatabaseHas('product_parameter_definitions', ['name' => 'Rozmiar']);
        $this->assertDatabaseHas('product_parameter_definitions', ['name' => 'BLVariant']);
        $this->assertFalse(ProductParameterDefinition::query()->where('name', 'Rozmiary')->exists());

        foreach (['IMPORT-PLURAL', 'IMPORT-BL-SIZE', 'IMPORT-EN-VARIANT-SIZE', 'IMPORT-BL-DECIMAL'] as $sku) {
            $root = Product::query()->where('sku', $sku)->firstOrFail();
            $root->load('variantChildren');
            $repair = app(WooOwnedVariantAxisRepairService::class);
            $this->assertTrue(
                $repair->isSizeVariantRootCandidate($root),
                $sku.' should be a repair candidate after import finalization.',
            );
            $mapping = ProductChannelMapping::query()
                ->where('product_id', $root->id)
                ->whereNull('external_variation_id')
                ->firstOrFail();
            $this->assertSame(
                WooOwnedVariantAxisRepairService::REVISION,
                data_get($mapping->metadata, WooOwnedVariantAxisRepairService::STATE_PATH.'.revision'),
            );
            $this->assertSame(
                'pending',
                data_get($mapping->metadata, WooOwnedVariantAxisRepairService::STATE_PATH.'.status'),
            );
        }

        $colorMapping = ProductChannelMapping::query()
            ->where('product_id', $color->id)
            ->whereNull('external_variation_id')
            ->firstOrFail();
        $this->assertNull(data_get(
            $colorMapping->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
        ));
        $directDecimalMapping = ProductChannelMapping::query()
            ->where('product_id', Product::query()->where('sku', 'IMPORT-DIRECT-DECIMAL')->value('id'))
            ->whereNull('external_variation_id')
            ->firstOrFail();
        $this->assertNull(data_get(
            $directDecimalMapping->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
        ));

        $auditedMapping = ProductChannelMapping::query()
            ->where('product_id', Product::query()->where('sku', 'IMPORT-PLURAL')->value('id'))
            ->whereNull('external_variation_id')
            ->firstOrFail();
        $auditedMetadata = (array) $auditedMapping->metadata;
        $auditedState = [
            'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_BLANK_CHILD_ASSIGNMENT_AUDIT_REVISION,
            'status' => 'manual_review',
            'requested_at' => '2026-07-16T08:00:00+00:00',
            'completed_at' => '2026-07-16T08:01:00+00:00',
            'result' => ['reason' => 'historical audit result'],
        ];
        data_set(
            $auditedMetadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
            $auditedState,
        );
        $auditedMapping->forceFill(['metadata' => $auditedMetadata])->save();

        app(WooCommerceImportService::class)->importProducts($integration);

        $this->assertSame(
            $auditedState,
            data_get(
                $auditedMapping->fresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH,
            ),
            'A regular import may not promote the broad 000032 audit into the narrowly scoped 000033 release.',
        );
    }

    /**
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private function wooVariableProduct(
        int $id,
        string $sku,
        string $axis,
        array $options,
        ?string $englishAxis = null,
    ): array {
        $product = [
            'id' => $id,
            'sku' => $sku,
            'name' => $sku,
            'type' => 'variable',
            'status' => 'publish',
            'meta_data' => [[
                'key' => '_sempre_erp_variant_attribute',
                'value' => $axis,
            ]],
            'attributes' => [[
                'name' => $axis,
                'variation' => true,
                'options' => $options,
            ]],
        ];

        if ($englishAxis !== null) {
            $product['erp_translations'] = ['en' => [
                'id' => $id + 1000,
                'sku' => $sku,
                'name' => $sku,
                'attributes' => [[
                    'name' => $englishAxis,
                    'variation' => true,
                    'options' => $options,
                ]],
            ]];
        }

        return $product;
    }
}
