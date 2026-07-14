<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dokument magazynowy {{ $document->number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #111; font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; line-height: 1.45; }
        .page { max-width: 980px; margin: 0 auto; padding: 28px; }
        .top { display: flex; justify-content: space-between; gap: 24px; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 16px; margin-bottom: 18px; }
        h1 { margin: 0 0 8px; font-size: 24px; line-height: 1.1; }
        .muted { color: #666; }
        .status { display: inline-block; border: 1px solid #111; border-radius: 4px; padding: 3px 8px; font-weight: 700; }
        .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 14px 0 20px; }
        .box { border: 1px solid #bbb; border-radius: 6px; padding: 10px; min-height: 58px; }
        .box span { display: block; color: #666; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 3px; }
        .box strong { font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 7px 8px; text-align: left; vertical-align: top; }
        th { background: #f1f1f1; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
        .numeric { text-align: right; font-variant-numeric: tabular-nums; }
        .section-title { margin-top: 22px; font-weight: 800; font-size: 14px; }
        .notes { border: 1px solid #ccc; border-radius: 6px; padding: 10px; white-space: pre-wrap; }
        .order-snapshot-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-top: 10px; }
        .order-snapshot-card { border: 1px solid #bbb; border-radius: 6px; padding: 10px; line-height: 1.5; overflow-wrap: anywhere; break-inside: avoid; }
        .order-snapshot-card > strong { display: block; margin-bottom: 5px; }
        .order-snapshot-label { color: #555; font-size: 10px; font-weight: 700; }
        .order-snapshot-note { grid-column: 1 / -1; white-space: pre-wrap; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; margin-top: 54px; }
        .signature { border-top: 1px solid #111; text-align: center; padding-top: 8px; color: #555; }
        .no-print { margin-bottom: 14px; }
        .button { border: 1px solid #111; border-radius: 5px; background: #fff; color: #111; padding: 7px 12px; font: inherit; cursor: pointer; text-decoration: none; display: inline-block; }
        @media print {
            .no-print { display: none; }
            .page { padding: 0; max-width: none; }
            body { padding: 16mm; }
        }
    </style>
</head>
<body>
    @php
        $qty = fn ($value) => number_format((float) $value, 4, ',', ' ');
    @endphp
    <main class="page">
        <div class="no-print">
            <button class="button" type="button" onclick="window.print()">Drukuj</button>
            <a class="button" href="{{ route('documents.show', $document) }}">Wróć do dokumentu</a>
        </div>

        <header class="top">
            <div>
                <h1>Dokument magazynowy {{ $document->number }}</h1>
                <div class="muted">Typ: {{ $document->type }} | Data: {{ $document->document_date?->format('Y-m-d H:i') ?? '-' }}</div>
            </div>
            <div class="status">{{ $document->status }}</div>
        </header>

        <section class="grid" aria-label="Dane dokumentu">
            <div class="box">
                <span>Magazyn źródłowy</span>
                <strong>{{ $document->sourceWarehouse?->code ?? '-' }}</strong><br>
                <small>{{ $document->sourceWarehouse?->name ?? '' }}</small>
            </div>
            <div class="box">
                <span>Magazyn docelowy</span>
                <strong>{{ $document->destinationWarehouse?->code ?? '-' }}</strong><br>
                <small>{{ $document->destinationWarehouse?->name ?? '' }}</small>
            </div>
            <div class="box">
                <span>Referencja</span>
                <strong>{{ $document->external_reference ?: '-' }}</strong>
            </div>
            <div class="box">
                <span>Zaksięgowano</span>
                <strong>{{ $document->posted_at?->format('Y-m-d H:i') ?? '-' }}</strong>
            </div>
        </section>

        @include('documents._order-snapshot', ['printMode' => true])

        <div class="section-title">Pozycje</div>
        <table>
            <thead>
                <tr>
                    <th>Lp.</th>
                    <th>SKU</th>
                    <th>Nazwa</th>
                    <th>JM</th>
                    <th class="numeric">Ilość</th>
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
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $line->product?->sku ?? '-' }}</strong></td>
                        <td>{{ $line->product?->name ?? '-' }}</td>
                        <td>{{ $line->product?->unit ?? 'szt' }}</td>
                        <td class="numeric">{{ $qty($line->quantity) }}</td>
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
                                {{ $returnNotes->implode(' | ') }}
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

        @if ($document->ledgerEntries->isNotEmpty())
            <div class="section-title">Ruchy ledger</div>
            <table>
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
                    @foreach ($document->ledgerEntries as $entry)
                        <tr>
                            <td>{{ $entry->posted_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>{{ $entry->warehouse?->code ?? '-' }}</td>
                            <td>{{ $entry->product?->sku ?? '-' }}</td>
                            <td>{{ $entry->direction }}</td>
                            <td class="numeric">{{ $qty($entry->quantity_change) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (filled($document->notes))
            <div class="section-title">Notatka</div>
            <div class="notes">{{ $document->notes }}</div>
        @endif

        <section class="signatures" aria-label="Podpisy">
            <div class="signature">Wystawił</div>
            <div class="signature">Przyjął / wydał</div>
        </section>
    </main>
</body>
</html>
