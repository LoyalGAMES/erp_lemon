@extends('layouts.app', [
    'title' => $title,
    'subtitle' => $subtitle,
    'module' => 'documents',
])

@push('styles')
    <style>
        .document-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .document-summary-card { padding: 14px 16px; }
        .document-summary-card span { display: block; color: var(--muted); font-size: 12px; font-weight: 720; margin-bottom: 4px; }
        .document-summary-card strong { font-size: 16px; }
        .document-section { margin-top: 16px; }
        .document-notes { padding: 16px; color: var(--muted); white-space: pre-wrap; }
        .document-page-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        @media (max-width: 960px) {
            .document-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 560px) {
            .document-summary { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')
    @php
        $qty = fn ($value) => number_format((float) $value, 4, ',', ' ');
        $statusClass = match ($document->status) {
            'posted' => '',
            'cancelled' => 'red',
            default => 'blue',
        };
    @endphp

    <div class="page-toolbar">
        <div class="document-page-actions">
            <a class="button secondary" href="{{ route('documents.index') }}">Wróć do listy</a>
            <a class="button secondary" href="{{ route('documents.print', $document) }}" target="_blank" rel="noopener">Drukuj</a>
            @if ($document->status === 'draft')
                <a class="button secondary" href="{{ route('documents.edit', $document) }}">Edytuj</a>
                @include('partials.post-document-button', ['document' => $document])
                <form method="POST" action="{{ route('documents.cancel', $document) }}">
                    @csrf
                    <button class="button secondary" type="submit">Anuluj</button>
                </form>
            @elseif ($document->status === 'posted')
                <form method="POST" action="{{ route('documents.cancel', $document) }}">
                    @csrf
                    <button class="button secondary" type="submit">Anuluj</button>
                </form>
            @endif
        </div>
        <span class="status {{ $statusClass }}">{{ $document->status }}</span>
    </div>

    <section class="document-summary" aria-label="Podsumowanie dokumentu">
        <article class="card document-summary-card">
            <span>Numer</span>
            <strong>{{ $document->number }}</strong>
        </article>
        <article class="card document-summary-card">
            <span>Typ</span>
            <strong>{{ $document->type }}</strong>
        </article>
        <article class="card document-summary-card">
            <span>Data dokumentu</span>
            <strong>{{ $document->document_date?->format('Y-m-d H:i') ?? '-' }}</strong>
        </article>
        <article class="card document-summary-card">
            <span>Referencja</span>
            <strong>{{ $document->external_reference ?: '-' }}</strong>
        </article>
        <article class="card document-summary-card">
            <span>Magazyn źródłowy</span>
            <strong>{{ $document->sourceWarehouse?->code ?? '-' }}</strong>
        </article>
        <article class="card document-summary-card">
            <span>Magazyn docelowy</span>
            <strong>{{ $document->destinationWarehouse?->code ?? '-' }}</strong>
        </article>
        <article class="card document-summary-card">
            <span>Zaksięgowano</span>
            <strong>{{ $document->posted_at?->format('Y-m-d H:i') ?? '-' }}</strong>
        </article>
        <article class="card document-summary-card">
            <span>Anulowano</span>
            <strong>{{ $document->cancelled_at?->format('Y-m-d H:i') ?? '-' }}</strong>
        </article>
    </section>

    <section class="card document-section">
        <div class="panel-header">
            <span>Pozycje dokumentu</span>
            <span>{{ $document->lines->count() }} pozycji</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Nazwa</th>
                        <th class="numeric">Ilość</th>
                        <th>JM</th>
                        <th class="numeric">Cena zakupu</th>
                        <th>Lokalizacja</th>
                        <th>Zwrot</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($document->lines as $line)
                        @php
                            $returnConditionLabels = collect((array) data_get($line->metadata, 'condition_labels'))->filter()->values();
                            $returnDispositionLabels = collect((array) data_get($line->metadata, 'disposition_labels'))->filter()->values();
                            $returnNotes = collect((array) data_get($line->metadata, 'return_notes'))->filter()->values();
                        @endphp
                        <tr>
                            <td><strong>{{ $line->product?->sku ?? '-' }}</strong></td>
                            <td>{{ $line->product?->name ?? '-' }}</td>
                            <td class="numeric">{{ $qty($line->quantity) }}</td>
                            <td>{{ $line->product?->unit ?? 'szt' }}</td>
                            <td class="numeric">{{ $line->unit_gross_price !== null ? number_format((float) $line->unit_gross_price, 2, ',', ' ') . ' PLN' : '-' }}</td>
                            <td>{{ data_get($line->metadata, 'location') ?: '-' }}</td>
                            <td>
                                @if (filled(data_get($line->metadata, 'return_case_number')))
                                    <strong>{{ data_get($line->metadata, 'return_case_number') }}</strong><br>
                                @endif
                                @if ($returnConditionLabels->isNotEmpty())
                                    Stan: {{ $returnConditionLabels->implode(', ') }}<br>
                                @endif
                                @if ($returnDispositionLabels->isNotEmpty())
                                    Dyspozycja: {{ $returnDispositionLabels->implode(', ') }}<br>
                                @endif
                                @if ($returnNotes->isNotEmpty())
                                    <span class="muted">{{ $returnNotes->implode(' | ') }}</span>
                                @endif
                                @if (
                                    blank(data_get($line->metadata, 'return_case_number'))
                                    && $returnConditionLabels->isEmpty()
                                    && $returnDispositionLabels->isEmpty()
                                    && $returnNotes->isEmpty()
                                )
                                    -
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="card document-section">
        <div class="panel-header">
            <span>Ruchy ledger</span>
            <span>{{ $document->ledgerEntries->count() }} wpisów</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Magazyn</th>
                        <th>SKU</th>
                        <th>Kierunek</th>
                        <th class="numeric">Zmiana</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($document->ledgerEntries as $entry)
                        <tr>
                            <td>{{ $entry->posted_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>{{ $entry->warehouse?->code ?? '-' }}</td>
                            <td>{{ $entry->product?->sku ?? '-' }}</td>
                            <td>{{ $entry->direction }}</td>
                            <td class="numeric">{{ $qty($entry->quantity_change) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">Brak ruchów ledger. Dokument jest szkicem albo został anulowany bez ruchu magazynowego.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if (filled($document->notes))
        <section class="card document-section">
            <div class="panel-header">Notatka</div>
            <div class="document-notes">{{ $document->notes }}</div>
        </section>
    @endif
@endsection
