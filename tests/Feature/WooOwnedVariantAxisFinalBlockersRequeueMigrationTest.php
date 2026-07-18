<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Services\WooCommerce\WooOwnedVariantAxisDeploymentGate;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WooOwnedVariantAxisFinalBlockersRequeueMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_requeues_only_the_two_recoverable_final_diagnostics(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 15:10:00');

        try {
            $channel = SalesChannel::query()->create([
                'code' => 'FINAL-AXIS',
                'name' => 'Final axis',
                'type' => 'woocommerce',
                'is_active' => true,
            ]);
            $duplicate = $this->mapping(
                $channel,
                'DUPLICATE',
                'Polskie warianty nie odpowiadają dokładnie wariantom rodziny ERP.',
            );
            $sourceTerm = $this->mapping(
                $channel,
                'SOURCE-TERM',
                'WooCommerce EN #500316: WooCommerce nie zawiera źródłowej polskiej wartości XS globalnego atrybutu #1.',
            );
            $unrelated = $this->mapping(
                $channel,
                'UNRELATED',
                'Polska i angielska rodzina WooCommerce mają różne zbiory rozmiarów.',
            );
            $unrelatedBefore = $unrelated->metadata;

            $this->runMigration();

            foreach ([$duplicate, $sourceTerm] as $mapping) {
                $state = (array) data_get(
                    $mapping->refresh()->metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH,
                );

                $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, $state['revision']);
                $this->assertSame('pending', $state['status']);
                $this->assertSame(now()->toISOString(), $state['requested_at']);
                $this->assertSame(
                    WooOwnedVariantAxisRepairService::PREVIOUS_FINAL_VARIANT_REPAIR_BLOCKERS_REVISION,
                    data_get($state, 'previous.revision'),
                );
                $this->assertSame('manual_review', data_get($state, 'previous.status'));
                $this->assertArrayNotHasKey('pending_token', $state);
            }

            $this->assertSame($unrelatedBefore, $unrelated->refresh()->metadata);
            $postcondition = app(WooOwnedVariantAxisDeploymentGate::class)->postcondition();
            $this->assertFalse($postcondition['passed']);
            $this->assertSame(['pending' => 2], $postcondition['statuses']);

            $afterFirstRun = ProductChannelMapping::query()
                ->orderBy('id')
                ->pluck('metadata', 'id')
                ->all();
            CarbonImmutable::setTestNow('2026-07-18 15:15:00');
            $this->runMigration();
            $this->assertSame(
                $afterFirstRun,
                ProductChannelMapping::query()->orderBy('id')->pluck('metadata', 'id')->all(),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function mapping(
        SalesChannel $channel,
        string $suffix,
        string $reason,
    ): ProductChannelMapping {
        $product = Product::query()->create([
            'sku' => 'FINAL-AXIS-'.$suffix,
            'name' => 'Final axis '.$suffix,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'woocommerce_import',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
            ]],
        ]);

        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => (string) (900000 + $product->id),
            'stock_sync_enabled' => true,
            'metadata' => [
                'operator_note' => 'preserve',
                'maintenance' => ['woo_owned_variant_axis_repair' => [
                    'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_FINAL_VARIANT_REPAIR_BLOCKERS_REVISION,
                    'status' => 'manual_review',
                    'requested_at' => '2026-07-18T12:00:00+00:00',
                    'completed_at' => '2026-07-18T12:05:00+00:00',
                    'result' => [
                        'status' => 'manual_review',
                        'reason' => $reason,
                    ],
                ]],
            ],
        ]);
    }

    private function runMigration(): void
    {
        (require database_path(
            'migrations/2026_07_18_000056_requeue_final_woo_variant_axis_blockers.php',
        ))->up();
    }
}
