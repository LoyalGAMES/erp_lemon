<?php

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\WordpressIntegration;
use App\Services\Products\ProductVariantAxisNameResolver;
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
use App\Services\WooCommerce\WooVariationMappingRelinker;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
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
    $mappings
        ->filter(fn (ProductChannelMapping $mapping): bool => data_get(
            $mapping->metadata,
            $statePath.'.status',
        ) !== 'completed')
        ->each(function (ProductChannelMapping $mapping): void {
            $root = Product::query()
                ->with([
                    'channelMappings',
                    'channelAliases',
                    'stockBalances',
                    'variantChildren.channelMappings',
                    'variantChildren.channelAliases',
                    'variantChildren.stockBalances',
                    'variantChildren.variantParents.channelMappings',
                ])
                ->find($mapping->product_id);

            if (! $root instanceof Product) {
                return;
            }

            $identity = static fn (mixed $record): string => trim(
                (string) $record->external_product_id,
            ).(filled($record->external_variation_id)
                ? '#'.trim((string) $record->external_variation_id)
                : '');
            $detail = [
                'root' => [
                    'id' => (int) $root->id,
                    'sku' => trim((string) $root->sku),
                    'name' => trim((string) $root->name),
                    'active' => (bool) $root->is_active,
                    'master_source' => $root->masterSource(),
                    'stock_verification_required' => $root->requiresStockVerification(),
                    'mappings' => $root->channelMappings->map($identity)->values()->all(),
                    'aliases' => $root->channelAliases->map($identity)->values()->all(),
                ],
                'children' => $root->variantChildren->map(
                    function (Product $child) use ($identity): array {
                        return [
                            'id' => (int) $child->id,
                            'sku' => trim((string) $child->sku),
                            'name' => trim((string) $child->name),
                            'active' => (bool) $child->is_active,
                            'master_source' => $child->masterSource(),
                            'stock_verification_required' => $child->requiresStockVerification(),
                            'relation_option' => data_get($child->pivot?->metadata, 'variant_option'),
                            'mappings' => $child->channelMappings->map($identity)->values()->all(),
                            'aliases' => $child->channelAliases->map($identity)->values()->all(),
                            'parents' => $child->variantParents->map(fn (Product $parent): array => [
                                'id' => (int) $parent->id,
                                'sku' => trim((string) $parent->sku),
                                'mappings' => $parent->channelMappings->map($identity)->values()->all(),
                            ])->values()->all(),
                            'balances' => $child->stockBalances->map(fn (mixed $balance): array => [
                                'warehouse_id' => (int) $balance->warehouse_id,
                                'on_hand' => (float) $balance->quantity_on_hand,
                                'reserved' => (float) $balance->quantity_reserved,
                                'available' => (float) $balance->quantity_available,
                            ])->values()->all(),
                            'merged_into' => data_get(
                                $child->attributes,
                                'master.translation_merge.merged_into_product_id',
                            ),
                        ];
                    },
                )->values()->all(),
            ];

            $this->line(
                'Woo-owned unresolved family detail: '
                .(json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-'),
            );
        });
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

Artisan::command('erp:relink-woocommerce-variation-mappings {--sku= : Limit to a single variable product (parent or variant SKU)} {--channel= : Limit to one sales-channel code} {--dry-run : Report changes without writing them}', function (WooVariationMappingRelinker $relinker): int {
    $dryRun = (bool) $this->option('dry-run');
    $skuOption = trim((string) $this->option('sku'));
    $channelOption = trim((string) $this->option('channel'));

    // Resolve the parent products in scope. A supplied SKU may be the variable
    // parent itself or any of its variants; in both cases relinking runs on the
    // family root so children are matched against the live parent's variations.
    $rootIds = collect();

    if ($skuOption !== '') {
        $product = Product::query()->where('sku', $skuOption)->first();

        if (! $product instanceof Product) {
            $this->error("Nie znaleziono produktu o SKU {$skuOption}.");

            return 1;
        }

        $rootId = ProductRelation::query()
            ->where('child_product_id', $product->id)
            ->where('relation_type', 'variant')
            ->orderBy('id')
            ->value('parent_product_id');
        $rootIds->push(is_numeric($rootId) && (int) $rootId > 0 ? (int) $rootId : (int) $product->id);
    } else {
        $rootIds = ProductRelation::query()
            ->where('relation_type', 'variant')
            ->distinct()
            ->pluck('parent_product_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0);
    }

    $rootIds = $rootIds->unique()->values();

    if ($rootIds->isEmpty()) {
        $this->warn('Brak rodzin wariantowych do sprawdzenia.');

        return 0;
    }

    $rows = [];
    $changedTotal = 0;
    $familiesTouched = 0;
    $errors = 0;

    foreach ($rootIds as $rootId) {
        $root = Product::query()->find($rootId);

        if (! $root instanceof Product) {
            continue;
        }

        $mappingQuery = ProductChannelMapping::query()
            ->where('product_id', $root->id)
            ->whereNull('external_variation_id')
            ->with('salesChannel');

        if ($channelOption !== '') {
            $mappingQuery->whereHas('salesChannel', fn ($query) => $query->where('code', $channelOption));
        }

        foreach ($mappingQuery->get() as $mapping) {
            $integration = WordpressIntegration::query()
                ->where('sales_channel_id', $mapping->sales_channel_id)
                ->first();

            if (! $integration instanceof WordpressIntegration) {
                continue;
            }

            $channelCode = $mapping->salesChannel?->code ?? (string) $mapping->sales_channel_id;

            try {
                $report = $relinker->relinkFamily($root, $integration, (int) $mapping->sales_channel_id, $dryRun);
            } catch (\Throwable $exception) {
                $errors++;
                $rows[] = [$root->sku, $channelCode, 'ERROR', '-', '-', str($exception->getMessage())->limit(80)->toString()];

                continue;
            }

            $changedTotal += (int) $report['changed'];

            if ((int) $report['changed'] > 0 || $report['parent']['status'] !== 'ok') {
                $familiesTouched++;
            }

            if ($report['parent']['status'] !== 'ok') {
                $rows[] = [
                    $root->sku,
                    $channelCode,
                    'parent:'.$report['parent']['status'],
                    $report['parent']['stored_external_product_id'],
                    $report['parent']['live_external_product_id'],
                    '-',
                ];
            }

            foreach ($report['variants'] as $variant) {
                if ($variant['status'] === 'ok') {
                    continue;
                }

                $rows[] = [
                    $variant['sku'],
                    $channelCode,
                    $variant['status'],
                    $variant['stored_external_variation_id'] ?: '-',
                    $variant['live_external_variation_id'] ?: '-',
                    '-',
                ];
            }
        }
    }

    $this->table(
        ['sku', 'channel', 'status', 'stored_id', 'live_id', 'note'],
        $rows,
    );
    $this->info(sprintf(
        '%s families_in_scope=%d, families_touched=%d, mappings_%s=%d, errors=%d.',
        $dryRun ? '[DRY-RUN] would relink.' : 'Relinked.',
        $rootIds->count(),
        $familiesTouched,
        $dryRun ? 'to_change' : 'changed',
        $changedTotal,
        $errors,
    ));

    return $errors > 0 ? 1 : 0;
})->purpose('Rebind stale WooCommerce variation/parent IDs onto the live SKU-matched posts without deleting variations or touching stock.');

Artisan::command('erp:consolidate-variant-size-dictionary {--dry-run : Report changes without writing them}', function (ProductVariantAxisNameResolver $axisNames): int {
    $dryRun = (bool) $this->option('dry-run');

    // The canonical axis is 'Rozmiar' (slug 'rozmiar'). Everything the resolver
    // treats as a direct size alias — e.g. the duplicate 'Rozmiary' shown in the
    // PIM — is folded into it. This touches only product_parameter_definitions;
    // it never reads or writes product rows, relations or stock.
    $definitions = ProductParameterDefinition::query()->orderBy('id')->get();
    $canonical = $definitions->first(fn (ProductParameterDefinition $definition): bool => $axisNames
        ->isCanonicalSize((string) $definition->slug)
        || $axisNames->isCanonicalSize((string) $definition->name));

    if (! $canonical instanceof ProductParameterDefinition) {
        $this->error("Brak kanonicznego słownika 'Rozmiar' (slug 'rozmiar'). Utwórz go w Parametrach przed konsolidacją.");

        return 1;
    }

    $duplicates = $definitions->filter(fn (ProductParameterDefinition $definition): bool => $definition->id !== $canonical->id
        && (
            $axisNames->isDirectSizeAlias((string) $definition->slug)
            || $axisNames->isDirectSizeAlias((string) $definition->name)
        ))
        ->values();

    if ($duplicates->isEmpty()) {
        $this->info("Słownik rozmiarów jest już skonsolidowany do '{$canonical->name}'.");

        return 0;
    }

    $identity = static fn (string $value): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));
    $pl = collect((array) $canonical->values)->map(fn (mixed $value): string => trim((string) $value))->values()->all();
    $en = collect((array) $canonical->values_en)->map(fn (mixed $value): string => trim((string) $value))->values()->all();
    $known = collect($pl)->mapWithKeys(fn (string $value): array => [$identity($value) => true])->all();
    $rows = [];
    $added = 0;

    foreach ($duplicates as $duplicate) {
        $dupPl = collect((array) $duplicate->values)->map(fn (mixed $value): string => trim((string) $value))->values();
        $dupEn = collect((array) $duplicate->values_en)->map(fn (mixed $value): string => trim((string) $value))->values();

        foreach ($dupPl as $index => $value) {
            if ($value === '' || array_key_exists($identity($value), $known)) {
                continue;
            }

            $pl[] = $value;
            $en[] = (string) ($dupEn[$index] ?? '');
            $known[$identity($value)] = true;
            $added++;
            $rows[] = [$duplicate->name, $value, 'dołączona do Rozmiar'];
        }

        $rows[] = [$duplicate->name, '('.$dupPl->count().' wartości)', $dryRun ? 'zostanie usunięty' : 'usunięty'];
    }

    if (! $dryRun) {
        DB::transaction(function () use ($canonical, $pl, $en, $duplicates): void {
            $canonical->forceFill([
                'values' => array_values($pl),
                'values_en' => array_values($en),
                'is_variant' => true,
            ])->save();

            foreach ($duplicates as $duplicate) {
                $duplicate->delete();
            }
        });
    }

    $this->table(['słownik źródłowy', 'wartość', 'akcja'], $rows);
    $this->info(sprintf(
        '%s canonical=%s, duplicates=%d, values_added=%d.',
        $dryRun ? '[DRY-RUN] would consolidate.' : 'Consolidated.',
        $canonical->name,
        $duplicates->count(),
        $added,
    ));

    return 0;
})->purpose('Merge duplicate size dictionaries (e.g. Rozmiary) into the canonical Rozmiar without touching products, relations or stock.');

