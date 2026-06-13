<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\StockReservation;
use App\Services\Inventory\StockReservationService;
use App\Services\Orders\OrderFulfillmentStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ExternalOrderController extends Controller
{
    public function show(ExternalOrder $order, OrderFulfillmentStatusService $fulfillmentStatus): View
    {
        $order->load([
            'salesChannel',
            'lines.product',
            'invoices.files',
            'invoices.ksefSubmissions',
            'packingTasks',
            'shippingLabels',
        ]);

        $reservations = StockReservation::query()
            ->with(['warehouse', 'product'])
            ->where('sales_channel_id', $order->sales_channel_id)
            ->where('external_order_id', $order->external_id)
            ->latest('reserved_at')
            ->get();

        $wzDocuments = $fulfillmentStatus->wzDocumentsForOrder($order)
            ->with(['sourceWarehouse', 'destinationWarehouse', 'lines.product'])
            ->latest('document_date')
            ->get();

        $latestInvoice = $order->invoices->sortByDesc('id')->first();

        return view('orders.show', [
            'title' => 'Zamówienie ' . ($order->external_number ?: $order->external_id),
            'subtitle' => 'Szczegóły operacyjne zamówienia: pozycje, rezerwacje, WZ, faktury, pakowanie i notatki WooCommerce.',
            'module' => 'orders',
            'order' => $order,
            'reservations' => $reservations,
            'wzDocuments' => $wzDocuments,
            'latestWz' => $fulfillmentStatus->latestWz($order),
            'latestInvoice' => $latestInvoice,
            'activeReservations' => (float) $reservations
                ->where('status', 'active')
                ->sum(fn (StockReservation $reservation): float => (float) $reservation->quantity),
            'waitingReservations' => (float) $reservations
                ->where('status', 'waiting')
                ->sum(fn (StockReservation $reservation): float => (float) $reservation->quantity),
            'orderNotes' => collect(data_get($order->raw_payload, 'erp_imported_order_notes', [])),
        ]);
    }

    public function split(Request $request, ExternalOrder $order, StockReservationService $reservations): RedirectResponse
    {
        $validated = $request->validate([
            'split_lines' => ['required', 'array'],
            'split_lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $quantities = collect($validated['split_lines'])
            ->filter(fn ($line): bool => is_array($line) && (float) ($line['quantity'] ?? 0) > 0)
            ->mapWithKeys(fn (array $line, string|int $lineId): array => [(int) $lineId => (float) $line['quantity']])
            ->all();

        if ($quantities === []) {
            return back()->with('error', 'Podaj ilość co najmniej jednej pozycji do wydzielenia.');
        }

        $splitOrder = DB::transaction(function () use ($order, $quantities, $validated): ExternalOrder {
            $order = ExternalOrder::query()
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail($order->id);

            $splitIndex = ExternalOrder::query()
                ->where('sales_channel_id', $order->sales_channel_id)
                ->where('external_id', 'like', $order->external_id . '-SPLIT-%')
                ->count() + 1;

            $splitOrder = ExternalOrder::query()->create([
                'sales_channel_id' => $order->sales_channel_id,
                'external_id' => $order->external_id . '-SPLIT-' . $splitIndex,
                'external_number' => ($order->external_number ?: $order->external_id) . '/S' . $splitIndex,
                'status' => in_array($order->status, ['pending', 'processing', 'on-hold'], true) ? $order->status : 'processing',
                'currency' => $order->currency,
                'total_gross' => 0,
                'billing_data' => $order->billing_data,
                'shipping_data' => $order->shipping_data,
                'raw_payload' => array_replace_recursive((array) $order->raw_payload, [
                    'sempre_erp_split' => [
                        'parent_order_id' => $order->id,
                        'parent_external_id' => $order->external_id,
                        'note' => $validated['note'] ?? null,
                        'created_at' => now()->toISOString(),
                    ],
                ]),
                'external_created_at' => $order->external_created_at,
                'external_updated_at' => now(),
            ]);

            foreach ($quantities as $lineId => $quantity) {
                /** @var ExternalOrderLine|null $line */
                $line = $order->lines->firstWhere('id', $lineId);

                if (! $line instanceof ExternalOrderLine) {
                    continue;
                }

                $currentQuantity = (float) $line->quantity;
                $splitQuantity = min($quantity, $currentQuantity);

                if ($splitQuantity <= 0) {
                    continue;
                }

                $splitOrder->lines()->create([
                    'product_id' => $line->product_id,
                    'external_line_id' => $line->external_line_id ? $line->external_line_id . '-S' . $splitIndex : null,
                    'sku' => $line->sku,
                    'name' => $line->name,
                    'quantity' => $splitQuantity,
                    'unit_net_price' => $line->unit_net_price,
                    'unit_gross_price' => $line->unit_gross_price,
                    'vat_rate' => $line->vat_rate,
                    'raw_payload' => array_replace_recursive((array) $line->raw_payload, [
                        'sempre_erp_split' => [
                            'source_order_line_id' => $line->id,
                            'source_quantity' => $currentQuantity,
                        ],
                    ]),
                ]);

                $remainingQuantity = $currentQuantity - $splitQuantity;

                if ($remainingQuantity <= 0) {
                    $line->delete();
                } else {
                    $line->update(['quantity' => $remainingQuantity]);
                }
            }

            $order->update([
                'total_gross' => $this->grossTotalFromLines($order->refresh()->lines),
                'raw_payload' => array_replace_recursive((array) $order->raw_payload, [
                    'sempre_erp_split_child_orders' => array_values(array_filter([
                        ...((array) data_get($order->raw_payload, 'sempre_erp_split_child_orders', [])),
                        $splitOrder->external_id,
                    ])),
                ]),
            ]);
            $splitOrder->update(['total_gross' => $this->grossTotalFromLines($splitOrder->lines)]);

            return $splitOrder;
        });

        $reservations->syncForOrder($order);
        $reservations->syncForOrder($splitOrder);

        return redirect()
            ->route('orders.show', $splitOrder)
            ->with('status', "Wydzielono zamówienie {$splitOrder->external_number}. Rezerwacje zostały przeliczone.");
    }

    private function grossTotalFromLines($lines): float
    {
        return (float) collect($lines)->sum(
            fn (ExternalOrderLine $line): float => (float) ($line->unit_gross_price ?? 0) * (float) $line->quantity,
        );
    }
}
