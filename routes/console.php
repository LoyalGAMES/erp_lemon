<?php

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Services\Communication\UnpaidOrderReminderService;
use App\Services\Integrations\WooCommerceImportQueueService;
use App\Services\Inventory\StockSyncQueueService;
use App\Services\Invoices\InvoiceEppDeliveryService;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Invoices\InvoiceTemplateService;
use App\Services\Invoices\OrderInvoiceService;
use App\Services\Ksef\KsefSubmissionService;
use App\Services\Payments\PayuRefundService;
use App\Services\Shipping\CourierPickupTrackingService;
use App\Services\Shipping\ShippedOrderWooSyncService;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\ProductDataExportService;
use App\Services\WooCommerce\WooCommerceProductCreationRecoveryService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('erp:send-scheduled-invoice-epp', function (InvoiceEppDeliveryService $delivery): int {
    $result = $delivery->sendIfDue();
    $this->info('Scheduled EPP delivery: '.$result);

    return 0;
})->purpose('Send the scheduled invoice EPP export when it is due.');

Artisan::command('erp:queue-woocommerce-imports {--products : Queue product imports} {--orders : Queue order imports} {--customers : Queue customer imports} {--all : Queue products, orders and customers}', function (): int {
    $all = (bool) $this->option('all');
    $products = $all || (bool) $this->option('products');
    $orders = $all || (bool) $this->option('orders');
    $customers = $all || (bool) $this->option('customers');

    if (! $products && ! $orders && ! $customers) {
        $orders = true;
    }

    $result = app(WooCommerceImportQueueService::class)->queueEnabledImports(
        products: $products,
        orders: $orders,
        customers: $customers,
    );

    $this->info(sprintf(
        'WooCommerce imports queued: %d, skipped active: %d, integrations scanned: %d, operations: %s.',
        $result['queued'],
        $result['skipped_active'],
        $result['integrations'],
        implode(', ', $result['operations']) ?: '-',
    ));

    return 0;
})->purpose('Queue WooCommerce imports for enabled ERP integrations.');

Artisan::command('erp:release-stale-woocommerce-imports {--minutes=60 : Mark running imports older than this limit as failed}', function (): int {
    $minutes = max(1, (int) $this->option('minutes'));
    $result = app(WooCommerceImportQueueService::class)->releaseStaleRunningImports($minutes);

    $this->info(sprintf(
        'WooCommerce stale imports released: %d, threshold: %d minutes.',
        $result['released'],
        $result['minutes'],
    ));

    return 0;
})->purpose('Release WooCommerce imports stuck in running status.');

