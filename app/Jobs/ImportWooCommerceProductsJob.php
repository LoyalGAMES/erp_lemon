<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IntegrationSyncLog;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ImportWooCommerceProductsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        private readonly int $integrationId,
        private readonly int $syncLogId,
    ) {
    }

    public function handle(WooCommerceImportService $importer): void
    {
        $integration = WordpressIntegration::query()->findOrFail($this->integrationId);
        $log = IntegrationSyncLog::query()->findOrFail($this->syncLogId);

        $log->update([
            'status' => 'running',
            'attempts' => $this->attempts(),
            'started_at' => now(),
            'finished_at' => null,
            'error_message' => null,
        ]);

        $stats = $importer->importProducts($integration);

        $integration->update(['last_successful_sync_at' => now()]);
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
}
