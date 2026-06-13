<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

enum WarehouseDocumentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
}