Artisan::command('erp:clear-woo-axis-repair-block {--sku= : Limit to a single variable product (parent or variant SKU)} {--dry-run : Report families without clearing}', function (WooOwnedVariantAxisRepairService $axisRepair): int {
    $dryRun = (bool) $this->option('dry-run');
    $skuOption = trim((string) $this->option('sku'));

    // Resolve family roots. A supplied SKU may be the variable parent or any of
    // its variants; the block always lives on the family root's parent mapping.
    $rootIds = collect();

    if ($skuOption !== '') {
        $product = Product::query()->where('sku', $skuOption)->first();

        if (! $product instanceof Product) {
            $this->error("Nie znaleziono produktu o SKU {$skuOption}.");

            return 1;
        }

        $rootIds->push($axisRepair->familyRootId((int) $product->id));
    } else {
        // Every family whose parent mapping still carries a current-revision
        // block. Preview with --dry-run before clearing catalogue-wide.
        $statePath = str_replace('.', '->', WooOwnedVariantAxisRepairService::STATE_PATH);
        $rootIds = ProductChannelMapping::query()
            ->whereNull('external_variation_id')
            ->where('metadata->'.$statePath.'->revision', WooOwnedVariantAxisRepairService::REVISION)
            ->whereIn('metadata->'.$statePath.'->status', ['pending', 'queued', 'manual_review'])
            ->pluck('product_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0);
    }

    $rootIds = $rootIds->unique()->values();

    if ($rootIds->isEmpty()) {
        $this->warn('Brak rodzin z blokadą naprawy osi.');

        return 0;
    }

    $rows = [];
    $clearedTotal = 0;

    foreach ($rootIds as $rootId) {
        $root = Product::query()->find($rootId);
        $result = $axisRepair->clearFamilyRepairBlock((int) $rootId, $dryRun);
        $clearedTotal += (int) $result['cleared'];

        foreach ($result['targets'] as $target) {
            $rows[] = [
                $root?->sku ?? (string) $rootId,
                $target['channel'],
                $target['status'] ?: '-',
                $target['external_product_id'] ?: '-',
                $dryRun ? 'do odblokowania' : 'odblokowano',
            ];
        }
    }

    $this->table(['sku', 'channel', 'prior_status', 'woo_id', 'akcja'], $rows);
    $this->info(sprintf(
        '%s families_in_scope=%d, mappings_%s=%d.',
        $dryRun ? '[DRY-RUN] would clear.' : 'Cleared.',
        $rootIds->count(),
        $dryRun ? 'to_clear' : 'cleared',
        $dryRun ? count($rows) : $clearedTotal,
    ));

    if (! $dryRun && $clearedTotal > 0) {
        $this->line('Uruchom teraz eksport rodziny: przycisk „Wyślij dane do WooCommerce" albo zapis produktu.');
    }

    return 0;
})->purpose('Clear a stuck WooCommerce axis-repair manual_review block after a manual Woo fix so the normal full export can rebuild the family.');

Artisan::command('erp:dispatch-english-translation-repair {--limit=3 : Families queued per run} {--check-limit=10 : Families remotely verified per run} {--dry-run : Classify without pruning or queueing} {--sku= : Limit to a single family}', function (
    ProductDataExportService $exporter,
    WooOwnedVariantAxisRepairService $axisRepair,
): int {
    $limit = max(1, (int) $this->option('limit'));
    $checkLimit = max(1, (int) $this->option('check-limit'));
    $dryRun = (bool) $this->option('dry-run');
    $skuOption = trim((string) $this->option('sku'));

    // Only channels that actually export English can miss an English copy.
    $integrations = WordpressIntegration::query()
        ->with('salesChannel')
        ->get()
        ->filter(fn (WordpressIntegration $integration): bool => $integration->salesChannel?->type === 'woocommerce'
            && (bool) $integration->salesChannel?->is_active
            && in_array('en', $integration->productExportLanguages(), true));

    if ($integrations->isEmpty()) {
        $this->info('Brak integracji WooCommerce z eksportem języka angielskiego.');

        return 0;
    }

    $counts = [
        'healthy' => 0,
        'monolingual' => 0,
        'translation_row' => 0,
        'axis_blocked' => 0,
        'shared_children' => 0,
        'recently_checked' => 0,
        'failed_cooldown' => 0,
        'manual_after_failures' => 0,
        'check_failed' => 0,
        'live_ref_manual' => 0,
        'checked' => 0,
        'queued' => 0,
    ];
    $rows = [];
    $writeMarker = function (ProductChannelMapping $mapping, array $extra = []): void {
        // Locked fresh re-read: the loop's model may be minutes old and a
        // whole-column save from it would clobber concurrent metadata writes
        // (e.g. a publish pending_token). Only the repair subtree is touched.
        DB::transaction(function () use ($mapping, $extra): void {
            $locked = ProductChannelMapping::query()->lockForUpdate()->find($mapping->id);

            if ($locked === null) {
                return;
            }

            $metadata = (array) $locked->metadata;
            data_set($metadata, 'english_translation_repair.checked_at', now()->toISOString());

            foreach ($extra as $key => $value) {
                data_set($metadata, "english_translation_repair.{$key}", $value);
            }

            $locked->forceFill(['metadata' => $metadata])->save();
        }, 3);
    };

    foreach ($integrations as $integration) {
        $channelId = (int) $integration->sales_channel_id;
        $mappings = ProductChannelMapping::query()
            ->where('sales_channel_id', $channelId)
            ->whereNull('external_variation_id')
            ->where('external_product_id', '!=', '')
            ->whereHas('product', fn ($query) => $query
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->where('is_translation', false))
            ->orderBy('product_id')
            ->with('product')
            ->get();

        foreach ($mappings as $mapping) {
            $product = $mapping->product;

            if (! $product instanceof Product
                || ($skuOption !== '' && $product->sku !== $skuOption)
            ) {
                continue;
            }

            // A deliberately monolingual legacy record must stay monolingual —
            // the same rule the exporter itself applies (exportLanguages()).
            if (! is_array(data_get($product->masterData(), 'content.en'))) {
                $counts['monolingual']++;

                continue;
            }

            if (ProductChannelAlias::query()
                ->where('product_id', $product->id)
                ->where('sales_channel_id', $channelId)
                ->where('language', 'en')
                ->exists()) {
                $counts['healthy']++;

                continue;
            }

            if ($axisRepair->blocksFullExport($product)) {
                $counts['axis_blocked']++;

                continue;
            }

            // A child claimed by two parents means an unresolved translation-row
            // twin (the KESJA Shoes Black case) — deduplicate manually first.
            $childIds = ProductRelation::query()
                ->where('parent_product_id', $product->id)
                ->where('relation_type', 'variant')
                ->pluck('child_product_id');
            if ($childIds->isNotEmpty() && ProductRelation::query()
                ->whereIn('child_product_id', $childIds->all())
                ->where('relation_type', 'variant')
                ->where('parent_product_id', '!=', $product->id)
                ->exists()) {
                $counts['shared_children']++;
                $rows[] = [$product->sku, 'wspólne warianty z innym rodzicem — rozdziel ręcznie'];

                continue;
            }

            $repairState = (array) data_get($mapping->metadata, 'english_translation_repair', []);
            $failureCount = (int) ($repairState['failure_count'] ?? 0);

            // Failure memory: a family whose repair export keeps dying gets an
            // exponential cooldown (1d, 2d, 4d) and, after three strikes, waits
            // for a human instead of burning 70 retries every day forever.
            if ($failureCount >= 3) {
                $counts['manual_after_failures']++;
                $rows[] = [$product->sku, "eksport naprawczy padł {$failureCount}× — wymaga człowieka ("
                    .str((string) ($repairState['last_error'] ?? ''))->limit(60)->toString().')'];

                continue;
            }

            if ($failureCount > 0 && filled($repairState['last_failed_at'] ?? null)
                && now()->subDays(2 ** ($failureCount - 1))->lt(Carbon::parse((string) $repairState['last_failed_at']))
            ) {
                $counts['failed_cooldown']++;

                continue;
            }

            // One remote check per family per day keeps the loop gentle when a
            // family cannot be healed automatically (live-but-unlinked EN post)
            // or its queued export has not produced an alias yet.
            $checkedAt = $repairState['checked_at'] ?? null;
            if (filled($checkedAt) && now()->subDay()->lt(Carbon::parse((string) $checkedAt))) {
                $counts['recently_checked']++;

                continue;
            }

            if ($dryRun) {
                $counts['queued']++;
                $snapshotRef = data_get($product->attributes, 'woocommerce_translations.en.product_id');
                $rows[] = [$product->sku, $snapshotRef
                    ? "kandydat (snapshot en -> {$snapshotRef} do weryfikacji)"
                    : 'kandydat (brak referencji — eksport utworzy EN)'];

                if ($counts['queued'] >= $limit) {
                    break 2;
                }

                continue;
            }

            // Remote-verification budget is separate from the queue budget: the
            // very first run meets the whole backlog and must not spend an
            // unbounded number of HTTP round-trips in a single scheduler tick.
            $counts['checked']++;

            try {
                $exporter->pruneDeadLegacyTranslationSnapshot($product, $integration);
                $writeMarker($mapping);
            } catch (Throwable $exception) {
                // One family with an unreachable ref must not wedge the sweep
                // (head-of-line) nor be retried every 5 minutes: mark it
                // checked, report, and move on — the daily window retries it.
                report($exception);
                $writeMarker($mapping, [
                    'check_error' => str($exception->getMessage())->limit(300)->toString(),
                ]);
                $counts['check_failed']++;
                $rows[] = [$product->sku, 'błąd weryfikacji: '.str($exception->getMessage())->limit(70)->toString()];

                if ($counts['checked'] >= $checkLimit) {
                    break 2;
                }

                continue;
            }

            $liveRef = data_get($product->fresh()->attributes, 'woocommerce_translations.en.product_id');

            if (filled($liveRef)) {
                // The referenced EN post exists but is not our linked
                // translation (no alias). Creating another copy would duplicate
                // it; adopting blind would guess. Leave the decision to the
                // operator, re-checking at most daily.
                $counts['live_ref_manual']++;
                $rows[] = [$product->sku, "istnieje niespięty post EN #{$liveRef} — spięcie/kasacja to decyzja operatora"];

                if ($counts['checked'] >= $checkLimit) {
                    break 2;
                }

                continue;
            }

            // The bounded repair queue keeps heavy rebuild exports away from
            // the default lane (stock sync) and behind woocommerce-critical
            // (operator publishes) on the shared worker.
            ExportWooCommerceProductDataJob::dispatch((int) $product->id)
                ->onConnection('database')
                ->onQueue(WooOwnedVariantAxisRepairService::REPAIR_QUEUE);
            $counts['queued']++;
            $rows[] = [$product->sku, 'zakolejkowano pełny eksport (utworzy i zepnie EN)'];

            if ($counts['queued'] >= $limit || $counts['checked'] >= $checkLimit) {
                break 2;
            }
        }
    }

    if ($rows !== []) {
        $this->table(['sku', 'status'], $rows);
    }

    $this->info(sprintf(
        '%s zdrowe=%d, jednojęzyczne=%d, wiersze-tłumaczeń=%d, oś-w-naprawie=%d, wspólne-warianty=%d, sprawdzone-ostatnio=%d, cooldown-po-porażce=%d, do-decyzji-po-porażkach=%d, błędy-weryfikacji=%d, żywy-niespięty-post=%d, sprawdzone-teraz=%d, %s=%d.',
        $dryRun ? '[DRY-RUN]' : 'English repair:',
        $counts['healthy'],
        $counts['monolingual'],
        $counts['translation_row'],
        $counts['axis_blocked'],
        $counts['shared_children'],
        $counts['recently_checked'],
        $counts['failed_cooldown'],
        $counts['manual_after_failures'],
        $counts['check_failed'],
        $counts['live_ref_manual'],
        $counts['checked'],
        $dryRun ? 'kandydaci' : 'zakolejkowane',
        $counts['queued'],
    ));

    return 0;
})->purpose('Automatically rebuild missing English translations: prune dead snapshot refs and queue bounded repair exports; report every family that needs a human.');

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

Schedule::command('erp:release-stale-woocommerce-imports --minutes=20')
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

Schedule::command('erp:dispatch-english-translation-repair --limit=3')
    ->cron('*/5 * * * *')
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
