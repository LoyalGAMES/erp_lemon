<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Support\OperationalStatus;

class DashboardController extends Controller
{
    public function __invoke(OperationalStatus $status)
    {
        $metrics = $status->dashboardMetrics();

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

        $ksef = $status->ksefQueueRows();

        return view('dashboard', compact('metrics', 'warehouseBalances', 'documents', 'ksef'));
    }
}
