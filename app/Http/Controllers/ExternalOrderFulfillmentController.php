<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExternalOrder;
use App\Models\WarehouseDocument;
use App\Services\Orders\OrderFulfillmentStatusService;
use App\Services\Orders\OrderMutationLock;
use App\Services\Orders\OrderWzDocumentService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class ExternalOrderFulfillmentController extends Controller
{
    public function createWz(
        ExternalOrder $order,
        OrderFulfillmentStatusService $fulfillmentStatus,
        OrderWzDocumentService $wzDocuments,
        OrderMutationLock $orderMutationLock,
    ): RedirectResponse {
        try {
            return $orderMutationLock->forOrder(
                $order,
                function () use ($fulfillmentStatus, $order, $wzDocuments): RedirectResponse {
                    $existingDocument = $fulfillmentStatus->latestWz($order);

                    if ($existingDocument?->status === 'posted') {
                        return back()->with('status', "WZ {$existingDocument->number} jest już zaksięgowane dla tego zamówienia.");
                    }

                    if ($existingDocument instanceof WarehouseDocument) {
                        return back()->with('status', "WZ {$existingDocument->number} istnieje już jako szkic. Zaksięguj dokument w module Dokumenty.");
                    }

                    $documents = $wzDocuments->ensureDrafts($order);

                    if ($documents === []) {
                        return back()->with('error', 'Brak aktywnych rezerwacji dla tego zamówienia. WZ nie został utworzony.');
                    }

                    $numbers = collect($documents)->pluck('number')->implode(', ');

                    return back()->with('status', 'Utworzono szkic WZ: '.$numbers.'. Zaksięguj dokument w module Dokumenty.');
                },
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }
}
