@php
    $showDetailsLink = $showDetailsLink ?? true;
@endphp

<div class="inline-actions">
    @if ($showDetailsLink)
        <a class="button secondary" href="{{ route('orders.show', $order) }}">Szczegóły</a>
    @endif

    @if ($invoice)
        <a class="button secondary" href="{{ route('invoices.edit', $invoice) }}">Faktura {{ $invoice->number }}</a>
    @elseif ($wzDocument?->status === 'posted')
        <span class="status">WZ zaksięgowane</span>
        <form method="POST" action="{{ route('orders.invoice.create', $order) }}">
            @csrf
            <button class="button" type="submit">Wystaw fakturę</button>
        </form>
    @elseif ($wzDocument)
        <a class="button secondary" href="{{ route('documents.show', $wzDocument) }}">WZ w szkicu</a>
    @elseif ($activeReservations > 0)
        <form method="POST" action="{{ route('orders.wz.create', $order) }}">
            @csrf
            <button class="button" type="submit">Utwórz WZ</button>
        </form>
    @else
        <span class="muted">Brak aktywnej rezerwacji</span>
    @endif
</div>
