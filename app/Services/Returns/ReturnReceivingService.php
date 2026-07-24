<?php

declare(strict_types=1);

namespace App\Services\Returns;

use App\Models\ReturnCase;
use App\Models\ReturnCaseLine;
use App\Models\WarehouseDocument;
use App\Services\Inventory\WarehouseDocumentNumberService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReturnReceivingService
{
    public function __construct(
        private readonly WarehouseDocumentNumberService $numbers,
        private readonly ReturnSettingsService $settings,
        private readonly ReturnInventoryReceiptService $inventoryReceipt,
    ) {}

    public function createReceivingDocument(ReturnCase $returnCase): WarehouseDocument
    {
        $document = $this->createReceivingDocuments($returnCase)->first();

        if (! $document instanceof WarehouseDocument) {
            throw new RuntimeException('Nie utworzono dokumentu RX dla zwrotu.');
        }

        return $document;
    }

    /**
     * @return Collection<int, WarehouseDocument>
     */
    public function createReceivingDocuments(ReturnCase $returnCase): Collection
    {
        return DB::transaction(function () use ($returnCase): Collection {
            $returnSettings = $this->settings->data();
            $conditionLabels = $this->optionLabels($returnSettings['conditions'] ?? []);
            $dispositionLabels = $this->optionLabels($returnSettings['dispositions'] ?? []);

            $returnCase = ReturnCase::query()
                ->with(['lines.product', 'lines.externalOrderLine', 'lines.targetWarehouse', 'lines.warehouseDocument', 'warehouseDocument', 'targetWarehouse', 'externalOrder'])
                ->lockForUpdate()
                ->findOrFail($returnCase->id);

            $acceptedLines = $returnCase->lines
                ->filter(fn (ReturnCaseLine $line): bool => (float) $line->quantity_accepted > 0 && $line->product_id !== null);

            if ($acceptedLines->isEmpty()) {
                throw new RuntimeException('Zwrot nie ma przyjętych pozycji.');
            }

            if ($this->inventoryReceipt->isComplete($returnCase)) {
                throw new RuntimeException('Zwrot został już przyjęty.');
            }

            $noRestockLines = $acceptedLines
                ->filter(fn (ReturnCaseLine $line): bool => $this->inventoryReceipt
                    ->isNoRestock((string) $line->disposition));
            $stockLines = $acceptedLines
                ->reject(fn (ReturnCaseLine $line): bool => $this->inventoryReceipt
                    ->isNoRestock((string) $line->disposition));

            $existingDocuments = collect([$returnCase->warehouseDocument])
                ->merge($returnCase->lines->map(fn (ReturnCaseLine $line) => $line->warehouseDocument))
                ->filter(fn ($document): bool => $document instanceof WarehouseDocument)
                ->unique('id')
                ->values();

            if ($existingDocuments->contains(fn (WarehouseDocument $document): bool => $document->status === 'cancelled')) {
                throw new RuntimeException('Zwrot ma anulowany dokument RX. Utwórz nowy zwrot albo popraw powiązanie dokumentu.');
            }

            if ($existingDocuments->isNotEmpty()) {
                return $existingDocuments;
            }

            if ($stockLines->isEmpty()
                && $noRestockLines->isNotEmpty()
                && $noRestockLines->every(fn (ReturnCaseLine $line): bool => $this->inventoryReceipt
                    ->isPreparedWithoutStock($line))) {
                return collect();
            }

            $preparedAt = now();

            foreach ($noRestockLines as $line) {
                if ($this->inventoryReceipt->isPreparedWithoutStock($line)) {
                    continue;
                }

                $line->update([
                    'warehouse_document_id' => null,
                    'metadata' => array_merge($line->metadata ?? [], [
                        'inventory_receipt' => [
                            'mode' => ReturnInventoryReceiptService::NO_RESTOCK_DISPOSITION,
                            'prepared_at' => $preparedAt->toISOString(),
                            'received_at' => null,
                            'stock_changed' => false,
                        ],
                    ]),
                ]);
            }

            $linesByWarehouse = $stockLines
                ->mapToGroups(function (ReturnCaseLine $line) use ($returnCase): array {
                    $warehouseId = $line->target_warehouse_id ?? $returnCase->target_warehouse_id;

                    if ($warehouseId === null) {
                        throw new RuntimeException('Pozycja zwrotu wymaga magazynu docelowego wynikającego z dyspozycji.');
                    }

                    return [(int) $warehouseId => $line];
                });

            $documents = collect();

            foreach ($linesByWarehouse as $warehouseId => $warehouseLines) {
                $document = WarehouseDocument::query()->create([
                    'number' => $this->numbers->next('RX'),
                    'type' => 'RX',
                    'status' => 'draft',
                    'destination_warehouse_id' => (int) $warehouseId,
                    'document_date' => now(),
                    'external_reference' => $returnCase->externalOrder?->external_number ?? $returnCase->number,
                    'notes' => 'Przyjęcie zwrotu '.$returnCase->number,
                    'metadata' => [
                        'source' => 'return_case',
                        'return_case_id' => $returnCase->id,
                        'return_case_number' => $returnCase->number,
                        'external_order_id' => $returnCase->externalOrder?->external_id,
                        'dispositions' => $warehouseLines->pluck('disposition')->unique()->values()->all(),
                    ],
                ]);

                foreach ($warehouseLines->groupBy('product_id') as $productId => $lines) {
                    $conditions = $lines->pluck('condition')->filter()->unique()->values();
                    $dispositions = $lines->pluck('disposition')->filter()->unique()->values();
                    $notes = $lines
                        ->pluck('notes')
                        ->filter(fn (?string $note): bool => filled($note))
                        ->unique()
                        ->values();

                    $document->lines()->create([
                        'product_id' => (int) $productId,
                        'quantity' => $lines->sum(fn (ReturnCaseLine $line): float => (float) $line->quantity_accepted),
                        'metadata' => [
                            'source' => 'return_case',
                            'return_case_id' => $returnCase->id,
                            'return_case_number' => $returnCase->number,
                            'return_case_line_ids' => $lines->pluck('id')->values()->all(),
                            'conditions' => $conditions->all(),
                            'condition_labels' => $conditions
                                ->map(fn (string $code): string => $conditionLabels[$code] ?? $code)
                                ->values()
                                ->all(),
                            'dispositions' => $dispositions->all(),
                            'disposition_labels' => $dispositions
                                ->map(fn (string $code): string => $dispositionLabels[$code] ?? $code)
                                ->values()
                                ->all(),
                            'return_notes' => $notes->all(),
                            'external_order_line_ids' => $lines
                                ->map(fn (ReturnCaseLine $line): mixed => $line->externalOrderLine?->external_line_id)
                                ->filter()
                                ->unique()
                                ->values()
                                ->all(),
                        ],
                    ]);
                }

                ReturnCaseLine::query()
                    ->whereIn('id', $warehouseLines->pluck('id')->values()->all())
                    ->update(['warehouse_document_id' => $document->id]);

                $documents->push($document);
            }

            $documentIds = $documents->pluck('id')->values()->all();

            if ($documentIds === [] && $noRestockLines->isEmpty()) {
                throw new RuntimeException('Nie utworzono dokumentu RX dla zwrotu.');
            }

            $returnCase->update([
                'warehouse_document_id' => $documentIds[0] ?? null,
                'status' => 'document_created',
                'metadata' => array_merge($returnCase->metadata ?? [], [
                    'warehouse_document_ids' => $documentIds,
                    'warehouse_document_numbers' => $documents->pluck('number')->values()->all(),
                    'inventory_receipt' => [
                        'mode' => match (true) {
                            $documents->isEmpty() => ReturnInventoryReceiptService::NO_RESTOCK_DISPOSITION,
                            $noRestockLines->isNotEmpty() => 'mixed',
                            default => 'stock',
                        },
                        'prepared_at' => $preparedAt->toISOString(),
                        'no_restock_line_ids' => $noRestockLines->pluck('id')->values()->all(),
                        'no_restock_quantity' => (float) $noRestockLines
                            ->sum(fn (ReturnCaseLine $line): float => (float) $line->quantity_accepted),
                        'stock_changed' => $documents->isNotEmpty(),
                    ],
                ]),
            ]);

            return $documents->values();
        });
    }

    /**
     * Marks physically accepted lines that must not affect inventory. Returns
     * true only when this operation completes the whole return receipt.
     */
    public function receiveWithoutStockMovement(ReturnCase $returnCase): bool
    {
        return DB::transaction(function () use ($returnCase): bool {
            $returnCase = ReturnCase::query()
                ->with(['lines.warehouseDocument'])
                ->lockForUpdate()
                ->findOrFail($returnCase->id);
            $wasComplete = $this->inventoryReceipt->isComplete($returnCase);
            $receivedAt = now();
            $noRestockLines = $returnCase->lines
                ->filter(fn (ReturnCaseLine $line): bool => (float) $line->quantity_accepted > 0
                    && $line->product_id !== null
                    && $this->inventoryReceipt->isNoRestock((string) $line->disposition));

            if ($noRestockLines->isEmpty()) {
                return false;
            }

            foreach ($noRestockLines as $line) {
                if ($this->inventoryReceipt->isReceivedWithoutStock($line)) {
                    continue;
                }

                $receiptMetadata = (array) data_get($line->metadata, 'inventory_receipt', []);
                $line->update([
                    'warehouse_document_id' => null,
                    'metadata' => array_merge($line->metadata ?? [], [
                        'inventory_receipt' => array_merge($receiptMetadata, [
                            'mode' => ReturnInventoryReceiptService::NO_RESTOCK_DISPOSITION,
                            'prepared_at' => $receiptMetadata['prepared_at'] ?? $receivedAt->toISOString(),
                            'received_at' => $receivedAt->toISOString(),
                            'stock_changed' => false,
                        ]),
                    ]),
                ]);
            }

            $returnCase->unsetRelation('lines');
            $returnCase->load('lines.warehouseDocument');
            $isComplete = $this->inventoryReceipt->isComplete($returnCase);
            $receiptMetadata = (array) data_get($returnCase->metadata, 'inventory_receipt', []);

            $returnCase->update([
                'status' => $isComplete ? 'completed' : 'document_created',
                'metadata' => array_merge($returnCase->metadata ?? [], [
                    'inventory_receipt' => array_merge($receiptMetadata, [
                        'no_restock_received_at' => $receivedAt->toISOString(),
                        'completed_at' => $isComplete ? $receivedAt->toISOString() : null,
                    ]),
                ]),
            ]);

            return ! $wasComplete && $isComplete;
        });
    }

    /**
     * @param  list<array{code?:string,label?:string}>  $options
     * @return array<string, string>
     */
    private function optionLabels(array $options): array
    {
        $labels = [];

        foreach ($options as $option) {
            $code = trim((string) ($option['code'] ?? ''));
            $label = trim((string) ($option['label'] ?? ''));

            if ($code !== '' && $label !== '') {
                $labels[$code] = $label;
            }
        }

        return $labels;
    }
}
