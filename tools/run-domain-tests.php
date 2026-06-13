<?php

declare(strict_types=1);

use App\Domain\Inventory\Data\WarehouseDocument;
use App\Domain\Inventory\Data\WarehouseDocumentLine;
use App\Domain\Inventory\Enums\WarehouseDocumentStatus;
use App\Domain\Inventory\Enums\WarehouseDocumentType;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Services\StockPostingService;

require_once __DIR__ . '/../app/Domain/Inventory/Enums/WarehouseDocumentStatus.php';
require_once __DIR__ . '/../app/Domain/Inventory/Enums/WarehouseDocumentType.php';
require_once __DIR__ . '/../app/Domain/Inventory/Exceptions/InventoryException.php';
require_once __DIR__ . '/../app/Domain/Inventory/Data/WarehouseDocumentLine.php';
require_once __DIR__ . '/../app/Domain/Inventory/Data/WarehouseDocument.php';
require_once __DIR__ . '/../app/Domain/Inventory/Data/StockLedgerEntry.php';
require_once __DIR__ . '/../app/Domain/Inventory/Data/PostingResult.php';
require_once __DIR__ . '/../app/Domain/Inventory/Services/StockPostingService.php';

$service = new StockPostingService();

$pz = new WarehouseDocument(
    number: 'PZ/2026/0001',
    type: WarehouseDocumentType::PZ,
    status: WarehouseDocumentStatus::Draft,
    sourceWarehouseId: null,
    destinationWarehouseId: 1,
    lines: [new WarehouseDocumentLine(productId: 10, quantity: 100)],
);

$postedPz = $service->post($pz);
assert($postedPz->balances['1:10'] === 100);
assert(count($postedPz->entries) === 1);
assert($postedPz->entries[0]->quantityChange === 100);

$wz = new WarehouseDocument(
    number: 'WZ/2026/0001',
    type: WarehouseDocumentType::WZ,
    status: WarehouseDocumentStatus::Draft,
    sourceWarehouseId: 1,
    destinationWarehouseId: null,
    lines: [new WarehouseDocumentLine(productId: 10, quantity: 30)],
);

$postedWz = $service->post($wz, $postedPz->balances);
assert($postedWz->balances['1:10'] === 70);
assert($postedWz->entries[0]->quantityChange === -30);

$mm = new WarehouseDocument(
    number: 'MM/2026/0001',
    type: WarehouseDocumentType::MM,
    status: WarehouseDocumentStatus::Draft,
    sourceWarehouseId: 1,
    destinationWarehouseId: 2,
    lines: [new WarehouseDocumentLine(productId: 10, quantity: 25)],
);

$postedMm = $service->post($mm, $postedWz->balances);
assert($postedMm->balances['1:10'] === 45);
assert($postedMm->balances['2:10'] === 25);
assert(count($postedMm->entries) === 2);
assert($postedMm->entries[0]->quantityChange === -25);
assert($postedMm->entries[1]->quantityChange === 25);

$rx = new WarehouseDocument(
    number: 'RX/2026/0001',
    type: WarehouseDocumentType::RX,
    status: WarehouseDocumentStatus::Draft,
    sourceWarehouseId: null,
    destinationWarehouseId: 3,
    lines: [new WarehouseDocumentLine(productId: 10, quantity: 5)],
);

$postedRx = $service->post($rx, $postedMm->balances);
assert($postedRx->balances['3:10'] === 5);
assert($postedRx->entries[0]->quantityChange === 5);

$thrown = false;
try {
    $service->post(new WarehouseDocument(
        number: 'WZ/2026/9999',
        type: WarehouseDocumentType::WZ,
        status: WarehouseDocumentStatus::Draft,
        sourceWarehouseId: 2,
        destinationWarehouseId: null,
        lines: [new WarehouseDocumentLine(productId: 10, quantity: 1000)],
    ), $postedRx->balances);
} catch (InventoryException) {
    $thrown = true;
}

assert($thrown === true);

echo "Domain tests passed.\n";

