@php
    $seller = $invoice->seller_data ?? [];
    $buyer = $invoice->buyer_data ?? [];
    $logo = $assets['logo_data_uri'] ?? '';
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
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle }} {{ $invoice->number }}</title>
    <style>
        @page { margin: 24px 28px 28px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #161412;
            background: #fff;
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 11px;
            line-height: 1.42;
        }
        table { border-collapse: collapse; }
        .page { width: 100%; }
        .top-rule { height: 4px; background: #867364; margin-bottom: 16px; }
        .header-table, .meta-table, .party-table, .items-table, .summary-table, .vat-table, .payment-table { width: 100%; }
        .header-table td { vertical-align: top; padding: 0; }
        .brand-cell { width: 42%; }
        .logo { width: 150px; height: auto; display: block; margin-bottom: 13px; }
        .logo-fallback { margin-bottom: 13px; color: #161412; font-size: 28px; letter-spacing: 8px; font-weight: 400; }
        .document-title { margin: 0 0 7px; color: #161412; font-size: 29px; line-height: 1.05; font-weight: 900; }
        .document-number { color: #867364; font-size: 15px; font-weight: 900; }
        .document-note { margin-top: 8px; color: #71685f; font-size: 9.5px; }
        .meta-shell {
            width: 100%;
            border: 1px solid #d9d1ca;
            background: #fbfaf8;
        }
        .meta-table td {
            width: 50%;
            padding: 7px 10px 8px;
            border-bottom: 1px solid #e8e1dc;
            border-right: 1px solid #e8e1dc;
            vertical-align: top;
        }
        .meta-table tr:last-child td { border-bottom: 0; }
        .meta-table td:last-child { border-right: 0; }
        .label {
            display: block;
            color: #756b62;
            font-size: 8.8px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .value { display: block; margin-top: 2px; color: #161412; font-size: 12.2px; font-weight: 900; }
        .section-title {
            margin: 15px 0 6px;
            color: #867364;
            font-size: 9.6px;
            font-weight: 900;
            letter-spacing: .1em;
            text-transform: uppercase;
        }
        .party-table { margin-top: 8px; }
        .party-table td {
            width: 50%;
            padding: 12px 14px 11px;
            vertical-align: top;
            border: 1px solid #d9d1ca;
            background: #fff;
        }
        .party-table td + td { border-left: 0; }
        .party-heading {
            margin-bottom: 8px;
            color: #867364;
            font-size: 9.4px;
            font-weight: 900;
            letter-spacing: .12em;
            text-transform: uppercase;
        }
        .party-name { margin-bottom: 5px; color: #161412; font-size: 13.2px; line-height: 1.3; font-weight: 900; }
        .tax-id { margin: 0 0 6px; font-weight: 900; }
        .muted { color: #71685f; }
        .correction-box {
            margin-top: 18px;
            padding: 10px 12px;
            border-left: 5px solid #867364;
            background: #f3efec;
            color: #161412;
        }
        .items-table { margin-top: 7px; }
        .items-table th {
            padding: 7px 6px;
            background: #eee9e5;
            border-top: 1px solid #d9d1ca;
            border-bottom: 1px solid #d9d1ca;
            color: #5d544d;
            font-size: 8.7px;
            font-weight: 900;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .items-table td {
            padding: 8px 6px;
            border-bottom: 1px solid #e8e1dc;
            vertical-align: top;
        }
        .items-table tbody tr:nth-child(even) td { background: #fbfaf8; }
        .item-name { color: #161412; font-weight: 900; }
        .item-sku { margin-top: 3px; color: #71685f; font-size: 9.5px; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }
        .totals-layout { width: 100%; margin-top: 15px; }
        .totals-layout td { vertical-align: top; }
        .vat-table {
            width: 330px;
            border: 1px solid #d9d1ca;
            background: #fff;
        }
        .vat-table th {
            padding: 7px 8px;
            background: #eee9e5;
            color: #5d544d;
            font-size: 8.7px;
            font-weight: 900;
            text-align: right;
            text-transform: uppercase;
        }
        .vat-table th:first-child, .vat-table td:first-child { text-align: left; }
        .vat-table td {
            padding: 7px 8px;
            border-top: 1px solid #e8e1dc;
            text-align: right;
        }
        .summary-table {
            width: 330px;
            margin-left: auto;
            border: 1px solid #d9d1ca;
        }
        .summary-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e8e1dc;
        }
        .summary-table tr:last-child td { border-bottom: 0; }
        .summary-table .total td {
            background: #867364;
            color: #fff;
            font-size: 14px;
            font-weight: 900;
        }
        .currency-note {
            margin-top: 10px;
            color: #71685f;
            font-size: 9.5px;
            line-height: 1.35;
        }
        .payment-table {
            margin-top: 15px;
            border: 1px solid #d9d1ca;
            background: #fbfaf8;
        }
        .payment-table td {
            width: 33.333%;
            padding: 10px 12px;
            border-right: 1px solid #e8e1dc;
            vertical-align: top;
        }
        .payment-table td:last-child { border-right: 0; }
        .payment-value { display: block; margin-top: 3px; font-size: 13px; font-weight: 900; }
        .payment-total { color: #867364; font-size: 16px; }
        .footer {
            margin-top: 18px;
            padding-top: 11px;
            border-top: 1px solid #d9d1ca;
            color: #71685f;
            font-size: 9px;
        }
    </style>
</head>
<body>
    <main class="page">
        <div class="top-rule"></div>

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

        <div class="section-title">Pozycje faktury</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 26px;">Lp.</th>
                    <th style="width: 198px;">Nazwa towaru/usługi</th>
                    <th class="right nowrap" style="width: 44px;">Ilość</th>
                    <th class="right nowrap" style="width: 65px;">Cena netto</th>
                    <th class="right nowrap" style="width: 58px;">Netto</th>
                    <th class="right nowrap" style="width: 47px;">VAT</th>
                    <th class="right nowrap" style="width: 62px;">Kwota VAT</th>
                    <th class="right nowrap" style="width: 64px;">Brutto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>
                            <div class="item-name">{{ $line->name }}</div>
                            <div class="item-sku">SKU: {{ $line->sku ?: '-' }} | jm: {{ $line->unit ?: 'szt' }}</div>
                        </td>
                        <td class="right nowrap">{{ $qty($line->quantity) }}</td>
                        <td class="right nowrap">{{ $money($line->unit_net_price) }}</td>
                        <td class="right nowrap">{{ $money($line->net_total) }}</td>
                        <td class="right nowrap">{{ $money($line->vat_rate) }}%</td>
                        <td class="right nowrap">{{ $money($line->vat_total) }}</td>
                        <td class="right nowrap">{{ $money($line->gross_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals-layout">
            <tr>
                <td>
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
                <td>
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
            Dokument wystawiony elektronicznie w Sempre ERP.
        </div>
    </main>
</body>
</html>
