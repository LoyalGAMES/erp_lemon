<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Data;

use App\Domain\Inventory\Enums\WarehouseDocumentStatus;
use App\Domain\Inventory\Enums\WarehouseDocumentType;
use App\Domain\Inventory\Exceptions\InventoryException;

final readonly class WarehouseDocument
{
    /**
     * @param list<WarehouseDocumentLine> $lines
     */
    public function __construct(
        public string $number,
        public WarehouseDocumentType $type,
        public WarehouseDocumentStatus $status,
        public ?int $sourceWarehouseId,
        public ?int $destinationWarehouseId,
        public array $lines,
        public bool $allowNegativeStock = false,
    ) {
        if ($this->number === '') {
            throw new InventoryException('Document number is required.');
        }

        if ($this->lines === []) {
            throw new InventoryException('Document must have at least one line.');
        }

        foreach ($this->lines as $line) {
            if (!$line instanceof WarehouseDocumentLine) {
                throw new InventoryException('Document lines must be WarehouseDocumentLine instances.');
            }
        }

        if ($this->type->requiresSourceWarehouse() && $this->sourceWarehouseId === null) {
            throw new InventoryException("Document {$this->type->value} requires source warehouse.");
        }

        if ($this->type->requiresDestinationWarehouse() && $this->destinationWarehouseId === null) {
            throw new InventoryException("Document {$this->type->value} requires destination warehouse.");
        }

        if ($this->type === WarehouseDocumentType::MM && $this->sourceWarehouseId === $this->destinationWarehouseId) {
            throw new InventoryException('MM document requires different source and destination warehouses.');
        }
    }
}

