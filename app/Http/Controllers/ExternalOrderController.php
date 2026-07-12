<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CourierAccount;
use App\Models\CustomerPayment;
use App\Models\EmailTemplate;
use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\InternalNote;
use App\Models\ProductChannelMapping;
use App\Models\StockReservation;
use App\Services\Audit\AuditLogService;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Inventory\StockReservationService;
use App\Services\Orders\OrderEditingService;
use App\Services\Orders\OrderFulfillmentStatusService;
use App\Services\Orders\OrderPaymentLinkService;
use App\Services\Orders\OrderSplitService;
use App\Services\Packing\PackingTaskService;
use App\Services\Packing\ProductSegmentService;
use App\Services\Shipping\ShippingLabelService;
use App\Services\WooCommerce\WooCommerceOrderStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ExternalOrderController extends Controller
{
    public function show(
        ExternalOrder $order,
        OrderFulfillmentStatusService $fulfillmentStatus,
        ProductSegmentService $segments,
        OrderEditingService $editing,
        OrderPaymentLinkService $paymentLinks,
    ): View {
        $order->load([
            'salesChannel',
            'lines.product',
            'invoices.files',
            'invoices.ksefSubmissions',
            'packingTasks',
            'shippingLabels.courierAccount',
            'customerMessages',
            'internalNotes',
            'customerPayments',
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

        $latestInvoice = $order->invoices
            ->reject(fn ($invoice): bool => $invoice->type === 'proforma')
            ->sortByDesc('id')
            ->first();
        $latestProforma = $order->invoices
            ->where('type', 'proforma')
            ->sortByDesc('id')
            ->first();

        return view('orders.show', [
            'title' => 'Zamówienie '.($order->external_number ?: $order->external_id),
            'subtitle' => 'Szczegóły operacyjne zamówienia: pozycje, rezerwacje, WZ, faktury, pakowanie i notatki WooCommerce.',
            'module' => 'orders',
            'order' => $order,
            'reservations' => $reservations,
            'wzDocuments' => $wzDocuments,
            'latestWz' => $fulfillmentStatus->latestWz($order),
            'latestInvoice' => $latestInvoice,
            'latestProforma' => $latestProforma,
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
                ->where('is_active', true)
                ->orderBy('provider')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            'emailTemplates' => EmailTemplate::query()
                ->where('is_active', true)
                ->whereIn('context', ['order', 'both'])
                ->orderBy('name')
                ->get(),
            'lineEditing' => $editing->availability($order),
            'productLookupUrl' => route('orders.products.lookup', $order),
            'paymentUrl' => $paymentLinks->resolve($order),
            'orderStatusOptions' => $this->orderStatusOptions((string) $order->status),
        ]);
    }

    public function lookupProducts(Request $request, ExternalOrder $order): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $like = '%'.$query.'%';

        return response()->json(
            ProductChannelMapping::query()
                ->with('product')
                ->where('sales_channel_id', $order->sales_channel_id)
                ->whereHas('product', function (Builder $product) use ($like): void {
                    $product
                        ->where('is_translation', false)
                        ->where(function (Builder $product) use ($like): void {
                            $product
                                ->where('sku', 'like', $like)
                                ->orWhere('name', 'like', $like)
                                ->orWhere('ean', 'like', $like);
                        });
                })
                ->orderBy('external_sku')
                ->limit(20)
                ->get()
                ->filter(fn (ProductChannelMapping $mapping): bool => $mapping->product !== null)
                ->map(fn (ProductChannelMapping $mapping): array => [
                    'id' => $mapping->product->id,
                    'sku' => $mapping->product->sku,
                    'name' => $mapping->product->name,
                    'label' => $mapping->product->sku.' | '.$mapping->product->name,
                    'thumbnail_url' => $mapping->product->thumbnailUrl(72, 88),
                ])
                ->unique('id')
                ->values(),
        );
    }

    public function updateLines(Request $request, ExternalOrder $order, OrderEditingService $editing): RedirectResponse
    {
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'max:999999.9999'],
            'lines.*.remove' => ['nullable', 'boolean'],
            'new_line' => ['nullable', 'array'],
            'new_line.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'new_line.quantity' => ['nullable', 'numeric', 'min:0.0001', 'max:999999.9999'],
        ]);

        try {
            $result = $editing->updateLines(
                $order,
                (array) $validated['lines'],
                (array) ($validated['new_line'] ?? []),
            );
        } catch (Throwable $exception) {
            return back()->withInput()->with('error', 'Nie udało się zapisać pozycji: '.$exception->getMessage());
        }

        $message = "Zapisano pozycje zamówienia w WooCommerce i ERP: {$result['updated']} zmienionych, {$result['added']} dodanych, {$result['removed']} usuniętych.";

        if ($result['warnings'] !== []) {
            $message .= ' Ostrzeżenia: '.implode(' | ', $result['warnings']);
        }

        return back()->with('status', $message);
    }

    public function updateStatus(
        Request $request,
        ExternalOrder $order,
        WooCommerceOrderStatusService $statuses,
        StockReservationService $reservations,
        PackingTaskService $packingTasks,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
        ]);
        $before = ['status' => $order->status, 'fulfillment_status' => $order->fulfillment_status];

        try {
            $result = $statuses->updateManually($order, $validated['status']);
        } catch (Throwable $exception) {
            return back()->withInput()->with('error', 'Nie udało się zmienić statusu: '.$exception->getMessage());
        }

        $warnings = [];
        $freshOrder = $order->fresh();

        try {
            $reservations->syncForOrder($freshOrder);
        } catch (Throwable $exception) {
            $warnings[] = 'rezerwacje: '.$exception->getMessage();
        }

        try {
            $packingTasks->syncForOrder($freshOrder);
        } catch (Throwable $exception) {
            $warnings[] = 'pakowanie: '.$exception->getMessage();
        }

        $freshOrder = $order->fresh();
        $audit->record('order.status_updated', $freshOrder, $before, [
            'status' => $freshOrder->status,
            'fulfillment_status' => $freshOrder->fulfillment_status,
        ], [
            'source' => 'order_view',
            'warnings' => $warnings,
        ]);

        $message = "Status zamówienia zmieniono w WooCommerce i ERP na {$result['status']}.";

        if ($warnings !== []) {
            $message .= ' Ostrzeżenia: '.implode(' | ', $warnings);
        }

        return back()->with('status', $message);
    }

    public function sendPaymentReminder(
        Request $request,
        ExternalOrder $order,
        CustomerCommunicationService $communication,
    ): RedirectResponse {
        $validated = $request->validate([
            'payment_url' => ['required', 'url', 'max:1000'],
        ]);
        $scheme = mb_strtolower((string) parse_url($validated['payment_url'], PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return back()->withInput()->with('error', 'Link do płatności musi używać protokołu HTTPS lub HTTP.');
        }

        try {
            $communication->sendPaymentReminderForOrder($order, $validated['payment_url']);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Ponowienie prośby o wpłatę dla zamówienia {$order->external_number} zostało wysłane.");
    }

    public function sendMessage(
        Request $request,
        ExternalOrder $order,
        CustomerCommunicationService $communication,
    ): RedirectResponse {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $communication->sendManualForOrder($order, $validated['subject'], $validated['body']);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Wiadomość do klienta zamówienia {$order->external_number} została wysłana.");
    }

    public function storeNote(Request $request, ExternalOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:3000'],
        ]);

        InternalNote::query()->create([
            'external_order_id' => $order->id,
            'user_id' => Auth::id(),
            'author_name' => Auth::user()?->name ?: (string) $request->server('PHP_AUTH_USER', 'ERP'),
            'body' => $validated['body'],
            'metadata' => ['source' => 'order_view'],
        ]);

        return back()->with('status', 'Notatka wewnętrzna została dodana.');
    }

    public function storePayment(Request $request, ExternalOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'method' => ['required', 'string', 'in:blik,bank_transfer,cash,card,payu,other'],
            'reference' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'booked_at' => ['nullable', 'date'],
        ]);

        CustomerPayment::query()->create([
            'external_order_id' => $order->id,
            'direction' => 'incoming',
            'method' => $validated['method'],
            'status' => 'booked',
            'amount' => round((float) $validated['amount'], 2),
            'currency' => mb_strtoupper($validated['currency'] ?? $order->currency),
            'reference' => $validated['reference'] ?? null,
            'description' => $validated['description'] ?? null,
            'booked_at' => $validated['booked_at'] ?? now(),
            'metadata' => [
                'source' => 'order_view',
                'booked_by' => Auth::user()?->name ?: (string) $request->server('PHP_AUTH_USER', 'ERP'),
            ],
        ]);

        return back()->with('status', 'Wpłata klienta została zaksięgowana w saldzie zamówienia.');
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
            return back()->with('error', 'Nie udało się wygenerować przesyłki: '.$exception->getMessage());
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
    ): RedirectResponse {
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

    /**
     * @return array<string, string>
     */
    private function orderStatusOptions(string $currentStatus): array
    {
        $options = [
            'pending' => 'Oczekujące na płatność',
            'processing' => 'W realizacji',
            'on-hold' => 'Wstrzymane',
            'ready-to-ship' => 'Gotowe do wysyłki',
            'completed' => 'Zrealizowane',
            'cancelled' => 'Anulowane',
            'refunded' => 'Zwrócone',
            'failed' => 'Nieudane',
        ];

        if ($currentStatus !== '' && ! array_key_exists($currentStatus, $options)) {
            $options = [$currentStatus => $currentStatus] + $options;
        }

        return $options;
    }
}
