<?php

use App\Models\Invoice;
use App\Services\Communication\UnpaidOrderReminderService;
use App\Services\Integrations\WooCommerceImportQueueService;
use App\Services\Inventory\StockSyncQueueService;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Invoices\InvoiceTemplateService;
use App\Services\Invoices\OrderInvoiceService;
use App\Services\Ksef\KsefSubmissionService;
use App\Services\Payments\PayuRefundService;
use App\Services\Shipping\CourierPickupTrackingService;
use App\Services\Shipping\ShippedOrderWooSyncService;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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

Artisan::command('erp:dispatch-legacy-variant-backfill {--limit=10 : Maximum number of product families to queue} {--stale-minutes=120 : Replace an abandoned export reservation after this many minutes}', function (): int {
    $result = app(LegacyVariantFamilyBackfillService::class)->dispatchPending(
        max(1, (int) $this->option('limit')),
        max(1, (int) $this->option('stale-minutes')),
    );

    $this->info(sprintf(
        'Legacy variant backfill: scanned %d, dispatched %d, active %d, backoff %d, plugin unready %d, failed %d.',
        $result['scanned'],
        $result['dispatched'],
        $result['skipped_active'],
        $result['skipped_backoff'],
        $result['skipped_unready'],
        $result['failed'],
    ));

    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Queue durable full WooCommerce exports for repaired legacy variant families.');

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

// Production uses the database queue by default. Drain it from the same
// scheduler installed during deploy so imports, KSeF and stock jobs do not
// silently wait forever when a separate Supervisor worker is unavailable.
// The database queue remains safe when a dedicated worker is running too.
Schedule::command('queue:work --stop-when-empty --sleep=1 --tries=2 --timeout=900 --max-time=3300')
    ->everyMinute()
    ->withoutOverlapping(60)
    ->runInBackground();
