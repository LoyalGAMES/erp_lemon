@extends('layouts.app', ['title' => 'Dokumenty magazynowe', 'subtitle' => 'Dokument w statusie szkic nie zmienia stanu. Dopiero księgowanie tworzy ledger i aktualizuje stock balances.', 'module' => 'documents'])

@push('styles')
    <style>
        .document-filter-panel { margin-bottom: 14px; padding: 14px; display: grid; gap: 10px; }
        .document-filters { display: grid; grid-template-columns: minmax(240px, 1.6fr) repeat(3, minmax(150px, 1fr)) auto auto; gap: 10px; align-items: end; }
        .document-filters .button { min-height: 40px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; white-space: nowrap; }
        .filter-result-line { color: var(--muted); font-size: 12px; }
        .document-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .document-actions .button { min-width: 86px; }
        .document-line-summaries { display: grid; gap: 7px; min-width: 320px; }
        .document-line-summary { display: grid; gap: 3px; }
        .document-line-title { display: flex; flex-wrap: wrap; gap: 6px; align-items: baseline; }
        .document-line-title strong { font-size: 12px; }
        .document-line-title span { color: var(--muted); font-size: 12px; }
        .document-line-meta { display: flex; flex-wrap: wrap; gap: 6px; color: var(--muted); font-size: 11px; }
        .document-line-pill { display: inline-flex; align-items: center; border: 1px solid var(--border); border-radius: 999px; padding: 2px 7px; background: #fffdfb; white-space: nowrap; }
        .pagination-wrapper { padding: 14px 16px; border-top: 1px solid var(--border); }
        .pagination-bar { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
        .pagination-pages { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .pagination-page { min-width: 36px; min-height: 36px; border: 1px solid var(--border); border-radius: 8px; padding: 7px 10px; display: inline-flex; align-items: center; justify-content: center; color: var(--text); background: var(--surface); text-decoration: none; font-weight: 760; }
        .pagination-page.active { color: var(--green-dark); background: var(--green-soft); border-color: rgba(134, 115, 100, .34); }
        .pagination-page.disabled { opacity: .45; pointer-events: none; }
        .pagination-summary { color: var(--muted); font-size: 12px; }
        @media (max-width: 1120px) {
            .document-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            .document-filters { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')
    @php
        $qty = static fn ($value): string => number_format((float) $value, 0, ',', ' ');
        $money = static fn ($value): string => number_format((float) $value, 2, ',', ' ') . ' PLN';
    @endphp

    <div class="page-toolbar">
        <div class="toolbar-note">PZ/PW/RX/ZW/KOR przyjmują towar, WZ/RW wydają, MM przesuwa między magazynami.</div>
        <div class="document-actions">
            <a class="button secondary" href="{{ route('documents.export', array_filter($filters, fn ($value) => $value !== '')) }}">Eksport CSV</a>
            <a class="button" href="{{ route('documents.create') }}">Utwórz dokument</a>
        </div>
    </div>

    <article class="card document-filter-panel">
        <form class="document-filters" method="GET" action="{{ route('documents.index') }}">
            <label>Szukaj
                <input
                    name="q"
                    type="search"
                    value="{{ $filters['q'] }}"
                    placeholder="Numer, SKU, nazwa, EAN, notatka..."
                    autocomplete="off"
                >
            </label>
            <label>Typ
                <select name="type">
                    <option value="">Wszystkie</option>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </label>
            <label>Status
                <select name="status">
                    <option value="">Wszystkie</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </label>
            <label>Magazyn
                <select name="warehouse">
                    <option value="">Wszystkie</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected($filters['warehouse'] === (string) $warehouse->id)>{{ $warehouse->code }} - {{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="button secondary" type="submit">Filtruj</button>
            <a class="button secondary" href="{{ route('documents.index') }}">Wyczyść</a>
        </form>
        <div class="filter-result-line">
            Wynik: {{ $documents->total() }} dokumentów po filtrach.
        </div>
    </article>

    <article class="card">
        <div class="panel-header">
            <span>Dokumenty</span>
            <span>{{ $documents->total() }} rekordów</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Numer</th>
                        <th>Typ</th>
                        <th>Status</th>
                        <th>Data</th>
                        <th>Źródło</th>
                        <th>Cel</th>
                        <th>Pozycje</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($documents as $document)
                        <tr>
                            <td><strong>{{ $document->number }}</strong></td>
                            <td>{{ $document->type }}</td>
                            <td><span class="status {{ $document->status === 'draft' ? 'blue' : '' }}">{{ $document->status }}</span></td>
                            <td>{{ $document->document_date?->format('Y-m-d') ?? '-' }}</td>
                            <td>{{ $document->sourceWarehouse?->code ?? '-' }}</td>
                            <td>{{ $document->destinationWarehouse?->code ?? '-' }}</td>
                            <td>
                                <div class="document-line-summaries">
                                    @foreach ($document->lines as $line)
                                        @php
                                            $returnConditionLabels = collect((array) data_get($line->metadata, 'condition_labels'))->filter()->values();
                                            $returnDispositionLabels = collect((array) data_get($line->metadata, 'disposition_labels'))->filter()->values();
                                            $returnNotes = collect((array) data_get($line->metadata, 'return_notes'))->filter()->values();
                                        @endphp
                                        <div class="document-line-summary">
                                            <div class="document-line-title">
                                                <strong>{{ $line->product?->sku ?? '-' }}</strong>
                                                <span>{{ $line->product?->name ?? '-' }}</span>
                                            </div>
                                            <div class="document-line-meta">
                                                <span class="document-line-pill">Ilość: {{ $qty($line->quantity) }} {{ $line->product?->unit ?? 'szt' }}</span>
                                                @if ($line->unit_gross_price !== null)
                                                    <span class="document-line-pill">Cena zakupu: {{ $money($line->unit_gross_price) }}</span>
                                                @endif
                                                @if (filled(data_get($line->metadata, 'location')))
                                                    <span class="document-line-pill">Lokalizacja: {{ data_get($line->metadata, 'location') }}</span>
                                                @endif
                                                @if (filled(data_get($line->metadata, 'return_case_number')))
                                                    <span class="document-line-pill">Zwrot: {{ data_get($line->metadata, 'return_case_number') }}</span>
                                                @endif
                                                @if ($returnConditionLabels->isNotEmpty())
                                                    <span class="document-line-pill">Stan zwrotu: {{ $returnConditionLabels->implode(', ') }}</span>
                                                @endif
                                                @if ($returnDispositionLabels->isNotEmpty())
                                                    <span class="document-line-pill">Dyspozycja: {{ $returnDispositionLabels->implode(', ') }}</span>
                                                @endif
                                                @if ($returnNotes->isNotEmpty())
                                                    <span class="document-line-pill">Notatka: {{ $returnNotes->implode(' | ') }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                <div class="document-actions">
                                    <a class="button secondary" href="{{ route('documents.show', $document) }}">Szczegóły</a>
                                    <a class="button secondary" href="{{ route('documents.print', $document) }}" target="_blank" rel="noopener">Drukuj</a>
                                    @if ($document->status === 'draft')
                                        <a class="button secondary" href="{{ route('documents.edit', $document) }}">Edytuj</a>
                                        @include('partials.post-document-button', ['document' => $document])
                                        <form method="POST" action="{{ route('documents.cancel', $document) }}">
                                            @csrf
                                            <button class="button secondary" type="submit">Anuluj</button>
                                        </form>
                                    @elseif ($document->status === 'posted')
                                        <span class="muted">{{ $document->posted_at?->format('Y-m-d H:i') ?? 'Zaksięgowany' }}</span>
                                        <form method="POST" action="{{ route('documents.cancel', $document) }}">
                                            @csrf
                                            <button class="button secondary" type="submit">Anuluj</button>
                                        </form>
                                    @else
                                        <span class="muted">Anulowany {{ $document->cancelled_at?->format('Y-m-d H:i') ?? '' }}</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">Brak dokumentów. Utwórz dokument PZ, WZ, RW, RX, PW, MM, ZW albo KOR.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($documents->hasPages())
            <div class="pagination-wrapper">
                <div class="pagination-bar">
                    <div class="pagination-summary">
                        Strona {{ $documents->currentPage() }} z {{ $documents->lastPage() }}.
                        Rekordy {{ $documents->firstItem() }}-{{ $documents->lastItem() }} z {{ $documents->total() }}.
                    </div>
                    <div class="pagination-pages">
                        <a @class(['pagination-page', 'disabled' => $documents->onFirstPage()]) href="{{ $documents->previousPageUrl() ?: '#' }}">Poprzednia</a>
                        @foreach ($documents->getUrlRange(max(1, $documents->currentPage() - 2), min($documents->lastPage(), $documents->currentPage() + 2)) as $page => $url)
                            <a @class(['pagination-page', 'active' => $page === $documents->currentPage()]) href="{{ $url }}">{{ $page }}</a>
                        @endforeach
                        <a @class(['pagination-page', 'disabled' => ! $documents->hasMorePages()]) href="{{ $documents->nextPageUrl() ?: '#' }}">Następna</a>
                    </div>
                </div>
            </div>
        @endif
    </article>
@endsection
