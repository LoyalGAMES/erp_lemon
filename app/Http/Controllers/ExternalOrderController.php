<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CourierAccount;
use App\Models\CustomerMessage;
use App\Models\CustomerPayment;
use App\Models\EmailTemplate;
use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\InternalNote;
use App\Models\ProductChannelMapping;
use App\Models\ShippingLabel;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Communication\CustomerMailPresentationService;
use App\Services\Inventory\StockReservationService;
use App\Services\Orders\HistoricalSplitReconciliationService;
use App\Services\Orders\HistoricalSplitSnapshot;
use App\Services\Orders\OrderCancellationService;
use App\Services\Orders\OrderEditingService;
use App\Services\Orders\OrderFulfillmentStatusService;
use App\Services\Orders\OrderMutationLock;
use App\Services\Orders\OrderPaymentLinkService;
use App\Services\Orders\OrderSplitReversalService;
use App\Services\Orders\OrderSplitService;
use App\Services\Packing\PackedOrderPickingResetService;
use App\Services\Packing\PackingTaskService;
use App\Services\Packing\ProductSegmentService;
use App\Services\Payments\OrderSettlementService;
use App\Services\Payments\PaymentMethodClassifier;
use App\Services\Shipping\ShippingCancellationService;
use App\Services\Shipping\ShippingLabelService;
use App\Services\Shipping\ShippingProviderResolver;
use App\Services\WooCommerce\WooCommerceOrderStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        OrderSettlementService $settlements,
        OrderSplitService $splitter,
        OrderSplitReversalService $splitReversalService,
        HistoricalSplitReconciliationService $historicalSplitReconciliationService,
        PackedOrderPickingResetService $packedOrderPickingResetService,
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
        $settlementOrder = $settlements->rootOrder($order);
        $settlementOrder->loadMissing('customerPayments');
        $settlementSummary = $settlements->summary($settlementOrder);
        $settlementPayments = $settlements->familyPayments($settlementOrder);
        $manualRefundAvailability = $settlements->manualRefundAvailability($settlementOrder, $settlementSummary);
        $cancellation = $settlementOrder->cancellationOperation()?->load(['steps', 'requestedBy']);
        $woocommerceOperations = (array) $settlementSummary['woocommerce_payment_records'];
        $erpOperations = (array) $settlementSummary['erp'];
        $ambiguousRefundAmount = (float) data_get($woocommerceOperations, 'pending.outgoing', 0)
            + (float) data_get($woocommerceOperations, 'processing.outgoing', 0)
            + (float) data_get($woocommerceOperations, 'unknown.outgoing', 0)
            + (float) data_get($erpOperations, 'pending.outgoing', 0)
            + (float) data_get($erpOperations, 'processing.outgoing', 0)
            + (float) data_get($erpOperations, 'unknown.outgoing', 0);
        $automaticRefundAllowed = data_get($settlementSummary, 'woo.paid') === true
            && (float) data_get($settlementSummary, 'woo.refundable', 0) > 0
            && (float) data_get($settlementSummary, 'balance', 0) > 0
            && $manualRefundAvailability['category'] === PaymentMethodClassifier::ONLINE
            && $ambiguousRefundAmount <= 0
            && $cancellation === null
            && (int) $settlementOrder->id === (int) $order->id;
        $orderOperationsLocked = $cancellation !== null
            || in_array(mb_strtolower((string) $order->status), [
                'cancellation-pending',
                'cancelled',
                'refunded',
            ], true);
        $splitAvailability = $splitter->availability($order);
        $splitReversal = $splitReversalService->availability($order);
        $historicalSplitReconciliation = Auth::user()?->isAdministrator() === true
            && $splitReversal['family']->count() > 1
            && ! $splitReversal['available']
            && ! is_array(data_get($splitReversal['root']->raw_payload, 'sempre_erp_split_original'))
                ? $historicalSplitReconciliationService->preview($order)
                : null;
        $packedOrderPickingReset = Auth::user()?->isAdministrator() === true
            && (in_array((string) $order->fulfillment_status, ['awaiting_courier', 'picking'], true)
                || is_array(data_get($order->raw_payload, 'sempre_erp_picking_reset')))
                    ? $packedOrderPickingResetService->preview($order)
                    : null;

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
            'settlementSummary' => $settlementSummary,
            'manualRefundAvailability' => $manualRefundAvailability,
            'automaticRefundAllowed' => $automaticRefundAllowed,
            'automaticRefundOperationId' => (string) Str::uuid(),
            'manualRefundOperationId' => (string) Str::uuid(),
            'incomingPaymentOperationId' => (string) Str::uuid(),
            'manualSplitOperationId' => (string) Str::uuid(),
            'shippingDecisionSplitOperationId' => (string) Str::uuid(),
            'orderCancellation' => $cancellation,
            'orderOperationsLocked' => $orderOperationsLocked,
            'settlementOrder' => $settlementOrder,
            'settlementIsRoot' => (int) $settlementOrder->id === (int) $order->id,
            'settlementPayments' => $settlementPayments,
            'splitAvailability' => $splitAvailability,
            'splitReversal' => $splitReversal,
            'historicalSplitReconciliation' => $historicalSplitReconciliation,
            'packedOrderPickingReset' => $packedOrderPickingReset,
            'pickingResetOperationId' => (string) Str::uuid(),
            'splitFamily' => $splitReversal['family'],
        ]);
    }

    public function edit(
        Request $request,
        ExternalOrder $order,
        OrderEditingService $editing,
        ShippingProviderResolver $shippingProviders,
    ): View {
        $this->assertCanEditOrder($request, $order);
        $order->load([
            'salesChannel',
            'lines.product',
            'packingTasks',
            'shipmentLabels',
            'invoices',
        ]);
        $returnTo = $request->query('return_to') === 'packing' ? 'packing' : 'order';
        $canViewOrders = Auth::user()?->canAccessArea('orders') ?? false;
        $cancellation = $order->cancellationOperation()?->load('steps');
        $cancellationRetryable = $cancellation !== null
            && (
                in_array($cancellation->status, ['requested', 'processing'], true)
                || $cancellation->steps->contains(
                    fn ($step): bool => in_array($step->status, ['failed'], true),
                )
            );
        $backUrl = $returnTo === 'packing' || ! $canViewOrders
            ? route('packing.index', ['view' => 'pack'])
            : route('orders.show', $order);

        return view('orders.edit', [
            'title' => 'Edycja zamówienia '.($order->external_number ?: $order->external_id),
            'subtitle' => 'Zmiana zostanie zapisana jednocześnie w WooCommerce, ERP, aktywnym pakowaniu i szkicu WZ.',
            'module' => $canViewOrders ? 'orders' : 'packing',
            'order' => $order,
            'editingAvailability' => $editing->availability($order),
            'productLookupUrl' => route('orders.products.lookup', $order),
            'billingTaxId' => $editing->billingTaxId($order),
            'targetPoint' => $editing->targetPoint($order),
            'shippingLine' => $editing->shippingLine($order),
            'paymentMethodLocked' => $editing->paymentMethodLocked($order),
            'cancellation' => $cancellation,
            'canCancelOrder' => $canViewOrders && ($cancellation === null || $cancellationRetryable),
            'canViewOrderDetails' => $canViewOrders,
            'expectedVersion' => $editing->version($order),
            'expectedRemoteModifiedAt' => $editing->expectedRemoteModifiedAt($order),
            'orderStatusOptions' => $this->orderStatusOptions((string) $order->status),
            'detectedShippingProvider' => $shippingProviders->providerForOrder($order),
            'returnTo' => $returnTo,
            'backUrl' => $backUrl,
        ]);
    }

    public function update(
        Request $request,
        ExternalOrder $order,
        OrderEditingService $editing,
        CustomerCommunicationService $communication,
    ): RedirectResponse {
        $this->assertCanEditOrder($request, $order);
        $validated = $request->validate([
            'expected_version' => ['required', 'string', 'size:64'],
            'expected_remote_modified_at' => ['nullable', 'string', 'max:80'],
            'return_to' => ['nullable', 'string', 'in:order,packing'],
            'billing' => ['required', 'array'],
            'billing.first_name' => ['nullable', 'string', 'max:100'],
            'billing.last_name' => ['nullable', 'string', 'max:100'],
            'billing.company' => ['nullable', 'string', 'max:200'],
            'billing.address_1' => ['nullable', 'string', 'max:200'],
            'billing.address_2' => ['nullable', 'string', 'max:200'],
            'billing.city' => ['nullable', 'string', 'max:120'],
            'billing.state' => ['nullable', 'string', 'max:120'],
            'billing.postcode' => ['nullable', 'string', 'max:32'],
            'billing.country' => ['nullable', 'string', 'size:2', 'alpha'],
            'billing.email' => ['nullable', 'email:rfc', 'max:254'],
            'billing.phone' => ['nullable', 'string', 'max:50'],
            'shipping' => ['required', 'array'],
            'shipping.first_name' => ['nullable', 'string', 'max:100'],
            'shipping.last_name' => ['nullable', 'string', 'max:100'],
            'shipping.company' => ['nullable', 'string', 'max:200'],
            'shipping.address_1' => ['nullable', 'string', 'max:200'],
            'shipping.address_2' => ['nullable', 'string', 'max:200'],
            'shipping.city' => ['nullable', 'string', 'max:120'],
            'shipping.state' => ['nullable', 'string', 'max:120'],
            'shipping.postcode' => ['nullable', 'string', 'max:32'],
            'shipping.country' => ['nullable', 'string', 'size:2', 'alpha'],
            'shipping.phone' => ['nullable', 'string', 'max:50'],
            'billing_tax_id' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z\- ]*$/'],
            'target_point' => ['nullable', 'string', 'max:40', 'regex:/^[0-9A-Za-z_-]*$/'],
            'customer_note' => ['nullable', 'string', 'max:5000'],
            'payment_method' => ['nullable', 'string', 'max:100', 'regex:/^[0-9A-Za-z_-]*$/'],
            'payment_method_title' => ['nullable', 'string', 'max:160'],
            'shipping_line' => ['nullable', 'array'],
            'shipping_line.id' => ['required_with:shipping_line', 'integer', 'min:1'],
            'shipping_line.method_id' => ['nullable', 'string', 'max:100'],
            'shipping_line.method_title' => ['nullable', 'string', 'max:160'],
            'shipping_line.total' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'max:999999.9999'],
            'lines.*.subtotal' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'lines.*.total' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'lines.*.remove' => ['nullable', 'boolean'],
            'new_line' => ['nullable', 'array'],
            'new_line.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'new_line.quantity' => ['nullable', 'numeric', 'min:0.0001', 'max:999999.9999'],
            'new_line.subtotal' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'new_line.total' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $details = [
            'billing' => (array) $validated['billing'],
            'shipping' => (array) $validated['shipping'],
            'billing_tax_id' => (string) ($validated['billing_tax_id'] ?? ''),
            'target_point' => (string) ($validated['target_point'] ?? ''),
            'customer_note' => (string) ($validated['customer_note'] ?? ''),
            'payment_method' => (string) ($validated['payment_method'] ?? ''),
            'payment_method_title' => (string) ($validated['payment_method_title'] ?? ''),
        ];

        if (array_key_exists('shipping_line', $validated)) {
            $details['shipping_line'] = (array) $validated['shipping_line'];
        }

        try {
            $result = $editing->updateOrder(
                $order,
                $details,
                (array) $validated['lines'],
                (array) ($validated['new_line'] ?? []),
                (string) $validated['expected_version'],
                (string) ($validated['expected_remote_modified_at'] ?? ''),
            );
        } catch (Throwable $exception) {
            return back()->withInput()->with('error', 'Nie udało się zapisać zamówienia: '.$exception->getMessage());
        }

        $freshOrder = $order->fresh() ?? $order;

        if ($result['lines_changed']) {
            $communication->sendOrderStatus($freshOrder, 'order_updated');
        }

        $message = "Zamówienie {$freshOrder->external_number} zapisano w WooCommerce i ERP.";

        if ($result['lines_changed']) {
            $message .= " Produkty: {$result['updated']} zmienionych, {$result['added']} dodanych, {$result['removed']} usuniętych.";
        }

        if ($result['warnings'] === []) {
            $message .= ' Rezerwacje, szkic WZ oraz pakowanie zostały odświeżone.';
        } else {
            $message .= ' Ostrzeżenia: '.implode(' | ', $result['warnings']);
        }

        $returnTo = ($validated['return_to'] ?? null) === 'packing' ? 'packing' : 'order';
        $canViewOrders = Auth::user()?->canAccessArea('orders') ?? false;

        return redirect()
            ->to($returnTo === 'packing' || ! $canViewOrders
                ? route('packing.index', ['view' => 'pack'])
                : route('orders.show', $freshOrder))
            ->with('status', $message);
    }

    public function storeManualShippingLabel(
        Request $request,
        ExternalOrder $order,
        ShippingLabelService $shippingLabels,
        ShippingProviderResolver $shippingProviders,
    ): RedirectResponse {
        $this->assertCanEditOrder($request, $order);

        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:inpost,gls'],
            'tracking_number' => ['required', 'string', 'regex:/^[0-9A-Za-z-]{8,40}$/'],
        ], [
            'tracking_number.regex' => 'Numer przesyłki powinien zawierać od 8 do 40 liter, cyfr lub myślników.',
        ]);

        $trackingNumber = trim((string) $validated['tracking_number']);
        try {
            $detectedProvider = $shippingProviders->providerForOrder($order);
            if ($detectedProvider !== null && $detectedProvider !== $validated['provider']) {
                throw new RuntimeException('Wybrany przewoźnik nie zgadza się z metodą dostawy zamówienia.');
            }
            $shippingLabels->registerManualShipment($order, (string) $validated['provider'], $trackingNumber);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Numer przesyłki {$trackingNumber} zapisano dla przewoźnika ".mb_strtoupper((string) $validated['provider']).'.');
    }

    public function lookupProducts(Request $request, ExternalOrder $order): JsonResponse
    {
        $this->assertCanEditOrder($request, $order);
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $like = '%'.$query.'%';

        return response()->json(
            ProductChannelMapping::query()
                ->with('product')
                ->where('sales_channel_id', $order->sales_channel_id)
                ->where('external_product_id', '!=', '')
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

    public function previewMessage(
        ExternalOrder $order,
        CustomerMessage $message,
        CustomerMailPresentationService $presentation,
    ): Response {
        abort_unless(
            (int) $message->external_order_id === (int) $order->id
                && $message->direction === 'outgoing'
                && $message->status === 'sent',
            404,
        );

        $deliverySnapshot = (array) $message->delivery_snapshot;
        $layout = is_array($deliverySnapshot['layout'] ?? null)
            ? $deliverySnapshot['layout']
            : null;
        $html = filled($message->rendered_html_snapshot)
            ? (string) $message->rendered_html_snapshot
            : $presentation->html($message, $layout);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; img-src https: http: data:; style-src 'unsafe-inline'; script-src 'none'; object-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function updateLines(
        Request $request,
        ExternalOrder $order,
        OrderEditingService $editing,
        CustomerCommunicationService $communication,
    ): RedirectResponse {
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

        if (($result['updated'] + $result['added'] + $result['removed']) > 0) {
            $communication->sendOrderStatus($order->fresh() ?? $order, 'order_updated');
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
        CustomerCommunicationService $communication,
        OrderCancellationService $cancellations,
        OrderMutationLock $orderMutationLock,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'cancellation_reason' => ['nullable', 'required_if:status,cancelled', 'string', 'min:3', 'max:1000'],
        ]);

        if ($validated['status'] === 'refunded') {
            return back()->withInput()->with(
                'error',
                'Status anulowania lub zwrotu można ustawić wyłącznie dedykowaną operacją, która bezpiecznie cofa dokumenty i rozliczenia.',
            );
        }

        if ($validated['status'] === 'cancelled') {
            try {
                $result = $cancellations->cancel(
                    $order,
                    (string) $validated['cancellation_reason'],
                    Auth::id(),
                    ['source' => 'order_status_edit'],
                );
            } catch (Throwable $exception) {
                return back()->withInput()->with('error', 'Nie udało się anulować zamówienia: '.$exception->getMessage());
            }

            return back()->with(
                $result['attention_required'] ? 'error' : 'status',
                $result['attention_required']
                    ? 'Anulowanie wymaga interwencji. '.implode(' | ', $result['warnings'])
                    : 'Zamówienie anulowano. Cofnięto wysyłkę, dokumenty i rezerwacje oraz zapisano rozliczenie.',
            );
        }

        if ($order->hasCancellationOperation()
            || in_array($order->status, ['cancellation-pending', 'cancelled', 'refunded'], true)) {
            return back()->withInput()->with(
                'error',
                'Anulowanego zamówienia ani zamówienia w trakcie anulacji nie można ponownie otworzyć zwykłą zmianą statusu.',
            );
        }

        try {
            $state = $orderMutationLock->forOrder(
                $order,
                function () use ($order, $validated, $statuses, $reservations, $packingTasks): array {
                    $freshOrder = ExternalOrder::query()->findOrFail($order->id);
                    $before = [
                        'status' => $freshOrder->status,
                        'fulfillment_status' => $freshOrder->fulfillment_status,
                    ];
                    $result = $statuses->updateManually($freshOrder, $validated['status']);
                    $warnings = [];
                    $freshOrder = $freshOrder->fresh();

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

                    return [
                        'before' => $before,
                        'result' => $result,
                        'warnings' => $warnings,
                        'order' => $freshOrder->fresh(),
                    ];
                },
            );
        } catch (Throwable $exception) {
            return back()->withInput()->with('error', 'Nie udało się zmienić statusu: '.$exception->getMessage());
        }

        $before = $state['before'];
        $result = $state['result'];
        $warnings = $state['warnings'];
        $freshOrder = $state['order'];
        $notificationTrigger = match ((string) $freshOrder->status) {
            'processing' => 'order_received',
            'cancelled' => 'order_cancelled',
            'failed' => 'order_cancelled',
            'refunded' => 'order_refunded',
            default => null,
        };

        if ($notificationTrigger !== null && $before['status'] !== $freshOrder->status) {
            try {
                $orderMutationLock->forOrderFamily(
                    $freshOrder,
                    function () use ($freshOrder, $notificationTrigger, $communication): void {
                        $activeOrder = ExternalOrder::query()->find($freshOrder->id);

                        if (! $activeOrder instanceof ExternalOrder || $activeOrder->status !== $freshOrder->status) {
                            return;
                        }

                        $communication->sendOrderStatus($activeOrder, $notificationTrigger);
                    },
                );
            } catch (Throwable $exception) {
                $warnings[] = 'wiadomość: '.$exception->getMessage();
            }
        }

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
        OrderMutationLock $orderLock,
    ): RedirectResponse {
        $validated = $request->validate([
            'payment_url' => ['required', 'url', 'max:1000'],
        ]);
        $scheme = mb_strtolower((string) parse_url($validated['payment_url'], PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return back()->withInput()->with('error', 'Link do płatności musi używać protokołu HTTPS lub HTTP.');
        }

        try {
            $orderLock->forOrderFamily($order, function () use ($order, $validated, $communication): void {
                $freshOrder = ExternalOrder::query()->find($order->id);

                if (! $freshOrder instanceof ExternalOrder) {
                    throw new RuntimeException('Zamówienie zostało zarchiwizowane. Odśwież widok.');
                }

                $communication->sendPaymentReminderForOrder($freshOrder, $validated['payment_url']);
            });
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Ponowienie prośby o wpłatę dla zamówienia {$order->external_number} zostało wysłane.");
    }

    public function sendMessage(
        Request $request,
        ExternalOrder $order,
        CustomerCommunicationService $communication,
        OrderMutationLock $orderLock,
    ): RedirectResponse {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $orderLock->forOrderFamily($order, function () use ($order, $validated, $communication): void {
                $freshOrder = ExternalOrder::query()->find($order->id);

                if (! $freshOrder instanceof ExternalOrder) {
                    throw new RuntimeException('Zamówienie zostało zarchiwizowane. Odśwież widok.');
                }

                $communication->sendManualForOrder($freshOrder, $validated['subject'], $validated['body']);
            });
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Wiadomość do klienta zamówienia {$order->external_number} została wysłana.");
    }

    public function storeNote(
        Request $request,
        ExternalOrder $order,
        OrderMutationLock $orderLock,
    ): RedirectResponse {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:3000'],
        ]);

        try {
            $orderLock->forOrderFamily($order, function () use ($order, $request, $validated): void {
                $freshOrder = ExternalOrder::query()->find($order->id);

                if (! $freshOrder instanceof ExternalOrder) {
                    throw new RuntimeException('Zamówienie zostało zarchiwizowane. Odśwież widok.');
                }

                InternalNote::query()->create([
                    'external_order_id' => $freshOrder->id,
                    'user_id' => Auth::id(),
                    'author_name' => Auth::user()?->name ?: (string) $request->server('PHP_AUTH_USER', 'ERP'),
                    'body' => $validated['body'],
                    'metadata' => ['source' => 'order_view'],
                ]);
            });
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Notatka wewnętrzna została dodana.');
    }

    public function storePayment(
        Request $request,
        ExternalOrder $order,
        CustomerCommunicationService $communication,
        OrderMutationLock $orderLock,
        OrderSettlementService $settlements,
    ): RedirectResponse {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'method' => ['required', 'string', 'in:blik,bank_transfer,cash,card,payu,other'],
            'reference' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'booked_at' => ['nullable', 'date'],
            'operation_id' => ['required', 'uuid'],
        ]);
        $root = $settlements->rootOrder($order);
        $amount = round((float) $validated['amount'], 2);
        $currency = mb_strtoupper((string) $validated['currency']);
        $idempotencyKey = 'manual-order-payment:'.$root->id.':'.$validated['operation_id'];

        try {
            $result = $orderLock->forOrders(
                $settlements->familyOrderIds($root),
                fn (): array => DB::transaction(function () use (
                    $root,
                    $settlements,
                    $validated,
                    $amount,
                    $currency,
                    $idempotencyKey,
                    $request,
                ): array {
                    $lockedRoot = ExternalOrder::query()->lockForUpdate()->findOrFail($root->id);
                    $existing = CustomerPayment::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing instanceof CustomerPayment) {
                        if ((int) $existing->external_order_id !== (int) $lockedRoot->id
                            || $existing->direction !== 'incoming'
                            || mb_strtolower((string) $existing->purpose) !== 'manual_order_payment'
                            || abs((float) $existing->amount - $amount) > 0.005
                            || mb_strtoupper((string) $existing->currency) !== $currency) {
                            throw new RuntimeException(
                                'Identyfikator wpłaty był już użyty z innymi danymi. Odśwież kartę zamówienia.',
                            );
                        }

                        return ['payment' => $existing, 'already_recorded' => true];
                    }

                    $summary = $settlements->summary($lockedRoot);

                    if (mb_strtoupper((string) $summary['currency']) !== $currency) {
                        throw new RuntimeException(
                            'Waluta wpłaty nie zgadza się z walutą zamówienia. Odśwież kartę zamówienia.',
                        );
                    }

                    if ($lockedRoot->hasCancellationOperation()
                        || in_array(mb_strtolower((string) $lockedRoot->status), [
                            'cancellation-pending',
                            'cancelled',
                            'refunded',
                        ], true)) {
                        throw new RuntimeException('Nie można dodać wpłaty do anulowanego zamówienia.');
                    }

                    $bookedAt = filled($validated['booked_at'] ?? null)
                        ? $validated['booked_at']
                        : now();
                    $payment = CustomerPayment::query()->create([
                        'external_order_id' => $lockedRoot->id,
                        'idempotency_key' => $idempotencyKey,
                        'direction' => 'incoming',
                        'source' => 'manual',
                        'purpose' => 'manual_order_payment',
                        'method' => $validated['method'],
                        'status' => 'booked',
                        'amount' => $amount,
                        'currency' => $currency,
                        'reference' => $validated['reference'] ?? null,
                        'description' => $validated['description'] ?? null,
                        'requested_at' => now(),
                        'booked_at' => $bookedAt,
                        'paid_at' => $bookedAt,
                        'metadata' => [
                            'source' => 'order_view',
                            'operation_id' => (string) $validated['operation_id'],
                            'booked_by' => Auth::user()?->name ?: (string) $request->server('PHP_AUTH_USER', 'ERP'),
                            'booked_by_user_id' => Auth::id(),
                        ],
                    ]);

                    return ['payment' => $payment, 'already_recorded' => false];
                }, 3),
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Nie zaksięgowano wpłaty: '.$exception->getMessage());
        }

        /** @var CustomerPayment $payment */
        $payment = $result['payment'];

        if ($result['already_recorded']) {
            return redirect()
                ->route('orders.show', $root)
                ->with('status', 'Ta wpłata była już zaksięgowana. Nie dodano drugiego wpisu.');
        }

        try {
            $communication->sendOrderStatus($root, 'order_payment_received', [
                'amount' => number_format((float) $payment->amount, 2, ',', ' '),
                'currency' => $payment->currency,
                'payment_reference' => $payment->reference,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('orders.show', $root)
                ->with(
                    'status',
                    'Wpłata klienta została zaksięgowana. Nie udało się wysłać powiadomienia, ale księgowanie pozostaje ważne: '.$exception->getMessage(),
                );
        }

        return redirect()
            ->route('orders.show', $root)
            ->with('status', 'Wpłata klienta została zaksięgowana w saldzie zamówienia.');
    }

    public function generateLabel(
        Request $request,
        ExternalOrder $order,
        ShippingLabelService $shippingLabels,
    ): RedirectResponse {
        $pickingReset = data_get($order->raw_payload, 'sempre_erp_picking_reset');
        $preservedLabel = is_array($pickingReset)
            && (string) ($pickingReset['status'] ?? '') === 'completed'
                ? ShippingLabel::query()
                    ->shipments()
                    ->where('external_order_id', $order->id)
                    ->where('status', 'generated')
                    ->latest('generated_at')
                    ->latest('id')
                    ->first()
                : null;

        if ($preservedLabel instanceof ShippingLabel) {
            return back()->with(
                'error',
                'Dla tego zamówienia zachowano etykietę '.$preservedLabel->trackingIdentifier().'. Nie utworzono drugiej przesyłki; istniejąca etykieta zostanie użyta przy ponownym pakowaniu.',
            );
        }

        $data = $request->validate([
            'courier_account_id' => ['nullable', 'integer', 'exists:courier_accounts,id'],
        ]);

        $account = filled($data['courier_account_id'] ?? null)
            ? CourierAccount::query()->where('is_active', true)->find((int) $data['courier_account_id'])
            : null;

        try {
            $label = $shippingLabels->generateForOrder($order, $account, forceNew: true);
        } catch (RuntimeException $exception) {
            return back()->with('error', 'Nie udało się wygenerować przesyłki: '.$exception->getMessage());
        }

        $message = "Przesyłka dla zamówienia {$order->external_number} została wygenerowana: {$label->filename()}.";

        if ($account instanceof CourierAccount) {
            $message .= " Konto nadawcze: {$account->name}.";
        }

        return back()->with('status', $message);
    }

    public function destroyLabel(
        ExternalOrder $order,
        ShippingLabel $label,
        ShippingCancellationService $cancellations,
    ): RedirectResponse {
        if ((int) $label->external_order_id !== (int) $order->id || $label->purpose !== 'shipment') {
            abort(404);
        }

        try {
            $result = $cancellations->deleteLabel($label);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if (! $result['deleted']) {
            return back()->with('error', implode(' ', array_column($result['manual_required'], 'message')));
        }

        return back()->with('status', 'Etykieta została anulowana u przewoźnika i usunięta z zamówienia.');
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
            'split_request_uuid' => ['nullable', 'uuid'],
        ]);

        $quantities = collect($validated['split_lines'])
            ->filter(fn ($line): bool => is_array($line) && (float) ($line['quantity'] ?? 0) > 0)
            ->mapWithKeys(fn (array $line, string|int $lineId): array => [(int) $lineId => (float) $line['quantity']])
            ->all();

        try {
            $splitOrder = $splitter->split(
                $order,
                $quantities,
                $validated['note'] ?? null,
                requestUuid: $validated['split_request_uuid'] ?? (string) Str::uuid(),
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('orders.show', $splitOrder)
            ->with('status', "Wydzielono zamówienie {$splitOrder->external_number}. Rezerwacje zostały przeliczone.");
    }

    public function reverseSplit(
        Request $request,
        ExternalOrder $order,
        OrderSplitReversalService $splitReversal,
    ): RedirectResponse {
        $actor = Auth::user();
        $reversalRoot = $splitReversal->availability($order)['root'];
        $historicalSnapshot = data_get($reversalRoot->raw_payload, 'sempre_erp_split_original');

        if (HistoricalSplitSnapshot::isVerified(is_array($historicalSnapshot) ? $historicalSnapshot : null)
            && (! $actor instanceof User || ! $actor->isAdministrator())) {
            abort(403, 'Tylko administrator może cofnąć zweryfikowany historyczny podział.');
        }

        $validated = $request->validate([
            'family_version' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'note' => ['nullable', 'string', 'max:1000'],
            'confirm_manual_shipping_cancellation' => ['nullable', 'boolean'],
        ], [
            'family_version.required' => 'Odśwież stronę i ponownie sprawdź części zamówienia przed cofnięciem rozdzielenia.',
            'family_version.size' => 'Stan rodziny zamówień jest nieprawidłowy. Odśwież stronę i spróbuj ponownie.',
            'family_version.regex' => 'Stan rodziny zamówień jest nieprawidłowy. Odśwież stronę i spróbuj ponownie.',
        ]);

        try {
            $rootOrder = $splitReversal->reverse(
                $order,
                $validated['family_version'],
                $validated['note'] ?? null,
                (bool) ($validated['confirm_manual_shipping_cancellation'] ?? false),
                $actor instanceof User ? $actor : null,
            );
        } catch (Throwable $exception) {
            if (! $exception instanceof RuntimeException) {
                report($exception);
            }

            $details = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Wystąpił nieoczekiwany błąd. Operację można bezpiecznie ponowić; system wznowi ją od ostatniego zapisanego kroku.';

            return back()
                ->withInput()
                ->with('error', 'Nie cofnięto rozdzielenia zamówienia. '.$details);
        }

        return redirect()
            ->route('orders.show', $rootOrder)
            ->with('status', "Cofnięto rozdzielenie zamówienia {$rootOrder->external_number}. Wszystkie aktywne części zostały scalone, a zamówienia częściowe zarchiwizowano.");
    }

    public function shippingDecision(
        Request $request,
        ExternalOrder $order,
        OrderSplitService $splitter,
        ProductSegmentService $segments,
        OrderMutationLock $orderLock,
    ): RedirectResponse {
        if ($order->hasCancellationOperation()
            || in_array($order->status, ['cancellation-pending', 'cancelled', 'refunded'], true)) {
            return back()->with(
                'error',
                'Nie można zmienić sposobu wysyłki anulowanego zamówienia ani zamówienia w trakcie anulacji.',
            );
        }

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:ship_footwear_now,wait_for_all'],
            'split_request_uuid' => ['nullable', 'uuid'],
        ]);

        $order->load('lines.product');

        $shippingDecision = [
            'decision' => $validated['decision'],
            'decided_by' => Auth::user()?->name,
            'decided_at' => now()->toISOString(),
        ];

        if ($validated['decision'] === 'wait_for_all') {
            try {
                $order = $orderLock->forOrderFamily($order, function () use ($order, $shippingDecision): ExternalOrder {
                    return DB::transaction(function () use ($order, $shippingDecision): ExternalOrder {
                        $freshOrder = ExternalOrder::query()->lockForUpdate()->find($order->id);

                        if (! $freshOrder instanceof ExternalOrder) {
                            throw new RuntimeException('Zamówienie zostało zarchiwizowane. Odśwież widok.');
                        }

                        if ($freshOrder->hasCancellationOperation()
                            || in_array($freshOrder->status, ['cancellation-pending', 'cancelled', 'refunded'], true)) {
                            throw new RuntimeException('Nie można zmienić sposobu wysyłki anulowanego zamówienia ani zamówienia w trakcie anulacji.');
                        }

                        $raw = (array) $freshOrder->raw_payload;
                        $raw['sempre_erp_shipping_decision'] = $shippingDecision;
                        $freshOrder->update(['raw_payload' => $raw]);

                        return $freshOrder;
                    }, 3);
                });
            } catch (RuntimeException $exception) {
                return back()->with('error', $exception->getMessage());
            }

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
                ['shipping_decision' => $shippingDecision],
                $validated['split_request_uuid'] ?? (string) Str::uuid(),
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
            'failed' => 'Nieudane',
            'cancelled' => 'Anulowane',
        ];

        if ($currentStatus !== '' && ! array_key_exists($currentStatus, $options)) {
            $options = [$currentStatus => $currentStatus] + $options;
        }

        return $options;
    }

    private function assertCanEditOrder(Request $request, ExternalOrder $order): void
    {
        $user = $request->attributes->get('erp_user') ?: Auth::user();

        if (! $user instanceof User || $user->role !== User::ROLE_PACKER) {
            return;
        }

        if (! $order->packingTasks()->whereIn('status', ['open', 'picked'])->exists()) {
            abort(403, 'Pakujący może edytować tylko zamówienie znajdujące się w aktywnym pakowaniu.');
        }
    }
}
