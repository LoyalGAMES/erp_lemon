<?php

declare(strict_types=1);

namespace App\Services\Returns;

use App\Models\ReturnCase;
use App\Models\ReturnCaseLine;
use App\Models\WarehouseDocument;
use App\Services\Inventory\WarehouseDocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use RuntimeException;

final class ReturnReceivingService
{
    public function __construct(
        private readonly WarehouseDocumentNumberService $numbers,
        private readonly ReturnSettingsService $settings,
    ) {
    }

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
                ->with(['lines.product', 'lines.externalOrderLine', 'lines.targetWarehouse', 'lines.warehouseDocument', 'targetWarehouse', 'externalOrder'])
                ->lockForUpdate()
                ->findOrFail($returnCase->id);

            $hasExistingDocument = $returnCase->warehouse_document_id !== null
                || $returnCase->lines->contains(fn (ReturnCaseLine $line): bool => $line->warehouse_document_id !== null);

            if ($hasExistingDocument) {
                throw new RuntimeException('Zwrot ma już utworzony dokument magazynowy.');
            }

            $acceptedLines = $returnCase->lines
                ->filter(fn (ReturnCaseLine $line): bool => (float) $line->quantity_accepted > 0 && $line->product_id !== null);

            if ($acceptedLines->isEmpty()) {
                throw new RuntimeException('Zwrot nie ma przyjętych pozycji.');
            }

            $linesByWarehouse = $acceptedLines
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
                    'notes' => 'Przyjęcie zwrotu ' . $returnCase->number,
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

            if ($documentIds === []) {
                throw new RuntimeException('Nie utworzono dokumentu RX dla zwrotu.');
            }

            $returnCase->update([
                'warehouse_document_id' => $documentIds[0],
                'status' => 'document_created',
                'metadata' => array_merge($returnCase->metadata ?? [], [
                    'warehouse_document_ids' => $documentIds,
                    'warehouse_document_numbers' => $documents->pluck('number')->values()->all(),
                ]),
            ]);

            return $documents->values();
        });
    }

    /**
     * @param list<array{code?:string,label?:string}> $options
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
