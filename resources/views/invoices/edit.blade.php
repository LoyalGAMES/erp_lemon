@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@php
    $seller = $invoice->seller_data ?? [];
    $buyer = $invoice->buyer_data ?? [];
@endphp

@push('styles')
    <style>
        .invoice-line-table { min-width: 1120px; }
        .invoice-line-table th,
        .invoice-line-table td { vertical-align: top; }
        .invoice-line-table input { width: 100%; min-width: 78px; padding: 8px 9px; font-size: 13px; }
        .invoice-line-table .invoice-line-name { min-width: 260px; }
        .invoice-line-table .invoice-line-sku { min-width: 150px; }
        .invoice-line-table .invoice-line-unit { min-width: 70px; }
        .invoice-line-table .invoice-line-number { text-align: right; }
    </style>
@endpush

@section('content')
    <section class="page-toolbar">
        <div class="toolbar-note">Faktura {{ $invoice->number }} | {{ number_format((float) $invoice->gross_total, 2, ',', ' ') }} {{ $invoice->currency }}</div>
        <div class="inline-actions">
            <a class="button secondary" href="{{ route('invoices.index') }}">Wróć do faktur</a>
            <a class="button secondary" href="{{ route('invoices.preview', $invoice) }}" target="_blank" rel="noopener">Podgląd</a>
        </div>
    </section>

    @if ($isKsefAccepted)
        <div class="alert error">
            Faktura została przyjęta przez KSeF i nie może być edytowana. Do zmiany danych użyj faktury korygującej.
        </div>
    @endif

    @if ($validationState['is_blocking'])
        <div class="alert error">
            <strong>Walidacja: do poprawy.</strong>
            {{ implode(' ', $validationState['errors']) }}
        </div>
    @elseif ($validationState['warnings'])
        <div class="alert">
            <strong>Walidacja: ostrzeżenia.</strong>
            {{ implode(' ', $validationState['warnings']) }}
        </div>
    @else
        <div class="alert ok">Walidacja faktury: OK.</div>
    @endif

    <form class="form-grid" method="POST" action="{{ route('invoices.data.update', $invoice) }}">
        @csrf
        @method('PUT')

        <article class="card">
            <div class="panel-header">
                <span>Dane dokumentu</span>
                <span>{{ $invoice->status }}</span>
            </div>
            <div class="form-grid" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
                <label>Data wystawienia
                    <input name="issue_date" type="date" value="{{ old('issue_date', $invoice->issue_date?->toDateString()) }}" required @disabled($isKsefAccepted)>
                </label>
                <label>Data sprzedaży
                    <input name="sale_date" type="date" value="{{ old('sale_date', $invoice->sale_date?->toDateString()) }}" required @disabled($isKsefAccepted)>
                </label>
                <label>Termin płatności
                    <input name="payment_due_date" type="date" value="{{ old('payment_due_date', $invoice->payment_due_date?->toDateString()) }}" @disabled($isKsefAccepted)>
                </label>
                <label>Waluta
                    <input name="currency" value="{{ old('currency', $invoice->currency) }}" maxlength="3" required @disabled($isKsefAccepted)>
                </label>
                <label style="grid-column: 1 / -1;">Metoda płatności
                    <input name="payment_method" value="{{ old('payment_method', $invoice->payment_method) }}" @disabled($isKsefAccepted)>
                </label>
            </div>
        </article>

        <article class="card">
            <div class="panel-header">
                <span>KSeF</span>
                <span>{{ $ksefEligibility['label'] }}</span>
            </div>
            <div class="form-grid">
                <label>Kwalifikacja wysyłki
                    <select name="ksef_policy" @disabled($isKsefAccepted)>
                        @php
                            $selectedKsefPolicy = old('ksef_policy', $ksefEligibility['policy']);
                        @endphp
                        <option value="auto" @selected($selectedKsefPolicy === 'auto')>Automatycznie: B2B wysyłaj, B2C pomiń</option>
                        <option value="send" @selected($selectedKsefPolicy === 'send')>Wyślij do KSeF</option>
                        <option value="skip" @selected($selectedKsefPolicy === 'skip')>Nie wysyłaj do KSeF</option>
                    </select>
                </label>
                <p class="muted">{{ $ksefEligibility['reason'] }}</p>
                @if ($invoice->ksef_number)
                    <p class="muted">Nr KSeF: {{ $invoice->ksef_number }}</p>
                @endif
            </div>
        </article>

        <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px;">
            <article class="card">
                <div class="panel-header">
                    <span>Sprzedawca</span>
                    <span>Dane na fakturze</span>
                </div>
                <div class="form-grid">
                    <label>Nazwa <input name="seller[name]" value="{{ old('seller.name', $seller['name'] ?? '') }}" required @disabled($isKsefAccepted)></label>
                    <label>NIP <input name="seller[tax_id]" value="{{ old('seller.tax_id', $seller['tax_id'] ?? '') }}" required @disabled($isKsefAccepted)></label>
                    <label>Adres 1 <input name="seller[address_1]" value="{{ old('seller.address_1', $seller['address_1'] ?? '') }}" required @disabled($isKsefAccepted)></label>
                    <label>Adres 2 <input name="seller[address_2]" value="{{ old('seller.address_2', $seller['address_2'] ?? '') }}" @disabled($isKsefAccepted)></label>
                    <label>Kod pocztowy <input name="seller[postcode]" value="{{ old('seller.postcode', $seller['postcode'] ?? '') }}" @disabled($isKsefAccepted)></label>
                    <label>Miasto <input name="seller[city]" value="{{ old('seller.city', $seller['city'] ?? '') }}" @disabled($isKsefAccepted)></label>
                    <label>Kraj <input name="seller[country]" value="{{ old('seller.country', $seller['country'] ?? 'PL') }}" maxlength="2" required @disabled($isKsefAccepted)></label>
                    <label>E-mail <input name="seller[email]" type="email" value="{{ old('seller.email', $seller['email'] ?? '') }}" @disabled($isKsefAccepted)></label>
                    <label>Telefon <input name="seller[phone]" value="{{ old('seller.phone', $seller['phone'] ?? '') }}" @disabled($isKsefAccepted)></label>
                    <label>Nr konta <input name="seller[bank_account]" value="{{ old('seller.bank_account', $seller['bank_account'] ?? '') }}" @disabled($isKsefAccepted)></label>
                </div>
            </article>

            <article class="card">
                <div class="panel-header">
                    <span>Nabywca</span>
                    <span>Dane z zamówienia lub ręcznej korekty</span>
                </div>
                <div class="form-grid">
                    <label>Nazwa <input name="buyer[name]" value="{{ old('buyer.name', $buyer['name'] ?? '') }}" required @disabled($isKsefAccepted)></label>
                    <label>NIP <input name="buyer[tax_id]" value="{{ old('buyer.tax_id', $buyer['tax_id'] ?? '') }}" @disabled($isKsefAccepted)></label>
                    <label>Adres 1 <input name="buyer[address_1]" value="{{ old('buyer.address_1', $buyer['address_1'] ?? '') }}" required @disabled($isKsefAccepted)></label>
                    <label>Adres 2 <input name="buyer[address_2]" value="{{ old('buyer.address_2', $buyer['address_2'] ?? '') }}" @disabled($isKsefAccepted)></label>
                    <label>Kod pocztowy <input name="buyer[postcode]" value="{{ old('buyer.postcode', $buyer['postcode'] ?? '') }}" @disabled($isKsefAccepted)></label>
                    <label>Miasto <input name="buyer[city]" value="{{ old('buyer.city', $buyer['city'] ?? '') }}" @disabled($isKsefAccepted)></label>
                    <label>Kraj <input name="buyer[country]" value="{{ old('buyer.country', $buyer['country'] ?? 'PL') }}" maxlength="2" required @disabled($isKsefAccepted)></label>
                    <label>E-mail <input name="buyer[email]" type="email" value="{{ old('buyer.email', $buyer['email'] ?? '') }}" @disabled($isKsefAccepted)></label>
                    <label>Telefon <input name="buyer[phone]" value="{{ old('buyer.phone', $buyer['phone'] ?? '') }}" @disabled($isKsefAccepted)></label>
                </div>
            </article>
        </div>

        <article class="card">
            <div class="panel-header">
                <span>Pozycje faktury</span>
                <span>{{ $invoice->lines->count() }} pozycji</span>
            </div>
            <div class="table-scroll">
                <table class="dense-table invoice-line-table">
                    <thead>
                        <tr>
                            <th>Nazwa</th>
                            <th>SKU</th>
                            <th>Ilość</th>
                            <th>JM</th>
                            <th>Cena j. netto</th>
                            <th>Wartość netto</th>
                            <th>VAT %</th>
                            <th>Kwota VAT</th>
                            <th>Brutto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->lines as $line)
                            @php
                                $lineId = (int) $line->id;
                            @endphp
                            <tr>
                                <td class="invoice-line-name">
                                    <input type="hidden" name="lines[{{ $lineId }}][id]" value="{{ $lineId }}">
                                    <input name="lines[{{ $lineId }}][name]" value="{{ old("lines.$lineId.name", $line->name) }}" required @disabled($isKsefAccepted)>
                                </td>
                                <td class="invoice-line-sku">
                                    <input name="lines[{{ $lineId }}][sku]" value="{{ old("lines.$lineId.sku", $line->sku) }}" @disabled($isKsefAccepted)>
                                </td>
                                <td>
                                    <input class="invoice-line-number" name="lines[{{ $lineId }}][quantity]" type="number" step="0.0001" value="{{ old("lines.$lineId.quantity", $line->quantity) }}" required @disabled($isKsefAccepted)>
                                </td>
                                <td class="invoice-line-unit">
                                    <input name="lines[{{ $lineId }}][unit]" value="{{ old("lines.$lineId.unit", $line->unit) }}" required @disabled($isKsefAccepted)>
                                </td>
                                <td>
                                    <input class="invoice-line-number" name="lines[{{ $lineId }}][unit_net_price]" type="number" step="0.0001" value="{{ old("lines.$lineId.unit_net_price", $line->unit_net_price) }}" required @disabled($isKsefAccepted)>
                                </td>
                                <td>
                                    <input class="invoice-line-number" name="lines[{{ $lineId }}][net_total]" type="number" step="0.01" value="{{ old("lines.$lineId.net_total", $line->net_total) }}" required @disabled($isKsefAccepted)>
                                </td>
                                <td>
                                    <input class="invoice-line-number" name="lines[{{ $lineId }}][vat_rate]" type="number" step="0.01" min="0" max="100" value="{{ old("lines.$lineId.vat_rate", $line->vat_rate) }}" required @disabled($isKsefAccepted)>
                                </td>
                                <td>
                                    <input class="invoice-line-number" name="lines[{{ $lineId }}][vat_total]" type="number" step="0.01" value="{{ old("lines.$lineId.vat_total", $line->vat_total) }}" required @disabled($isKsefAccepted)>
                                </td>
                                <td>
                                    <input class="invoice-line-number" name="lines[{{ $lineId }}][gross_total]" type="number" step="0.01" value="{{ old("lines.$lineId.gross_total", $line->gross_total) }}" required @disabled($isKsefAccepted)>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted">Kwoty faktury zostaną przeliczone z wartości pozycji po zapisie.</p>
        </article>

        <button class="button" type="submit" @disabled($isKsefAccepted)>Zapisz i wygeneruj pliki ponownie</button>
    </form>
@endsection
