<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IntegrationSyncLog;
use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Throwable;

final class ImportWooCommerceProductsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Lock contention releases the job and consumes a queue attempt. Keep
    // enough attempts for catalog maintenance while preserving the original
    // two-failure limit for actual importer exceptions.
    public int $tries = 70;

    public int $maxExceptions = 2;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    // Aligned with $uniqueFor and kept comfortably above the longest single run
    // that holds this shared lock (900s import / 840s export). A process killed
    // mid-run therefore frees the catalog lock in ~20 min instead of an hour, so
    // a dead import no longer blocks publishes and relinks for a full hour.
    public const CATALOG_LOCK_SECONDS = 1200;

    public function __construct(
        private readonly int $integrationId,
        private readonly int $syncLogId,
    ) {}

    public function uniqueId(): string
    {
        return 'woocommerce-products-log:'.$this->syncLogId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [self::catalogLock($this->integrationId)];
    }

    public static function catalogLock(int $integrationId): WithoutOverlapping
    {
        return (new WithoutOverlapping(self::catalogLockKey($integrationId)))
            ->releaseAfter(60)
            ->expireAfter(self::CATALOG_LOCK_SECONDS)
            ->withPrefix('')
            ->shared();
    }

    public static function catalogLockKey(int $integrationId): string
    {
        return "woocommerce-catalog-integration:{$integrationId}";
    }

    /** @return Collection<int,int> */
    public static function catalogIntegrationIdsForProduct(int $familyRootId): Collection
    {
        $salesChannelIds = ProductChannelMapping::query()
            ->where('product_id', $familyRootId)
            ->distinct()
            ->pluck('sales_channel_id');

        return WordpressIntegration::query()
            ->whereIn('sales_channel_id', $salesChannelIds)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);
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
