@php
    $showDetailsLink = $showDetailsLink ?? true;
    $proforma = $proforma ?? null;
@endphp

<div class="inline-actions">
    @if ($showDetailsLink)
        <a class="button secondary" href="{{ route('orders.show', $order) }}">Szczegóły</a>
    @endif

    @if ($invoice)
        <a class="button secondary" href="{{ route('invoices.edit', $invoice) }}">Faktura {{ $invoice->number }}</a>
    @else
        @if ($proforma)
            <a class="button secondary" href="{{ route('invoices.edit', $proforma) }}">Proforma {{ $proforma->number }}</a>
        @else
            <form method="POST" action="{{ route('orders.invoice.create', $order) }}">
                @csrf
                <input type="hidden" name="document_type" value="proforma">
                <button class="button secondary" type="submit">Wystaw proformę</button>
            </form>
        @endif
    @endif

    @if (! $invoice && $wzDocument?->status === 'posted')
        <span class="status">WZ zaksięgowane</span>
        <form method="POST" action="{{ route('orders.invoice.create', $order) }}">
            @csrf
            <input type="hidden" name="document_type" value="vat">
            <button class="button" type="submit">Wystaw fakturę</button>
        </form>
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
