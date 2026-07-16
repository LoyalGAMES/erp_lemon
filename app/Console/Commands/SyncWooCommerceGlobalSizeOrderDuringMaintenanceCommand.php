<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncWooCommerceGlobalSizeOrderJob;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceGlobalSizeOrderSyncService;
use Illuminate\Console\Command;
use Throwable;

final class SyncWooCommerceGlobalSizeOrderDuringMaintenanceCommand extends Command
{
    protected $signature = 'erp:sync-woocommerce-global-size-order-during-maintenance
        {--trigger=deploy : Audit trigger stored with the synchronization log}';

    protected $description = 'Synchronize global WooCommerce size order synchronously while deploy maintenance isolates the catalog.';

    public function handle(WooCommerceGlobalSizeOrderSyncService $sync): int
    {
        $trigger = trim((string) $this->option('trigger'));

        if ($trigger === '') {
            $this->error('The synchronization trigger cannot be empty.');

            return self::FAILURE;
        }

        if (! app()->isDownForMaintenance()) {
            $this->error(
                'Synchronous Woo size-order synchronization is allowed only while the application is in maintenance mode.',
            );

            return self::FAILURE;
        }

        $integrations = WordpressIntegration::query()
            ->whereHas('salesChannel', fn ($query) => $query
                ->where('type', 'woocommerce')
                ->where('is_active', true))
            ->orderBy('id')
            ->get(['id']);
        $failures = 0;

        foreach ($integrations as $integration) {
            $job = new SyncWooCommerceGlobalSizeOrderJob(
                (int) $integration->id,
                $trigger,
            );

            try {
                // Intentionally call the handler directly. The deploy script
                // has already enabled maintenance and waited for every old
                // queue worker to exit, so no catalog writer can overlap. An
                // ordinary queued job still uses the shared catalog lock.
                $job->handle($sync);
                $this->line(sprintf(
                    'Woo size-order synchronization completed for integration %d.',
                    (int) $integration->id,
                ));
            } catch (Throwable $exception) {
                $failures++;

                try {
                    $job->failed($exception);
                } catch (Throwable $loggingException) {
                    report($loggingException);
                }

                report($exception);
                $this->error(sprintf(
                    'Woo size-order synchronization failed for integration %d: %s',
                    (int) $integration->id,
                    str($exception->getMessage())->limit(300)->toString(),
                ));
            }
        }

        $this->info(sprintf(
            'Woo size-order deployment synchronization finished: active=%d, succeeded=%d, failed=%d, trigger=%s.',
            $integrations->count(),
            $integrations->count() - $failures,
            $failures,
            $trigger,
        ));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
