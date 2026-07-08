<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\StockReservation;
use App\Services\Orders\OrderFulfillmentStatusService;
use App\Services\Orders\OrderSplitService;
use App\Services\Packing\ProductSegmentService;
use App\Services\Shipping\ShippingLabelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

class ExternalOrderController extends Controller
{
    public function show(
        ExternalOrder $order,
        OrderFulfillmentStatusService $fulfillmentStatus,
        ProductSegmentService $segments,
    ): View {
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
            'orderSegments' => $segments->segmentsForOrder($order),
            'shippingDecision' => data_get($order->raw_payload, 'sempre_erp_shipping_decision'),
            'courierAccounts' => CourierAccount::query()
                ->where('provider', 'inpost')
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function generateLabel(
        Request $request,
        ExternalOrder $order,
        ShippingLabelService $shippingLabels,
    ): RedirectResponse {
        $data = $request->validate([
            'courier_account_id' => ['nullable', 'integer', 'exists:courier_accounts,id'],
        ]);

        $account = filled($data['courier_account_id'] ?? null)
            ? CourierAccount::query()->where('is_active', true)->find((int) $data['courier_account_id'])
            : null;

        try {
            $label = $shippingLabels->generateForOrder($order, $account);
        } catch (RuntimeException $exception) {
            return back()->with('error', 'Nie udało się wygenerować przesyłki: ' . $exception->getMessage());
        }

        $message = "Przesyłka dla zamówienia {$order->external_number} została wygenerowana: {$label->filename()}.";

        if ($account instanceof CourierAccount) {
            $message .= " Konto nadawcze: {$account->name}.";
        }

        return back()->with('status', $message);
    }

    public function split(
        Request $request,
        ExternalOrder $order,
        OrderSplitService $splitter,
    ): RedirectResponse
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

        try {
            $splitOrder = $splitter->split($order, $quantities, $validated['note'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('orders.show', $splitOrder)
            ->with('status', "Wydzielono zamówienie {$splitOrder->external_number}. Rezerwacje zostały przeliczone.");
    }

    public function shippingDecision(
        Request $request,
        ExternalOrder $order,
        OrderSplitService $splitter,
        ProductSegmentService $segments,
    ): RedirectResponse {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:ship_footwear_now,wait_for_all'],
        ]);

        $order->load('lines.product');

        $raw = (array) $order->raw_payload;
        $raw['sempre_erp_shipping_decision'] = [
            'decision' => $validated['decision'],
            'decided_by' => Auth::user()?->name,
            'decided_at' => now()->toISOString(),
        ];
        $order->update(['raw_payload' => $raw]);

        if ($validated['decision'] === 'wait_for_all') {
            return back()->with('status', "Zamówienie {$order->external_number} zostanie wysłane w całości po skompletowaniu wszystkich pozycji.");
        }

        $footwearQuantities = $order->lines
            ->filter(fn (ExternalOrderLine $line): bool => (float) $line->quantity > 0
                && $segments->segmentForLine($line) === ProductSegmentService::SEGMENT_FOOTWEAR)
            ->mapWithKeys(fn (ExternalOrderLine $line): array => [$line->id => (float) $line->quantity])
            ->all();

        if ($footwearQuantities === []) {
            return back()->with('error', 'To zamówienie nie zawiera pozycji obuwia do wydzielenia.');
        }

        if (count($footwearQuantities) === $order->lines->where('quantity', '>', 0)->count()) {
            return back()->with('error', 'Całe zamówienie to obuwie — nie ma czego wydzielać, zostanie wysłane standardowo.');
        }

        try {
            $splitOrder = $splitter->split(
                $order,
                $footwearQuantities,
                'Wysyłka butów od razu — decyzja z widoku zamówienia.',
                'ship_footwear_now',
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('orders.show', $splitOrder)
            ->with('status', "Buty z zamówienia trafiły do osobnego zamówienia {$splitOrder->external_number} i idą od razu do kompletacji. Reszta zamówienia czeka na skompletowanie.");
    }
}
