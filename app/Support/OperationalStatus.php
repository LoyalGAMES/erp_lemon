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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class OperationalStatus
{
    public function __construct(
        private readonly KsefClient $ksefClient,
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
     *     woocommerce: array{tone:string,label:string},
     *     ksef: array{tone:string,label:string}
     * }
     */
    public function navigation(): array
    {
        return [
            'packing_orders' => $this->packingOrdersToHandle(),
            'return_cases' => $this->returnCasesToHandle(),
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
            ->whereIn('status', ['opened', 'document_created'])
            ->count();
    }

    /**
     * @return array{tone:string,label:string}
     */
    private function woocommerceStatus(): array
    {
        try {
            if (! $this->tableExists('wordpress_integrations')) {
                return ['tone' => 'red', 'label' => 'Brak konfiguracji'];
            }

            $integrations = WordpressIntegration::query()->count();

            if ($integrations === 0) {
                return ['tone' => 'red', 'label' => 'Brak konfiguracji'];
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
                ? $this->statusCounts(StockSyncQueueItem::query())
                : [];
            $failed = ($imports['failed'] ?? 0) + ($stockExports['failed'] ?? 0);
            $active = ($imports['queued'] ?? 0)
                + ($imports['running'] ?? 0)
                + ($stockExports['pending'] ?? 0)
                + ($stockExports['running'] ?? 0);

            if ($failed > 0) {
                return ['tone' => 'red', 'label' => "Błędy: {$failed}"];
            }

            if ($activeIntegrations === 0) {
                return ['tone' => 'orange', 'label' => 'Nieaktywne'];
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
     * @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query
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
