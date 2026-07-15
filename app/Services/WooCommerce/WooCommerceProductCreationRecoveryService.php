<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Jobs\RetryWooCommerceProductCreationJob;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class WooCommerceProductCreationRecoveryService
{
    public const REVISION = 'duplicate_global_attribute_term_2026_07_15_000009';

    private const ROOT_PATH = 'woocommerce_creation_recovery.'.self::REVISION;

    public function markPending(
        Product $product,
        WordpressIntegration $integration,
        ?int $sourceAuditLogId = null,
    ): void {
        DB::transaction(function () use ($product, $integration, $sourceAuditLogId): void {
            $lockedProduct = Product::query()->lockForUpdate()->find($product->id);

            if (! $lockedProduct instanceof Product) {
                return;
            }

            $attributes = (array) $lockedProduct->attributes;
            $path = $this->metadataPath($integration->id);
            $state = (array) data_get($attributes, $path, []);

            if (in_array(($state['status'] ?? null), ['pending', 'queued', 'completed'], true)) {
                return;
            }

            data_set($attributes, $path, array_filter([
                'status' => 'pending',
                'requested_at' => now()->toISOString(),
                'source_audit_log_id' => $sourceAuditLogId,
                'sales_channel_id' => (int) $integration->sales_channel_id,
            ], fn (mixed $value): bool => $value !== null));
            $lockedProduct->forceFill(['attributes' => $attributes])->save();
        });
    }

    /**
     * @return array{scanned:int,dispatched:int,active:int,backoff:int,skipped:int,failed:int}
     */
    public function dispatchPending(int $limit = 10, int $staleMinutes = 120): array
    {
        $result = [
            'scanned' => 0,
            'dispatched' => 0,
            'active' => 0,
            'backoff' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach (Product::query()->orderBy('id')->lazyById(100) as $product) {
            $states = (array) data_get($product->attributes, self::ROOT_PATH, []);

            foreach (array_keys($states) as $integrationId) {
                if ($result['dispatched'] >= max(1, $limit)) {
                    return $result;
                }

                if (! ctype_digit((string) $integrationId) || (int) $integrationId <= 0) {
                    continue;
                }

                $state = (array) ($states[$integrationId] ?? []);

                if (! in_array(($state['status'] ?? null), ['pending', 'queued'], true)) {
                    continue;
                }

                $result['scanned']++;
                $reservation = $this->reserve(
                    (int) $product->id,
                    (int) $integrationId,
                    max(1, $staleMinutes),
                );

                if ($reservation['status'] === 'active') {
                    $result['active']++;

                    continue;
                }

                if ($reservation['status'] === 'backoff') {
                    $result['backoff']++;

                    continue;
                }

                if ($reservation['status'] === 'skipped') {
                    $result['skipped']++;

                    continue;
                }

                if ($reservation['status'] !== 'reserved') {
                    continue;
                }

                try {
                    RetryWooCommerceProductCreationJob::dispatch(
                        (int) $product->id,
                        (int) $integrationId,
                        $reservation['token'],
                    )->onConnection('database');
                    $result['dispatched']++;
                } catch (Throwable $exception) {
                    report($exception);
                    $this->releaseReservation(
                        (int) $product->id,
                        (int) $integrationId,
                        $reservation['token'],
                        $exception,
                    );
                    $result['failed']++;
                }
            }
        }

        return $result;
    }

    public function metadataPath(int $integrationId): string
    {
        return self::ROOT_PATH.'.'.$integrationId;
    }

    /**
     * @return array{status:'active'|'backoff'|'missing'|'skipped'|'reserved',token?:string}
     */
    private function reserve(int $productId, int $integrationId, int $staleMinutes): array
    {
        return DB::transaction(function () use ($productId, $integrationId, $staleMinutes): array {
            $product = Product::query()
                ->with(['parentRelations', 'channelMappings'])
                ->lockForUpdate()
                ->find($productId);
            $integration = WordpressIntegration::query()
                ->with('salesChannel')
                ->find($integrationId);

            if (! $product instanceof Product || ! $integration instanceof WordpressIntegration) {
                return ['status' => 'missing'];
            }

            $attributes = (array) $product->attributes;
            $path = $this->metadataPath($integrationId);
            $state = (array) data_get($attributes, $path, []);
            $status = $state['status'] ?? null;

            if (! in_array($status, ['pending', 'queued'], true)) {
                return ['status' => 'missing'];
            }

            $nextAttemptAt = $this->date($state['next_attempt_at'] ?? null);

            if ($nextAttemptAt instanceof CarbonImmutable && $nextAttemptAt->isFuture()) {
                return ['status' => 'backoff'];
            }

            $queuedAt = $this->date($state['queued_at'] ?? null);

            if ($status === 'queued'
                && $queuedAt instanceof CarbonImmutable
                && $queuedAt->gt(now()->subMinutes($staleMinutes))
            ) {
                return ['status' => 'active'];
            }

            if (! $this->isCanonicalErpRoot($product)
                || ! $integration->salesChannel?->is_active
                || $integration->salesChannel?->type !== 'woocommerce'
                || (int) ($state['sales_channel_id'] ?? 0) !== (int) $integration->sales_channel_id
            ) {
                data_set($attributes, $path.'.status', 'skipped');
                data_set($attributes, $path.'.updated_at', now()->toISOString());
                data_forget($attributes, $path.'.token');
                $product->forceFill(['attributes' => $attributes])->save();

                return ['status' => 'skipped'];
            }

            $mapping = $product->channelMappings->first(
                fn (ProductChannelMapping $candidate): bool => (int) $candidate->sales_channel_id
                    === (int) $integration->sales_channel_id,
            );

            if ($mapping instanceof ProductChannelMapping
                && data_get($mapping->metadata, 'creation_state') === 'completed'
            ) {
                data_set($attributes, $path.'.status', 'completed');
                data_set($attributes, $path.'.completed_at', now()->toISOString());
                data_set($attributes, $path.'.external_product_id', (string) $mapping->external_product_id);
                data_forget($attributes, $path.'.token');
                $product->forceFill(['attributes' => $attributes])->save();

                return ['status' => 'skipped'];
            }

            $token = (string) Str::uuid();
            data_set($attributes, $path.'.status', 'queued');
            data_set($attributes, $path.'.token', $token);
            data_set($attributes, $path.'.queued_at', now()->toISOString());
            data_forget($attributes, $path.'.next_attempt_at');
            data_forget($attributes, $path.'.last_error');
            $product->forceFill(['attributes' => $attributes])->save();

            return ['status' => 'reserved', 'token' => $token];
        });
    }

    private function releaseReservation(
        int $productId,
        int $integrationId,
        string $token,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($productId, $integrationId, $token, $exception): void {
            $product = Product::query()->lockForUpdate()->find($productId);

            if (! $product instanceof Product) {
                return;
            }

            $attributes = (array) $product->attributes;
            $path = $this->metadataPath($integrationId);

            if (data_get($attributes, $path.'.token') !== $token) {
                return;
            }

            data_set($attributes, $path.'.status', 'pending');
            data_set($attributes, $path.'.last_error', $exception->getMessage());
            data_set($attributes, $path.'.next_attempt_at', now()->addMinutes(5)->toISOString());
            data_forget($attributes, $path.'.token');
            data_forget($attributes, $path.'.queued_at');
            $product->forceFill(['attributes' => $attributes])->save();
        });
    }

    private function isCanonicalErpRoot(Product $product): bool
    {
        return ! $product->is_translation
            && trim((string) $product->sku) !== ''
            && $product->masterSource() === 'erp'
            && data_get($product->masterData(), 'product_type') !== 'variation'
            && ! $product->parentRelations->contains(
                fn ($relation): bool => $relation->relation_type === 'variant',
            );
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