Artisan::command('erp:dispatch-legacy-variant-backfill {--limit=10 : Maximum number of historical catalog products to queue} {--stale-minutes=120 : Replace an abandoned export reservation after this many minutes}', function (): int {
    $result = app(LegacyVariantFamilyBackfillService::class)->dispatchPending(
        max(1, (int) $this->option('limit')),
        max(1, (int) $this->option('stale-minutes')),
    );

    $this->info(sprintf(
        'Historical catalog backfill: scanned %d, dispatched %d, active %d, backoff %d, plugin unready %d, failed %d.',
        $result['scanned'],
        $result['dispatched'],
        $result['skipped_active'],
        $result['skipped_backoff'],
        $result['skipped_unready'],
        $result['failed'],
    ));

    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Queue durable full WooCommerce exports for historical catalog repairs.');

Artisan::command('erp:sync-pending-woocommerce-product-labels-during-maintenance {--limit=100 : Maximum number of labeled products to update}', function (): int {
    if (! app()->isDownForMaintenance()) {
        $this->error('Bezpośrednia synchronizacja labeli wymaga trybu maintenance.');

        return 1;
    }

    $result = app(LegacyVariantFamilyBackfillService::class)
        ->syncPendingCustomProductLabels(max(1, (int) $this->option('limit')));

    $this->table(
        ['product', 'sku', 'status', 'error'],
        collect($result['results'])->map(fn (array $row): array => [
            $row['product_id'],
            $row['sku'],
            $row['status'],
            $row['error'] ?? '-',
        ])->all(),
    );
    $this->info(sprintf(
        'Woo custom storefront metadata sync: scanned=%d, succeeded=%d, failed=%d.',
        $result['scanned'],
        $result['succeeded'],
        $result['failed'],
    ));

    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Synchronously update Lemon theme storefront meta during deployment maintenance.');

Artisan::command('erp:sync-pending-woocommerce-storefront-metadata-during-maintenance {--limit=50 : Maximum number of recent pending or failed products to update}', function (): int {
    if (! app()->isDownForMaintenance()) {
        $this->error('Bezpośrednia synchronizacja konfiguracji sklepowej wymaga trybu maintenance.');

        return 1;
    }

    $limit = max(1, (int) $this->option('limit'));
    $baseQuery = static fn () => ProductChannelMapping::query()
        ->whereNotNull('external_product_id')
        ->where('external_product_id', '!=', '')
        ->whereHas('salesChannel', fn ($query) => $query
            ->where('type', 'woocommerce')
            ->where('is_active', true));
    $productIds = $baseQuery()
        ->whereNotNull('metadata->product_data_export->pending_token')
        ->orderByDesc('updated_at')
        ->orderByDesc('id')
        ->pluck('product_id')
        ->map(fn (mixed $productId): int => (int) $productId)
        ->unique()
        ->take($limit)
        ->values();

    if ($productIds->count() < $limit) {
        $failedProductIds = $baseQuery()
            ->whereNotNull('metadata->product_data_export->error')
            ->when($productIds->isNotEmpty(), fn ($query) => $query->whereNotIn('product_id', $productIds->all()))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->pluck('product_id')
            ->map(fn (mixed $productId): int => (int) $productId)
            ->unique()
            ->take($limit - $productIds->count());
        $productIds = $productIds->concat($failedProductIds)->values();
    }

    $result = [
        'scanned' => 0,
        'succeeded' => 0,
        'failed' => 0,
        'results' => [],
    ];
    $exporter = app(ProductDataExportService::class);

    foreach ($productIds as $productId) {
        $product = Product::query()
            ->with('channelMappings.salesChannel')
            ->find($productId);

        if (! $product instanceof Product || $product->masterSource() !== 'erp') {
            continue;
        }

        $result['scanned']++;

        try {
            $exporter->exportStorefrontMetadata($product);

            DB::transaction(function () use ($product): void {
                ProductChannelMapping::query()
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->get()
                    ->each(function (ProductChannelMapping $mapping): void {
                        $metadata = (array) $mapping->metadata;
                        $token = trim((string) data_get($metadata, 'product_data_export.pending_token', ''));

                        if ($token === '') {
                            return;
                        }

                        data_set(
                            $metadata,
                            ProductDataExportService::STOREFRONT_METADATA_SYNC_PATH.'.synced_token',
                            $token,
                        );
                        data_set(
                            $metadata,
                            ProductDataExportService::STOREFRONT_METADATA_SYNC_PATH.'.synced_at',
                            now()->toISOString(),
                        );
                        $mapping->forceFill(['metadata' => $metadata])->save();
                    });
            });

            $result['succeeded']++;
            $result['results'][] = [$product->id, $product->sku, 'success', '-'];
        } catch (Throwable $exception) {
            report($exception);
            $result['failed']++;
            $result['results'][] = [
                $product->id,
                $product->sku,
                'failed',
                str($exception->getMessage())->limit(180)->toString(),
            ];
        }
    }

    $this->table(['product', 'sku', 'status', 'error'], $result['results']);
    $this->info(sprintf(
        'Woo storefront metadata sync: scanned=%d, succeeded=%d, failed=%d.',
        $result['scanned'],
        $result['succeeded'],
        $result['failed'],
    ));

    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Synchronously update labels, shipping and preorder meta for recent pending Woo exports.');

Artisan::command('erp:dispatch-woo-owned-variant-axis-repair {--limit=10 : Maximum number of Woo-owned historical families to queue} {--stale-minutes=120 : Replace an abandoned repair reservation after this many minutes}', function (): int {
    $result = app(WooOwnedVariantAxisRepairService::class)->dispatchPending(
        max(1, (int) $this->option('limit')),
        max(1, (int) $this->option('stale-minutes')),
    );

    $this->info(sprintf(
        'Woo-owned variant axis repair: scanned %d, dispatched %d, active %d, backoff %d, failed %d.',
        $result['scanned'],
        $result['dispatched'],
        $result['active'],
        $result['backoff'],
        $result['failed'],
    ));

    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Queue axis-only repairs for historical Woo-owned size families.');

Artisan::command('erp:inspect-woo-owned-variant-axis-repair {--limit=30 : Maximum number of recent family states to show}', function (): int {
    $limit = max(1, min(100, (int) $this->option('limit')));
    $statePath = WooOwnedVariantAxisRepairService::STATE_PATH;
    $mappings = ProductChannelMapping::query()
        ->with('product:id,sku')
        ->where(function ($query): void {
            $query
                ->whereNull('external_variation_id')
                ->orWhereIn('external_variation_id', ['', '0'])
                ->orWhereRaw("TRIM(external_variation_id) = ''");
        })
        ->latest('updated_at')
        ->get()
        ->filter(fn (ProductChannelMapping $mapping): bool => data_get(
            $mapping->metadata,
            $statePath.'.revision',
        ) === WooOwnedVariantAxisRepairService::REVISION);
    $statusCounts = $mappings
        ->countBy(fn (ProductChannelMapping $mapping): string => (string) data_get(
            $mapping->metadata,
            $statePath.'.status',
            'missing',
        ))
        ->sortKeys();

    $this->info('Woo-owned axis state: '.($statusCounts->isEmpty()
        ? 'no matching families'
        : $statusCounts->map(fn (int $count, string $status): string => "{$status}={$count}")->implode(', ')));
    $this->table(
        ['product', 'sku', 'woo', 'status', 'queue', 'next_attempt', 'error/reason'],
        $mappings
            ->sortBy(fn (ProductChannelMapping $mapping): string => sprintf(
                '%d|%s',
                data_get($mapping->metadata, $statePath.'.status') === 'completed' ? 1 : 0,
                (string) $mapping->updated_at,
            ))
            ->take($limit)
            ->map(function (ProductChannelMapping $mapping) use ($statePath): array {
                $state = (array) data_get($mapping->metadata, $statePath, []);

                return [
                    (int) $mapping->product_id,
                    $mapping->product?->sku ?? 'missing',
                    $mapping->external_product_id ?? '-',
                    (string) ($state['status'] ?? '-'),
                    (string) data_get($state, 'result.full_export_queue', '-'),
                    (string) ($state['next_attempt_at'] ?? '-'),
                    filled($state['error'] ?? null)
                        ? (string) $state['error']
                        : (string) data_get($state, 'result.reason', '-'),
                ];
            })
            ->values()
            ->all(),
    );
    $this->info(sprintf(
        'Woo-owned axis diagnostics: matching=%d, queued_jobs=%d, failed_jobs=%d, sync_success=%d, sync_failed=%d, revision=%s.',
        $mappings->count(),
        DB::table('jobs')->where('payload', 'like', '%RepairWooOwnedVariantAxisJob%')->count(),
        DB::table('failed_jobs')->where('payload', 'like', '%RepairWooOwnedVariantAxisJob%')->count(),
        DB::table('integration_sync_logs')
            ->where('operation', 'repair_woo_owned_variant_axis')
            ->where('status', 'success')
            ->count(),
        DB::table('integration_sync_logs')
            ->where('operation', 'repair_woo_owned_variant_axis')
            ->where('status', 'failed')
            ->count(),
        WooOwnedVariantAxisRepairService::REVISION,
    ));

    return 0;
})->purpose('Inspect durable historical Woo-owned size-axis repair state without changing it.');

Artisan::command('erp:dispatch-woocommerce-product-creation-recovery {--limit=10 : Maximum number of failed product creations to queue} {--stale-minutes=120 : Replace an abandoned creation reservation after this many minutes}', function (): int {
    $result = app(WooCommerceProductCreationRecoveryService::class)->dispatchPending(
        max(1, (int) $this->option('limit')),
        max(1, (int) $this->option('stale-minutes')),
    );

    $this->info(sprintf(
        'WooCommerce product creation recovery: scanned %d, dispatched %d, active %d, backoff %d, plugin unready %d, skipped %d, failed %d.',
        $result['scanned'],
        $result['dispatched'],
        $result['active'],
        $result['backoff'],
        $result['unready'],
        $result['skipped'],
        $result['failed'],
    ));

    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Queue durable retries for WooCommerce products whose initial creation was interrupted.');

Artisan::command('erp:inspect-woocommerce-product-creation-recovery {--limit=20 : Maximum number of recent matching failures to show}', function (): int {
    $limit = max(1, min(100, (int) $this->option('limit')));
    $productMorph = (new Product)->getMorphClass();
    $recovery = app(WooCommerceProductCreationRecoveryService::class);
    $audits = AuditLog::query()
        ->where('action', 'product.woocommerce_create_failed')
        ->where('auditable_type', $productMorph)
        ->where('created_at', '>=', '2026-07-14 00:00:00')
        ->latest('id')
        ->limit($limit * 5)
        ->get()
        ->filter(function (AuditLog $audit) use ($recovery): bool {
            $error = trim((string) data_get($audit->metadata, 'error', ''));

            return $recovery->isRetryableFailure($error);
        })
        ->unique(fn (AuditLog $audit): string => $audit->auditable_id.'|'.(string) data_get(
            $audit->metadata,
            'wordpress_integration_id',
            '',
        ))
        ->take($limit);

    $rows = $audits->map(function (AuditLog $audit) use ($recovery): array {
        $product = Product::query()
            ->with(['channelMappings', 'parentRelations'])
            ->find($audit->auditable_id);
        $integrationId = (int) data_get($audit->metadata, 'wordpress_integration_id', 0);
        $salesChannelId = (int) data_get($audit->metadata, 'sales_channel_id', 0);
        $state = $product instanceof Product
            ? (array) data_get($product->attributes, $recovery->metadataPath($integrationId), [])
            : [];
        $mapping = $product?->channelMappings->first(
            fn ($candidate): bool => (int) $candidate->sales_channel_id === $salesChannelId,
        );
        $root = $product instanceof Product
            && ! $product->is_translation
            && trim((string) $product->sku) !== ''
            && $product->masterSource() === 'erp'
            && data_get($product->masterData(), 'product_type') !== 'variation'
            && ! $product->parentRelations->contains(
                fn ($relation): bool => $relation->relation_type === 'variant',
            );

        return [
            (int) $audit->id,
            (int) $audit->auditable_id,
            $product?->sku ?? 'missing',
            $integrationId,
            $root ? 'yes' : 'no',
            $mapping?->external_product_id ?? '-',
            (string) ($state['status'] ?? '-'),
            (int) ($state['attempts'] ?? 0),
            str((string) ($state['last_error'] ?? data_get($audit->metadata, 'error', '-')))
                ->squish()
                ->limit(180)
                ->toString(),
        ];
    })->values()->all();

    $this->table(
        ['audit', 'product', 'sku', 'integration', 'erp_root', 'woo_mapping', 'state', 'attempts', 'last_error'],
        $rows,
    );
    $this->info(sprintf(
        'Recovery diagnostics: matching=%d, queued_jobs=%d, failed_jobs=%d, revision=%s.',
        count($rows),
        DB::table('jobs')->where('payload', 'like', '%RetryWooCommerceProductCreationJob%')->count(),
        DB::table('failed_jobs')->where('payload', 'like', '%RetryWooCommerceProductCreationJob%')->count(),
        WooCommerceProductCreationRecoveryService::REVISION,
    ));

    return 0;
})->purpose('Inspect recent duplicate-global-attribute product creation recovery without changing state.');

Artisan::command('erp:inspect-woocommerce-product-export-failures {--limit=20 : Maximum number of recent root product export failures to show}', function (): int {
    $limit = max(1, min(100, (int) $this->option('limit')));
    $rows = [];
    $safeError = static function (mixed $value): string {
        $error = str((string) $value)->squish()->toString();

        if ($error === '') {
            return '-';
        }

        $error = (string) preg_replace(
            [
                '/\bBearer\s+\S+/iu',
                '/\b(?:ck|cs)_[A-Za-z0-9._-]+\b/u',
                '/\b(consumer_(?:key|secret)|authorization|password|access[_-]?token|secret|token)\s*[:=]\s*[^\s,;]+/iu',
            ],
            [
                'Bearer [redacted]',
                '[redacted]',
                '$1=[redacted]',
            ],
            $error,
        );

        return str($error)->limit(180)->toString();
    };

    ProductChannelMapping::query()
        ->where(function ($query): void {
            $query
                ->whereNull('external_variation_id')
                ->orWhereIn('external_variation_id', ['', '0'])
                ->orWhereRaw("TRIM(external_variation_id) = ''");
        })
        ->whereHas('salesChannel', fn ($query) => $query->where('type', 'woocommerce'))
        ->with(['product.parentRelations', 'salesChannel'])
        ->orderByDesc('updated_at')
        ->orderByDesc('id')
        ->chunk(200, function ($mappings) use (&$rows, $limit, $safeError): bool {
            foreach ($mappings as $mapping) {
                $product = $mapping->product;

                if (! $product instanceof Product
                    || $product->is_translation
                    || trim((string) $product->sku) === ''
                    || $product->masterSource() !== 'erp'
                    || data_get($product->masterData(), 'product_type') === 'variation'
                    || $product->parentRelations->contains(
                        fn ($relation): bool => $relation->relation_type === 'variant',
                    )
                ) {
                    continue;
                }

                $export = (array) data_get($mapping->metadata, 'product_data_export', []);
                $backfill = (array) data_get($export, 'legacy_variant_backfill', []);
                $backfillStatus = mb_strtolower(trim((string) ($backfill['status'] ?? '')));
                $exportError = trim((string) ($export['error'] ?? ''));
                $hasUnfinishedBackfill = $backfillStatus !== '' && $backfillStatus !== 'completed';

                if ($exportError === '' && ! $hasUnfinishedBackfill) {
                    continue;
                }

                $rows[] = [
                    $mapping->updated_at?->format('Y-m-d H:i:s') ?? '-',
                    $product->sku,
                    $mapping->salesChannel?->code ?? (string) $mapping->sales_channel_id,
                    trim((string) $mapping->external_product_id) ?: '-',
                    $backfillStatus !== '' ? $backfillStatus : '-',
                    trim((string) ($backfill['revision'] ?? '')) ?: '-',
                    trim((string) ($backfill['next_attempt_at'] ?? '')) ?: '-',
                    $safeError($exportError),
                ];

                if (count($rows) >= $limit) {
                    return false;
                }
            }

            return true;
        });

    $this->table(
        ['updated', 'sku', 'channel', 'woo_mapping', 'backfill', 'revision', 'next_attempt', 'export_error'],
        $rows,
    );
    $this->info(sprintf(
        'Product export failure diagnostics: matching=%d, queued_jobs=%d, failed_jobs=%d.',
        count($rows),
        DB::table('jobs')->where('payload', 'like', '%ExportWooCommerceProductDataJob%')->count(),
        DB::table('failed_jobs')->where('payload', 'like', '%ExportWooCommerceProductDataJob%')->count(),
    ));

    return 0;
})->purpose('Inspect recent root WooCommerce product export failures without changing state or exposing payloads.');

Artisan::command('erp:refresh-ksef-submissions {--limit=25 : Maximum number of KSeF submissions to refresh} {--minutes=2 : Refresh submissions older than this many minutes}', function (): int {
    $limit = max(1, (int) $this->option('limit'));
    $minutes = max(0, (int) $this->option('minutes'));
    $result = app(KsefSubmissionService::class)->refreshPending($limit, $minutes);

    $this->info(sprintf(
        'KSeF submissions refreshed: scanned %d, refreshed %d, accepted %d, rejected %d, pending %d, failed %d.',
        $result['scanned'],
        $result['refreshed'],
        $result['accepted'],
        $result['rejected'],
        $result['still_pending'],
        $result['failed'],
    ));

    return 0;
})->purpose('Refresh KSeF submissions that are waiting for final status.');

Artisan::command('erp:dispatch-stock-sync {--limit=100 : Maximum number of pending stock exports to dispatch} {--release-minutes=30 : Release running stock exports older than this limit}', function (): int {
    $limit = max(1, (int) $this->option('limit'));
    $releaseMinutes = max(1, (int) $this->option('release-minutes'));
    $stockSync = app(StockSyncQueueService::class);

    $released = $stockSync->releaseStaleRunning($releaseMinutes, $limit);
    $dispatch = $stockSync->dispatchPending($limit);

    $this->info(sprintf(
        'Stock sync jobs dispatched: scanned %d, released stale %d, dispatched %d.',
        $dispatch['scanned'],
        $released['released'],
        $dispatch['dispatched'],
    ));

    return 0;
})->purpose('Dispatch pending WooCommerce stock exports and recover stale running exports.');

Artisan::command('erp:refresh-invoice-template {--regenerate : Regenerate HTML/PDF files for existing invoices} {--apply-seller : Apply current seller settings before regenerating repairable invoices} {--limit=0 : Maximum number of invoices to regenerate, 0 means all}', function (): int {
    $template = app(InvoiceTemplateService::class)->refreshManagedDefaultTemplate();
    $regenerated = 0;
    $sellerApplied = 0;
    $sellerSkipped = 0;

    if ((bool) $this->option('regenerate')) {
        $limit = max(0, (int) $this->option('limit'));
        $files = app(OrderInvoiceService::class);
        $query = Invoice::query()->orderBy('id');
        $sellerData = null;

        if ((bool) $this->option('apply-seller')) {
            $settings = app(InvoiceSettingsService::class);
            $sellerStatus = $settings->sellerConfigurationStatus();

            if (! $sellerStatus['is_ready']) {
                $this->error('Nie można zastosować danych sprzedawcy: '.implode(' ', $sellerStatus['errors']));

                return 1;
            }

            $sellerData = $settings->sellerData();
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->with('ksefSubmissions')->get() as $invoice) {
            if ($sellerData !== null) {
                if (filled($invoice->ksef_number) || $invoice->ksefSubmissions->contains(fn ($submission): bool => $submission->status === 'accepted')) {
                    $sellerSkipped++;
                } else {
                    $metadata = $invoice->metadata ?? [];

                    if (data_get($metadata, 'woocommerce_upload.status') === 'success') {
                        data_set($metadata, 'woocommerce_upload.status', 'stale');
                        data_set($metadata, 'woocommerce_upload.requires_resend', true);
                        data_set($metadata, 'woocommerce_upload.stale_reason', 'seller_data_refreshed');
                    }

                    $invoice->update([
                        'seller_data' => $sellerData,
                        'metadata' => $metadata,
                    ]);
                    $sellerApplied++;
                }
            }

            $files->regenerateFiles($invoice);
            $regenerated++;
        }
    }

    $this->info(sprintf(
        'Invoice template refreshed: %s (%s). Regenerated invoices: %d. Seller applied: %d, skipped: %d.',
        $template->name,
        $template->settings['source_version'] ?? '-',
        $regenerated,
        $sellerApplied,
        $sellerSkipped,
    ));

    return 0;
})->purpose('Refresh the managed Sempre invoice template and optionally regenerate invoice files.');

Artisan::command('erp:track-courier-pickups {--limit=50 : Maximum number of shipments to check}', function (): int {
    $limit = max(1, (int) $this->option('limit'));
    $result = app(CourierPickupTrackingService::class)->trackPackedOrders($limit);

    $this->info(sprintf(
        'Courier pickup tracking: checked %d, picked up %d, orders shipped %d.',
        $result['checked'],
        $result['picked_up'],
        $result['orders'],
    ));

    foreach ($result['warnings'] as $warning) {
        $this->warn($warning);
    }

    return 0;
})->purpose('Check courier tracking for packed orders and mark physically picked up parcels as shipped.');

Artisan::command('erp:retry-shipped-woo-sync {--limit=25 : Maximum number of shipped orders to retry}', function (): int {
    $result = app(ShippedOrderWooSyncService::class)->retry(max(1, (int) $this->option('limit')));

    $this->info(sprintf(
        'Shipped Woo sync: checked %d, synced %d, failed %d.',
        $result['checked'],
        $result['synced'],
        $result['failed'],
    ));

    foreach ($result['warnings'] as $warning) {
        $this->warn($warning);
    }

    return 0;
})->purpose('Retry WooCommerce shipped status after temporary API failures.');

Artisan::command('erp:refresh-payu-refunds {--limit=25 : Maximum number of pending PayU refunds to refresh}', function (): int {
    $result = app(PayuRefundService::class)->refreshPending(max(1, (int) $this->option('limit')));

    $this->info(sprintf(
        'PayU refunds refreshed: checked %d, finalized %d, pending %d, failed %d.',
        $result['checked'],
        $result['finalized'],
        $result['pending'],
        $result['failed'],
    ));

    foreach ($result['warnings'] as $warning) {
        $this->warn($warning);
    }

    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Refresh pending PayU refunds and send the final customer confirmation.');

Artisan::command('erp:send-unpaid-order-reminders {--limit=100 : Maximum number of reminders to create}', function (): int {
    $result = app(UnpaidOrderReminderService::class)->dispatchDue(max(1, (int) $this->option('limit')));

    $this->info(sprintf(
        'Unpaid reminders: scanned %d, eligible %d, created %d, sent %d, held %d, failed %d, skipped %d.',
        $result['scanned'],
        $result['eligible'],
        $result['created'],
        $result['sent'],
        $result['held'],
        $result['failed'],
        $result['skipped'],
    ));

    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Send delayed reminders only for orders that are still unpaid.');

Artisan::command('erp:preflight {--skip-views : Skip Blade view compilation check}', function (): int {
    $failures = 0;
    $runCheck = function (string $label, callable $callback) use (&$failures): void {
        try {
            $callback();
            $this->line("OK  {$label}");
        } catch (Throwable $exception) {
            $failures++;
            $this->error("ERR {$label}: ".mb_substr($exception->getMessage(), 0, 240));
        }
    };

    $runCheck('database connection', function (): void {
        DB::select('select 1 as ok');
    });

    $runCheck('storage writable', function (): void {
        $directory = storage_path('app/preflight');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/'.uniqid('erp_preflight_', true).'.tmp';

        if (File::put($path, 'ok') === false || File::get($path) !== 'ok') {
            throw new RuntimeException('Nie można zapisać i odczytać pliku testowego w storage/app.');
        }

        File::delete($path);
    });

    $runCheck('runtime directories writable', function (): void {
        $directories = [
            storage_path('app'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
        ];

        foreach ($directories as $directory) {
            File::ensureDirectoryExists($directory);

            if (! is_writable($directory)) {
                throw new RuntimeException("Katalog nie jest zapisywalny: {$directory}");
            }
        }
    });

    $runCheck('critical routes registered', function (): void {
        $routes = [
            'dashboard',
            'products.index',
            'documents.index',
            'warehouses.index',
            'returns.index',
            'invoices.index',
            'integrations.index',
            'settings.index',
        ];

        foreach ($routes as $route) {
            if (! Route::has($route)) {
                throw new RuntimeException("Brak trasy: {$route}");
            }
        }
    });

    $runCheck('brand assets available', function (): void {
        $assets = [
            public_path('assets/sempre-logotyp.svg'),
            public_path('assets/sempre-logotyp.png'),
        ];

        foreach ($assets as $asset) {
            if (! is_file($asset)) {
                throw new RuntimeException("Brak assetu: {$asset}");
            }
        }
    });

    if (! (bool) $this->option('skip-views')) {
        $runCheck('Blade views compile', function (): void {
            try {
                Artisan::call('view:clear');
                $exitCode = Artisan::call('view:cache');
                $output = trim(Artisan::output());

                if ($exitCode !== 0) {
                    throw new RuntimeException($output !== '' ? $output : 'view:cache zwrócił kod '.$exitCode);
                }
            } finally {
                Artisan::call('view:clear');
            }
        });
    }

    if ($failures > 0) {
        $this->error("ERP preflight failed: {$failures} błędów.");

        return 1;
    }

    $this->info('ERP preflight OK.');

    return 0;
})->purpose('Run ERP runtime checks before or after deployment.');

Schedule::command('erp:queue-woocommerce-imports --orders')
    ->cron('*/5 * * * *')
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('erp:queue-woocommerce-imports --customers')
    ->cron('*/15 * * * *')
    ->withoutOverlapping(20)
    ->runInBackground();

Schedule::command('erp:release-stale-woocommerce-imports --minutes=60')
    ->cron('*/10 * * * *')
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('erp:dispatch-legacy-variant-backfill --limit=10 --stale-minutes=120')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('erp:dispatch-woo-owned-variant-axis-repair --limit=20 --stale-minutes=120')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('erp:dispatch-woocommerce-product-creation-recovery --limit=10 --stale-minutes=120')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('erp:dispatch-stock-sync --limit=100 --release-minutes=30')
    ->cron('*/5 * * * *')
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('erp:refresh-ksef-submissions --limit=25 --minutes=2')
    ->cron('*/10 * * * *')
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('erp:track-courier-pickups --limit=50')
    ->cron('*/5 * * * *')
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('erp:retry-shipped-woo-sync --limit=25')
    ->cron('*/5 * * * *')
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('erp:refresh-payu-refunds --limit=25')
    ->cron('*/10 * * * *')
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('erp:send-unpaid-order-reminders --limit=100')
    ->cron('*/5 * * * *')
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('erp:send-scheduled-invoice-epp')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// Keep corrective catalog traffic isolated from the default queue. A bounded
// repair worker gives it prompt progress without starving stock, order, KSeF,
// import, or other operational jobs handled by the default worker below.
Schedule::command('queue:work --queue=woocommerce-size-order,woocommerce-critical,woocommerce-repair --stop-when-empty --sleep=1 --tries=2 --timeout=900 --max-jobs=30 --max-time=3300')
    ->everyMinute()
    ->withoutOverlapping(60)
    ->runInBackground();

// Production uses the database queue by default. Drain it from the same
// scheduler installed during deploy when Supervisor is unavailable.
Schedule::command('queue:work --queue=default --stop-when-empty --sleep=1 --tries=2 --timeout=900 --max-time=3300')
    ->everyMinute()
    ->withoutOverlapping(60)
    ->runInBackground();
