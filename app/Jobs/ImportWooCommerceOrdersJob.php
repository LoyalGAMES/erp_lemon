<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IntegrationSyncLog;
use App\Models\WordpressIntegration;
use App\Services\Integrations\WooCommerceImportQueueService;
use App\Services\WooCommerce\WooCommerceImportService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ImportWooCommerceOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    public function __construct(
        private readonly int $integrationId,
        private readonly int $syncLogId,
        private readonly ?string $modifiedAfter = null,
        private readonly int $page = 1,
        private readonly bool $backfill = false,
    ) {}

    public function uniqueId(): string
    {
        return 'woocommerce-orders-log:'.$this->syncLogId;
    }

    public function handle(
        WooCommerceImportService $importer,
        WooCommerceImportQueueService $imports,
    ): void {
        $integration = WordpressIntegration::query()->findOrFail($this->integrationId);
        $log = IntegrationSyncLog::query()->findOrFail($this->syncLogId);
        $context = $this->importContext($integration);

        $log->update([
            'status' => 'running',
            'attempts' => $this->attempts(),
            'started_at' => now(),
            'finished_at' => null,
            'error_message' => null,
        ]);

        $stats = $importer->importOrders(
            $integration,
            $context['modified_after'],
            $context['page'],
        );
        $stats['mode'] = $context['backfill'] ? 'backfill' : 'incremental';
        $stats['modified_after'] = $context['modified_after']?->toIso8601String();

        if ($stats['has_more']) {
            $nextPage = (int) $stats['next_page'];

            $integration->saveOrderImportContinuation(
                $context['backfill'],
                $context['modified_after']?->toIso8601String(),
                $nextPage,
            );

            $continuation = $imports->queueOrderImportContinuation(
                $integration,
                $log,
                $context['modified_after']?->toIso8601String(),
                $nextPage,
                $context['backfill'],
            );
            $stats['continuation_log_id'] = $continuation->id;
        } else {
            $integration->clearOrderImportContinuation();
            $integration->update(['last_successful_sync_at' => now()]);
        }

        $log->update([
            'status' => 'success',
            'response_payload' => $stats,
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        IntegrationSyncLog::query()
            ->whereKey($this->syncLogId)
            ->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
    }

    /**
     * @return array{backfill:bool,modified_after:?CarbonImmutable,page:int}
     */
    private function importContext(WordpressIntegration $integration): array
    {
        if ($this->backfill || $this->modifiedAfter !== null || $this->page !== 1) {
            return [
                'backfill' => $this->backfill,
                'modified_after' => $this->dateFromString($this->modifiedAfter),
                'page' => max(1, $this->page),
            ];
        }

        $continuation = $integration->orderImportContinuation();

        if ($continuation !== null) {
            return [
                'backfill' => $continuation['mode'] === 'backfill',
                'modified_after' => $this->dateFromString($continuation['modified_after']),
                'page' => $continuation['next_page'],
            ];
        }

        $lastImport = IntegrationSyncLog::query()
            ->where('wordpress_integration_id', $integration->id)
            ->where('operation', 'import_orders')
            ->where('status', 'success')
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->first();

        if (! $lastImport instanceof IntegrationSyncLog || $lastImport->finished_at === null) {
            return [
                'backfill' => true,
                'modified_after' => null,
                'page' => 1,
            ];
        }

        return [
            'backfill' => false,
            'modified_after' => CarbonImmutable::instance($lastImport->finished_at)
                ->subMinutes($integration->orderImportSettings()['overlap_minutes']),
            'page' => 1,
        ];
    }

    private function dateFromString(?string $value): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse($value) : null;
    }
}
