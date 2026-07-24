<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Domain\Inventory\Enums\WarehouseDocumentType;
use App\Jobs\SendReturnReceivedMailJob;
use App\Models\CustomerPayment;
use App\Models\ReturnCase;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\WarehouseDocument;
use App\Services\Audit\AuditLogService;
use App\Services\Automation\DocumentAutomationSettingsService;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Invoices\ReturnCorrectionInvoiceService;
use App\Services\Orders\OrderCancellationGuard;
use App\Services\Payments\PayuRefundService;
use App\Services\Returns\ReturnInventoryReceiptService;
use App\Services\WooCommerce\InvoiceWooCommerceUploadService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class WarehouseDocumentPostingService
{
    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly StockSyncQueueService $stockSyncQueue,
        private readonly AuditLogService $audit,
        private readonly DocumentAutomationSettingsService $automationSettings,
        private readonly ReturnCorrectionInvoiceService $returnCorrections,
        private readonly InvoiceWooCommerceUploadService $invoiceUpload,
        private readonly CustomerCommunicationService $communication,
        private readonly PayuRefundService $payuRefunds,
        private readonly OrderCancellationGuard $cancellationGuard,
        private readonly ReturnInventoryReceiptService $returnInventoryReceipt,
    ) {}

    public function post(WarehouseDocument $document): void
    {
        $this->assertReturnPostingAllowed($document);
        $completedReturnCaseIds = [];

        DB::transaction(function () use ($document, &$completedReturnCaseIds): void {
            $document = WarehouseDocument::query()
                ->with(['lines.product', 'sourceWarehouse', 'destinationWarehouse'])
                ->lockForUpdate()
                ->findOrFail($document->id);

            if ($document->status !== 'draft') {
                throw new RuntimeException('Tylko dokument w statusie szkic może zostać zaksięgowany.');
            }

            if ($document->lines->isEmpty()) {
                throw new RuntimeException('Dokument nie ma pozycji.');
            }

            $this->assertWarehouseTopology($document);

            $before = [
                'number' => $document->number,
                'type' => $document->type,
                'status' => $document->status,
                'source_warehouse' => $document->sourceWarehouse?->code,
                'destination_warehouse' => $document->destinationWarehouse?->code,
                'posted_at' => $document->posted_at?->toDateTimeString(),
                'lines' => $document->lines->map(fn ($line): array => [
                    'sku' => $line->product?->sku,
                    'quantity' => (string) $line->quantity,
                ])->values()->all(),
            ];
            $postedAt = now();
            $balanceChanges = [];
            $ledgerEntryIds = [];
            $stockSyncTriggers = [];
            $waitingAllocationTriggers = [];

            foreach ($document->lines as $line) {
                foreach ($this->movementRows($document, (float) $line->quantity) as [$warehouseId, $quantityChange]) {
                    $balance = $this->lockedBalance(
                        (int) $warehouseId,
                        (int) $line->product_id,
                        $postedAt,
                    );

                    $newOnHand = (float) $balance->quantity_on_hand + $quantityChange;

                    if ($newOnHand < 0 && ! $this->warehouseAllowsNegative($document, $warehouseId)) {
                        throw new RuntimeException("Brak stanu dla SKU {$line->product->sku} w magazynie #{$warehouseId}.");
                    }

                    $reserved = (float) $balance->quantity_reserved;
                    $previousOnHand = (float) $balance->quantity_on_hand;
                    $sourceBaseline = $this->sourceBaseline($balance);
                    $balance->update([
                        'quantity_on_hand' => $newOnHand,
                        'quantity_available' => max(0, $newOnHand - $reserved),
                        'source_sales_channel_id' => null,
                        'source_available_quantity' => null,
                        'source_observed_at' => null,
                        'source_reflected_order_quantities' => null,
                        'recalculated_at' => $postedAt,
                    ]);

                    $ledgerEntry = StockLedgerEntry::query()->create([
                        'warehouse_document_id' => $document->id,
                        'warehouse_document_line_id' => $line->id,
                        'warehouse_id' => $warehouseId,
                        'product_id' => $line->product_id,
                        'quantity_change' => $quantityChange,
                        'direction' => $quantityChange >= 0 ? 'in' : 'out',
                        'posted_at' => $postedAt,
                        'metadata' => [
                            'document_number' => $document->number,
                            'document_type' => $document->type,
                            'source_balance_before_movement' => $sourceBaseline,
                        ],
                    ]);
                    $ledgerEntryIds[] = $ledgerEntry->id;
                    $balanceChanges[] = [
                        'warehouse_id' => $warehouseId,
                        'sku' => $line->product?->sku,
                        'before_on_hand' => $previousOnHand,
                        'change' => $quantityChange,
                        'after_on_hand' => $newOnHand,
                    ];

                    $stockSyncTriggers[] = [
                        'warehouse_id' => $warehouseId,
                        'product_id' => $line->product_id,
                    ];

                    if ($quantityChange > 0) {
                        $waitingAllocationTriggers[] = [
                            'warehouse_id' => $warehouseId,
                            'product_id' => $line->product_id,
                        ];
                    }
                }
            }

            $document->update([
                'status' => 'posted',
                'posted_at' => $postedAt,
            ]);

            $allocatedWaitingReservations = $this->allocateWaitingReservations($waitingAllocationTriggers);
            $this->reservations->releaseForPostedDocument($document);
            $completedReturnCaseIds = $this->completeReturnCase($document);
            $this->queueStockSync($stockSyncTriggers, 'warehouse_document_posted');
            $document->refresh();

            $this->audit->record(
                'warehouse_document.posted',
                $document,
                $before,
                [
                    'number' => $document->number,
                    'type' => $document->type,
                    'status' => $document->status,
                    'posted_at' => $document->posted_at?->toDateTimeString(),
                    'balance_changes' => $balanceChanges,
                ],
                [
                    'ledger_entry_ids' => $ledgerEntryIds,
                    'allocated_waiting_reservations' => $allocatedWaitingReservations,
                ],
            );
        }, 3);

        $this->issueReturnCorrectionsAfterPosting($completedReturnCaseIds);
        $this->queueReturnReceivedAfterPosting($completedReturnCaseIds);
    }

    public function handleReturnReceiptCompletion(ReturnCase $returnCase): void
    {
        $returnCase = ReturnCase::query()
            ->with('lines.warehouseDocument')
            ->findOrFail($returnCase->id);

        if (! $this->returnInventoryReceipt->isComplete($returnCase)) {
            throw new RuntimeException('Zwrot nie został jeszcze w pełni przyjęty.');
        }

        $returnCase->update(['status' => 'completed']);
        $this->issueReturnCorrectionsAfterPosting([(int) $returnCase->id]);
        $this->queueReturnReceivedAfterPosting([(int) $returnCase->id]);
    }

    public function cancel(WarehouseDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $document = WarehouseDocument::query()
                ->with(['lines.product', 'sourceWarehouse', 'destinationWarehouse'])
                ->lockForUpdate()
                ->findOrFail($document->id);

            if ($document->status === 'cancelled') {
                throw new RuntimeException('Dokument jest już anulowany.');
            }

            if (! in_array($document->status, ['draft', 'posted'], true)) {
                throw new RuntimeException('Ten status dokumentu nie pozwala na anulowanie.');
            }

            if ($document->status === 'posted') {
                $this->assertWarehouseTopology($document);
            }

            $before = [
                'number' => $document->number,
                'type' => $document->type,
                'status' => $document->status,
                'posted_at' => $document->posted_at?->toDateTimeString(),
                'cancelled_at' => $document->cancelled_at?->toDateTimeString(),
                'lines' => $document->lines->map(fn ($line): array => [
                    'sku' => $line->product?->sku,
                    'quantity' => (string) $line->quantity,
                ])->values()->all(),
            ];

            $cancelledAt = now();
            $balanceChanges = [];
            $ledgerEntryIds = [];
            $stockSyncTriggers = [];

            if ($document->status === 'posted') {
                foreach ($document->lines as $line) {
                    foreach ($this->movementRows($document, (float) $line->quantity) as [$warehouseId, $originalQuantityChange]) {
                        $quantityChange = -$originalQuantityChange;
                        $balance = $this->lockedBalance(
                            (int) $warehouseId,
                            (int) $line->product_id,
                            $cancelledAt,
                        );

                        $previousOnHand = (float) $balance->quantity_on_hand;
                        $newOnHand = $previousOnHand + $quantityChange;

                        if ($newOnHand < 0 && ! $this->warehouseAllowsNegative($document, $warehouseId)) {
                            throw new RuntimeException("Anulowanie dokumentu {$document->number} spowodowałoby ujemny stan SKU {$line->product->sku} w magazynie #{$warehouseId}.");
                        }

                        $reserved = (float) $balance->quantity_reserved;
                        $sourceBaseline = $this->restorableSourceBaseline(
                            $document,
                            (int) $line->id,
                            $balance,
                        );
                        $balanceUpdates = [
                            'quantity_on_hand' => $newOnHand,
                            'quantity_available' => max(0, $newOnHand - $reserved),
                            'source_sales_channel_id' => null,
                            'source_available_quantity' => null,
                            'source_observed_at' => null,
                            'source_reflected_order_quantities' => null,
                            'recalculated_at' => $cancelledAt,
                        ];

                        if ($sourceBaseline !== null) {
                            $balanceUpdates = array_merge($balanceUpdates, [
                                'source_sales_channel_id' => $sourceBaseline['sales_channel_id'],
                                'source_available_quantity' => $sourceBaseline['available_quantity'],
                                'source_observed_at' => $sourceBaseline['observed_at'],
                                'source_reflected_order_quantities' => $sourceBaseline['reflected_order_quantities'],
                            ]);
                        }

                        $balance->update($balanceUpdates);

                        $ledgerEntry = StockLedgerEntry::query()->create([
                            'warehouse_document_id' => $document->id,
                            'warehouse_document_line_id' => $line->id,
                            'warehouse_id' => $warehouseId,
                            'product_id' => $line->product_id,
                            'quantity_change' => $quantityChange,
                            'direction' => $quantityChange >= 0 ? 'in' : 'out',
                            'posted_at' => $cancelledAt,
                            'metadata' => [
                                'document_number' => $document->number,
                                'document_type' => $document->type,
                                'source' => 'warehouse_document_cancelled',
                                'reverses_original_change' => $originalQuantityChange,
                                'source_balance_restored' => $sourceBaseline !== null,
                            ],
                        ]);

                        $ledgerEntryIds[] = $ledgerEntry->id;
                        $balanceChanges[] = [
                            'warehouse_id' => $warehouseId,
                            'sku' => $line->product?->sku,
                            'before_on_hand' => $previousOnHand,
                            'change' => $quantityChange,
                            'after_on_hand' => $newOnHand,
                        ];

                        $stockSyncTriggers[] = [
                            'warehouse_id' => $warehouseId,
                            'product_id' => $line->product_id,
                        ];
                    }
                }
            }

            $document->update([
                'status' => 'cancelled',
                'cancelled_at' => $cancelledAt,
            ]);

            $this->reopenReturnCase($document);
            $this->queueStockSync($stockSyncTriggers, 'warehouse_document_cancelled');
            $document->refresh();

            $this->audit->record(
                'warehouse_document.cancelled',
                $document,
                $before,
                [
                    'number' => $document->number,
                    'type' => $document->type,
                    'status' => $document->status,
                    'cancelled_at' => $document->cancelled_at?->toDateTimeString(),
                    'balance_changes' => $balanceChanges,
                ],
                [
                    'ledger_entry_ids' => $ledgerEntryIds,
                    'reversal_required' => $before['status'] === 'posted',
                ],
            );
        }, 3);
    }

    private function lockedBalance(int $warehouseId, int $productId, mixed $timestamp): StockBalance
    {
        DB::table('stock_balances')->insertOrIgnore([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
            'quantity_available' => 0,
            'recalculated_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return list<array{0:int,1:float}>
     */
    private function movementRows(WarehouseDocument $document, float $quantity): array
    {
        return $this->documentType($document)->movementRows(
            (int) $document->source_warehouse_id,
            (int) $document->destination_warehouse_id,
            $quantity,
        );
    }

    private function assertWarehouseTopology(WarehouseDocument $document): void
    {
        $type = $this->documentType($document);
        $warehouseError = $type->warehouseTopologyError(
            $document->source_warehouse_id !== null ? (int) $document->source_warehouse_id : null,
            $document->destination_warehouse_id !== null ? (int) $document->destination_warehouse_id : null,
        );

        if ($warehouseError !== null) {
            throw new RuntimeException($warehouseError);
        }

        if ($type->requiresSourceWarehouse()) {
            if ($document->sourceWarehouse === null) {
                throw new RuntimeException("Dokument {$document->type} wymaga magazynu źródłowego.");
            }
        }

        if ($type->requiresDestinationWarehouse()) {
            if ($document->destinationWarehouse === null) {
                throw new RuntimeException("Dokument {$document->type} wymaga magazynu docelowego.");
            }
        }
    }

    /**
     * @param  list<array{warehouse_id:int,product_id:int}>  $triggers
     */
    private function allocateWaitingReservations(array $triggers): int
    {
        $allocated = 0;

        collect($triggers)
            ->unique(fn (array $trigger): string => $trigger['warehouse_id'].':'.$trigger['product_id'])
            ->each(function (array $trigger) use (&$allocated): void {
                $allocated += $this->reservations->allocateWaitingReservations(
                    (int) $trigger['warehouse_id'],
                    (int) $trigger['product_id'],
                );
            });

        return $allocated;
    }

    private function warehouseAllowsNegative(WarehouseDocument $document, int $warehouseId): bool
    {
        $warehouse = $document->source_warehouse_id === $warehouseId
            ? $document->sourceWarehouse
            : $document->destinationWarehouse;

        return (bool) ($warehouse?->allow_negative_stock);
    }

    /**
     * @param  list<array{warehouse_id:int,product_id:int}>  $triggers
     */
    private function queueStockSync(array $triggers, string $reason): void
    {
        $this->stockSyncQueue->queueForTriggers($triggers, $reason);
    }

    /** @return array<string,mixed> */
    private function sourceBaseline(StockBalance $balance): array
    {
        return [
            'sales_channel_id' => $balance->source_sales_channel_id !== null
                ? (int) $balance->source_sales_channel_id
                : null,
            'available_quantity' => $balance->source_available_quantity !== null
                ? (string) $balance->source_available_quantity
                : null,
            'observed_at' => $balance->getRawOriginal('source_observed_at'),
            'reflected_order_quantities' => (array) $balance->source_reflected_order_quantities,
        ];
    }

    /** @return array<string,mixed>|null */
    private function restorableSourceBaseline(
        WarehouseDocument $document,
        int $documentLineId,
        StockBalance $balance,
    ): ?array {
        // A source snapshot imported after the WZ was posted supersedes the
        // old baseline, so it must not be replaced with the older snapshot.
        // The inverse warehouse movement below deliberately switches the
        // balance to local mode because that newer snapshot predates the
        // cancellation and can no longer be treated as current stock.
        if ($balance->source_sales_channel_id !== null
            || $balance->source_available_quantity !== null
            || $balance->source_observed_at !== null
            || (array) $balance->source_reflected_order_quantities !== []) {
            return null;
        }

        $originalEntry = StockLedgerEntry::query()
            ->where('warehouse_document_id', $document->id)
            ->where('warehouse_document_line_id', $documentLineId)
            ->where('warehouse_id', $balance->warehouse_id)
            ->where('product_id', $balance->product_id)
            ->orderBy('id')
            ->get()
            ->first(fn (StockLedgerEntry $entry): bool => is_array(
                data_get($entry->metadata, 'source_balance_before_movement'),
            ));

        if (! $originalEntry instanceof StockLedgerEntry) {
            return null;
        }

        $baseline = data_get($originalEntry->metadata, 'source_balance_before_movement');

        if (! is_array($baseline)
            || ! is_numeric($baseline['sales_channel_id'] ?? null)
            || ! is_numeric($baseline['available_quantity'] ?? null)
            || blank($baseline['observed_at'] ?? null)) {
            return null;
        }

        $hasUnrelatedMovement = StockLedgerEntry::query()
            ->where('warehouse_id', $balance->warehouse_id)
            ->where('product_id', $balance->product_id)
            ->where('id', '>', $originalEntry->id)
            ->where('warehouse_document_id', '!=', $document->id)
            ->exists();

        return $hasUnrelatedMovement ? null : $baseline;
    }

    /**
     * @return list<int>
     */
    private function completeReturnCase(WarehouseDocument $document): array
    {
        if (! $this->documentType($document)->isReturnReceipt()) {
            return [];
        }

        $completed = [];
        $returnCases = ReturnCase::query()
            ->where(function ($query) use ($document): void {
                $query
                    ->where('warehouse_document_id', $document->id)
                    ->orWhereHas('lines', fn ($lineQuery) => $lineQuery->where('warehouse_document_id', $document->id));
            })
            ->with(['lines.warehouseDocument', 'warehouseDocument'])
            ->get();

        foreach ($returnCases as $returnCase) {
            if ($this->allReturnDocumentsPosted($returnCase)) {
                $returnCase->update(['status' => 'completed']);
                $completed[] = (int) $returnCase->id;
            }
        }

        return $completed;
    }

    private function assertReturnPostingAllowed(WarehouseDocument $document): void
    {
        if (! $this->documentType($document)->isReturnReceipt()) {
            return;
        }

        ReturnCase::query()
            ->where(function ($query) use ($document): void {
                $query
                    ->where('warehouse_document_id', $document->id)
                    ->orWhereHas('lines', fn ($lineQuery) => $lineQuery
                        ->where('warehouse_document_id', $document->id));
            })
            ->with('externalOrder')
            ->get()
            ->each(fn (ReturnCase $returnCase) => $this->cancellationGuard
                ->assertReturnAllowedForCase($returnCase));
    }

    /**
     * @param  list<int>  $returnCaseIds
     */
    private function queueReturnReceivedAfterPosting(array $returnCaseIds): void
    {
        foreach (array_unique($returnCaseIds) as $returnCaseId) {
            SendReturnReceivedMailJob::dispatch((int) $returnCaseId)
                ->delay(now()->addMinutes(10));
        }
    }

    /**
     * @param  list<int>  $returnCaseIds
     */
    private function issueReturnCorrectionsAfterPosting(array $returnCaseIds): void
    {
        if (
            $returnCaseIds === []
            || ! $this->automationSettings->actionEnabled('warehouse_document.rx.posted', 'return.correction.create')
        ) {
            return;
        }

        foreach (array_unique($returnCaseIds) as $returnCaseId) {
            $invoice = null;

            try {
                $returnCase = ReturnCase::query()->findOrFail($returnCaseId);
                $invoice = $this->returnCorrections->createForReturn($returnCase);
                $this->invoiceUpload->upload($invoice);
                $payment = $this->payuRefunds->attemptAutomaticRefund($returnCase->fresh() ?? $returnCase, $invoice);
                $this->communication->sendReturnSettlement(
                    $returnCase->fresh() ?? $returnCase,
                    $payment instanceof CustomerPayment ? $payment : null,
                    $invoice->number,
                );
            } catch (Throwable $exception) {
                $returnCase = ReturnCase::query()->find($returnCaseId);

                if (! $returnCase instanceof ReturnCase) {
                    continue;
                }

                if ($invoice !== null) {
                    $this->communication->sendReturnSettlement(
                        $returnCase->fresh() ?? $returnCase,
                        null,
                        $invoice->number,
                    );
                }

                $warnings = (array) data_get($returnCase->metadata, 'automation_warnings', []);
                $warnings[] = [
                    'type' => 'return_correction_after_rx_posted',
                    'message' => $exception->getMessage(),
                    'created_at' => now()->toISOString(),
                ];

                $returnCase->update([
                    'metadata' => array_merge($returnCase->metadata ?? [], [
                        'automation_warnings' => array_slice($warnings, -10),
                    ]),
                ]);
            }
        }
    }

    private function reopenReturnCase(WarehouseDocument $document): void
    {
        if (! $this->documentType($document)->isReturnReceipt()) {
            return;
        }

        $returnCases = ReturnCase::query()
            ->where(function ($query) use ($document): void {
                $query
                    ->where('warehouse_document_id', $document->id)
                    ->orWhereHas('lines', fn ($lineQuery) => $lineQuery->where('warehouse_document_id', $document->id));
            })
            ->with(['lines.warehouseDocument', 'warehouseDocument'])
            ->get();

        foreach ($returnCases as $returnCase) {
            $returnCase->lines()
                ->where('warehouse_document_id', $document->id)
                ->update(['warehouse_document_id' => null]);

            $returnCase->load(['lines.warehouseDocument', 'warehouseDocument']);
            $remainingDocumentIds = $returnCase->lines
                ->pluck('warehouse_document_id')
                ->filter()
                ->unique()
                ->values();

            if ($returnCase->warehouse_document_id === $document->id) {
                $returnCase->warehouse_document_id = $remainingDocumentIds->first();
            }

            $returnCase->status = $remainingDocumentIds->isNotEmpty() ? 'document_created' : 'opened';
            $returnCase->metadata = array_merge($returnCase->metadata ?? [], [
                'warehouse_document_ids' => $remainingDocumentIds->all(),
                'reopened_by_cancelled_document_id' => $document->id,
            ]);
            $returnCase->save();
        }
    }

    private function allReturnDocumentsPosted(ReturnCase $returnCase): bool
    {
        return $this->returnInventoryReceipt->isComplete($returnCase);
    }

    private function documentType(WarehouseDocument $document): WarehouseDocumentType
    {
        return WarehouseDocumentType::tryFrom((string) $document->type)
            ?? throw new RuntimeException("Nieznany typ dokumentu: {$document->type}.");
    }
}
