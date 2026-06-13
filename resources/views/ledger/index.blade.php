@extends('layouts.app', [
    'title' => 'Ledger stanów',
    'subtitle' => 'Dziennik zaksięgowanych ruchów magazynowych z filtrowaniem i eksportem CSV.',
    'module' => 'ledger',
])

@section('content')
    @php
        $qty = fn ($value) => number_format((float) $value, 4, ',', ' ');
    @endphp

    <section class="metrics">
        <article class="card metric">
            <div class="metric-label">Przychody</div>
            <div class="metric-value">{{ $qty($summary['incoming']) }}</div>
            <div class="metric-caption">Suma dodatnich ruchów w filtrze</div>
        </article>
        <article class="card metric">
            <div class="metric-label">Rozchody</div>
            <div class="metric-value">{{ $qty($summary['outgoing']) }}</div>
            <div class="metric-caption">Suma ujemnych ruchów w filtrze</div>
        </article>
        <article class="card metric">
            <div class="metric-label">Saldo netto</div>
            <div class="metric-value">{{ $qty($summary['net']) }}</div>
            <div class="metric-caption">Przychody minus rozchody</div>
        </article>
        <article class="card metric">
            <div class="metric-label">Wpisy</div>
            <div class="metric-value">{{ $summary['count'] }}</div>
            <div class="metric-caption">Liczba ruchów spełniających filtr</div>
        </article>
    </section>

    <article class="card" style="margin-bottom: 18px;">
        <div class="panel-header">
            <span>Filtry ledger</span>
            <a class="button secondary" href="{{ route('ledger.export', request()->query()) }}">Eksport CSV</a>
        </div>
        <form class="form-grid" method="GET" action="{{ route('ledger.index') }}">
            <div style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px;">
                <label>Magazyn
                    <select name="warehouse_id">
                        <option value="">Wszystkie</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((string) ($filters['warehouse_id'] ?? '') === (string) $warehouse->id)>
                                {{ $warehouse->code }} - {{ $warehouse->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label>Produkt
                    <select name="product_id">
                        <option value="">Wszystkie</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((string) ($filters['product_id'] ?? '') === (string) $product->id)>
                                {{ $product->sku }} - {{ $product->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label>Typ dokumentu
                    <select name="document_type">
                        <option value="">Wszystkie</option>
                        @foreach ($documentTypes as $type)
                            <option value="{{ $type }}" @selected(($filters['document_type'] ?? '') === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Kierunek
                    <select name="direction">
                        <option value="">Wszystkie</option>
                        <option value="in" @selected(($filters['direction'] ?? '') === 'in')>Przychód</option>
                        <option value="out" @selected(($filters['direction'] ?? '') === 'out')>Rozchód</option>
                    </select>
                </label>
                <label>Data od
                    <input name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}">
                </label>
                <label>Data do
                    <input name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}">
                </label>
                <label>Szukaj
                    <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="SKU, nazwa, numer dokumentu">
                </label>
                <div class="inline-actions" style="align-items: end;">
                    <button class="button" type="submit">Filtruj</button>
                    <a class="button secondary" href="{{ route('ledger.index') }}">Wyczyść</a>
                </div>
            </div>
        </form>
    </article>

    <article class="card">
        <div class="panel-header">
            <span>Ruchy magazynowe</span>
            <span>{{ $entries->count() }} z {{ $summary['count'] }} rekordów</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Dokument</th>
                        <th>Typ</th>
                        <th>Magazyn</th>
                        <th>SKU</th>
                        <th>Produkt</th>
                        <th class="numeric">Zmiana</th>
                        <th>Kierunek</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td>{{ $entry->posted_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                            <td>{{ $entry->document?->number ?? '-' }}</td>
                            <td>{{ $entry->document?->type ?? '-' }}</td>
                            <td>{{ $entry->warehouse?->code ?? '-' }}</td>
                            <td>{{ $entry->product?->sku ?? '-' }}</td>
                            <td>{{ $entry->product?->name ?? '-' }}</td>
                            <td class="numeric">{{ $qty($entry->quantity_change) }}</td>
                            <td>
                                <span class="status {{ $entry->direction === 'out' ? 'orange' : '' }}">
                                    {{ $entry->direction === 'out' ? 'Rozchód' : 'Przychód' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">Brak ruchów magazynowych dla wybranych filtrów.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
@endsection
