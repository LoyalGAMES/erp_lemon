<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class LegacyVariantFamilyBackfillService
{
    public const REASON = 'legacy_variant_family_backfill_2026_07_14';

    public const UNMARKED_FAMILY_PROMOTION_REVISION = 'legacy_unmarked_variant_family_2026_07_14_000006';

    /**
     * Reuse the durable historical catalog-export queue for mapped products
     * that predate automatic creation of their configured translations. The
     * metadata path keeps its legacy name for backward compatibility with
     * already queued production work.
     */
    public const MISSING_PRODUCT_TRANSLATIONS_REVISION = 'missing_product_translations_2026_07_14_000007';

    public const GLOBAL_ATTRIBUTE_TERM_RECOVERY_REVISION = 'translated_global_attribute_taxonomy_2026_07_15_000010';

    public const PUBLICATION_DATE_AND_ATTRIBUTE_ORDER_REVISION = 'publication_date_and_attribute_order_all_size_definitions_2026_07_15_000012';

    public const VARIATION_TRANSLATION_LINK_RECOVERY_REVISION = 'variation_translation_link_recovery_2026_07_15_000013';

    public const VARIATION_STOCK_MANAGEMENT_RECOVERY_REVISION = 'variation_stock_management_recovery_2026_07_15_000014';

    public const LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION = 'legacy_size_variant_axis_recovery_2026_07_15_000015';

    public const LEGACY_SIZE_VARIANT_AXIS_FOLLOWUP_REVISION = 'legacy_size_variant_axis_followup_2026_07_15_000017';

    public const LEGACY_SIZE_PARENT_TERM_ORDER_FOLLOWUP_REVISION = 'legacy_size_parent_term_order_followup_2026_07_15_000018';

    public const WOO_OWNED_POST_AXIS_CATALOG_SYNC_REVISION = 'woo_owned_post_axis_catalog_sync_2026_07_15_000020';

    public const CRITICAL_EXPORT_QUEUE = 'woocommerce-critical';

    private const BACKFILL_PATH = 'product_data_export.legacy_variant_backfill';

    /** @var array<string, bool> */
    private array $integrationReadiness = [];

    public function __construct(
        private readonly WooCommerceClient $client,
    ) {}

    /**
     * Mark a mapped product family for a durable full export. This method only
     * writes local state; migrations can call it without performing HTTP or
     * relying on a queue worker being available during deployment.
     */
    public function markPending(Product $product): int
    {
        return DB::transaction(function () use ($product): int {
            $mappings = ProductChannelMapping::query()
                ->where('product_id', $product->id)
                ->where(function ($query): void {
                    $query
                        ->whereNull('external_variation_id')
                        ->orWhereIn('external_variation_id', ['', '0'])
                        ->orWhereRaw("TRIM(external_variation_id) = ''");
                })
                ->lockForUpdate()
                ->get();

            foreach ($mappings as $mapping) {
                $metadata = (array) $mapping->metadata;
                $backfill = (array) data_get($metadata, self::BACKFILL_PATH, []);

                if (($backfill['reason'] ?? null) === self::REASON
                    && in_array(($backfill['status'] ?? null), ['pending', 'queued', 'completed'], true)
                ) {
                    continue;
                }

                data_set($metadata, self::BACKFILL_PATH, array_merge($backfill, [
                    'status' => 'pending',
                    'reason' => self::REASON,
                    'requested_at' => now()->toISOString(),
                ]));
                $mapping->forceFill(['metadata' => $metadata])->save();
            }

            return $mappings->count();
        });
    }

    /**
     * Request a newer, explicitly versioned repair export. An already running
     * export keeps its token; its completion notices the revision mismatch and
     * leaves this request pending for a follow-up pass.
     */
    public function markPendingRevision(Product $product, string $revision): int
    {
        $revision = trim($revision);

        if ($revision === '') {
            throw new \InvalidArgumentException('Backfill revision must not be empty.');
        }

        return DB::transaction(function () use ($product, $revision): int {
            $mappings = ProductChannelMapping::query()
                ->where('product_id', $product->id)
                ->where(function ($query): void {
                    $query
                        ->whereNull('external_variation_id')
                        ->orWhereIn('external_variation_id', ['', '0'])
                        ->orWhereRaw("TRIM(external_variation_id) = ''");
                })
                ->lockForUpdate()
                ->get();

            foreach ($mappings as $mapping) {
                $metadata = (array) $mapping->metadata;
                $backfill = (array) data_get($metadata, self::BACKFILL_PATH, []);

                if (($backfill['reason'] ?? null) === self::REASON
                    && ($backfill['revision'] ?? null) === $revision
                    && in_array(($backfill['status'] ?? null), ['pending', 'queued', 'completed'], true)
                ) {
                    continue;
                }

                $hasActiveExport = filled(data_get(
                    $metadata,
                    'product_data_export.pending_token',
                ));
                $backfill = array_merge($backfill, [
                    'status' => 'pending',
                    'reason' => self::REASON,
                    'revision' => $revision,
                    'requested_at' => now()->toISOString(),
                ]);

                unset(
                    $backfill['completed_at'],
                    $backfill['failed_at'],
                    $backfill['next_attempt_at'],
                    $backfill['error'],
                );

                if (! $hasActiveExport) {
                    unset($backfill['queued_at'], $backfill['queued_revision']);
                }

                data_set($metadata, self::BACKFILL_PATH, $backfill);
                $mapping->forceFill(['metadata' => $metadata])->save();
            }

            return $mappings->count();
        });
    }

    /**
     * Durably request and, when the integration is ready, immediately queue a
     * full export for one exact product family. A currently active export is
     * left untouched; its completion observes the newer requested revision
     * and leaves a follow-up pass pending.
     *
     * @return 'dispatched'|'active'|'backoff'|'unready'|'missing'
     */
    public function queueProductRevision(
        Product $product,
        string $revision,
        int $staleMinutes = 120,
    ): string {
        if ($this->markPendingRevision($product, $revision) === 0) {
            return 'missing';
        }

        $mapping = ProductChannelMapping::query()
            ->where('product_id', $product->id)
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->orderBy('id')
            ->first();

        if (! $mapping instanceof ProductChannelMapping) {
            return 'missing';
        }

        $localState = $this->localReservationState($mapping, max(1, $staleMinutes));

        if ($localState === 'active') {
            return 'active';
        }

        if ($localState === 'backoff') {
            return 'backoff';
        }

        // Queue workers are long-lived; a plugin upgrade must be observable
        // immediately instead of reusing readiness from an earlier attempt.
        $this->integrationReadiness = [];

        if (! $this->productIntegrationsReady((int) $product->id)) {
            return 'unready';
        }

        $reservation = $this->reserve((int) $mapping->id, max(1, $staleMinutes));

        if ($reservation['status'] === 'active') {
            return 'active';
        }

        if ($reservation['status'] === 'backoff') {
            return 'backoff';
        }

        if ($reservation['status'] !== 'reserved') {
            return 'missing';
        }

        try {
            if (in_array($revision, [
                self::WOO_OWNED_POST_AXIS_CATALOG_SYNC_REVISION,
                self::LEGACY_SIZE_PARENT_TERM_ORDER_FOLLOWUP_REVISION,
            ], true)) {
                ExportWooCommerceProductDataJob::dispatch(
                    $reservation['product_id'],
                    $reservation['token'],
                )
                    ->onConnection('database')
                    ->onQueue(self::CRITICAL_EXPORT_QUEUE);
            } else {
                ExportWooCommerceProductDataJob::dispatch(
                    $reservation['product_id'],
                    $reservation['token'],
                )->onConnection('database');
            }
        } catch (Throwable $exception) {
            $this->releaseFailedReservation(
                $reservation['product_id'],
                $reservation['token'],
                $exception,
            );

            throw $exception;
        }

        return 'dispatched';
    }

    /**
     * @return array{scanned: int, dispatched: int, skipped_active: int, skipped_backoff: int, skipped_unready: int, failed: int}
     */
    public function dispatchPending(int $limit = 10, int $staleMinutes = 120): array
    {
        // A long-lived command/container may reuse this service. Re-check on
        // every dispatcher run so installing the plugin immediately unblocks
        // the next scheduled pass, while still caching per integration within
        // one scan.
        $this->integrationReadiness = [];
        $result = [
            'scanned' => 0,
            'dispatched' => 0,
            'skipped_active' => 0,
            'skipped_backoff' => 0,
            'skipped_unready' => 0,
            'failed' => 0,
        ];

        $seenMappingIds = [];

        // A narrow corrective migration must not wait behind an older broad
        // catalog backfill. Dispatch its revision first, then continue the
        // normal newest-first queue without visiting the same mapping twice.
        foreach ([
            self::WOO_OWNED_POST_AXIS_CATALOG_SYNC_REVISION,
            self::LEGACY_SIZE_PARENT_TERM_ORDER_FOLLOWUP_REVISION,
            self::LEGACY_SIZE_VARIANT_AXIS_FOLLOWUP_REVISION,
            self::LEGACY_SIZE_VARIANT_AXIS_RECOVERY_REVISION,
            null,
        ] as $priorityRevision) {
            foreach ($this->pendingMappingCandidates($priorityRevision) as $mapping) {
                if ($result['dispatched'] >= max(1, $limit)) {
                    break 2;
                }

                if (isset($seenMappingIds[$mapping->id])) {
                    continue;
                }

                $seenMappingIds[$mapping->id] = true;

                if (! $this->isPendingBackfill($mapping)) {
                    continue;
                }

                $result['scanned']++;
                $localState = $this->localReservationState($mapping, max(1, $staleMinutes));

                if ($localState === 'active') {
                    $result['skipped_active']++;

                    continue;
                }

                if ($localState === 'backoff') {
                    $result['skipped_backoff']++;

                    continue;
                }

                if (! $this->productIntegrationsReady((int) $mapping->product_id)) {
                    $result['skipped_unready']++;

                    continue;
                }

                $reservation = $this->reserve($mapping->id, max(1, $staleMinutes));

                if ($reservation['status'] === 'active') {
                    $result['skipped_active']++;

                    continue;
                }

                if ($reservation['status'] === 'backoff') {
                    $result['skipped_backoff']++;

                    continue;
                }

                if ($reservation['status'] !== 'reserved') {
                    continue;
                }

                try {
                    if (in_array(data_get(
                        $mapping->metadata,
                        self::BACKFILL_PATH.'.revision',
                    ), [
                        self::WOO_OWNED_POST_AXIS_CATALOG_SYNC_REVISION,
                        self::LEGACY_SIZE_PARENT_TERM_ORDER_FOLLOWUP_REVISION,
                    ], true)) {
                        ExportWooCommerceProductDataJob::dispatch(
                            $reservation['product_id'],
                            $reservation['token'],
                        )
                            ->onConnection('database')
                            ->onQueue(self::CRITICAL_EXPORT_QUEUE);
                    } else {
                        ExportWooCommerceProductDataJob::dispatch(
                            $reservation['product_id'],
                            $reservation['token'],
                        )->onConnection('database');
                    }
                    $result['dispatched']++;
                } catch (Throwable $exception) {
                    report($exception);
                    $this->releaseFailedReservation(
                        $reservation['product_id'],
                        $reservation['token'],
                        $exception,
                    );
                    $result['failed']++;
                }
            }
        }

        return $result;
    }

    /** @return iterable<int, ProductChannelMapping> */
    private function pendingMappingCandidates(?string $revision): iterable
    {
        $query = ProductChannelMapping::query()
            ->where(function ($mapping): void {
                $mapping
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            });

        if ($revision !== null) {
            $query->where(
                'metadata->product_data_export->legacy_variant_backfill->revision',
                $revision,
            );
        }

        return $query->lazyByIdDesc(100);
    }

    /**
     * @return array{status: 'active'|'backoff'|'missing'|'reserved', product_id?: int, token?: string}
     */
    private function reserve(int $mappingId, int $staleMinutes): array
    {
        return DB::transaction(function () use ($mappingId, $staleMinutes): array {
            $mapping = ProductChannelMapping::query()->lockForUpdate()->find($mappingId);

            if (! $mapping instanceof ProductChannelMapping || ! $this->isPendingBackfill($mapping)) {
                return ['status' => 'missing'];
            }

            $metadata = (array) $mapping->metadata;
            $nextAttemptAt = $this->date(data_get(
                $metadata,
                self::BACKFILL_PATH.'.next_attempt_at',
            ));

            if ($nextAttemptAt instanceof CarbonImmutable && $nextAttemptAt->isFuture()) {
                return ['status' => 'backoff'];
            }

            $pendingToken = trim((string) data_get($metadata, 'product_data_export.pending_token', ''));
            $requestedAt = $this->date(data_get($metadata, 'product_data_export.requested_at'));
            $isStale = $requestedAt instanceof CarbonImmutable
                && $requestedAt->lte(now()->subMinutes($staleMinutes));

            if ($pendingToken !== '' && ! $isStale) {
                return ['status' => 'active'];
            }

            $token = (string) Str::uuid();
            $mappings = ProductChannelMapping::query()
                ->where('product_id', $mapping->product_id)
                ->lockForUpdate()
                ->get();

            foreach ($mappings as $productMapping) {
                $productMetadata = (array) $productMapping->metadata;
                $backfill = (array) data_get($productMetadata, self::BACKFILL_PATH, []);

                data_set($productMetadata, 'product_data_export.pending_token', $token);
                data_set($productMetadata, 'product_data_export.requested_at', now()->toISOString());

                if (($backfill['reason'] ?? null) === self::REASON
                    && ($backfill['status'] ?? null) !== 'completed'
                ) {
                    $queuedBackfill = array_merge($backfill, [
                        'status' => 'queued',
                        'queued_at' => now()->toISOString(),
                        'attempts' => max(0, (int) ($backfill['attempts'] ?? 0)) + 1,
                    ]);
                    $revision = trim((string) ($backfill['revision'] ?? ''));

                    if ($revision === '') {
                        unset($queuedBackfill['queued_revision']);
                    } else {
                        $queuedBackfill['queued_revision'] = $revision;
                    }

                    data_set($productMetadata, self::BACKFILL_PATH, $queuedBackfill);
                    data_forget($productMetadata, self::BACKFILL_PATH.'.next_attempt_at');
                }

                $productMapping->forceFill(['metadata' => $productMetadata])->save();
            }

            return [
                'status' => 'reserved',
                'product_id' => (int) $mapping->product_id,
                'token' => $token,
            ];
        });
    }

    private function releaseFailedReservation(int $productId, string $token, Throwable $exception): void
    {
        DB::transaction(function () use ($productId, $token, $exception): void {
            ProductChannelMapping::query()
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->get()
                ->each(function (ProductChannelMapping $mapping) use ($token, $exception): void {
                    $metadata = (array) $mapping->metadata;

                    if (data_get($metadata, 'product_data_export.pending_token') !== $token) {
                        return;
                    }

                    data_forget($metadata, 'product_data_export.pending_token');
                    data_forget($metadata, 'product_data_export.requested_at');

                    if (data_get($metadata, self::BACKFILL_PATH.'.reason') === self::REASON) {
                        data_set($metadata, self::BACKFILL_PATH.'.status', 'pending');
                        data_set($metadata, self::BACKFILL_PATH.'.failed_at', now()->toISOString());
                        data_set($metadata, self::BACKFILL_PATH.'.next_attempt_at', now()->addMinutes(15)->toISOString());
                        data_set($metadata, self::BACKFILL_PATH.'.error', $exception->getMessage());
                    }

                    $mapping->forceFill(['metadata' => $metadata])->save();
                });
        });
    }

    private function isPendingBackfill(ProductChannelMapping $mapping): bool
    {
        return data_get($mapping->metadata, self::BACKFILL_PATH.'.reason') === self::REASON
            && in_array(
                data_get($mapping->metadata, self::BACKFILL_PATH.'.status'),
                ['pending', 'queued'],
                true,
            );
    }

    private function localReservationState(ProductChannelMapping $mapping, int $staleMinutes): ?string
    {
        $nextAttemptAt = $this->date(data_get(
            $mapping->metadata,
            self::BACKFILL_PATH.'.next_attempt_at',
        ));

        if ($nextAttemptAt instanceof CarbonImmutable && $nextAttemptAt->isFuture()) {
            return 'backoff';
        }

        $pendingToken = trim((string) data_get(
            $mapping->metadata,
            'product_data_export.pending_token',
            '',
        ));

        if ($pendingToken === '') {
            return null;
        }

        $requestedAt = $this->date(data_get(
            $mapping->metadata,
            'product_data_export.requested_at',
        ));

        return $requestedAt instanceof CarbonImmutable
            && $requestedAt->lte(now()->subMinutes($staleMinutes))
                ? null
                : 'active';
    }

    private function productIntegrationsReady(int $productId): bool
    {
        $requiresVariantTranslationLink = Product::query()
            ->whereKey($productId)
            ->whereHas('variantChildren')
            ->exists();
        $salesChannelIds = ProductChannelMapping::query()
            ->where('product_id', $productId)
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->pluck('sales_channel_id')
            ->map(fn (mixed $salesChannelId): int => (int) $salesChannelId)
            ->unique()
            ->values();

        if ($salesChannelIds->isEmpty()) {
            return false;
        }

        foreach ($salesChannelIds as $salesChannelId) {
            $integration = WordpressIntegration::query()
                ->where('sales_channel_id', $salesChannelId)
                ->first();

            if (! $integration instanceof WordpressIntegration) {
                return false;
            }

            $languages = $integration->productExportLanguages();
            $needsTranslations = collect($languages)->contains(
                fn (mixed $language): bool => mb_strtolower(trim((string) $language)) !== 'pl',
            );
            $readinessKey = $integration->id.'|'.implode(',', $languages)
                .'|variants:'.(int) ($requiresVariantTranslationLink && $needsTranslations);

            if (! array_key_exists($readinessKey, $this->integrationReadiness)) {
                $this->integrationReadiness[$readinessKey] = $this->client
                    ->productTranslationLinkingAvailable($integration, $languages)
                    && (! $requiresVariantTranslationLink
                        || ! $needsTranslations
                        || $this->client->productVariationTranslationLinkingAvailable(
                            $integration,
                            $languages,
                        ));
            }

            if (! $this->integrationReadiness[$readinessKey]) {
                return false;
            }
        }

        return true;
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
