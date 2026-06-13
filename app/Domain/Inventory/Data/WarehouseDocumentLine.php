<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Data;

use App\Domain\Inventory\Exceptions\InventoryException;

final readonly class WarehouseDocumentLine
{
    public function __construct(
        public int $productId,
        public int $quantity,
        public ?int $externalOrderLineId = null,
        public ?string $note = null,
    ) {
        if ($this->productId <= 0) {
            throw new InventoryException('Product id must be positive.');
        }

        if ($this->quantity <= 0) {
            throw new InventoryException('Quantity must be positive.');
        }
    }
}

