<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * The "Wymuś ponowne połączenie" button must clear a stuck axis-repair block
 * even while the family's bouncing export job holds the mutation locks —
 * otherwise the loop the button exists to end starves the button forever.
 */
class WooRelinkUnblockButtonTest extends TestCase
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

    private function integration(SalesChannel $channel): WordpressIntegration
    {
        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'stock_export_enabled' => true,
        ]);
    }

    private function parent(array $axisState = []): Product
    {
        $parent = Product::query()->create([
            'sku' => 'BLS-HEROS',
            'name' => 'Klapki HEROS Beżowe',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        ProductChannelMapping::query()->create([
            'product_id' => $parent->id,
            'sales_channel_id' => $this->channel()->id,
            'external_product_id' => '700137',
            'external_variation_id' => null,
            'external_sku' => $parent->sku,
            'stock_sync_enabled' => true,
            'metadata' => $axisState === [] ? [] : [
                'maintenance' => ['woo_owned_variant_axis_repair' => $axisState],
            ],
        ]);

        return $parent;
    }

    public function test_relink_clears_the_block_and_queues_export_even_when_the_family_lock_is_held(): void
    {
        Bus::fake();
        $parent = $this->parent([
            'revision' => WooOwnedVariantAxisRepairService::REVISION,
            'status' => 'manual_review',
        ]);
        $this->integration(SalesChannel::query()->firstOrFail());

        // Simulate the bouncing export job owning the family mutation lock.
        $held = Cache::lock(
            ExportWooCommerceProductDataJob::lockKey((int) $parent->id),
            ExportWooCommerceProductDataJob::LOCK_SECONDS,
        );
        $this->assertTrue($held->get());

        try {
            $response = $this->post(route('products.woocommerce.relink', $parent));
        } finally {
            $held->release();
        }

        $response->assertRedirect();
        $this->assertStringContainsString(
            'Zdjęto blokadę naprawy osi Rozmiar',
            (string) session('status'),
        );

        // The block is gone despite the busy lock, and a superseding export is queued.
        $this->assertFalse(
            app(WooOwnedVariantAxisRepairService::class)->blocksFullExport($parent->fresh()),
        );
        Bus::assertDispatched(ExportWooCommerceProductDataJob::class);
    }

    public function test_relink_without_a_block_still_reports_sync_in_progress_when_locked(): void
    {
        Bus::fake();
        $parent = $this->parent();
        $this->integration(SalesChannel::query()->firstOrFail());

        $held = Cache::lock(
            ExportWooCommerceProductDataJob::lockKey((int) $parent->id),
            ExportWooCommerceProductDataJob::LOCK_SECONDS,
        );
        $this->assertTrue($held->get());

        try {
            $response = $this->post(route('products.woocommerce.relink', $parent));
        } finally {
            $held->release();
        }

        $response->assertRedirect();
        $this->assertStringContainsString(
            'już trwa',
            (string) session('status'),
        );
        Bus::assertNothingDispatched();
    }
}
