<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Data\PostingResult;
use App\Domain\Inventory\Data\StockLedgerEntry;
use App\Domain\Inventory\Data\WarehouseDocument;
use App\Domain\Inventory\Enums\WarehouseDocumentStatus;
use App\Domain\Inventory\Exceptions\InventoryException;

final class StockPostingService
{
    /**
     * @param array<string, int> $currentBalances keyed by "warehouseId:productId"
     */
    public function post(WarehouseDocument $document, array $currentBalances = []): PostingResult
    {
        if ($document->status !== WarehouseDocumentStatus::Draft) {
            throw new InventoryException('Only draft documents can be posted.');
        }

        $entries = [];
        $balances = $currentBalances;

        foreach ($document->lines as $line) {
            if ($document->type->decreasesSourceStock()) {
                $entries[] = new StockLedgerEntry(
                    documentNumber: $document->number,
                    documentType: $document->type,
                    warehouseId: (int) $document->sourceWarehouseId,
                    productId: $line->productId,
                    quantityChange: -$line->quantity,
                );
            }

            if ($document->type->increasesDestinationStock()) {
                $entries[] = new StockLedgerEntry(
                    documentNumber: $document->number,
                    documentType: $document->type,
                    warehouseId: (int) $document->destinationWarehouseId,
                    productId: $line->productId,
                    quantityChange: $line->quantity,
                );
            }
        }

        foreach ($entries as $entry) {
            $key = self::balanceKey($entry->warehouseId, $entry->productId);
            $balances[$key] = ($balances[$key] ?? 0) + $entry->quantityChange;

            if (!$document->allowNegativeStock && $balances[$key] < 0) {
                throw new InventoryException(
                    "Insufficient stock for product {$entry->productId} in warehouse {$entry->warehouseId}."
                );
            }
        }

        return new PostingResult($entries, $balances);
    }

    public static function balanceKey(int $warehouseId, int $productId): string
    {
        return $warehouseId . ':' . $productId;
    }
}

