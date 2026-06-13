@php
    $returnDocuments = $returnCase->lines
        ->map(fn ($line) => $line->warehouseDocument)
        ->filter()
        ->push($returnCase->warehouseDocument)
        ->filter()
        ->unique('id')
        ->values();
    $allReturnDocumentsPosted = $returnDocuments->isNotEmpty()
        && $returnDocuments->every(fn ($document) => $document->status === 'posted');
@endphp

@if ($allReturnDocumentsPosted)
    <span class="status">Przyjęty</span>
    @if ($returnCase->correctionInvoice)
        <a class="button secondary" href="{{ route('invoices.preview', $returnCase->correctionInvoice) }}" target="_blank" rel="noopener">
            Korekta {{ $returnCase->correctionInvoice->number }}
        </a>
    @elseif ($returnCase->external_order_id)
        <form method="POST" action="{{ route('returns.correction.create', $returnCase) }}">
            @csrf
            <button class="button secondary" type="submit">Wystaw korektę</button>
        </form>
    @endif
@elseif ($returnDocuments->isNotEmpty())
    <span class="status blue">{{ $returnDocuments->pluck('number')->implode(', ') }}</span>
@else
    <a class="button secondary" href="{{ route('returns.edit', $returnCase) }}">Edytuj</a>
    <form method="POST" action="{{ route('returns.document.create', $returnCase) }}">
        @csrf
        <button class="button" type="submit">Utwórz RX</button>
    </form>
    <form method="POST" action="{{ route('returns.destroy', $returnCase) }}" onsubmit="return confirm('Usunąć zwrot {{ $returnCase->number }}?');">
        @csrf
        @method('DELETE')
        <button class="button danger" type="submit">Usuń</button>
    </form>
@endif
