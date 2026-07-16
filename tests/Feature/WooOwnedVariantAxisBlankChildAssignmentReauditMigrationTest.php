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
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WooOwnedVariantAxisBlankChildAssignmentReauditMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requeues_mixed_ownership_family_with_exact_parent_duplicate_and_only_blank_children(): void
    {
        Http::fake();
        CarbonImmutable::setTestNow('2026-07-16 22:00:00');

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
                'code' => 'BLANK-CHILD-READUIT',
                'name' => 'Blank child reaudit',
                'type' => 'woocommerce',
                'is_active' => true,
            ]);
            $warehouse = Warehouse::query()->create([
                'code' => 'BLANK-CHILD-READUIT',
                'name' => 'Blank child reaudit',
                'type' => 'own',
                'is_active' => true,
            ]);
            $parent = Product::query()->create([
                'sku' => 'HARMONY-PINK',
                'name' => 'Garnitur HARMONY Różowy',
                'unit' => 'szt',
                'vat_rate' => 23,
                'quantity_precision' => 0,
                'is_active' => true,
                'attributes' => [
                    'master' => [
                        'source' => 'erp',
                        'product_type' => 'variable',
                        'variant_attribute' => 'wariant',
                        'parameters' => [
                            [
                                'name' => 'wariant',
                                'value' => 'm-l | s-m',
                                'variation' => true,
                            ],
                            [
                                'name' => 'Rozmiar',
                                'value' => 'M/L | S/M',
                                'variation' => false,
                            ],
                        ],
                    ],
                    'woocommerce_attributes' => [
                        [
                            'id' => 6,
                            'name' => 'wariant',
                            'slug' => 'pa_wariant',
                            'variation' => true,
                            'options' => ['m-l', 's-m'],
                        ],
                        [
                            'id' => 1,
                            'name' => 'Rozmiar',
                            'slug' => 'pa_rozmiar',
                            'variation' => false,
                            'options' => ['M/L', 'S/M'],
                        ],
                    ],
                ],
            ]);
            $parentMapping = ProductChannelMapping::query()->create([
                'product_id' => $parent->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => '3890',
                'external_sku' => $parent->sku,
                'stock_sync_enabled' => true,
                'metadata' => [
                    'operator_note' => 'preserve',
                    'maintenance' => ['woo_owned_variant_axis_repair' => [
                        'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_CHILD_ASSIGNMENT_AUDIT_REVISION,
                        'status' => 'manual_review',
                        'reason' => 'Previous audit missed the blank child shape.',
                    ]],
                ],
            ]);

            foreach ([
                ['id' => '3891', 'sku' => 'HARMONY-PINK-SM', 'stock' => 0],
                ['id' => '3892', 'sku' => 'HARMONY-PINK-ML', 'stock' => 1],
            ] as $index => $row) {
                $child = Product::query()->create([
                    'sku' => $row['sku'],
                    'name' => "Garnitur HARMONY Różowy - wariant {$index}",
                    'unit' => 'szt',
                    'vat_rate' => 23,
                    'quantity_precision' => 0,
                    'is_active' => true,
                    'attributes' => [
                        'master' => [
                            'source' => 'woocommerce_import',
                            'product_type' => 'variation',
                            'variant_attribute' => 'wariant',
                            'parameters' => [[
                                'name' => 'wariant',
                                'value' => '',
                                'variation' => true,
                            ]],
                        ],
                        'woocommerce_variation_attributes' => [[
                            'id' => 6,
                            'name' => 'wariant',
                            'slug' => 'pa_wariant',
                            'option' => null,
                        ]],
                    ],
                ]);
                ProductRelation::query()->create([
                    'parent_product_id' => $parent->id,
                    'child_product_id' => $child->id,
                    'relation_type' => 'variant',
                    'sort_order' => $index * 10,
                    'metadata' => [
                        'variant_attribute' => 'wariant',
                        'variant_option' => '',
                    ],
                ]);
                ProductChannelMapping::query()->create([
                    'product_id' => $child->id,
                    'sales_channel_id' => $channel->id,
                    'external_product_id' => '3890',
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
            $this->assertFalse($repair->isComplementaryLanguageSizeRootCandidate($parent->fresh()));
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
            CarbonImmutable::setTestNow('2026-07-16 22:05:00');
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
            'migrations/2026_07_16_000032_requeue_blank_child_size_assignment_audit.php',
        ))->up();
    }
}
