<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Jobs\RetryWooCommerceProductCreationJob;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class WooCommerceProductCreationRecoveryService
{
    public const REVISION = 'translated_global_attribute_taxonomy_2026_07_15_000010';

    private const ROOT_PATH = 'woocommerce_creation_recovery.'.self::REVISION;

    public function __construct(private readonly WooCommerceClient $client) {}

    public function isRetryableFailure(string|Throwable $failure): bool
    {
        if ($failure instanceof WooCommerceProductTranslationNotReadyException) {
            return true;
        }

        $message = mb_strtolower(trim(
            $failure instanceof Throwable ? $failure->getMessage() : $failure,
        ));

        if (str_contains($message, 'nie jest gotowy do bezpiecznego utworzenia wersji językowych produktu')
            && str_contains($message, 'bootstrap tłumaczeń globalnych atrybutów')
        ) {
            return true;
        }

        if (str_contains($message, 'globalnych atrybutów')) {
            return str_contains($message, 'powiązanie tłumaczeń wartości')
                && (str_contains($message, 'wymaga wtyczki')
                    || str_contains($message, 'wymagana jest wtyczka'));
        }

        if (! str_contains($message, 'globalnego atrybutu')) {
            return false;
        }

        return str_contains($message, 'kilka wartości')
            || str_contains($message, 'id nie wskazuje tłumaczonego globalnego atrybutu')
            || str_contains($message, 'nie powiązał tłumaczeń wartości globalnego atrybutu');
    }

    public function markPendingForFailure(
        Product $product,
        WordpressIntegration $integration,
        AuditLog $audit,
        string|Throwable $failure,
    ): bool {
        if (! $this->isRetryableFailure($failure)) {
            return false;
        }

        $this->markPending($product, $integration, (int) $audit->id);

        return true;
    }

    public function productTranslationCreationReady(WordpressIntegration $integration): bool
    {
        $languages = $integration->productExportLanguages();
        $needsTranslations = collect($languages)->contains(
            fn (mixed $language): bool => mb_strtolower(trim((string) $language)) !== 'pl',
        );

        return ! $needsTranslations
            || $this->client->productTranslationLinkingAvailable($integration, $languages);
    }

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
     * @return array{scanned:int,dispatched:int,active:int,backoff:int,unready:int,skipped:int,failed:int}
     */
    public function dispatchPending(int $limit = 10, int $staleMinutes = 120): array
    {
        $result = [
            'scanned' => 0,
            'dispatched' => 0,
            'active' => 0,
            'backoff' => 0,
            'unready' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $integrationReadiness = [];

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
                if (! array_key_exists((int) $integrationId, $integrationReadiness)) {
                    $integration = WordpressIntegration::query()
                        ->with('salesChannel')
                        ->find((int) $integrationId);
                    $integrationReadiness[(int) $integrationId] = $integration instanceof WordpressIntegration
                        && $this->productTranslationCreationReady($integration);
                }

                $reservation = $this->reserve(
                    (int) $product->id,
                    (int) $integrationId,
                    max(1, $staleMinutes),
                    $integrationReadiness[(int) $integrationId],
                );

                if ($reservation['status'] === 'active') {
                    $result['active']++;

                    continue;
                }

                if ($reservation['status'] === 'backoff') {
                    $result['backoff']++;

                    continue;
                }

                if ($reservation['status'] === 'unready') {
                    $result['unready']++;

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
     * @return array{status:'active'|'backoff'|'missing'|'skipped'|'unready'|'reserved',token?:string}
     */
    private function reserve(
        int $productId,
        int $integrationId,
        int $staleMinutes,
        bool $integrationReady,
    ): array {
        return DB::transaction(function () use (
            $productId,
            $integrationId,
            $staleMinutes,
            $integrationReady,
        ): array {
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

            if (! $integrationReady) {
                data_set($attributes, $path.'.status', 'pending');
                data_set($attributes, $path.'.waiting_for_plugin_at', now()->toISOString());
                data_forget($attributes, $path.'.token');
                data_forget($attributes, $path.'.queued_at');
                data_forget($attributes, $path.'.next_attempt_at');
                $product->forceFill(['attributes' => $attributes])->save();

                return ['status' => 'unready'];
            }

            $token = (string) Str::uuid();
            data_set($attributes, $path.'.status', 'queued');
            data_set($attributes, $path.'.token', $token);
            data_set($attributes, $path.'.queued_at', now()->toISOString());
            data_forget($attributes, $path.'.next_attempt_at');
            data_forget($attributes, $path.'.last_error');
            data_forget($attributes, $path.'.waiting_for_plugin_at');
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
