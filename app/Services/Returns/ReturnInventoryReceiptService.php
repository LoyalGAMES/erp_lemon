<?php

declare(strict_types=1);

namespace App\Services\Returns;

use App\Models\ReturnCase;
use App\Models\ReturnCaseLine;

final class ReturnInventoryReceiptService
{
    public const NO_RESTOCK_DISPOSITION = 'no_restock';

    public function isNoRestock(string $disposition): bool
    {
        return mb_strtolower(trim($disposition)) === self::NO_RESTOCK_DISPOSITION;
    }

    public function isPreparedWithoutStock(ReturnCaseLine $line): bool
    {
        return $this->isNoRestock((string) $line->disposition)
            && data_get($line->metadata, 'inventory_receipt.mode') === self::NO_RESTOCK_DISPOSITION
            && filled(data_get($line->metadata, 'inventory_receipt.prepared_at'));
    }

    public function isReceivedWithoutStock(ReturnCaseLine $line): bool
    {
        return $this->isPreparedWithoutStock($line)
            && data_get($line->metadata, 'inventory_receipt.stock_changed') === false
            && filled(data_get($line->metadata, 'inventory_receipt.received_at'));
    }

    public function isComplete(ReturnCase $returnCase): bool
    {
        $returnCase->loadMissing(['lines.warehouseDocument', 'warehouseDocument']);
        $acceptedLines = $returnCase->lines
            ->filter(fn (ReturnCaseLine $line): bool => (float) $line->quantity_accepted > 0
                && $line->product_id !== null);
        $returnDocuments = collect([$returnCase->warehouseDocument])
            ->merge($returnCase->lines->map(fn (ReturnCaseLine $line) => $line->warehouseDocument))
            ->filter()
            ->unique('id')
            ->values();

        if ($acceptedLines->isEmpty()) {
            return $returnDocuments->isNotEmpty()
                && $returnDocuments->every(fn ($document): bool => $document->status === 'posted');
        }

        $noRestockComplete = $acceptedLines
            ->filter(fn (ReturnCaseLine $line): bool => $this->isNoRestock((string) $line->disposition))
            ->every(fn (ReturnCaseLine $line): bool => $this->isReceivedWithoutStock($line));

        if (! $noRestockComplete) {
            return false;
        }

        $stockLines = $acceptedLines
            ->reject(fn (ReturnCaseLine $line): bool => $this->isNoRestock((string) $line->disposition));

        if ($stockLines->isEmpty()) {
            return true;
        }

        if ($stockLines->every(fn (ReturnCaseLine $line): bool => $line->warehouseDocument?->status === 'posted')) {
            return true;
        }

        // Starsze zwroty miały RX przypisany tylko do karty zwrotu, bez
        // identyfikatora dokumentu na każdej pozycji.
        return $stockLines->every(fn (ReturnCaseLine $line): bool => $line->warehouse_document_id === null)
            && $returnDocuments->isNotEmpty()
            && $returnDocuments->every(fn ($document): bool => $document->status === 'posted');
    }

    public function hasNoRestockLines(ReturnCase $returnCase): bool
    {
        $returnCase->loadMissing('lines');

        return $returnCase->lines->contains(
            fn (ReturnCaseLine $line): bool => (float) $line->quantity_accepted > 0
                && $line->product_id !== null
                && $this->isNoRestock((string) $line->disposition),
        );
    }
}
