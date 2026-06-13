<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\KsefSubmission;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockSyncQueueItem;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $metrics = [
            ['Produkty', Product::query()->count(), 'Aktywne SKU w katalogu'],
            ['Magazyny', Warehouse::query()->count(), 'Magazyny operacyjne'],
            ['Synchronizacje', StockSyncQueueItem::query()->where('status', 'pending')->count(), 'W kolejce WooCommerce'],
            ['Faktury', Invoice::query()->count(), 'Dokumenty sprzedaży'],
        ];

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

        $ksef = [
            ['Do wysłania', KsefSubmission::query()->whereIn('status', ['pending', 'queued'])->count(), ''],
            ['W trakcie', KsefSubmission::query()->whereIn('status', ['running', 'submitted'])->count(), 'blue'],
            ['Zaakceptowane', KsefSubmission::query()->where('status', 'accepted')->count(), 'green'],
            ['Do konfiguracji', KsefSubmission::query()->whereIn('status', ['missing_configuration', 'requires_configuration'])->count(), 'red'],
        ];

        return view('dashboard', compact('metrics', 'warehouseBalances', 'documents', 'ksef'));
    }
}
