<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\ProductChannelMapping;
use App\Services\Inventory\ChannelStockAvailabilityService;
use App\Services\Inventory\StockSyncQueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function index(ChannelStockAvailabilityService $channelStock): View
    {
        $stockPreview = ProductChannelMapping::query()
            ->with(['product', 'salesChannel'])
            ->where('stock_sync_enabled', true)
            ->orderBy('sales_channel_id')
            ->latest()
            ->limit(60)
            ->get()
            ->map(function (ProductChannelMapping $mapping) use ($channelStock): array {
                $availability = $channelStock->availabilityForProduct(
                    (int) $mapping->sales_channel_id,
                    (int) $mapping->product_id,
                );

                return [
                    'channel' => $mapping->salesChannel?->code,
                    'sku' => $mapping->product?->sku,
                    'product_name' => $mapping->product?->name,
                    'quantity' => $availability['quantity'],
                    'breakdown' => $availability['breakdown'],
                ];
            });

        return view('warehouses.index', [
            'warehouses' => Warehouse::query()
                ->with(['routes.salesChannel', 'stockBalances.product'])
                ->orderBy('code')
                ->get(),
            'salesChannels' => SalesChannel::query()->where('is_active', true)->orderBy('code')->get(),
            'stockPreview' => $stockPreview,
            'module' => 'warehouses',
        ]);
    }

    public function edit(Warehouse $warehouse): View
    {
        return view('warehouses.edit', [
            'warehouse' => $warehouse->load('routes.salesChannel'),
            'salesChannels' => SalesChannel::query()->where('is_active', true)->orderBy('code')->get(),
            'title' => 'Edycja magazynu',
            'subtitle' => $warehouse->code . ' - ' . $warehouse->name,
            'module' => 'warehouses',
        ]);
    }

    public function update(Request $request, Warehouse $warehouse, StockSyncQueueService $stockSyncQueue): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:warehouses,code,' . $warehouse->id],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:40'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sales_channel_ids' => ['array'],
            'sales_channel_ids.*' => ['integer', 'exists:sales_channels,id'],
        ]);

        $beforeSalesChannelIds = $warehouse->routes()
            ->pluck('sales_channel_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $afterSalesChannelIds = collect($validated['sales_channel_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $queuedStockSyncs = 0;

        DB::transaction(function () use ($request, $warehouse, $validated, $beforeSalesChannelIds, $afterSalesChannelIds, $stockSyncQueue, &$queuedStockSyncs): void {
            $warehouse->update([
                'code' => Str::upper($validated['code']),
                'name' => $validated['name'],
                'type' => $validated['type'],
                'allow_negative_stock' => $request->boolean('allow_negative_stock'),
                'is_active' => $request->boolean('is_active', true),
            ]);

            $warehouse->routes()->delete();

            foreach ($afterSalesChannelIds as $channelId) {
                $warehouse->routes()->create([
                    'sales_channel_id' => $channelId,
                    'push_stock' => true,
                    'allocation_strategy' => 'warehouse_balance',
                    'stock_buffer' => 0,
                    'priority' => 100,
                ]);
            }

            $queuedStockSyncs = $stockSyncQueue->queueForWarehouseRouteChange(
                $warehouse->refresh(),
                $beforeSalesChannelIds,
                $afterSalesChannelIds,
            );
        });

        $status = "Konfiguracja magazynu {$warehouse->code} została zapisana.";

        if ($queuedStockSyncs > 0) {
            $status .= " Dodano {$queuedStockSyncs} aktualizacji stanów do kolejki.";
        }

        return back()->with('status', $status);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:warehouses,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:40'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'sales_channel_ids' => ['array'],
            'sales_channel_ids.*' => ['integer', 'exists:sales_channels,id'],
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $warehouse = Warehouse::query()->create([
                'code' => Str::upper($validated['code']),
                'name' => $validated['name'],
                'type' => $validated['type'],
                'allow_negative_stock' => $request->boolean('allow_negative_stock'),
                'is_active' => true,
            ]);

            foreach ($request->input('sales_channel_ids', []) as $channelId) {
                $warehouse->routes()->create([
                    'sales_channel_id' => $channelId,
                    'push_stock' => true,
                    'allocation_strategy' => 'warehouse_balance',
                    'stock_buffer' => 0,
                    'priority' => 100,
                ]);
            }
        });

        return back()->with('status', 'Magazyn został dodany.');
    }

    public function updateRoutes(Request $request, Warehouse $warehouse, StockSyncQueueService $stockSyncQueue): RedirectResponse
    {
        $validated = $request->validate([
            'sales_channel_ids' => ['array'],
            'sales_channel_ids.*' => ['integer', 'exists:sales_channels,id'],
        ]);

        $beforeSalesChannelIds = $warehouse->routes()
            ->pluck('sales_channel_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $afterSalesChannelIds = collect($validated['sales_channel_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $queuedStockSyncs = 0;

        DB::transaction(function () use ($warehouse, $afterSalesChannelIds, $beforeSalesChannelIds, $stockSyncQueue, &$queuedStockSyncs): void {
            $warehouse->routes()->delete();

            foreach ($afterSalesChannelIds as $channelId) {
                $warehouse->routes()->create([
                    'sales_channel_id' => $channelId,
                    'push_stock' => true,
                    'allocation_strategy' => 'warehouse_balance',
                    'stock_buffer' => 0,
                    'priority' => 100,
                ]);
            }

            $queuedStockSyncs = $stockSyncQueue->queueForWarehouseRouteChange(
                $warehouse->refresh(),
                $beforeSalesChannelIds,
                $afterSalesChannelIds,
            );
        });

        $status = 'Routing magazynu został zapisany.';

        if ($queuedStockSyncs > 0) {
            $status .= " Dodano {$queuedStockSyncs} aktualizacji stanów do kolejki.";
        }

        return back()->with('status', $status);
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        $hasStock = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where(function ($query): void {
                $query->where('quantity_on_hand', '!=', 0)
                    ->orWhere('quantity_reserved', '!=', 0);
            })
            ->exists();

        if ($hasStock) {
            return back()->with('error', 'Nie można usunąć magazynu ze stanem.');
        }

        $warehouse->delete();

        return back()->with('status', 'Magazyn został usunięty.');
    }
}
