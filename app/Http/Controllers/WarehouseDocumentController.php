<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Enums\WarehouseDocumentType;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Services\Audit\AuditLogService;
use App\Services\Inventory\WarehouseDocumentSettingsService;
use App\Services\Inventory\WarehouseDocumentPostingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class WarehouseDocumentController extends Controller
{
    public function show(WarehouseDocument $document): View
    {
        return view('documents.show', [
            'document' => $this->loadDocument($document),
            'title' => 'Dokument ' . $document->number,
            'subtitle' => 'Szczegóły dokumentu magazynowego, pozycje i ruchy ledger po zaksięgowaniu.',
            'module' => 'documents',
        ]);
    }

    public function edit(
        WarehouseDocument $document,
        WarehouseDocumentSettingsService $settings,
    ): View|RedirectResponse
    {
        if ($document->status !== 'draft') {
            return redirect()
                ->route('documents.show', $document)
                ->with('error', 'Edytować można tylko dokument w statusie szkic.');
        }

        $products = Product::query()
            ->with('stockBalances')
            ->orderBy('sku')
            ->get();

        return view('documents.edit', [
            'document' => $document->load(['lines.product', 'sourceWarehouse', 'destinationWarehouse']),
            'products' => $products,
            'productStock' => $this->productStockPayload($products),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
            'typeLabels' => WarehouseDocumentType::labels(),
            'typeHelpTexts' => WarehouseDocumentType::helpTexts(),
            'sourceTypes' => WarehouseDocumentType::sourceWarehouseValues(),
            'destinationTypes' => WarehouseDocumentType::destinationWarehouseValues(),
            'locations' => $settings->locations(),
            'title' => 'Edycja dokumentu ' . $document->number,
            'subtitle' => 'Popraw magazyny, pozycje i notatki przed księgowaniem. Po zaksięgowaniu dokument nie będzie edytowalny.',
            'module' => 'documents',
        ]);
    }

    public function update(
        Request $request,
        WarehouseDocument $document,
        AuditLogService $audit,
    ): RedirectResponse {
        if ($document->status !== 'draft') {
            return back()->with('error', 'Edytować można tylko dokument w statusie szkic.');
        }

        $validated = $request->validate([
            'document_date' => ['nullable', 'date'],
            'source_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_gross_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.location' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $lines = $this->documentLines($validated);

        if ($lines === []) {
            return back()->withInput()->with('error', 'Dokument musi mieć co najmniej jedną pozycję z produktem i ilością.');
        }

        $documentType = WarehouseDocumentType::from($document->type);

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

        $beforeDocument = $document->load(['lines.product', 'sourceWarehouse', 'destinationWarehouse']);
        $before = [
            'number' => $beforeDocument->number,
            'type' => $beforeDocument->type,
            'source_warehouse' => $beforeDocument->sourceWarehouse?->code,
            'destination_warehouse' => $beforeDocument->destinationWarehouse?->code,
            'notes' => $beforeDocument->notes,
            'lines' => $beforeDocument->lines->map(fn ($line): array => [
                'sku' => $line->product?->sku,
                'quantity' => (string) $line->quantity,
                'unit_gross_price' => $line->unit_gross_price !== null ? (string) $line->unit_gross_price : null,
                'location' => data_get($line->metadata, 'location'),
            ])->values()->all(),
        ];
        $documentDate = CarbonImmutable::parse((string) ($validated['document_date'] ?? $document->document_date?->toDateString() ?? now()->toDateString()))->startOfDay();

        DB::transaction(function () use ($document, $validated, $lines, $documentDate): void {
            $document->update([
                'source_warehouse_id' => $validated['source_warehouse_id'] ?? null,
                'destination_warehouse_id' => $validated['destination_warehouse_id'] ?? null,
                'document_date' => $documentDate,
                'notes' => $validated['notes'] ?? null,
            ]);

            $document->lines()->delete();

            foreach ($lines as $line) {
                $document->lines()->create($line);
            }
        });

        $document->load(['lines.product', 'sourceWarehouse', 'destinationWarehouse']);
        $audit->record(
            'warehouse_document.updated',
            $document,
            $before,
            [
                'number' => $document->number,
                'type' => $document->type,
                'source_warehouse' => $document->sourceWarehouse?->code,
                'destination_warehouse' => $document->destinationWarehouse?->code,
                'notes' => $document->notes,
                'lines' => $document->lines->map(fn ($line): array => [
                    'sku' => $line->product?->sku,
                    'quantity' => (string) $line->quantity,
                    'unit_gross_price' => $line->unit_gross_price !== null ? (string) $line->unit_gross_price : null,
                    'location' => data_get($line->metadata, 'location'),
                ])->values()->all(),
            ],
        );

        return redirect()
            ->route('documents.show', $document)
            ->with('status', "Szkic dokumentu {$document->number} został zaktualizowany.");
    }

    public function printView(WarehouseDocument $document): View
    {
        return view('documents.print', [
            'document' => $this->loadDocument($document),
        ]);
    }

    public function post(
        WarehouseDocument $document,
        WarehouseDocumentPostingService $postingService,
        AuditLogService $audit,
    ): RedirectResponse
    {
        try {
            $postingService->post($document);
        } catch (RuntimeException $exception) {
            $audit->record(
                'warehouse_document.post_failed',
                $document,
                ['status' => $document->status],
                null,
                ['error' => $exception->getMessage()],
            );

            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Dokument {$document->number} został zaksięgowany.");
    }

    public function cancel(
        WarehouseDocument $document,
        WarehouseDocumentPostingService $postingService,
        AuditLogService $audit,
    ): RedirectResponse
    {
        try {
            $postingService->cancel($document);
        } catch (RuntimeException $exception) {
            $audit->record(
                'warehouse_document.cancel_failed',
                $document,
                ['status' => $document->status],
                null,
                ['error' => $exception->getMessage()],
            );

            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Dokument {$document->number} został anulowany.");
    }

    private function loadDocument(WarehouseDocument $document): WarehouseDocument
    {
        return $document->load([
            'sourceWarehouse',
            'destinationWarehouse',
            'lines.product',
            'ledgerEntries.product',
            'ledgerEntries.warehouse',
        ]);
    }

    /**
     * @param array<string, mixed> $validated
     * @return list<array{product_id:int,quantity:float,unit_gross_price:?float,notes:?string,metadata:?array<string,string>}>
     */
    private function documentLines(array $validated): array
    {
        return collect($validated['lines'] ?? [])
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
            ->filter(fn (array $line): bool => $line['product_id'] > 0 && $line['quantity'] > 0)
            ->values()
            ->all();
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
}
