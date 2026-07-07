<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Enums\WarehouseDocumentType;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Services\Audit\AuditLogService;
use App\Services\Inventory\WarehouseDocumentNumberService;
use App\Services\Inventory\WarehouseDocumentSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehouseDocumentCreateController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->documentFilters($request);

        return view('documents.index', [
            'documents' => $this->documents($filters),
            'filters' => $filters,
            'types' => WarehouseDocumentType::values(),
            'statuses' => ['draft', 'posted', 'cancelled'],
            'warehouses' => Warehouse::query()->orderBy('code')->get(),
            'module' => 'documents',
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->documentFilters($request);
        $filename = 'dokumenty-magazynowe-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($filters): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Numer',
                'Typ',
                'Status',
                'Data dokumentu',
                'Zaksięgowano',
                'Anulowano',
                'Magazyn źródłowy',
                'Magazyn docelowy',
                'Referencja',
                'SKU',
                'Produkt',
                'EAN',
                'Ilość',
                'JM',
                'Cena zakupu brutto',
                'Lokalizacja',
                'Notatka dokumentu',
            ], ';');

            $this->documentQuery($filters)
                ->chunk(200, function (Collection $documents) use ($handle): void {
                    foreach ($documents as $document) {
                        foreach ($document->lines as $line) {
                            fputcsv($handle, [
                                $document->number,
                                $document->type,
                                $document->status,
                                $document->document_date?->format('Y-m-d H:i') ?? '',
                                $document->posted_at?->format('Y-m-d H:i') ?? '',
                                $document->cancelled_at?->format('Y-m-d H:i') ?? '',
                                $document->sourceWarehouse?->code ?? '',
                                $document->destinationWarehouse?->code ?? '',
                                $document->external_reference ?? '',
                                $line->product?->sku ?? '',
                                $line->product?->name ?? '',
                                $line->product?->ean ?? '',
                                $this->csvDecimal($line->quantity, 4),
                                $line->product?->unit ?? 'szt',
                                $line->unit_gross_price !== null ? $this->csvDecimal($line->unit_gross_price, 2) : '',
                                data_get($line->metadata, 'location') ?: '',
                                $document->notes ?? '',
                            ], ';');
                        }
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function create(WarehouseDocumentSettingsService $settings): View
    {
        $products = Product::query()
            ->with('stockBalances')
            ->orderBy('sku')
            ->get();

        return view('documents.create', [
            'products' => $products,
            'productStock' => $this->productStockPayload($products),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
            'types' => WarehouseDocumentType::values(),
            'typeLabels' => WarehouseDocumentType::labels(),
            'typeHelpTexts' => WarehouseDocumentType::helpTexts(),
            'sourceTypes' => WarehouseDocumentType::sourceWarehouseValues(),
            'destinationTypes' => WarehouseDocumentType::destinationWarehouseValues(),
            'locations' => $settings->locations(),
            'title' => 'Utwórz dokument magazynowy',
            'subtitle' => 'Najpierw wybierz typ dokumentu, potem magazyn i pozycje. Produkty dodajesz przez szybką wyszukiwarkę.',
            'module' => 'documents',
        ]);
    }

    public function store(
        Request $request,
        WarehouseDocumentNumberService $numbers,
        AuditLogService $audit,
    ): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(WarehouseDocumentType::values())],
            'document_date' => ['nullable', 'date'],
            'source_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'quantity' => ['nullable', 'numeric'],
            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*.product_id' => ['nullable', 'required_with:lines.*.quantity', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['nullable', 'required_with:lines.*.product_id', 'numeric'],
            'lines.*.unit_gross_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.location' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $documentType = WarehouseDocumentType::from($validated['type']);
        $type = $documentType->value;
        $documentDate = CarbonImmutable::parse((string) ($validated['document_date'] ?? now()->toDateString()))->startOfDay();
        $lines = $this->documentLines($validated, $documentType);

        if ($lines === []) {
            return back()->withInput()->with('error', 'Dokument musi mieć co najmniej jedną pozycję z produktem i poprawną ilością. Dla KOR ilość może być dodatnia albo ujemna, ale nie może wynosić zero.');
        }

        $warehouseError = $documentType->warehouseTopologyError(
            isset($validated['source_warehouse_id']) ? (int) $validated['source_warehouse_id'] : null,
            isset($validated['destination_warehouse_id']) ? (int) $validated['destination_warehouse_id'] : null,
        );

        if ($warehouseError !== null) {
            return back()->withInput()->with('error', $warehouseError);
        }

        if (! $documentType->requiresSourceWarehouse()) {
            $validated['source_warehouse_id'] = null;
        }

        if (! $documentType->requiresDestinationWarehouse()) {
            $validated['destination_warehouse_id'] = null;
        }

        $document = DB::transaction(function () use ($validated, $type, $numbers, $lines, $documentDate): WarehouseDocument {
            $document = WarehouseDocument::query()->create([
                'number' => $numbers->next($type, $documentDate),
                'type' => $type,
                'status' => 'draft',
                'source_warehouse_id' => $validated['source_warehouse_id'] ?? null,
                'destination_warehouse_id' => $validated['destination_warehouse_id'] ?? null,
                'document_date' => $documentDate,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                $document->lines()->create($line);
            }

            return $document;
        });

        $document->load(['lines.product', 'sourceWarehouse', 'destinationWarehouse']);
        $audit->record(
            'warehouse_document.created',
            $document,
            null,
            [
                'number' => $document->number,
                'type' => $document->type,
                'status' => $document->status,
                'source_warehouse' => $document->sourceWarehouse?->code,
                'destination_warehouse' => $document->destinationWarehouse?->code,
                'lines' => $document->lines->map(fn ($line): array => [
                    'sku' => $line->product?->sku,
                    'quantity' => (string) $line->quantity,
                    'unit_gross_price' => $line->unit_gross_price !== null ? (string) $line->unit_gross_price : null,
                    'location' => data_get($line->metadata, 'location'),
                ])->values()->all(),
            ],
            [
                'source' => 'manual_document_form',
            ],
        );

        return redirect()
            ->route('documents.show', $document)
            ->with('status', "Dokument {$document->number} został utworzony jako szkic.");
    }

    /**
     * @param array<string, mixed> $validated
     * @return list<array{product_id:int,quantity:float,unit_gross_price:?float,notes:?string,metadata:?array<string,string>}>
     */
    private function documentLines(array $validated, WarehouseDocumentType $documentType): array
    {
        $lines = collect($validated['lines'] ?? [])
            ->filter(fn (array $line): bool => ! empty($line['product_id']) || ! empty($line['quantity']))
            ->map(function (array $line): array {
                $location = trim((string) ($line['location'] ?? ''));

                return [
                    'product_id' => isset($line['product_id']) ? (int) $line['product_id'] : 0,
                    'quantity' => isset($line['quantity']) ? (float) $line['quantity'] : 0,
                    'unit_gross_price' => isset($line['unit_gross_price']) && $line['unit_gross_price'] !== ''
                        ? (float) $line['unit_gross_price']
                        : null,
                    'notes' => null,
                    'metadata' => $location !== '' ? ['location' => $location] : null,
                ];
            })
            ->filter(fn (array $line): bool => $line['product_id'] > 0 && $this->quantityAllowedForType((float) $line['quantity'], $documentType))
            ->values()
            ->all();

        if ($lines !== []) {
            return $lines;
        }

        if (! empty($validated['product_id']) && isset($validated['quantity']) && $this->quantityAllowedForType((float) $validated['quantity'], $documentType)) {
            return [[
                'product_id' => (int) $validated['product_id'],
                'quantity' => (float) $validated['quantity'],
                'unit_gross_price' => null,
                'notes' => null,
                'metadata' => null,
            ]];
        }

        return [];
    }

    private function quantityAllowedForType(float $quantity, WarehouseDocumentType $documentType): bool
    {
        if ($documentType === WarehouseDocumentType::KOR) {
            return abs($quantity) > 0.0000001;
        }

        return $quantity > 0;
    }

    /**
     * @param Collection<int, Product> $products
     * @return array<int, array<int, int>>
     */
    private function productStockPayload(Collection $products): array
    {
        return $products
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => $product->stockBalances
                    ->mapWithKeys(fn ($balance): array => [
                        (int) $balance->warehouse_id => (int) round((float) $balance->quantity_on_hand),
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array{q:string,type:string,status:string,warehouse:string}
     */
    private function documentFilters(Request $request): array
    {
        $type = (string) ($request->query('type') ?? '');
        $status = (string) ($request->query('status') ?? '');
        $warehouseId = (string) ($request->query('warehouse') ?? '');

        return [
            'q' => trim((string) ($request->query('q') ?? '')),
            'type' => in_array($type, WarehouseDocumentType::values(), true) ? $type : '',
            'status' => in_array($status, ['draft', 'posted', 'cancelled'], true) ? $status : '',
            'warehouse' => ctype_digit($warehouseId) && Warehouse::query()->whereKey((int) $warehouseId)->exists()
                ? $warehouseId
                : '',
        ];
    }

    /**
     * @param array{q:string,type:string,status:string,warehouse:string} $filters
     * @return LengthAwarePaginator<int, WarehouseDocument>
     */
    private function documents(array $filters): LengthAwarePaginator
    {
        return $this->documentQuery($filters)->paginate(25)->withQueryString();
    }

    /**
     * @param array{q:string,type:string,status:string,warehouse:string} $filters
     * @return \Illuminate\Database\Eloquent\Builder<WarehouseDocument>
     */
    private function documentQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = WarehouseDocument::query()
            ->with(['sourceWarehouse', 'destinationWarehouse', 'lines.product'])
            ->latest('document_date')
            ->latest('id');

        if ($filters['type'] !== '') {
            $query->where('type', $filters['type']);
        }

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if ($filters['warehouse'] !== '') {
            $warehouseId = (int) $filters['warehouse'];
            $query->where(function ($query) use ($warehouseId): void {
                $query->where('source_warehouse_id', $warehouseId)
                    ->orWhere('destination_warehouse_id', $warehouseId);
            });
        }

        if ($filters['q'] !== '') {
            $needle = mb_strtolower($filters['q']);
            $query->where(function ($query) use ($needle): void {
                $query->whereRaw('LOWER(number) LIKE ?', ['%' . $needle . '%'])
                    ->orWhereRaw("LOWER(COALESCE(external_reference, '')) LIKE ?", ['%' . $needle . '%'])
                    ->orWhereRaw("LOWER(COALESCE(notes, '')) LIKE ?", ['%' . $needle . '%'])
                    ->orWhereHas('lines.product', function ($query) use ($needle): void {
                        $query->whereRaw('LOWER(sku) LIKE ?', ['%' . $needle . '%'])
                            ->orWhereRaw('LOWER(name) LIKE ?', ['%' . $needle . '%'])
                            ->orWhereRaw("LOWER(COALESCE(ean, '')) LIKE ?", ['%' . $needle . '%']);
                });
            });
        }

        return $query;
    }

    private function csvDecimal(mixed $value, int $precision): string
    {
        return number_format((float) $value, $precision, ',', '');
    }
}
