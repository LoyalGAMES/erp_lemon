<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WooCommerceProductExportFailureInspectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reports_only_root_export_failures_and_does_not_change_state(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-INSPECT',
            'name' => 'Woo inspect',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $failed = $this->createProduct('EXPORT-FAILURE-ROOT');
        $mapping = ProductChannelMapping::query()->create([
            'product_id' => $failed->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '88001',
            'external_variation_id' => null,
            'external_sku' => $failed->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'mapping_role' => 'primary',
                'language' => 'pl',
                'product_data_export' => [
                    'error' => 'Remote failure consumer_secret=must-not-leak',
                    'legacy_variant_backfill' => [
                        'status' => 'pending',
                        'revision' => 'repair-2026-07-15',
                        'next_attempt_at' => '2026-07-15T14:30:00+02:00',
                    ],
                ],
            ],
        ]);
        $variation = $this->createProduct('EXPORT-FAILURE-VARIATION', 'variation');
        ProductChannelMapping::query()->create([
            'product_id' => $variation->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '88001',
            'external_variation_id' => '88002',
            'external_sku' => $variation->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['product_data_export' => ['error' => 'variation failure']],
        ]);
        $successful = $this->createProduct('EXPORT-SUCCESS-ROOT');
        ProductChannelMapping::query()->create([
            'product_id' => $successful->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '88003',
            'external_variation_id' => null,
            'external_sku' => $successful->sku,
            'stock_sync_enabled' => true,
            'metadata' => ['mapping_role' => 'primary', 'language' => 'pl'],
        ]);
        DB::table('jobs')->insert([
            'queue' => 'database',
            'payload' => '{"displayName":"ExportWooCommerceProductDataJob"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'export-failure-inspection',
            'connection' => 'database',
            'queue' => 'database',
            'payload' => '{"displayName":"ExportWooCommerceProductDataJob"}',
            'exception' => 'fixture',
            'failed_at' => now(),
        ]);
        $before = $mapping->fresh()->getAttributes();

        $exitCode = Artisan::call('erp:inspect-woocommerce-product-export-failures', [
            '--limit' => 5,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('EXPORT-FAILURE-ROOT', $output);
        $this->assertStringContainsString('88001', $output);
        $this->assertStringContainsString('pending', $output);
        $this->assertStringContainsString('repair-2026-07-15', $output);
        $this->assertStringContainsString('2026-07-15T14:30:00+02:00', $output);
        $this->assertStringContainsString('consumer_secret=[redacted]', $output);
        $this->assertStringNotContainsString('must-not-leak', $output);
        $this->assertStringNotContainsString('EXPORT-FAILURE-VARIATION', $output);
        $this->assertStringNotContainsString('EXPORT-SUCCESS-ROOT', $output);
        $this->assertStringContainsString('matching=1, queued_jobs=1, failed_jobs=1', $output);
        $this->assertSame($before, $mapping->fresh()->getAttributes());
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    private function createProduct(string $sku, string $productType = 'simple'): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'is_translation' => false,
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'product_type' => $productType,
                    'content' => ['pl' => ['name' => $sku]],
                ],
            ],
        ]);
    }
}
