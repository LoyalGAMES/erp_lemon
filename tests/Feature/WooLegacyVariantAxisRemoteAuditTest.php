<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooLegacyVariantAxisRemoteAuditService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooLegacyVariantAxisRemoteAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_remote_audit_finds_mapped_and_unmapped_legacy_axes_without_local_candidate_filtering(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['36'],
            'values_en' => ['36'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 10,
        ]);
        $channel = SalesChannel::query()->create([
            'code' => 'REMOTE-AXIS-AUDIT',
            'name' => 'Remote axis audit',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo audit',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
        ]);
        $localProduct = Product::query()->create([
            'sku' => 'REMOTE-MAPPED',
            'name' => 'Mapped legacy product',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'woocommerce_import',
                'product_type' => 'variable',
            ]],
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $localProduct->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '4380',
            'external_sku' => $localProduct->sku,
            'stock_sync_enabled' => true,
        ]);

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET'
                && $path === '/wp-json/wc/v3/products/attributes'
            ) {
                return Http::response([[
                    'id' => 6,
                    'name' => 'wariant',
                    'slug' => 'pa_wariant',
                ]]);
            }

            if ($request->method() === 'GET'
                && $path === '/wp-json/wc/v3/products/attributes/6/terms'
            ) {
                return Http::response([[
                    'id' => 88,
                    'name' => '36',
                    'slug' => '36',
                    'count' => 2,
                ]]);
            }

            if ($request->method() === 'GET'
                && $path === '/wp-json/wc/v3/products'
                && $request['attribute'] === 'pa_wariant'
                && (string) $request['attribute_term'] === '88'
            ) {
                return Http::response([
                    $this->legacyProduct(4380, 'REMOTE-MAPPED'),
                    $this->legacyProduct(9999, ''),
                ]);
            }

            return Http::response([], 404);
        });

        $result = app(WooLegacyVariantAxisRemoteAuditService::class)->audit();

        $this->assertSame(1, $result['integrations']);
        $this->assertSame(1, $result['attributes']);
        $this->assertSame(2, $result['remote_products']);
        $this->assertSame(1, $result['unique_local_roots']);
        $this->assertSame(1, $result['mapped_products']);
        $this->assertSame(1, $result['unmapped_products']);
        $this->assertSame(0, $result['ambiguous_products']);
        $this->assertSame(0, $result['current_candidates']);
        $this->assertSame(2, $result['missed_products']);
        $this->assertSame(1, $result['exact_remote_products']);
        $this->assertSame(1, $result['migration_safe_remote_products']);
        $this->assertSame(0, $result['conflicting_remote_products']);
        $this->assertSame(1, $result['exact_remote_roots']);
        $this->assertSame(1, $result['migration_safe_remote_roots']);
        $mapped = collect($result['rows'])->firstWhere('external_product_id', '4380');
        $this->assertSame([$localProduct->id], $mapped['owner_root_ids'] ?? null);
        $this->assertSame([], $mapped['candidate_root_ids'] ?? null);
        $this->assertSame(false, data_get($mapped, 'size_axes.0.variation'));
        $this->assertSame(['36'], $mapped['legacy_options'] ?? null);
        $this->assertSame('pl', $mapped['language'] ?? null);
        $this->assertSame('REMOTE-MAPPED', data_get(
            $mapped,
            'owner_details.'.$localProduct->id.'.root_sku',
        ));

        $marked = app(WooLegacyVariantAxisRemoteAuditService::class)
            ->markSafeRemoteRepairCandidates($result);
        $this->assertSame([
            'eligible_roots' => 1,
            'marked_roots' => 1,
            'marked_mappings' => 1,
            'skipped_roots' => 0,
        ], $marked);
        $metadata = (array) ProductChannelMapping::query()
            ->where('product_id', $localProduct->id)
            ->firstOrFail()
            ->metadata;
        $this->assertSame(
            WooOwnedVariantAxisRepairService::REVISION,
            data_get($metadata, WooOwnedVariantAxisRepairService::STATE_PATH.'.revision'),
        );
        $this->assertSame(
            'pending',
            data_get($metadata, WooOwnedVariantAxisRepairService::STATE_PATH.'.status'),
        );
        $this->assertTrue((bool) data_get(
            $metadata,
            WooOwnedVariantAxisRepairService::REMOTE_EVIDENCE_PATH.'.verified',
        ));
        $this->assertSame('pl', data_get(
            $metadata,
            WooOwnedVariantAxisRepairService::REMOTE_EVIDENCE_PATH.'.targets.0.language',
        ));
    }

    public function test_remote_audit_treats_the_dictionary_backed_live_legacy_axis_as_authoritative(): void
    {
        ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S', 'M', 'S/M', 'M/L'],
            'values_en' => ['S', 'M', 'S/M', 'M/L'],
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 10,
        ]);
        $method = new \ReflectionMethod(
            WooLegacyVariantAxisRemoteAuditService::class,
            'remoteSizeEvidence',
        );
        $service = app(WooLegacyVariantAxisRemoteAuditService::class);
        $legacy = ['options' => ['M', 'S']];

        $legacyOnly = $method->invoke($service, $legacy, collect());
        $this->assertTrue($legacyOnly['verified']);
        $this->assertSame('legacy_only', $legacyOnly['mode']);
        $this->assertSame(['s', 'm'], $legacyOnly['option_keys']);

        $informationalConflict = $method->invoke($service, $legacy, collect([[
            'id' => 1,
            'variation' => false,
            'options' => ['S/M', 'M/L'],
        ]]));
        $this->assertTrue($informationalConflict['verified']);
        $this->assertSame('legacy_over_informational_size', $informationalConflict['mode']);
        $this->assertSame(['s', 'm'], $informationalConflict['option_keys']);

        $twoActiveAxes = $method->invoke($service, $legacy, collect([[
            'id' => 1,
            'variation' => true,
            'options' => ['S/M', 'M/L'],
        ]]));
        $this->assertFalse($twoActiveAxes['verified']);
        $this->assertNull($twoActiveAxes['mode']);
    }

    /** @return array<string,mixed> */
    private function legacyProduct(int $id, string $sku): array
    {
        return [
            'id' => $id,
            'sku' => $sku,
            'type' => 'variable',
            'lemon_erp_language' => 'pl',
            'permalink' => 'https://shop.test/product/'.$id,
            'attributes' => [
                [
                    'id' => 1,
                    'name' => 'Rozmiar',
                    'slug' => 'pa_rozmiar',
                    'variation' => false,
                    'options' => ['36'],
                ],
                [
                    'id' => 6,
                    'name' => 'wariant',
                    'slug' => 'pa_wariant',
                    'variation' => true,
                    'options' => ['36'],
                ],
            ],
        ];
    }
}
