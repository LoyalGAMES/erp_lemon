<?php

use App\Models\Invoice;
use App\Services\Integrations\WooCommerceImportQueueService;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Invoices\InvoiceTemplateService;
use App\Services\Invoices\OrderInvoiceService;
use App\Services\Inventory\StockSyncQueueService;
use App\Services\Ksef\KsefSubmissionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('erp:queue-woocommerce-imports {--products : Queue product imports} {--orders : Queue order imports} {--all : Queue products and orders}', function (): int {
    $all = (bool) $this->option('all');
    $products = $all || (bool) $this->option('products');
    $orders = $all || (bool) $this->option('orders');

    if (! $products && ! $orders) {
        $orders = true;
    }

    $result = app(WooCommerceImportQueueService::class)->queueEnabledImports(
        products: $products,
        orders: $orders,
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
                $this->error('Nie można zastosować danych sprzedawcy: ' . implode(' ', $sellerStatus['errors']));

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

Schedule::command('erp:queue-woocommerce-imports --orders')
    ->cron('*/5 * * * *')
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('erp:release-stale-woocommerce-imports --minutes=60')
    ->cron('*/10 * * * *')
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
