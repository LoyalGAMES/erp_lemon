<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WooAxisRepairBlockClearTest extends TestCase
{
    use RefreshDatabase;

    private function channel(): SalesChannel
    {
        return SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
    }

    private function product(string $sku, string $name): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
    }

    /** Parent mapping (no variation id) carrying a current-revision block. */
    private function blockedParentMapping(Product $parent, SalesChannel $channel, string $status = 'manual_review'): ProductChannelMapping
    {
        return ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '700137',
            'external_variation_id' => null,
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'maintenance' => [
                    'woo_owned_variant_axis_repair' => [
                        'revision' => WooOwnedVariantAxisRepairService::REVISION,
                        'status' => $status,
                    ],
                ],
            ],
        ]);
    }

    public function test_clearing_the_block_unblocks_full_export(): void
    {
        $channel = $this->channel();
        $parent = $this->product('BLS-HEROS', 'Klapki HEROS Beżowe');
        $this->blockedParentMapping($parent, $channel);

        $service = app(WooOwnedVariantAxisRepairService::class);
        $this->assertTrue($service->blocksFullExport($parent->fresh()));

        $result = $service->clearFamilyRepairBlock((int) $parent->id);

        $this->assertSame(1, $result['cleared']);
        $this->assertSame((int) $parent->id, $result['root_id']);
        $this->assertTrue($result['stamped_synchronized']);
        $this->assertSame('manual_review', $result['targets'][0]['status']);
        $this->assertSame('B2C', $result['targets'][0]['channel']);

        $this->assertFalse($service->blocksFullExport($parent->fresh()));
        $this->assertNull(data_get(
            ProductChannelMapping::query()->where('product_id', $parent->id)->first()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
        ));

        // The root's masterData now carries the synchronized repair marker, so
        // protectedMappedLegacyVariantAttribute() stops resurrecting a legacy
        // axis from the stale Woo snapshot on the next export.
        $stampedState = (array) data_get(
            $parent->fresh()->masterData(),
            WooOwnedVariantAxisRepairService::STATE_PATH,
            [],
        );
        $this->assertTrue(WooOwnedVariantAxisRepairService::isSynchronizedRevision(
            $stampedState['revision'] ?? null,
        ));
        $this->assertTrue((bool) ($stampedState['released_by_operator'] ?? false));
    }

    public function test_release_stamps_the_root_even_when_only_the_snapshot_still_carries_the_legacy_axis(): void
    {
        $channel = $this->channel();
        $parent = $this->product('BLS-HEROS', 'Klapki HEROS Beżowe');
        // The mapping block was already cleared earlier; only the stale Woo
        // snapshot (attribute `wariant`, variation=true) keeps the legacy-axis
        // protection alive and lets every export recreate `wariant` in Woo.
        $parent->forceFill([
            'attributes' => array_merge((array) $parent->attributes, [
                'master' => ['variant_attribute' => 'Rozmiar'],
                'woocommerce_attributes' => [
                    ['name' => 'Rozmiar', 'options' => ['36', '37'], 'variation' => false],
                    ['name' => 'wariant', 'options' => ['36', '37'], 'variation' => true],
                ],
            ]),
        ])->save();
        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '700137',
            'external_variation_id' => null,
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
            'metadata' => [],
        ]);

        $result = app(WooOwnedVariantAxisRepairService::class)
            ->clearFamilyRepairBlock((int) $parent->id);

        $this->assertSame(0, $result['cleared']);
        $this->assertTrue($result['stamped_synchronized']);

        $stampedState = (array) data_get(
            $parent->fresh()->masterData(),
            WooOwnedVariantAxisRepairService::STATE_PATH,
            [],
        );
        $this->assertTrue(WooOwnedVariantAxisRepairService::isSynchronizedRevision(
            $stampedState['revision'] ?? null,
        ));
        $this->assertSame('Rozmiar', $stampedState['variant_attribute'] ?? null);
    }

    public function test_dry_run_reports_without_clearing(): void
    {
        $channel = $this->channel();
        $parent = $this->product('BLS-HEROS', 'Klapki HEROS Beżowe');
        $this->blockedParentMapping($parent, $channel);

        $service = app(WooOwnedVariantAxisRepairService::class);
        $result = $service->clearFamilyRepairBlock((int) $parent->id, true);

        $this->assertSame(0, $result['cleared']);
        $this->assertCount(1, $result['targets']);
        $this->assertTrue($result['stamped_synchronized']);
        // Nothing was written: the family is still blocked and unstamped.
        $this->assertTrue($service->blocksFullExport($parent->fresh()));
        $this->assertSame([], (array) data_get(
            $parent->fresh()->masterData(),
            WooOwnedVariantAxisRepairService::STATE_PATH,
            [],
        ));
    }

    public function test_clearing_by_a_variant_sku_resolves_to_the_family_root(): void
    {
        $channel = $this->channel();
        $parent = $this->product('BLS-HEROS', 'Klapki HEROS Beżowe');
        $child = $this->product('BLS-HEROS-36', 'Klapki HEROS Beżowe - 36');
        ProductRelation::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $child->id,
            'relation_type' => 'variant',
            'sort_order' => 10,
        ]);
        $this->blockedParentMapping($parent, $channel);

        $this->artisan('erp:clear-woo-axis-repair-block', ['--sku' => 'BLS-HEROS-36'])
            ->assertExitCode(0);

        $this->assertFalse(
            app(WooOwnedVariantAxisRepairService::class)->blocksFullExport($parent->fresh()),
        );
    }

    public function test_command_dry_run_keeps_the_block(): void
    {
        $channel = $this->channel();
        $parent = $this->product('BLS-HEROS', 'Klapki HEROS Beżowe');
        $this->blockedParentMapping($parent, $channel);

        $this->artisan('erp:clear-woo-axis-repair-block', ['--sku' => 'BLS-HEROS', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertTrue(
            app(WooOwnedVariantAxisRepairService::class)->blocksFullExport($parent->fresh()),
        );
    }

    public function test_command_reports_unknown_sku(): void
    {
        $this->artisan('erp:clear-woo-axis-repair-block', ['--sku' => 'NOPE'])
            ->assertExitCode(1);
    }
}
