<?php

use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REASON = 'Domyślny wariant nie mapuje się jednoznacznie na rozmiar.';

    public function up(): void
    {
        if (! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('sales_channels')
        ) {
            return;
        }

        ProductChannelMapping::query()
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->where(
                'metadata->maintenance->woo_owned_variant_axis_repair->revision',
                WooOwnedVariantAxisRepairService::PREVIOUS_DEFAULT_TERM_SLUG_REVISION,
            )
            ->orderBy('id')
            ->chunkById(100, function ($mappings): void {
                foreach ($mappings as $mapping) {
                    DB::transaction(function () use ($mapping): void {
                        $locked = ProductChannelMapping::query()
                            ->lockForUpdate()
                            ->find($mapping->id);

                        if (! $locked instanceof ProductChannelMapping) {
                            return;
                        }

                        $metadata = (array) $locked->metadata;
                        $state = (array) data_get(
                            $metadata,
                            WooOwnedVariantAxisRepairService::STATE_PATH,
                            [],
                        );

                        if (($state['revision'] ?? null)
                            !== WooOwnedVariantAxisRepairService::PREVIOUS_DEFAULT_TERM_SLUG_REVISION
                        ) {
                            return;
                        }

                        $channel = SalesChannel::query()->find($locked->sales_channel_id);

                        if ($this->shouldRequeue($state, $channel)) {
                            $state = [
                                'revision' => WooOwnedVariantAxisRepairService::REVISION,
                                'status' => 'pending',
                                'requested_at' => now()->toISOString(),
                                'requeued_from' => $state,
                            ];
                        } else {
                            // Preserve every other current-gate state exactly,
                            // changing only its revision. Otherwise a revision
                            // bump would make an unresolved family invisible to
                            // the deployment gate and full-export blocker.
                            $state['revision'] = WooOwnedVariantAxisRepairService::REVISION;
                        }

                        data_set(
                            $metadata,
                            WooOwnedVariantAxisRepairService::STATE_PATH,
                            $state,
                        );
                        DB::table('product_channel_mappings')
                            ->where('id', $locked->id)
                            ->update(['metadata' => json_encode(
                                $metadata,
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                            )]);
                    });
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. A remote repair may already have completed and
        // must never be rolled back to a damaged multilingual variation axis.
    }

    /** @param array<string,mixed> $state */
    private function shouldRequeue(array $state, ?SalesChannel $channel): bool
    {
        if (! $channel instanceof SalesChannel
            || mb_strtolower(trim((string) $channel->type)) !== 'woocommerce'
            || ! (bool) $channel->is_active
            || ($state['status'] ?? null) !== 'manual_review'
            || data_get($state, 'result.status') !== 'manual_review'
        ) {
            return false;
        }

        $reason = trim((string) data_get($state, 'result.reason', ''));

        return $reason === self::REASON
            || preg_match(
                '/^WooCommerce (?:PL|EN) #[0-9]+: '.preg_quote(self::REASON, '/').'$/u',
                $reason,
            ) === 1;
    }
};
