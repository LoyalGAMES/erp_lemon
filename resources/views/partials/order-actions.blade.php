@php
    $showDetailsLink = $showDetailsLink ?? true;
    $proforma = $proforma ?? null;
    $orderHasShipmentEvidence = (string) $order->fulfillment_status === 'shipped'
        || in_array(mb_strtolower((string) $order->status), ['completed', 'shipped'], true)
        || ($order->relationLoaded('packingTasks')
            && $order->packingTasks->contains(fn ($task): bool => (string) $task->status === 'shipped'));
    $canIssueInvoice = $wzDocument?->status === 'posted' || $orderHasShipmentEvidence;
@endphp

<div class="inline-actions">
    @if ($showDetailsLink)
        <a class="button secondary" href="{{ route('orders.show', $order) }}">Szczegóły</a>
    @endif

    @if ($invoice)
        <a class="button secondary" href="{{ route('invoices.edit', $invoice) }}">Faktura {{ $invoice->number }}</a>
    @endif

    @if ($proforma)
        <a class="button secondary" href="{{ route('invoices.edit', $proforma) }}">Proforma {{ $proforma->number }}</a>
        <form method="POST" action="{{ route('orders.proformas.destroy', [$order, $proforma]) }}" onsubmit="return confirm('Usunąć proformę {{ $proforma->number }}? Faktura VAT i zamówienie pozostaną bez zmian.');">
            @csrf
            @method('DELETE')
            <button class="button secondary" type="submit">Usuń proformę</button>
        </form>
    @elseif (! $invoice)
        <form method="POST" action="{{ route('orders.invoice.create', $order) }}">
            @csrf
            <input type="hidden" name="document_type" value="proforma">
            <button class="button secondary" type="submit">Wystaw proformę</button>
        </form>
    @endif

    @if (! $invoice && $canIssueInvoice)
        <form method="POST" action="{{ route('orders.invoice.create', $order) }}">
            @csrf
            <input type="hidden" name="document_type" value="vat">
            <button class="button" type="submit">Wystaw fakturę</button>
        </form>
        @if ($wzDocument?->status === 'posted')
            <span class="status">WZ zaksięgowane</span>
        @else
            <span class="status orange">Zamówienie wysłane · faktura zaległa</span>
        @endif
    @elseif (! $invoice && $wzDocument)
        <a class="button secondary" href="{{ route('documents.show', $wzDocument) }}">WZ w szkicu</a>
    @elseif (! $invoice && $activeReservations > 0)
        <form method="POST" action="{{ route('orders.wz.create', $order) }}">
            @csrf
            <button class="button" type="submit">Utwórz WZ</button>
        </form>
    @elseif (! $invoice)
        <span class="muted">Brak aktywnej rezerwacji</span>
    @endif
</div>
