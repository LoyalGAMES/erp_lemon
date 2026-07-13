<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Jobs\ImportWooCommerceCustomersJob;
use App\Jobs\ImportWooCommerceOrdersJob;
use App\Jobs\ImportWooCommerceProductsJob;
use App\Models\IntegrationSyncLog;
use App\Models\WordpressIntegration;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class WooCommerceImportQueueService
{
    private const STALE_RUNNING_IMPORT_MIN = 60;

    /**
     * @return array{queued:int,skipped_active:int,integrations:int,operations:list<string>}
     */
    public function queueEnabledImports(
        bool $products = false,
        bool $orders = true,
        bool $customers = false,
    ): array {
        $operations = collect([
            'import_products' => $products,
            'import_orders' => $orders,
            'import_customers' => $customers,
        ])
            ->filter()
            ->keys()
            ->values()
            ->all();

        if ($operations === []) {
            return [
                'queued' => 0,
                'skipped_active' => 0,
                'integrations' => 0,
                'operations' => [],
            ];
        }

        $integrations = WordpressIntegration::query()
            ->when(! $products && ($orders || $customers), fn ($query) => $query->where('order_import_enabled', true))
            ->orderBy('id')
            ->get();

        $queued = 0;
        $skipped = 0;

        foreach ($integrations as $integration) {
            if ($products) {
                $log = $this->queueImport($integration, 'import_products', source: 'scheduled_command');
                $log->wasRecentlyCreated ? $queued++ : $skipped++;
            }

            if ($orders && $integration->order_import_enabled) {
                $log = $this->queueImport($integration, 'import_orders', source: 'scheduled_command');
                $log->wasRecentlyCreated ? $queued++ : $skipped++;
            }

            if ($customers && $integration->order_import_enabled) {
                $log = $this->queueImport($integration, 'import_customers', source: 'scheduled_command');
                $log->wasRecentlyCreated ? $queued++ : $skipped++;
            }
        }

        return [
            'queued' => $queued,
            'skipped_active' => $skipped,
            'integrations' => $integrations->count(),
            'operations' => $operations,
        ];
    }

    public function queueImport(
        WordpressIntegration $integration,
        string $operation,
        ?IntegrationSyncLog $retryOf = null,
        string $source = 'erp_panel',
    ): IntegrationSyncLog {
        if (! in_array($operation, ['import_products', 'import_orders', 'import_customers'], true)) {
            throw new InvalidArgumentException("Nieobsługiwany import: {$operation}");
        }

        $log = DB::transaction(function () use ($integration, $operation, $retryOf, $source): IntegrationSyncLog {
            $lockedIntegration = WordpressIntegration::query()
                ->lockForUpdate()
                ->findOrFail($integration->id);

            $activeLog = IntegrationSyncLog::query()
                ->where('wordpress_integration_id', $lockedIntegration->id)
                ->where('operation', $operation)
                ->whereIn('status', ['queued', 'running'])
                ->latest()
                ->first();

            if ($activeLog instanceof IntegrationSyncLog
                && ! $this->releaseStaleRunningImport($activeLog)) {
                return $activeLog;
            }

            $requestPayload = $retryOf instanceof IntegrationSyncLog
                ? [
                    'source' => $source,
                    'retry_of_log_id' => $retryOf->id,
                    'retry_of_operation' => $retryOf->operation,
                    'retry_requested_at' => now()->toDateTimeString(),
                ]
                : [
                    'source' => $source,
                    'queued_at' => now()->toDateTimeString(),
                ];

            return IntegrationSyncLog::query()->create([
                'sales_channel_id' => $lockedIntegration->sales_channel_id,
                'wordpress_integration_id' => $lockedIntegration->id,
                'direction' => 'in',
                'operation' => $operation,
                'status' => 'queued',
                'request_payload' => $requestPayload,
                'attempts' => 1,
                'started_at' => now(),
            ]);
        }, 3);

        if (! $log->wasRecentlyCreated) {
            return $log;
        }

        match ($operation) {
            'import_products' => ImportWooCommerceProductsJob::dispatch($integration->id, $log->id),
            'import_orders' => ImportWooCommerceOrdersJob::dispatch($integration->id, $log->id),
            'import_customers' => ImportWooCommerceCustomersJob::dispatch($integration->id, $log->id),
        };

        return $log;
    }

    public function queueOrderImportContinuation(
        WordpressIntegration $integration,
        IntegrationSyncLog $parentLog,
        ?string $modifiedAfter,
        int $page,
        bool $backfill,
    ): IntegrationSyncLog {
        $log = DB::transaction(function () use ($integration, $parentLog, $backfill, $modifiedAfter, $page): IntegrationSyncLog {
            $lockedIntegration = WordpressIntegration::query()
                ->lockForUpdate()
                ->findOrFail($integration->id);

            $activeLog = IntegrationSyncLog::query()
                ->where('wordpress_integration_id', $lockedIntegration->id)
                ->where('operation', 'import_orders')
                ->whereIn('status', ['queued', 'running'])
                ->whereKeyNot($parentLog->id)
                ->latest()
                ->first();

            if ($activeLog instanceof IntegrationSyncLog) {
                return $activeLog;
            }

            return IntegrationSyncLog::query()->create([
                'sales_channel_id' => $lockedIntegration->sales_channel_id,
                'wordpress_integration_id' => $lockedIntegration->id,
                'direction' => 'in',
                'operation' => 'import_orders',
                'status' => 'queued',
                'request_payload' => [
                    'source' => 'continuation',
                    'parent_log_id' => $parentLog->id,
                    'mode' => $backfill ? 'backfill' : 'incremental',
                    'modified_after' => $modifiedAfter,
                    'page' => max(1, $page),
                    'queued_at' => now()->toDateTimeString(),
                ],
                'attempts' => 1,
                'started_at' => now(),
            ]);
        }, 3);

        if (! $log->wasRecentlyCreated) {
            return $log;
        }

        ImportWooCommerceOrdersJob::dispatch(
            $integration->id,
            $log->id,
            $modifiedAfter,
            max(1, $page),
            $backfill,
        );

        return $log;
    }

    /**
     * @return array{released:int,minutes:int}
     */
    public function releaseStaleRunningImports(int $olderMinutes = self::STALE_RUNNING_IMPORT_MIN): array
    {
        $minutes = $this->normalizedStaleLimit($olderMinutes);
        $released = 0;

        IntegrationSyncLog::query()
            ->whereIn('operation', ['import_products', 'import_orders', 'import_customers'])
            ->where('status', 'running')
            ->whereNotNull('started_at')
            ->where('started_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->each(function (IntegrationSyncLog $log) use (&$released, $minutes): void {
                if ($this->releaseStaleRunningImport($log, $minutes)) {
                    $released++;
                }
            });

        return [
            'released' => $released,
            'minutes' => $minutes,
        ];
    }

    private function releaseStaleRunningImport(IntegrationSyncLog $log, ?int $olderMinutes = null): bool
    {
        if ($log->status !== 'running' || $log->started_at === null) {
            return false;
        }

        $minutes = $this->normalizedStaleLimit($olderMinutes);

        if ($log->started_at->greaterThan(now()->subMinutes($minutes))) {
            return false;
        }

        $responsePayload = (array) $log->response_payload;
        $responsePayload['stale_recovery'] = [
            'released_at' => now()->toDateTimeString(),
            'stale_after_minutes' => $minutes,
            'previous_started_at' => $log->started_at->toDateTimeString(),
        ];

        $log->update([
            'status' => 'failed',
            'error_message' => "Import został oznaczony jako przerwany po {$minutes} minutach bez statusu końcowego.",
            'response_payload' => $responsePayload,
            'finished_at' => now(),
        ]);

        return true;
    }

    private function normalizedStaleLimit(?int $minutes = null): int
    {
        return max(1, $minutes ?? self::STALE_RUNNING_IMPORT_MIN);
    }
}
