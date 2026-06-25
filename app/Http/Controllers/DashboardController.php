<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Support\OperationalStatus;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $metrics = $this->dashboardMetrics();

        $warehouseBalances = Warehouse::query()
            ->with(['stockBalances.product', 'routes.salesChannel'])
            ->orderBy('code')
            ->get()
            ->map(fn (Warehouse $warehouse) => [
                'warehouse' => $warehouse,
                'products' => $warehouse->stockBalances->count(),
                'quantity' => $warehouse->stockBalances->sum(fn (StockBalance $balance) => (float) $balance->quantity_on_hand),
                'routes' => $warehouse->routes,
            ]);

        $documents = WarehouseDocument::query()
            ->with(['sourceWarehouse', 'destinationWarehouse'])
            ->latest('document_date')
            ->limit(7)
            ->get();

        $ksef = $this->ksefQueueRows();

        return view('dashboard', compact('metrics', 'warehouseBalances', 'documents', 'ksef'));
    }

    /**
     * @return list<array{0:string,1:string,2:string}>
     */
    private function dashboardMetrics(): array
    {
        try {
            return app(OperationalStatus::class)->dashboardMetrics();
        } catch (Throwable) {
            return [
                ['Zamówienia', '0', 'Metryki chwilowo niedostępne'],
                ['Przychód', '0,00 PLN', 'Metryki chwilowo niedostępne'],
                ['Zwroty', '0', 'Metryki chwilowo niedostępne'],
                ['Procent zwrotów', '0,0%', 'Metryki chwilowo niedostępne'],
            ];
        }
    }

    /**
     * @return list<array{0:string,1:int,2:string}>
     */
    private function ksefQueueRows(): array
    {
        try {
            return app(OperationalStatus::class)->ksefQueueRows();
        } catch (Throwable) {
            return [
                ['Do wysłania', 0, ''],
                ['W trakcie', 0, 'blue'],
                ['Zaakceptowane', 0, 'green'],
                ['Wymaga reakcji', 0, 'red'],
            ];
        }
    }
}
