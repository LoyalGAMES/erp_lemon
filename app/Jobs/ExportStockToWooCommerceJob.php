<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StockSyncQueueItem;
use App\Services\WooCommerce\StockSyncExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ExportStockToWooCommerceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        private readonly int $stockSyncQueueItemId,
    ) {
    }

    public function handle(StockSyncExportService $exporter): void
    {
        $item = StockSyncQueueItem::query()->findOrFail($this->stockSyncQueueItemId);

        if ($item->status === 'success') {
            return;
        }

        $item->update([
            'status' => 'running',
            'last_error' => null,
        ]);

        try {
            $exporter->export($item);
        } catch (Throwable $exception) {
            $exporter->markFailed($item, $exception);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $item = StockSyncQueueItem::query()->find($this->stockSyncQueueItemId);

        if ($item !== null) {
            app(StockSyncExportService::class)->markFailed($item, $exception);
        }
    }
}
