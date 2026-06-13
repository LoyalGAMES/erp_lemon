<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Data;

use App\Domain\Inventory\Enums\WarehouseDocumentType;

final readonly class StockLedgerEntry
{
    public function __construct(
        public string $documentNumber,
        public WarehouseDocumentType $documentType,
        public int $warehouseId,
        public int $productId,
        public int $quantityChange,
    ) {
    }
}

