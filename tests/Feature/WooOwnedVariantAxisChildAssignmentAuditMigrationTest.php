<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooOwnedVariantAxisChildAssignmentAuditMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requeues_a_completed_canonical_parent_whose_local_children_prove_exact_size_assignments(): void
    {
        Http::fake();
        CarbonImmutable::setTestNow('2026-07-16 20:00:00');

        try {
            ProductParameterDefinition::query()->create([
                'name' => 'Rozmiar',
                'name_en' => 'Size',
                'slug' => 'rozmiar',
                'input_type' => 'select',
                'values' => ['S/M', 'M/L'],
                'values_en' => ['S/M', 'M/L'],
                'is_variant' => true,
            ]);
            $channel = SalesChannel::query()->create([
                'code' => 'CHILD-SIZE-AUDIT',
                'name' => 'Child size audit',
                'type' => 'woocommerce',
                'is_active' => true,
            ]);
            WordpressIntegration::query()->create([
                'sales_channel_id' => $channel->id,
                'name' => 'Child size audit Woo',
                'base_url' => 'https://shop.test',
                'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
                'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            ]);
            $warehouse = Warehouse::query()->create([
                'code' => 'CHILD-SIZE-AUDIT',
                'name' => 'Child size audit',
                'type' => 'own',
                'is_active' => true,
            ]);
            $parent = Product::query()->create([
                'sku' => 'BLS681B4936A06EB',
                'name' => 'Garnitur HARMONY Biały',
                'unit' => 'szt',
                'vat_rate' => 23,
                'quantity_precision' => 0,
                'is_active' => true,
                'attributes' => ['master' => [
                    'source' => 'erp',
                    'product_type' => 'variable',
                    'variant_attribute' => 'Rozmiar',
                    'parameters' => [[
                        'name' => 'Rozmiar',
                        'value' => 'M/L | S/M',
                        'variation' => true,
                    ]],
                ]],
            ]);
            $parentMapping = ProductChannelMapping::query()->create([
                'product_id' => $parent->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => '1506',
                'external_sku' => $parent->sku,
                'stock_sync_enabled' => true,
                'metadata' => [
                    'operator_note' => 'preserve',
                    'maintenance' => ['woo_owned_variant_axis_repair' => [
                        'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_EXACT_DEFAULT_REPAIR_REVISION,
                        'status' => 'completed',
                        'result' => ['status' => 'already_canonical'],
                    ]],
                ],
            ]);

            foreach ([
                ['id' => '1507', 'sku' => 'BLS681B4936D462D', 'option' => 'S/M', 'stock' => 0],
                ['id' => '1508', 'sku' => 'BLS681B49370DFD4', 'option' => 'M/L', 'stock' => 1],
            ] as $index => $row) {
                $child = Product::query()->create([
                    'sku' => $row['sku'],
                    'name' => "Garnitur HARMONY Biały - {$row['option']}",
                    'unit' => 'szt',
                    'vat_rate' => 23,
                    'quantity_precision' => 0,
                    'is_active' => true,
                    'attributes' => ['master' => [
                        // Editing the parent promoted it to ERP ownership,
                        // while the already mapped children retained their
                        // historical Woo import provenance.
                        'source' => 'woocommerce_import',
                        'product_type' => 'variation',
                        'variant_attribute' => 'Rozmiar',
                        'parameters' => [[
                            'name' => 'Rozmiar',
                            'value' => $row['option'],
                            'variation' => true,
                        ]],
                    ]],
                ]);
                ProductRelation::query()->create([
                    'parent_product_id' => $parent->id,
                    'child_product_id' => $child->id,
                    'relation_type' => 'variant',
                    'sort_order' => $index * 10,
                    'metadata' => [
                        'variant_attribute' => 'Rozmiar',
                        'variant_option' => $row['option'],
                    ],
                ]);
                ProductChannelMapping::query()->create([
                    'product_id' => $child->id,
                    'sales_channel_id' => $channel->id,
                    'external_product_id' => '1506',
                    'external_variation_id' => $row['id'],
                    'external_sku' => $row['sku'],
                    'stock_sync_enabled' => true,
                ]);
                StockBalance::query()->create([
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $child->id,
                    'quantity_on_hand' => $row['stock'],
                    'quantity_reserved' => 0,
                    'quantity_available' => $row['stock'],
                ]);
            }

            $productRows = Product::query()->orderBy('id')->get()->map->getAttributes()->all();
            $stockRows = StockBalance::query()->orderBy('id')->get()->map->getAttributes()->all();
            $repair = app(WooOwnedVariantAxisRepairService::class);

            $this->assertFalse($repair->isSizeVariantRootCandidate($parent->fresh()));
            $this->assertTrue($repair->isChildSizeAssignmentAuditCandidate($parent->fresh()));

            $this->runMigration();

            $this->assertSame([
                'revision' => WooOwnedVariantAxisRepairService::REVISION,
                'status' => 'pending',
                'requested_at' => now()->toISOString(),
            ], data_get(
                $parentMapping->refresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH,
            ));
            $this->assertSame('preserve', data_get($parentMapping->metadata, 'operator_note'));
            $this->assertSame($productRows, Product::query()->orderBy('id')->get()->map->getAttributes()->all());
            $this->assertSame($stockRows, StockBalance::query()->orderBy('id')->get()->map->getAttributes()->all());
            Http::assertNothingSent();

            $requestedAt = data_get(
                $parentMapping->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.requested_at',
            );
            CarbonImmutable::setTestNow('2026-07-16 20:05:00');
            $this->runMigration();
            $this->assertSame($requestedAt, data_get(
                $parentMapping->refresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.requested_at',
            ));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function runMigration(): void
    {
        (require database_path(
            'migrations/2026_07_16_000031_requeue_all_size_families_for_child_assignment_audit.php',
        ))->up();
    }
}
