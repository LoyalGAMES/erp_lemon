<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ExternalOrder;
use App\Models\OrderCancellation;
use App\Models\ReturnCase;
use RuntimeException;

final class OrderCancellationGuard
{
    public function assertReturnAllowed(ExternalOrder $order): void
    {
        $freshOrder = ExternalOrder::query()->find($order->id);

        if (! $freshOrder instanceof ExternalOrder) {
            throw new RuntimeException('Nie znaleziono zamówienia powiązanego ze zwrotem.');
        }

        // cancellationOperation() is root-aware and deliberately ignores an
        // operation whose final status is "rejected".
        if ($freshOrder->cancellationOperation() instanceof OrderCancellation) {
            throw new RuntimeException(
                'Nie można rozpocząć ani realizować zwrotu, ponieważ dla rodziny tego zamówienia trwa lub zakończył się proces anulowania.',
            );
        }
    }

    public function assertReturnAllowedForCase(ReturnCase $returnCase): void
    {
        $returnCase->loadMissing('externalOrder');

        if ($returnCase->externalOrder instanceof ExternalOrder) {
            $this->assertReturnAllowed($returnCase->externalOrder);
        }
    }
}
