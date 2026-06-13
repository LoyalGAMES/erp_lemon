<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LedgerController extends Controller
{
    public function index(Request $request): View
    {
        $query = $this->filteredQuery($request);
        $entries = (clone $query)
            ->latest('posted_at')
            ->limit(500)
            ->get();

        return view('ledger.index', [
            'entries' => $entries,
            'summary' => $this->summary(clone $query),
            'filters' => $request->only([
                'warehouse_id',
                'product_id',
                'document_type',
                'direction',
                'date_from',
                'date_to',
                'q',
            ]),
            'warehouses' => Warehouse::query()->orderBy('code')->get(),
            'products' => Product::query()->orderBy('sku')->limit(1000)->get(),
            'documentTypes' => ['PZ', 'WZ', 'RW', 'RX', 'PW', 'MM', 'ZW', 'KOR'],
            'module' => 'ledger',
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'ledger-' . now()->format('Ymd-His') . '.csv';
        $query = $this->filteredQuery($request)->latest('posted_at');

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'posted_at',
                'document_number',
                'document_type',
                'warehouse',
                'sku',
                'product_name',
                'quantity_change',
                'direction',
            ], ';');

            $query->chunk(500, function (Collection $entries) use ($handle): void {
                foreach ($entries as $entry) {
                    fputcsv($handle, [
                        $entry->posted_at?->format('Y-m-d H:i:s'),
                        $entry->document?->number,
                        $entry->document?->type,
                        $entry->warehouse?->code,
                        $entry->product?->sku,
                        $entry->product?->name,
                        number_format((float) $entry->quantity_change, 4, '.', ''),
                        $entry->direction,
                    ], ';');
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function filteredQuery(Request $request): Builder
    {
        return StockLedgerEntry::query()
            ->with(['document', 'warehouse', 'product'])
            ->when($request->filled('warehouse_id'), fn (Builder $query) => $query
                ->where('warehouse_id', (int) $request->input('warehouse_id')))
            ->when($request->filled('product_id'), fn (Builder $query) => $query
                ->where('product_id', (int) $request->input('product_id')))
            ->when($request->filled('direction'), fn (Builder $query) => $query
                ->where('direction', $request->input('direction')))
            ->when($request->filled('date_from'), fn (Builder $query) => $query
                ->where('posted_at', '>=', $request->date('date_from')?->startOfDay()))
            ->when($request->filled('date_to'), fn (Builder $query) => $query
                ->where('posted_at', '<=', $request->date('date_to')?->endOfDay()))
            ->when($request->filled('document_type'), fn (Builder $query) => $query
                ->whereHas('document', fn (Builder $documentQuery) => $documentQuery
                    ->where('type', $request->input('document_type'))))
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->input('q')) . '%';

                $query->where(function (Builder $nested) use ($term): void {
                    $nested
                        ->whereHas('product', fn (Builder $productQuery) => $productQuery
                            ->where('sku', 'like', $term)
                            ->orWhere('name', 'like', $term))
                        ->orWhereHas('document', fn (Builder $documentQuery) => $documentQuery
                            ->where('number', 'like', $term)
                            ->orWhere('external_reference', 'like', $term));
                });
            });
    }

    /**
     * @return array{incoming:float,outgoing:float,net:float,count:int}
     */
    private function summary(Builder $query): array
    {
        $entries = $query->get(['quantity_change']);
        $incoming = $entries->sum(fn (StockLedgerEntry $entry): float => max(0, (float) $entry->quantity_change));
        $outgoing = abs($entries->sum(fn (StockLedgerEntry $entry): float => min(0, (float) $entry->quantity_change)));

        return [
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'net' => $incoming - $outgoing,
            'count' => $entries->count(),
        ];
    }
}
