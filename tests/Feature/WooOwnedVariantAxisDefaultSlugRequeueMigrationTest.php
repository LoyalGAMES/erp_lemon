<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooOwnedVariantAxisDeploymentGate;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WooOwnedVariantAxisDefaultSlugRequeueMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Domyślny wariant nie mapuje się jednoznacznie na rozmiar.';

    public function test_migration_requeues_only_exact_default_slug_failures_and_keeps_every_other_gate_state_visible(): void
    {
        CarbonImmutable::setTestNow('2026-07-16 19:30:00');

        try {
            $woo = $this->channel('AXIS-SLUG-WOO', 'woocommerce', true);
            WordpressIntegration::query()->create([
                'sales_channel_id' => $woo->id,
                'name' => 'Axis slug Woo',
                'base_url' => 'https://axis-slug.test',
                'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
                'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            ]);
            $inactiveWoo = $this->channel('AXIS-SLUG-INACTIVE', 'woocommerce', false);
            $otherChannel = $this->channel('AXIS-SLUG-OTHER', 'api', true);
            $exactStates = [
                'bare' => $this->manualState(self::REASON),
                'pl' => $this->manualState('WooCommerce PL #500460: '.self::REASON),
                'en' => $this->manualState('WooCommerce EN #500460: '.self::REASON),
            ];
            $exact = collect($exactStates)->mapWithKeys(fn (array $state, string $suffix): array => [
                $suffix => $this->mapping($woo, strtoupper($suffix), $state),
            ]);
            $otherState = $this->manualState(
                'WooCommerce EN #500460: '.self::REASON.' dodatkowy tekst',
            );
            $other = $this->mapping($woo, 'OTHER', $otherState);
            $completedState = [
                'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_DEFAULT_TERM_SLUG_REVISION,
                'status' => 'completed',
                'completed_at' => '2026-07-16T17:10:00+00:00',
                'result' => ['status' => 'already_canonical', 'mutations' => 0],
            ];
            $completed = $this->mapping($woo, 'COMPLETED', $completedState);
            $current = $this->mapping($woo, 'CURRENT', [
                'revision' => WooOwnedVariantAxisRepairService::REVISION,
                'status' => 'manual_review',
                'result' => ['status' => 'manual_review', 'reason' => 'keep current'],
            ]);
            $child = $this->mapping(
                $woo,
                'CHILD',
                $this->manualState('WooCommerce EN #500460: '.self::REASON),
                '600001',
            );
            $inactive = $this->mapping(
                $inactiveWoo,
                'INACTIVE',
                $this->manualState('WooCommerce EN #500460: '.self::REASON),
            );
            $nonWoo = $this->mapping(
                $otherChannel,
                'NON-WOO',
                $this->manualState('WooCommerce EN #500460: '.self::REASON),
            );
            DB::table('product_channel_mappings')
                ->where('id', $exact['en']->id)
                ->update([
                    'external_product_id' => ' 500460 ',
                    'external_variation_id' => '0',
                    'external_identity_key' => 'legacy-parent-zero',
                ]);
            $externalIdentityBefore = (array) DB::table('product_channel_mappings')
                ->where('id', $exact['en']->id)
                ->first([
                    'external_product_id',
                    'external_variation_id',
                    'external_identity_key',
                ]);
            $currentBefore = $current->metadata;
            $childBefore = $child->metadata;
            $productAttributesBefore = Product::query()->orderBy('id')->pluck('attributes', 'id')->all();

            $this->runMigration();

            foreach ($exact as $suffix => $mapping) {
                $metadata = $mapping->refresh()->metadata;
                $state = (array) data_get(
                    $metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH,
                );

                $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, $state['revision']);
                $this->assertSame('pending', $state['status']);
                $this->assertSame(now()->toISOString(), $state['requested_at']);
                $this->assertSame($exactStates[$suffix], $state['requeued_from']);
                $this->assertArrayNotHasKey('pending_token', $state);
                $this->assertArrayNotHasKey('queued_at', $state);
                $this->assertArrayNotHasKey('next_attempt_at', $state);
                $this->assertArrayNotHasKey('error', $state);
                $this->assertSame('preserve-'.strtolower($suffix), data_get($metadata, 'operator_note'));
            }

            $expectedOther = $otherState;
            $expectedOther['revision'] = WooOwnedVariantAxisRepairService::REVISION;
            $this->assertSame($expectedOther, data_get(
                $other->refresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH,
            ));
            $expectedCompleted = $completedState;
            $expectedCompleted['revision'] = WooOwnedVariantAxisRepairService::REVISION;
            $this->assertSame($expectedCompleted, data_get(
                $completed->refresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH,
            ));
            $this->assertSame('manual_review', data_get(
                $inactive->refresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.status',
            ));
            $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, data_get(
                $inactive->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.revision',
            ));
            $this->assertSame('manual_review', data_get(
                $nonWoo->refresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.status',
            ));
            $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, data_get(
                $nonWoo->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.revision',
            ));
            $this->assertSame($currentBefore, $current->refresh()->metadata);
            $this->assertSame($childBefore, $child->refresh()->metadata);
            $this->assertSame(
                $externalIdentityBefore,
                (array) DB::table('product_channel_mappings')
                    ->where('id', $exact['en']->id)
                    ->first([
                        'external_product_id',
                        'external_variation_id',
                        'external_identity_key',
                    ]),
            );
            $this->assertSame(
                $productAttributesBefore,
                Product::query()->orderBy('id')->pluck('attributes', 'id')->all(),
            );

            $postcondition = app(WooOwnedVariantAxisDeploymentGate::class)->postcondition();
            $this->assertFalse($postcondition['passed']);
            $this->assertSame(3, $postcondition['statuses']['pending']);
            $this->assertSame(4, $postcondition['statuses']['manual_review']);
            $this->assertSame(1, $postcondition['statuses']['completed']);
            $this->assertTrue(app(WooOwnedVariantAxisRepairService::class)->blocksFullExport(
                $other->product,
            ));

            $afterFirstRun = ProductChannelMapping::query()
                ->orderBy('id')
                ->pluck('metadata', 'id')
                ->all();
            CarbonImmutable::setTestNow('2026-07-16 19:35:00');
            $this->runMigration();
            $this->assertSame(
                $afterFirstRun,
                ProductChannelMapping::query()->orderBy('id')->pluck('metadata', 'id')->all(),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function channel(string $code, string $type, bool $active): SalesChannel
    {
        return SalesChannel::query()->create([
            'code' => $code,
            'name' => $code,
            'type' => $type,
            'is_active' => $active,
        ]);
    }

    /** @return array<string,mixed> */
    private function manualState(string $reason): array
    {
        return [
            'revision' => WooOwnedVariantAxisRepairService::PREVIOUS_DEFAULT_TERM_SLUG_REVISION,
            'status' => 'manual_review',
            'requested_at' => '2026-07-16T17:00:00+00:00',
            'completed_at' => '2026-07-16T17:05:00+00:00',
            'pending_token' => 'obsolete-token',
            'queued_at' => '2026-07-16T17:01:00+00:00',
            'next_attempt_at' => '2026-07-16T17:15:00+00:00',
            'failed_at' => '2026-07-16T17:02:00+00:00',
            'error' => 'old diagnostic',
            'result' => [
                'status' => 'manual_review',
                'targets' => 2,
                'mutations' => 0,
                'reason' => $reason,
            ],
        ];
    }

    /** @param array<string,mixed> $state */
    private function mapping(
        SalesChannel $channel,
        string $suffix,
        array $state,
        ?string $variationId = null,
    ): ProductChannelMapping {
        $product = Product::query()->create([
            'sku' => 'AXIS-SLUG-'.$suffix,
            'name' => 'Axis slug '.$suffix,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'woocommerce_import',
                'product_type' => $variationId === null ? 'variable' : 'variation',
                'variant_attribute' => 'Rozmiar',
            ]],
        ]);

        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => (string) (500000 + $product->id),
            'external_variation_id' => $variationId,
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
            'migrations/2026_07_16_000027_requeue_default_size_term_slug_axis_repairs.php',
        ))->up();
    }
}
