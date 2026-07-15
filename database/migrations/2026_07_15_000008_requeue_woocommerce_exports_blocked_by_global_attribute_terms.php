<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\WooCommerceProductCreationRecoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('wordpress_integrations')
        ) {
            return;
        }

        $integrations = WordpressIntegration::query()
            ->with('salesChannel')
            ->get()
            ->filter(fn (WordpressIntegration $integration): bool => $integration->salesChannel?->is_active
                && $integration->salesChannel?->type === 'woocommerce')
            ->groupBy('sales_channel_id')
            ->filter(fn ($channelIntegrations): bool => $channelIntegrations->count() === 1)
            ->map(fn ($channelIntegrations): WordpressIntegration => $channelIntegrations->first());

        if ($integrations->isEmpty()) {
            return;
        }

        $this->requeueMappedExports($integrations);

        if (Schema::hasTable('audit_logs')) {
            $this->requeueFailedCreations($integrations);
        }
    }

    public function down(): void
    {
        // Deliberate no-op: a queued or completed remote export must not be
        // undone when application code is rolled back.
    }

    private function requeueMappedExports($integrations): void
    {
        $backfill = app(LegacyVariantFamilyBackfillService::class);
        $markedProductIds = [];

        ProductChannelMapping::query()
            ->whereIn('sales_channel_id', $integrations->keys())
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->with(['product.channelMappings', 'product.parentRelations', 'salesChannel'])
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use ($backfill, &$markedProductIds): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product
                        || isset($markedProductIds[$product->id])
                        || ! $this->isCanonicalErpRoot($product)
                        || $product->channelMappings->pluck('sales_channel_id')->unique()->count() !== 1
                        || ! $this->isPrimaryPolishMapping($mapping)
                        || ! $this->needsMappedRecovery((array) $mapping->metadata)
                    ) {
                        continue;
                    }

                    $backfill->markPendingRevision(
                        $product,
                        LegacyVariantFamilyBackfillService::GLOBAL_ATTRIBUTE_TERM_RECOVERY_REVISION,
                    );
                    $markedProductIds[$product->id] = true;
                }
            });
    }

    private function requeueFailedCreations($integrations): void
    {
        $productMorph = (new Product)->getMorphClass();
        $queued = [];
        $recovery = app(WooCommerceProductCreationRecoveryService::class);

        AuditLog::query()
            ->where('action', 'product.woocommerce_create_failed')
            ->where('auditable_type', $productMorph)
            ->where('created_at', '>=', '2026-07-14 00:00:00')
            ->orderBy('id')
            ->chunkById(100, function ($logs) use ($integrations, $recovery, &$queued): void {
                foreach ($logs as $log) {
                    $integrationId = (int) data_get($log->metadata, 'wordpress_integration_id', 0);
                    $salesChannelId = (int) data_get($log->metadata, 'sales_channel_id', 0);
                    $integration = $integrations->first(
                        fn (WordpressIntegration $candidate): bool => (int) $candidate->id === $integrationId,
                    );
                    $error = trim((string) data_get($log->metadata, 'error', ''));
                    $productId = (int) $log->auditable_id;
                    $key = $productId.'|'.$integrationId;

                    if (isset($queued[$key])
                        || ! $integration instanceof WordpressIntegration
                        || $productId <= 0
                        || $salesChannelId <= 0
                        || $salesChannelId !== (int) $integration->sales_channel_id
                        || ! $recovery->isRetryableFailure($error)
                    ) {
                        continue;
                    }

                    $product = Product::query()
                        ->with(['channelMappings', 'parentRelations'])
                        ->find($productId);

                    if (! $product instanceof Product
                        || ! $this->isCanonicalErpRoot($product)
                        || $product->channelMappings->contains(
                            fn (ProductChannelMapping $mapping): bool => (int) $mapping->sales_channel_id
                                === (int) $integration->sales_channel_id,
                        )
                        || $this->hasLaterSuccessfulCreation($product, $integration, (int) $log->id)
                    ) {
                        continue;
                    }

                    $recovery->markPending($product, $integration, (int) $log->id);
                    $queued[$key] = true;
                }
            });
    }

    private function hasLaterSuccessfulCreation(
        Product $product,
        WordpressIntegration $integration,
        int $failedAuditId,
    ): bool {
        return AuditLog::query()
            ->where('action', 'product.woocommerce_created')
            ->where('auditable_type', $product->getMorphClass())
            ->where('auditable_id', $product->id)
            ->where('id', '>', $failedAuditId)
            ->where('metadata->wordpress_integration_id', $integration->id)
            ->exists();
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

    /** @param array<string, mixed> $metadata */
    private function needsMappedRecovery(array $metadata): bool
    {
        $backfillStatus = data_get($metadata, 'product_data_export.legacy_variant_backfill.status');
        $backfillReason = data_get($metadata, 'product_data_export.legacy_variant_backfill.reason');

        if ($backfillReason === LegacyVariantFamilyBackfillService::REASON
            && in_array($backfillStatus, ['pending', 'queued'], true)
        ) {
            return true;
        }

        if (data_get($metadata, 'creation_state') === 'creating'
            || data_get($metadata, 'product_translation_link.pending') === true
            || collect((array) data_get($metadata, 'product_translation_creation', []))
                ->contains(fn (mixed $state): bool => is_array($state) && ($state['pending'] ?? false) === true)
        ) {
            return true;
        }

        $failedAt = $this->date(data_get($metadata, 'product_data_export.failed_at'));
        $completedAt = $this->date(data_get($metadata, 'product_data_export.completed_at'));

        return $failedAt instanceof CarbonImmutable
            && (! $completedAt instanceof CarbonImmutable || $failedAt->gt($completedAt));
    }

    private function isPrimaryPolishMapping(ProductChannelMapping $mapping): bool
    {
        $metadata = (array) $mapping->metadata;
        $language = mb_strtolower(trim((string) ($metadata['language'] ?? '')));
        $role = mb_strtolower(trim((string) ($metadata['mapping_role'] ?? '')));
        $isPolish = $language === ''
            || $language === 'pl'
            || str_starts_with($language, 'pl-')
            || str_starts_with($language, 'pl_');

        return ! (bool) ($metadata['is_translation'] ?? false)
            && $isPolish
            && in_array($role, ['', 'primary'], true)
            && ctype_digit(trim((string) $mapping->external_product_id))
            && (int) $mapping->external_product_id > 0;
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
};
