<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\ProductDataExportService;
use App\Services\WooCommerce\WooCommerceProductCreationRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RetryWooCommerceProductCreationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 70;

    public int $timeout = 840;

    public function __construct(
        public readonly int $productId,
        public readonly int $integrationId,
        public readonly string $recoveryToken,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(ExportWooCommerceProductDataJob::lockKey($this->productId)))
                ->releaseAfter(60)
                ->expireAfter(ExportWooCommerceProductDataJob::LOCK_SECONDS)
                ->withPrefix('')
                ->shared(),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(ProductDataExportService $exporter): void
    {
        $product = Product::query()->find($this->productId);
        $integration = WordpressIntegration::query()
            ->with('salesChannel')
            ->find($this->integrationId);

        if (! $product instanceof Product
            || $this->recoveryToken === ''
            || ! $this->hasCurrentToken($product)
        ) {
            return;
        }

        $product->load(['parentRelations', 'channelMappings']);

        if (! $integration instanceof WordpressIntegration
            || ! $integration->salesChannel?->is_active
            || $integration->salesChannel?->type !== 'woocommerce'
            || (int) data_get($product->attributes, $this->metadataPath().'.sales_channel_id', 0)
                !== (int) $integration->sales_channel_id
            || $product->is_translation
            || trim((string) $product->sku) === ''
            || $product->masterSource() !== 'erp'
            || data_get($product->masterData(), 'product_type') === 'variation'
            || $product->parentRelations->contains(
                fn ($relation): bool => $relation->relation_type === 'variant',
            )
        ) {
            $this->markState('skipped');

            return;
        }

        $completedMapping = $product->channelMappings->first(
            fn (ProductChannelMapping $mapping): bool => (int) $mapping->sales_channel_id
                === (int) $integration->sales_channel_id
                && data_get($mapping->metadata, 'creation_state') === 'completed',
        );

        if ($completedMapping instanceof ProductChannelMapping) {
            $this->markState(
                'completed',
                null,
                (string) $completedMapping->external_product_id,
            );

            return;
        }

        $this->markAttempt();

        try {
            $result = $exporter->create($product, $integration);
        } catch (Throwable $exception) {
            $this->markState('queued', $exception);

            throw $exception;
        }

        $externalId = trim((string) data_get($result, 'mapping.external_product_id', ''));
        $this->markState('completed', null, $externalId !== '' ? $externalId : null);
    }

    public function failed(Throwable $exception): void
    {
        $this->markState('failed', $exception);
    }

    public function metadataPath(): string
    {
        return 'woocommerce_creation_recovery.'
            .WooCommerceProductCreationRecoveryService::REVISION
            .'.'.$this->integrationId;
    }

    private function hasCurrentToken(Product $product): bool
    {
        return data_get($product->attributes, $this->metadataPath().'.status') === 'queued'
            && hash_equals(
                $this->recoveryToken,
                (string) data_get($product->attributes, $this->metadataPath().'.token', ''),
            );
    }

    private function markAttempt(): void
    {
        DB::transaction(function (): void {
            $product = Product::query()->lockForUpdate()->find($this->productId);

            if (! $product instanceof Product) {
                return;
            }

            $attributes = (array) $product->attributes;
            $path = $this->metadataPath();

            if (data_get($attributes, $path.'.token') !== $this->recoveryToken) {
                return;
            }

            data_set($attributes, $path.'.status', 'queued');
            data_set($attributes, $path.'.last_attempt_at', now()->toISOString());
            data_set(
                $attributes,
                $path.'.attempts',
                max(0, (int) data_get($attributes, $path.'.attempts', 0)) + 1,
            );
            data_forget($attributes, $path.'.last_error');
            $product->forceFill(['attributes' => $attributes])->save();
        });
    }

    private function markState(
        string $status,
        ?Throwable $exception = null,
        ?string $externalProductId = null,
    ): void {
        DB::transaction(function () use ($status, $exception, $externalProductId): void {
            $product = Product::query()->lockForUpdate()->find($this->productId);

            if (! $product instanceof Product) {
                return;
            }

            $attributes = (array) $product->attributes;
            $path = $this->metadataPath();

            if (data_get($attributes, $path.'.token') !== $this->recoveryToken) {
                return;
            }

            data_set($attributes, $path.'.status', $status);
            data_set($attributes, $path.'.updated_at', now()->toISOString());

            if ($exception instanceof Throwable) {
                data_set($attributes, $path.'.last_error', $exception->getMessage());
            } else {
                data_forget($attributes, $path.'.last_error');
            }

            if ($externalProductId !== null) {
                data_set($attributes, $path.'.external_product_id', $externalProductId);
            }

            if (in_array($status, ['completed', 'failed', 'skipped'], true)) {
                data_forget($attributes, $path.'.token');
                data_forget($attributes, $path.'.queued_at');
            }

            $product->forceFill(['attributes' => $attributes])->save();
        });
    }
}
