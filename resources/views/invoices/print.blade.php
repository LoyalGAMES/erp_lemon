@php
    $seller = $invoice->seller_data ?? [];
    $buyer = $invoice->buyer_data ?? [];
    $logo = $assets['logo_data_uri'] ?? '';
    $ksefQr = $ksefQr ?? null;
    $money = fn ($value): string => number_format((float) $value, 2, ',', ' ');
    $qty = fn ($value): string => number_format((float) $value, floor((float) $value) === (float) $value ? 0 : 2, ',', ' ');
    $isCorrection = $invoice->type === 'correction';
    $documentTitle = $isCorrection ? 'Faktura korygująca' : 'Faktura VAT';
    $netSummaryLabel = $isCorrection ? 'Korekta netto' : 'Razem netto';
    $vatSummaryLabel = $isCorrection ? 'Korekta VAT' : 'Razem VAT';
    $grossSummaryLabel = $isCorrection ? 'Korekta brutto' : 'Do zapłaty';
    $settlementLabel = $isCorrection
        ? ((float) $invoice->gross_total < 0 ? 'Do zwrotu' : ((float) $invoice->gross_total > 0 ? 'Do dopłaty' : 'Saldo korekty'))
        : 'Kwota do zapłaty';
    $settlementAmount = $isCorrection ? abs((float) $invoice->gross_total) : (float) $invoice->gross_total;
    $vatSummary = $invoice->lines
        ->groupBy(fn ($line): string => number_format((float) $line->vat_rate, 2, '.', ''))
        ->map(fn ($lines): array => [
            'net' => $lines->sum(fn ($line): float => (float) $line->net_total),
            'vat' => $lines->sum(fn ($line): float => (float) $line->vat_total),
            'gross' => $lines->sum(fn ($line): float => (float) $line->gross_total),
        ]);
    $currencyConversion = (array) data_get($invoice->metadata, 'currency_conversion', []);
    $showCurrencyConversion = strtoupper((string) $invoice->currency) !== 'PLN' && $currencyConversion !== [];
    $vatSummaryPln = (array) ($currencyConversion['vat_summary_pln'] ?? []);
    $issuedBy = trim((string) data_get($invoice->metadata, 'issued_by_name', data_get($invoice->metadata, 'issued_by', 'Sempre ERP'))) ?: 'Sempre ERP';
    $correctedInvoice = $invoice->relationLoaded('correctedInvoice') ? $invoice->getRelation('correctedInvoice') : null;
    $lineSnapshot = fn ($line): array => [
        'name' => $line->name,
        'sku' => $line->sku,
        'unit' => $line->unit,
        'quantity' => (float) $line->quantity,
        'unit_net_price' => (float) $line->unit_net_price,
        'net_total' => (float) $line->net_total,
        'vat_rate' => (float) $line->vat_rate,
        'vat_total' => (float) $line->vat_total,
        'gross_total' => (float) $line->gross_total,
    ];
    $applyCorrectionLines = function (array $before, $correctionLines): array {
        $after = $before;

        foreach ($correctionLines as $correctionLine) {
            $after['quantity'] = round((float) $after['quantity'] + (float) $correctionLine->quantity, 4);
            $after['net_total'] = round((float) $after['net_total'] + (float) $correctionLine->net_total, 2);
            $after['vat_total'] = round((float) $after['vat_total'] + (float) $correctionLine->vat_total, 2);
            $after['gross_total'] = round((float) $after['gross_total'] + (float) $correctionLine->gross_total, 2);
        }

        return $after;
    };
    $matchesCorrectedLine = function ($correctionLine, $sourceLine): bool {
        $correctedLineId = data_get($correctionLine->metadata, 'corrected_invoice_line_id');

        if (is_numeric($correctedLineId)) {
            return (int) $correctedLineId === (int) $sourceLine->id;
        }

        if (! empty($correctionLine->sku) && ! empty($sourceLine->sku)) {
            return (string) $correctionLine->sku === (string) $sourceLine->sku;
        }

        return $correctionLine->product_id !== null && $correctionLine->product_id === $sourceLine->product_id;
    };
    $correctionBeforeRows = collect();
    $correctionAfterRows = collect();
    $matchedCorrectionLineIds = collect();

    if ($isCorrection && $correctedInvoice instanceof \App\Models\Invoice) {
        foreach ($correctedInvoice->lines as $sourceLine) {
            $before = $lineSnapshot($sourceLine);
            $correctionLines = $invoice->lines
                ->filter(fn ($line): bool => $matchesCorrectedLine($line, $sourceLine))
                ->values();

            $correctionLines->each(fn ($line) => $matchedCorrectionLineIds->push((int) $line->id));

            $correctionBeforeRows->push(['values' => $before]);
            $correctionAfterRows->push(['values' => $applyCorrectionLines($before, $correctionLines)]);
        }
    }

    foreach ($invoice->lines as $line) {
        if ($matchedCorrectionLineIds->contains((int) $line->id)) {
            continue;
        }

        $before = (array) data_get($line->metadata, 'before_correction', []);
        $after = (array) data_get($line->metadata, 'after_correction', []);

        if ($before !== [] && $after !== []) {
            $correctionBeforeRows->push(['values' => $before]);
            $correctionAfterRows->push(['values' => $after]);
        } elseif ($isCorrection && ! ($correctedInvoice instanceof \App\Models\Invoice)) {
            $correctionAfterRows->push(['values' => $lineSnapshot($line)]);
        }
    }

    $hasCorrectionComparison = $correctionBeforeRows->isNotEmpty() || $correctionAfterRows->isNotEmpty();
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle }} {{ $invoice->number }}</title>
    <style>
        @page { size: A4; margin: 20mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #202124;
            background: #fff;
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 9.2px;
            line-height: 1.34;
        }
        table { border-collapse: collapse; }
        .page { width: 100%; padding-bottom: 18mm; }
        .header-table, .meta-table, .party-table, .items-table, .summary-table, .vat-table, .payment-table, .totals-layout { width: 100%; }
        .header-table td { vertical-align: top; padding: 0; }
        .brand-cell { width: 42%; padding-right: 18px; }
        .logo { width: 108px; height: auto; display: block; margin-bottom: 8px; }
        .logo-fallback { margin-bottom: 8px; color: #202124; font-size: 18px; letter-spacing: 4px; font-weight: 700; }
        .document-title { margin: 0 0 4px; color: #202124; font-size: 21px; line-height: 1.12; font-weight: 700; }
        .document-number { color: #3f454b; font-size: 11.2px; font-weight: 700; }
        .document-note { margin-top: 5px; color: #5f666d; font-size: 8.2px; }
        .ksef-qr {
            width: 96px;
            margin-top: 8px;
            color: #30363d;
            font-size: 6.8px;
            line-height: 1.2;
            text-align: center;
        }
        .ksef-qr img {
            display: block;
            width: 54px;
            height: 54px;
            margin: 3px auto 2px;
        }
        .ksef-qr-label {
            display: block;
            color: #5f666d;
            font-weight: 700;
            letter-spacing: .02em;
            text-transform: uppercase;
        }
        .ksef-qr-number {
            display: block;
            color: #202124;
            font-size: 6.3px;
            font-weight: 700;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .meta-shell {
            width: 100%;
            border: 1px solid #d3d7dc;
            background: #fff;
        }
        .meta-table td {
            width: 50%;
            padding: 4px 6px 5px;
            border-bottom: 1px solid #e2e5e8;
            border-right: 1px solid #e2e5e8;
            vertical-align: top;
        }
        .meta-table tr:last-child td { border-bottom: 0; }
        .meta-table td:last-child { border-right: 0; }
        .label {
            display: block;
            color: #5f666d;
            font-size: 7.4px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
        }
        .value { display: block; margin-top: 1px; color: #202124; font-size: 9.4px; font-weight: 600; line-height: 1.25; }
        .section-title {
            margin: 11px 0 5px;
            color: #30363d;
            font-size: 8.5px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .party-table { margin-top: 9px; }
        .party-table td {
            width: 50%;
            padding: 8px 10px;
            vertical-align: top;
            border: 1px solid #d3d7dc;
            background: #fff;
        }
        .party-table td + td { border-left: 0; }
        .party-heading {
            margin-bottom: 5px;
            color: #3f454b;
            font-size: 7.8px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .party-name { margin-bottom: 3px; color: #202124; font-size: 10px; line-height: 1.25; font-weight: 700; }
        .tax-id { margin: 0 0 4px; font-weight: 700; }
        .muted { color: #5f666d; }
        .correction-box {
            margin-top: 10px;
            padding: 7px 9px;
            border: 1px solid #d3d7dc;
            background: #f7f8f9;
            color: #202124;
        }
        .items-table { margin-top: 5px; font-size: 8.2px; }
        .items-table th {
            padding: 5px 4px;
            background: #f0f2f4;
            border: 1px solid #cfd4da;
            color: #30363d;
            font-size: 7.5px;
            font-weight: 700;
            letter-spacing: .02em;
            line-height: 1.15;
            text-align: left;
            text-transform: uppercase;
        }
        .items-table th.right { text-align: right; }
        .items-table td {
            padding: 5px 4px;
            border: 1px solid #e0e4e8;
            vertical-align: top;
        }
        .items-table.compact-items th { padding: 4px 3px; font-size: 6.8px; }
        .items-table.compact-items td { padding: 4px 3px; font-size: 7.7px; }
        .items-table tbody tr:nth-child(even) td { background: #fafbfc; }
        .item-name { color: #202124; font-size: 8.4px; font-weight: 700; line-height: 1.25; word-break: break-word; overflow-wrap: anywhere; }
        .item-sku { margin-top: 2px; color: #5f666d; font-size: 7.5px; line-height: 1.2; word-break: break-word; overflow-wrap: anywhere; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }
        .totals-layout { margin-top: 10px; }
        .totals-layout td { vertical-align: top; }
        .totals-spacer { width: 44%; }
        .totals-stack { width: 56%; }
        .vat-table {
            width: 100%;
            margin-top: 7px;
            border: 1px solid #d3d7dc;
            background: #fff;
            font-size: 7.8px;
        }
        .vat-table th {
            padding: 4px 5px;
            background: #f0f2f4;
            color: #30363d;
            font-size: 7.2px;
            font-weight: 700;
            text-align: right;
            text-transform: uppercase;
        }
        .vat-table th:first-child, .vat-table td:first-child { text-align: left; }
        .vat-table td {
            padding: 4px 5px;
            border-top: 1px solid #e0e4e8;
            text-align: right;
        }
        .table-caption {
            margin-top: 7px;
            color: #30363d;
            font-size: 7.8px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .summary-table {
            width: 100%;
            margin-left: auto;
            border: 1px solid #d3d7dc;
        }
        .summary-table td {
            padding: 5px 7px;
            border-bottom: 1px solid #e0e4e8;
        }
        .summary-table tr:last-child td { border-bottom: 0; }
        .summary-table .total td {
            background: #f0f2f4;
            color: #202124;
            font-size: 10.2px;
            font-weight: 700;
            border-top: 2px solid #8f98a3;
        }
        .currency-note {
            margin-top: 6px;
            color: #5f666d;
            font-size: 7.6px;
            line-height: 1.3;
        }
        .payment-table {
            margin-top: 10px;
            border: 1px solid #d3d7dc;
            background: #fff;
        }
        .payment-table td {
            width: 33.333%;
            padding: 6px 8px;
            border-right: 1px solid #e0e4e8;
            vertical-align: top;
        }
        .payment-table td:last-child { border-right: 0; }
        .payment-value { display: block; margin-top: 2px; font-size: 8.8px; font-weight: 600; line-height: 1.25; word-break: break-word; overflow-wrap: anywhere; }
        .payment-total { color: #202124; font-size: 10.8px; font-weight: 700; }
        .footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            padding-top: 7px;
            border-top: 1px solid #d3d7dc;
            color: #5f666d;
            font-size: 7.6px;
        }
        .footer-table { width: 100%; }
        .footer-table td { padding: 0; vertical-align: top; }
        .footer-issued-by { text-align: right; color: #30363d; }
    </style>
</head>
<body>
    <main class="page">
        <table class="header-table">
            <tr>
                <td class="brand-cell">
                    @if ($logo !== '')
                        <img class="logo" src="{{ $logo }}" alt="SEMPRE">
                    @else
                        <div class="logo-fallback">SEMPRE</div>
                    @endif
                    <h1 class="document-title">{{ $documentTitle }}</h1>
                    <div class="document-number">{{ $invoice->number }}</div>
                    @if ($invoice->ksef_number)
                        <div class="document-note">Nr KSeF: {{ $invoice->ksef_number }}</div>
                    @endif
                    @if (is_array($ksefQr))
                        <div class="ksef-qr">
                            <span class="ksef-qr-label">Sprawdź fakturę w KSeF</span>
                            <img src="{{ $ksefQr['image_data_uri'] }}" alt="Kod QR KSeF">
                            <span class="ksef-qr-number">{{ $ksefQr['label'] }}</span>
                        </div>
                    @endif
                </td>
                <td>
                    <div class="meta-shell">
                        <table class="meta-table">
                            <tr>
                                <td>
                                    <span class="label">Data wystawienia</span>
                                    <span class="value">{{ $invoice->issue_date?->format('Y-m-d') }}</span>
                                </td>
                                <td>
                                    <span class="label">Data sprzedaży</span>
                                    <span class="value">{{ $invoice->sale_date?->format('Y-m-d') ?? '-' }}</span>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <span class="label">Termin płatności</span>
                                    <span class="value">{{ $invoice->payment_due_date?->format('Y-m-d') ?? '-' }}</span>
                                </td>
                                <td>
                                    <span class="label">Waluta</span>
                                    <span class="value">{{ $invoice->currency }}</span>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <span class="label">Płatność</span>
                                    <span class="value">{{ $invoice->payment_method ?: 'przelew' }}</span>
                                </td>
                                <td>
                                    <span class="label">Zamówienie</span>
                                    <span class="value">{{ data_get($invoice->metadata, 'external_order_number', $invoice->externalOrder?->external_number ?? '-') }}</span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        @if ($isCorrection)
            <div class="correction-box">
                <strong>Korekta do faktury:</strong> {{ data_get($invoice->metadata, 'corrected_invoice_number', '-') }}<br>
                <strong>Data faktury korygowanej:</strong> {{ data_get($invoice->metadata, 'corrected_invoice_issue_date', '-') }}<br>
                <strong>Powód korekty:</strong> {{ data_get($invoice->metadata, 'correction_reason', 'Zwrot towaru') }}
            </div>
        @endif

        <table class="party-table">
            <tr>
                <td>
                    <div class="party-heading">Sprzedawca</div>
                    <div class="party-name">{{ $seller['name'] ?? '' }}</div>
                    <div class="tax-id">NIP: {{ $seller['tax_id'] ?? '' }}</div>
                    {{ $seller['address_1'] ?? '' }}<br>
                    @if (! empty($seller['address_2']))
                        {{ $seller['address_2'] }}<br>
                    @endif
                    {{ $seller['postcode'] ?? '' }} {{ $seller['city'] ?? '' }}<br>
                    {{ $seller['country'] ?? '' }}
                    @if (! empty($seller['email']))
                        <br>{{ $seller['email'] }}
                    @endif
                    @if (! empty($seller['phone']))
                        <br>{{ $seller['phone'] }}
                    @endif
                </td>
                <td>
                    <div class="party-heading">Nabywca</div>
                    <div class="party-name">{{ $buyer['name'] ?? '' }}</div>
                    @if (! empty($buyer['tax_id']))
                        <div class="tax-id">NIP: {{ $buyer['tax_id'] }}</div>
                    @endif
                    {{ $buyer['address_1'] ?? '' }}
                    @if (! empty($buyer['address_2']))
                        {{ ' ' . $buyer['address_2'] }}
                    @endif
                    <br>
                    {{ $buyer['postcode'] ?? '' }} {{ $buyer['city'] ?? '' }}<br>
                    {{ $buyer['country'] ?? '' }}
                    @if (! empty($buyer['email']))
                        <br>{{ $buyer['email'] }}
                    @endif
                    @if (! empty($buyer['phone']))
                        <br>{{ $buyer['phone'] }}
                    @endif
                </td>
            </tr>
        </table>

        @if ($hasCorrectionComparison)
            <div class="section-title">Pozycje przed korektą</div>
            <table class="items-table compact-items">
                <thead>
                    <tr>
                        <th style="width: 22px;">Lp.</th>
                        <th style="width: 198px;">Nazwa towaru/usługi</th>
                        <th class="right" style="width: 48px;">Ilość / jm</th>
                        <th class="right" style="width: 58px;">Cena j. netto</th>
                        <th class="right" style="width: 58px;">Wartość netto</th>
                        <th class="right" style="width: 34px;">VAT</th>
                        <th class="right" style="width: 58px;">Kwota VAT</th>
                        <th class="right" style="width: 60px;">Brutto</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($correctionBeforeRows as $row)
                        @php($before = $row['values'])
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>
                                <div class="item-name">{{ $before['name'] ?? '' }}</div>
                                <div class="item-sku">SKU: {{ ($before['sku'] ?? null) ?: '-' }}</div>
                            </td>
                            <td class="right nowrap">{{ $qty($before['quantity'] ?? 0) }} {{ ($before['unit'] ?? null) ?: 'szt' }}</td>
                            <td class="right nowrap">{{ $money($before['unit_net_price'] ?? 0) }}</td>
                            <td class="right nowrap">{{ $money($before['net_total'] ?? 0) }}</td>
                            <td class="right nowrap">{{ $money($before['vat_rate'] ?? 0) }}%</td>
                            <td class="right nowrap">{{ $money($before['vat_total'] ?? 0) }}</td>
                            <td class="right nowrap">{{ $money($before['gross_total'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="section-title">Pozycje po korekcie</div>
            <table class="items-table compact-items">
                <thead>
                    <tr>
                        <th style="width: 22px;">Lp.</th>
                        <th style="width: 198px;">Nazwa towaru/usługi</th>
                        <th class="right" style="width: 48px;">Ilość / jm</th>
                        <th class="right" style="width: 58px;">Cena j. netto</th>
                        <th class="right" style="width: 58px;">Wartość netto</th>
                        <th class="right" style="width: 34px;">VAT</th>
                        <th class="right" style="width: 58px;">Kwota VAT</th>
                        <th class="right" style="width: 60px;">Brutto</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($correctionAfterRows as $row)
                        @php($after = $row['values'])
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>
                                <div class="item-name">{{ $after['name'] ?? '' }}</div>
                                <div class="item-sku">SKU: {{ ($after['sku'] ?? null) ?: '-' }}</div>
                            </td>
                            <td class="right nowrap">{{ $qty($after['quantity'] ?? 0) }} {{ ($after['unit'] ?? null) ?: 'szt' }}</td>
                            <td class="right nowrap">{{ $money($after['unit_net_price'] ?? 0) }}</td>
                            <td class="right nowrap">{{ $money($after['net_total'] ?? 0) }}</td>
                            <td class="right nowrap">{{ $money($after['vat_rate'] ?? 0) }}%</td>
                            <td class="right nowrap">{{ $money($after['vat_total'] ?? 0) }}</td>
                            <td class="right nowrap">{{ $money($after['gross_total'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
        <div class="section-title">{{ $isCorrection ? 'Pozycje korekty' : 'Pozycje faktury' }}</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 22px;">Lp.</th>
                    <th style="width: 198px;">Nazwa towaru/usługi</th>
                    <th class="right" style="width: 48px;">Ilość / jm</th>
                    <th class="right" style="width: 58px;">Cena j. netto</th>
                    <th class="right" style="width: 58px;">Wartość netto</th>
                    <th class="right" style="width: 34px;">VAT</th>
                    <th class="right" style="width: 58px;">Kwota VAT</th>
                    <th class="right" style="width: 60px;">Brutto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>
                            <div class="item-name">{{ $line->name }}</div>
                            <div class="item-sku">SKU: {{ $line->sku ?: '-' }}</div>
                        </td>
                        <td class="right nowrap">{{ $qty($line->quantity) }} {{ $line->unit ?: 'szt' }}</td>
                        <td class="right nowrap">{{ $money($line->unit_net_price) }}</td>
                        <td class="right nowrap">{{ $money($line->net_total) }}</td>
                        <td class="right nowrap">{{ $money($line->vat_rate) }}%</td>
                        <td class="right nowrap">{{ $money($line->vat_total) }}</td>
                        <td class="right nowrap">{{ $money($line->gross_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        <table class="totals-layout">
            <tr>
                <td class="totals-spacer">
                    @if ($showCurrencyConversion)
                        <div class="currency-note">
                            VAT w PLN: {{ $money((float) ($currencyConversion['vat_total_pln'] ?? 0)) }} PLN.
                            @if (! empty($currencyConversion['rate']))
                                Kurs VAT: 1 {{ $invoice->currency }} = {{ number_format((float) $currencyConversion['rate'], 4, ',', ' ') }} PLN,
                                tabela {{ $currencyConversion['table_no'] ?? 'NBP' }} z dnia {{ $currencyConversion['rate_date'] ?? '-' }}.
                            @else
                                {{ $currencyConversion['note'] ?? 'Kwota VAT nie wymaga przeliczenia.' }}
                            @endif
                        </div>
                    @endif
                </td>
                <td class="totals-stack">
                    <table class="summary-table">
                        <tr>
                            <td>{{ $netSummaryLabel }}</td>
                            <td class="right nowrap">{{ $money($invoice->net_total) }} {{ $invoice->currency }}</td>
                        </tr>
                        <tr>
                            <td>{{ $vatSummaryLabel }}</td>
                            <td class="right nowrap">{{ $money($invoice->vat_total) }} {{ $invoice->currency }}</td>
                        </tr>
                        <tr class="total">
                            <td>{{ $grossSummaryLabel }}</td>
                            <td class="right nowrap">{{ $money($invoice->gross_total) }} {{ $invoice->currency }}</td>
                        </tr>
                    </table>
                    <div class="table-caption">Podsumowanie VAT</div>
                    <table class="vat-table">
                        <thead>
                            <tr>
                                <th>Stawka VAT</th>
                                <th>Netto</th>
                                <th>VAT</th>
                                @if ($showCurrencyConversion)
                                    <th>VAT PLN</th>
                                @endif
                                <th>Brutto</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($vatSummary as $rate => $summary)
                                <tr>
                                    <td>{{ number_format((float) $rate, 2, ',', ' ') }}%</td>
                                    <td>{{ $money($summary['net']) }}</td>
                                    <td>{{ $money($summary['vat']) }}</td>
                                    @if ($showCurrencyConversion)
                                        <td>{{ $money((float) ($vatSummaryPln[$rate] ?? 0)) }}</td>
                                    @endif
                                    <td>{{ $money($summary['gross']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>
            </tr>
        </table>

        <table class="payment-table">
            <tr>
                <td>
                    <span class="label">Forma płatności</span>
                    <span class="payment-value">{{ $invoice->payment_method ?: 'przelew' }}</span>
                </td>
                <td>
                    <span class="label">Nr konta</span>
                    <span class="payment-value">{{ ! $isCorrection && ! empty($seller['bank_account']) ? $seller['bank_account'] : '-' }}</span>
                </td>
                <td>
                    <span class="label">{{ $settlementLabel }}</span>
                    <span class="payment-value payment-total">{{ $money($settlementAmount) }} {{ $invoice->currency }}</span>
                </td>
            </tr>
        </table>

        <div class="footer">
            <table class="footer-table">
                <tr>
                    <td>Dokument wystawiony elektronicznie w Sempre ERP.</td>
                    <td class="footer-issued-by">Wystawił: {{ $issuedBy }}</td>
                </tr>
            </table>
        </div>
    </main>
</body>
</html>
