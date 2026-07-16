<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

final class WooOwnedVariantAxisVerificationRequeueMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_requeues_every_unresolved_000025_state_and_leaves_terminal_or_newer_state_untouched(): void
    {
        CarbonImmutable::setTestNow('2026-07-16 17:00:00');

        try {
            $channel = $this->wooChannel();
            $requeued = collect(['pending', 'queued', 'failed', 'manual_review'])
                ->mapWithKeys(fn (string $status): array => [
                    $status => $this->mapping($channel, strtoupper($status), [
                        'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_COMPLEMENTARY_LANGUAGE_REVISION,
                        'status' => $status,
                        'requested_at' => '2026-07-16T14:00:00+00:00',
                        'queued_at' => '2026-07-16T14:01:00+00:00',
                        'next_attempt_at' => '2026-07-16T14:15:00+00:00',
                        'failed_at' => '2026-07-16T14:02:00+00:00',
                        'pending_token' => 'obsolete-'.$status,
                        'error' => 'stale '.$status.' diagnostic',
                        'result' => ['status' => 'manual_review'],
                    ]),
                ]);
            $completed = $this->mapping($channel, 'COMPLETED', [
                'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_COMPLEMENTARY_LANGUAGE_REVISION,
                'status' => 'completed',
                'completed_at' => '2026-07-16T14:05:00+00:00',
                'result' => ['status' => 'already_canonical'],
            ]);
            $current = $this->mapping($channel, 'CURRENT', [
                'revision' => WooOwnedVariantAxisRepairService::REVISION,
                'status' => 'manual_review',
                'error' => 'keep current-revision review',
            ]);
            $completedBefore = $completed->metadata;
            $currentBefore = $current->metadata;

            $this->runMigration();

            foreach ($requeued as $status => $mapping) {
                $metadata = $mapping->refresh()->metadata;

                $this->assertSame([
                    'revision' => WooOwnedVariantAxisRepairService::REVISION,
                    'status' => 'pending',
                    'requested_at' => now()->toISOString(),
                ], data_get($metadata, WooOwnedVariantAxisRepairService::STATE_PATH));
                $this->assertSame('preserve-'.$status, data_get($metadata, 'operator_note'));
            }

            $this->assertSame($completedBefore, $completed->refresh()->metadata);
            $this->assertSame($currentBefore, $current->refresh()->metadata);

            $requestedAt = $requeued['pending']->refresh()->metadata;
            CarbonImmutable::setTestNow('2026-07-16 17:05:00');
            $this->runMigration();
            $this->assertSame($requestedAt, $requeued['pending']->refresh()->metadata);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function wooChannel(): SalesChannel
    {
        $channel = SalesChannel::query()->create([
            'code' => 'AXIS-VERIFY-REQUEUE',
            'name' => 'Axis verification requeue',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Axis verification Woo',
            'base_url' => 'https://axis-verification.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
        ]);

        return $channel;
    }

    /** @param array<string, mixed> $state */
    private function mapping(SalesChannel $channel, string $suffix, array $state): ProductChannelMapping
    {
        $product = Product::query()->create([
            'sku' => 'AXIS-VERIFY-'.$suffix,
            'name' => 'Axis verification '.$suffix,
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
            'external_product_id' => (string) (980000 + $product->id),
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => [
                'operator_note' => 'preserve-'.strtolower($suffix),
                'maintenance' => ['woo_owned_variant_axis_repair' => $state],
            ],
        ]);
    }

    private function runMigration(): void
    {
        (require database_path(
            'migrations/2026_07_16_000026_requeue_verified_size_axis_repairs.php',
        ))->up();
    }
}
