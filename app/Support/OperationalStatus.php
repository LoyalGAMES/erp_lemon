<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExternalOrder;
use App\Models\IntegrationSyncLog;
use App\Models\KsefSubmission;
use App\Models\PackingTask;
use App\Models\ReturnCase;
use App\Models\StockSyncQueueItem;
use App\Models\WordpressIntegration;
use App\Services\Ksef\KsefClient;
use App\Services\Returns\ReturnSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class OperationalStatus
{
    public function __construct(
        private readonly KsefClient $ksefClient,
        private readonly ReturnSettingsService $returnSettings,
    ) {}

    /**
     * @return list<array{0:string,1:string,2:string}>
     */
    public function dashboardMetrics(): array
    {
        $ordersCount = $this->tableExists('external_orders')
            ? ExternalOrder::query()->count()
            : 0;
        $returnsCount = $this->tableExists('return_cases')
            ? ReturnCase::query()->count()
            : 0;
        $currencyTotals = $this->tableExists('external_orders')
            ? ExternalOrder::query()
                ->select('currency', DB::raw('SUM(total_gross) as total'))
                ->groupBy('currency')
                ->pluck('total', 'currency')
                ->map(fn ($total): float => (float) $total)
            : collect();
        $revenue = $currencyTotals->sum();
        $currency = $currencyTotals->count() === 1 ? (string) $currencyTotals->keys()->first() : 'PLN';
        $returnRate = $ordersCount > 0 ? ($returnsCount / $ordersCount) * 100 : 0;

        return [
            ['Zamówienia', number_format($ordersCount, 0, ',', ' '), 'Wszystkie zamówienia z kanałów sprzedaży'],
            ['Przychód', $this->money($revenue, $currency), $currencyTotals->count() > 1 ? 'Suma brutto zamówień w różnych walutach' : 'Suma brutto zamówień'],
            ['Zwroty', number_format($returnsCount, 0, ',', ' '), 'Zarejestrowane sprawy zwrotów'],
            ['Procent zwrotów', number_format($returnRate, 1, ',', ' ').'%', 'Zwroty względem liczby zamówień'],
        ];
    }

    /**
     * @return array{
     *     packing_orders: int,
     *     return_cases: int,
     *     store_returns: array{tone:string,label:string},
     *     woocommerce: array{tone:string,label:string,destination:string},
     *     ksef: array{tone:string,label:string}
     * }
     */
    public function navigation(): array
    {
        return [
            'packing_orders' => $this->packingOrdersToHandle(),
            'return_cases' => $this->returnCasesToHandle(),
            'store_returns' => $this->storeReturnsStatus(),
            'woocommerce' => $this->woocommerceStatus(),
            'ksef' => $this->ksefStatus(),
        ];
    }

    /**
     * @return list<array{0:string,1:int,2:string}>
     */
    public function ksefQueueRows(): array
    {
        if (! $this->tableExists('ksef_submissions')) {
            return [
                ['Do wysłania', 0, ''],
                ['W trakcie', 0, 'blue'],
                ['Zaakceptowane', 0, 'green'],
                ['Wymaga reakcji', 0, 'red'],
            ];
        }

        return [
            ['Do wysłania', KsefSubmission::query()->whereIn('status', ['pending', 'queued'])->count(), ''],
            ['W trakcie', KsefSubmission::query()->whereIn('status', ['running', 'submitted'])->count(), 'blue'],
            ['Zaakceptowane', KsefSubmission::query()->where('status', 'accepted')->count(), 'green'],
            ['Wymaga reakcji', KsefSubmission::query()->whereIn('status', ['missing_configuration', 'requires_configuration', 'failed', 'rejected'])->count(), 'red'],
        ];
    }

    private function packingOrdersToHandle(): int
    {
        if (! $this->tableExists('packing_tasks')) {
            return 0;
        }

        return (int) PackingTask::query()
            ->whereIn('status', ['open', 'picked', 'problem'])
            ->distinct()
            ->count('external_order_id');
    }

    private function returnCasesToHandle(): int
    {
        if (! $this->tableExists('return_cases')) {
            return 0;
        }

        return ReturnCase::query()
            ->whereIn('status', ['pending', 'opened', 'document_created'])
            ->count();
    }

    /**
     * @return array{tone:string,label:string}
     */
    private function storeReturnsStatus(): array
    {
        try {
            $settings = $this->returnSettings->data();

            if (blank($settings['store_api_token'] ?? null)) {
                return ['tone' => 'red', 'label' => 'API nieaktywne'];
            }

            return ['tone' => 'green', 'label' => 'API OK'];
        } catch (Throwable) {
            return ['tone' => 'red', 'label' => 'Błąd statusu API'];
        }
    }

    /**
     * @return array{tone:string,label:string,destination:string}
     */
    private function woocommerceStatus(): array
    {
        try {
            if (! $this->tableExists('wordpress_integrations')) {
                return ['tone' => 'red', 'label' => 'Brak konfiguracji', 'destination' => 'integrations'];
            }

            $integrations = WordpressIntegration::query()->count();

            if ($integrations === 0) {
                return ['tone' => 'red', 'label' => 'Brak konfiguracji', 'destination' => 'integrations'];
            }

            $activeIntegrations = WordpressIntegration::query()
                ->where(function ($query): void {
                    $query
                        ->where('order_import_enabled', true)
                        ->orWhere('stock_export_enabled', true)
                        ->orWhere('invoice_upload_enabled', true);
                })
                ->count();
            $imports = $this->latestImportStatusCounts();
            $stockExports = $this->tableExists('stock_sync_queue_items')
                && $this->tableExists('stock_sync_states')
                ? $this->statusCounts(StockSyncQueueItem::query()->currentState())
                : [];
            $failedImports = $imports['failed'] ?? 0;
            $failedStockExports = $stockExports['failed'] ?? 0;
            $failed = $failedImports + $failedStockExports;
            $activeImports = ($imports['queued'] ?? 0) + ($imports['running'] ?? 0);
            $activeStockExports = ($stockExports['pending'] ?? 0)
                + ($stockExports['queued'] ?? 0)
                + ($stockExports['running'] ?? 0);
            $active = $activeImports + $activeStockExports;

            if ($failed > 0) {
                $label = match (true) {
                    $failedImports > 0 && $failedStockExports === 0 => "Importy: {$failedImports}",
                    $failedStockExports > 0 && $failedImports === 0 => "Eksport stanów: {$failedStockExports}",
                    default => "Błędy: {$failed}",
                };

                return [
                    'tone' => 'red',
                    'label' => $label,
                    'destination' => $failedStockExports > 0 ? 'sync' : 'integration_logs',
                ];
            }

            if ($activeIntegrations === 0) {
                return ['tone' => 'orange', 'label' => 'Nieaktywne', 'destination' => 'integrations'];
            }

            if ($active > 0) {
                $label = match (true) {
                    $activeImports > 0 && $activeStockExports === 0 => "Importy w kolejce: {$activeImports}",
                    $activeStockExports > 0 && $activeImports === 0 => "Eksport stanów: {$activeStockExports}",
                    default => "Kolejka: {$active}",
                };

                return [
                    'tone' => 'orange',
                    'label' => $label,
                    'destination' => $activeStockExports > 0 ? 'sync' : 'integration_logs',
                ];
            }

            return ['tone' => 'green', 'label' => 'OK', 'destination' => 'integrations'];
        } catch (Throwable) {
            return ['tone' => 'red', 'label' => 'Błąd statusu', 'destination' => 'integrations'];
        }
    }

    /**
     * @return array{tone:string,label:string}
     */
    private function ksefStatus(): array
    {
        try {
            $configuration = $this->ksefClient->configurationStatus();
            $failed = $this->tableExists('ksef_submissions')
                ? KsefSubmission::query()->whereIn('status', ['failed', 'rejected'])->count()
                : 0;
            $configurationIssues = $this->tableExists('ksef_submissions')
                ? KsefSubmission::query()
                    ->whereIn('status', ['missing_configuration', 'requires_configuration'])
                    ->count()
                : 0;
            $active = $this->tableExists('ksef_submissions')
                ? KsefSubmission::query()
                    ->whereIn('status', ['pending', 'queued', 'running', 'submitted'])
                    ->count()
                : 0;

            if (! (bool) ($configuration['direct_online_send_ready'] ?? false)) {
                return ['tone' => 'red', 'label' => 'Brak konfiguracji'];
            }

            if ($failed + $configurationIssues > 0) {
                $count = $failed + $configurationIssues;

                return ['tone' => 'red', 'label' => "Błędy: {$count}"];
            }

            if ($active > 0) {
                return ['tone' => 'orange', 'label' => "Kolejka: {$active}"];
            }

            return ['tone' => 'green', 'label' => 'OK'];
        } catch (Throwable) {
            return ['tone' => 'red', 'label' => 'Błąd statusu'];
        }
    }

    /**
     * @return array<string, int>
     */
    private function latestImportStatusCounts(): array
    {
        if (! $this->tableExists('integration_sync_logs')) {
            return [];
        }

        $latestImportIds = IntegrationSyncLog::query()
            ->whereIn('operation', ['import_products', 'import_orders'])
            ->whereNotNull('wordpress_integration_id')
            ->select(DB::raw('max(id) as id'))
            ->groupBy('wordpress_integration_id', 'operation')
            ->pluck('id')
            ->all();

        if ($latestImportIds === []) {
            return [];
        }

        return $this->statusCounts(IntegrationSyncLog::query()->whereIn('id', $latestImportIds));
    }

    /**
     * @param  Builder<Model>  $query
     * @return array<string, int>
     */
    private function statusCounts($query): array
    {
        return $query
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    private function money(float $value, string $currency): string
    {
        return number_format($value, 2, ',', ' ').' '.($currency !== '' ? $currency : 'PLN');
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
