@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@php
    $ksefStatusLabel = [
        'queued' => 'W kolejce',
        'running' => 'Przetwarzanie',
        'missing_configuration' => 'Brak konfiguracji',
        'requires_configuration' => 'Wymaga konfiguracji',
        'submitted' => 'Wysłana',
        'accepted' => 'Przyjęta',
        'rejected' => 'Odrzucona',
        'failed' => 'Błąd',
    ];
    $invoiceStatusLabel = [
        'issued' => 'Wystawiona',
        'draft' => 'Szkic',
        'cancelled' => 'Anulowana',
    ];
    $invoiceTypeLabel = [
        'vat' => 'Faktura VAT',
        'correction' => 'Korekta',
    ];
@endphp

@push('styles')
    <style>
        .invoice-validation-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .invoice-validation-card { padding: 14px 16px; }
        .invoice-validation-card span { display: block; color: var(--muted); font-size: 12px; font-weight: 720; margin-bottom: 4px; }
        .invoice-validation-card strong { display: block; font-size: 24px; line-height: 1; }
        .invoice-validation-card.blocking strong { color: var(--red); }
        .invoice-validation-card.warnings strong { color: var(--orange); }
        .invoice-validation-cell { min-width: 260px; white-space: normal; }
        .invoice-validation-details { margin-top: 7px; }
        .invoice-validation-details summary { cursor: pointer; color: var(--green-dark); font-size: 12px; font-weight: 760; }
        .invoice-validation-list { margin: 7px 0 0; padding-left: 18px; color: var(--muted); font-size: 12px; line-height: 1.35; }
        .invoice-validation-list li + li { margin-top: 4px; }
        .invoice-validation-list .error { color: var(--red); }
        .invoice-validation-list .warning { color: var(--orange); }
        .invoice-config-alert { display: grid; gap: 10px; margin-bottom: 16px; padding: 16px; border: 1px solid rgba(134, 115, 100, .28); background: rgba(134, 115, 100, .08); }
        .invoice-config-alert strong { display: block; font-size: 16px; }
        .invoice-config-alert ul { margin: 0; padding-left: 18px; color: var(--muted); line-height: 1.45; }
        .invoice-config-alert .error { color: var(--red); }
        .invoice-config-alert .warning { color: var(--orange); }
        .invoice-seller-status { margin-bottom: 14px; }
        @media (max-width: 900px) {
            .invoice-validation-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 560px) {
            .invoice-validation-summary { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')
    <section class="page-toolbar">
        <div class="toolbar-note">Szablon domyślny: {{ $template->name }} | renderer: {{ $template->renderer }}</div>
        <div class="inline-actions">
            <label class="button secondary" for="invoice-settings-drawer">Ustawienia faktur</label>
            <label class="button secondary" for="invoice-seller-drawer">Dane sprzedawcy</label>
            <label class="button" for="invoice-template-drawer">Edytuj szablon faktury</label>
        </div>
    </section>

    <div class="drawer-host">
        <input class="drawer-toggle" type="checkbox" id="invoice-settings-drawer">
        <label class="drawer-backdrop" for="invoice-settings-drawer" aria-label="Zamknij"></label>
        <aside class="drawer-panel" aria-label="Ustawienia faktur">
            <div class="drawer-header">
                <div>
                    <div class="drawer-title">Ustawienia faktur</div>
                    <div class="muted">Serie numeracji oraz domyślny termin płatności nowych faktur.</div>
                </div>
                <label class="drawer-close" for="invoice-settings-drawer">&times;</label>
            </div>
            <form class="form-grid" method="POST" action="{{ route('invoices.settings.update') }}">
                @csrf
                @method('PUT')
                <label>Seria faktur sprzedaży
                    <input name="sales_prefix" value="{{ old('sales_prefix', $numbering['sales_prefix']) }}" required>
                </label>
                <label>Seria korekt
                    <input name="correction_prefix" value="{{ old('correction_prefix', $numbering['correction_prefix']) }}" required>
                </label>
                <label>Format numeru
                    <input name="pattern" value="{{ old('pattern', $numbering['pattern']) }}" required>
                </label>
                <label>Długość numeru
                    <input name="padding" value="{{ old('padding', $numbering['padding']) }}" type="number" min="3" max="9" required>
                </label>
                <label>Termin płatności (dni)
                    <input name="payment_due_days" value="{{ old('payment_due_days', $numbering['payment_due_days']) }}" type="number" min="0" max="365" required>
                </label>
                <p class="muted">Tokeny: {PREFIX}, {YYYY}, {YY}, {MM}, {SEQ}. Przykład: {{ strtr($numbering['pattern'], ['{PREFIX}' => trim($numbering['sales_prefix'], '/'), '{YYYY}' => now()->format('Y'), '{YY}' => now()->format('y'), '{MM}' => now()->format('m'), '{SEQ}' => str_pad('1', (int) $numbering['padding'], '0', STR_PAD_LEFT)]) }}</p>
                <button class="button" type="submit">Zapisz ustawienia</button>
            </form>
        </aside>
    </div>

    <div class="drawer-host">
        <input class="drawer-toggle" type="checkbox" id="invoice-seller-drawer">
        <label class="drawer-backdrop" for="invoice-seller-drawer" aria-label="Zamknij"></label>
        <aside class="drawer-panel" aria-label="Dane sprzedawcy">
            <div class="drawer-header">
                <div>
                    <div class="drawer-title">Dane sprzedawcy</div>
                    <div class="muted">Te dane trafiają do nowych faktur i szablonu wydruku.</div>
                </div>
                <label class="drawer-close" for="invoice-seller-drawer">&times;</label>
            </div>
            <div class="invoice-config-alert invoice-seller-status">
                <strong>{{ $sellerStatus['is_ready'] ? 'Konfiguracja sprzedawcy jest kompletna' : 'Konfiguracja sprzedawcy wymaga uzupełnienia' }}</strong>
                @if ($sellerStatus['errors'] || $sellerStatus['warnings'])
                    <ul>
                        @foreach ($sellerStatus['errors'] as $message)
                            <li class="error">{{ $message }}</li>
                        @endforeach
                        @foreach ($sellerStatus['warnings'] as $message)
                            <li class="warning">{{ $message }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <form class="form-grid" method="POST" action="{{ route('invoices.seller.update') }}">
                @csrf
                @method('PUT')
                <label>Nazwa firmy <input name="name" value="{{ old('name', $seller['name'] ?? '') }}" required></label>
                <label>NIP <input name="tax_id" value="{{ old('tax_id', $seller['tax_id'] ?? '') }}" required inputmode="numeric" autocomplete="off"></label>
                <label>Adres 1 <input name="address_1" value="{{ old('address_1', $seller['address_1'] ?? '') }}" required></label>
                <label>Adres 2 <input name="address_2" value="{{ old('address_2', $seller['address_2'] ?? '') }}"></label>
                <label>Kod pocztowy <input name="postcode" value="{{ old('postcode', $seller['postcode'] ?? '') }}"></label>
                <label>Miasto <input name="city" value="{{ old('city', $seller['city'] ?? '') }}"></label>
                <label>Kraj <input name="country" value="{{ old('country', $seller['country'] ?? 'PL') }}" required maxlength="2"></label>
                <label>E-mail <input name="email" value="{{ old('email', $seller['email'] ?? '') }}" type="email"></label>
                <label>Telefon <input name="phone" value="{{ old('phone', $seller['phone'] ?? '') }}"></label>
                <label>Nr konta <input name="bank_account" value="{{ old('bank_account', $seller['bank_account'] ?? '') }}"></label>
                <button class="button" type="submit">Zapisz dane sprzedawcy</button>
            </form>
        </aside>
    </div>

    <div class="drawer-host">
        <input class="drawer-toggle" type="checkbox" id="invoice-template-drawer">
        <label class="drawer-backdrop" for="invoice-template-drawer" aria-label="Zamknij"></label>
        <aside class="drawer-panel" aria-label="Edytuj szablon faktury">
            <div class="drawer-header">
                <div>
                    <div class="drawer-title">Szablon faktury</div>
                    <div class="muted">Blade HTML z dostępem do zmiennej $invoice.</div>
                </div>
                <label class="drawer-close" for="invoice-template-drawer">&times;</label>
            </div>
            <form class="form-grid template-editor" method="POST" action="{{ route('invoices.template.update') }}">
                @csrf
                @method('PUT')
                <label>Nazwa szablonu
                    <input name="name" value="{{ old('name', $template->name) }}" required>
                </label>
                <label>Treść szablonu
                    <textarea name="template_body" spellcheck="false" required>{{ old('template_body', $template->template_body) }}</textarea>
                </label>
                <button class="button" type="submit">Zapisz szablon</button>
            </form>
        </aside>
    </div>

    @if (! $sellerStatus['is_ready'] || $sellerStatus['warnings'])
        <section class="card invoice-config-alert" aria-label="Status konfiguracji sprzedawcy">
            <strong>{{ $sellerStatus['is_ready'] ? 'Sprawdź konfigurację faktur' : 'Uzupełnij dane sprzedawcy przed wysyłką faktur' }}</strong>
            <ul>
                @foreach ($sellerStatus['errors'] as $message)
                    <li class="error">{{ $message }}</li>
                @endforeach
                @foreach ($sellerStatus['warnings'] as $message)
                    <li class="warning">{{ $message }}</li>
                @endforeach
            </ul>
            <div>
                <label class="button secondary" for="invoice-seller-drawer">Otwórz dane sprzedawcy</label>
            </div>
        </section>
    @endif

    @if ($sellerStatus['is_ready'] && $sellerRepairableCount > 0)
        <section class="card invoice-config-alert" aria-label="Faktury wymagające uzupełnienia danych sprzedawcy">
            <strong>Faktury z nieaktualnymi danymi sprzedawcy: {{ $sellerRepairableCount }}</strong>
            <div class="muted">System może uzupełnić aktualne dane sprzedawcy z ustawień i wygenerować ponownie pliki HTML/PDF dla faktur, które nie zostały przyjęte przez KSeF.</div>
            <form method="POST" action="{{ route('invoices.seller.apply-batch') }}">
                @csrf
                <button class="button secondary" type="submit">Uzupełnij sprzedawcę we wszystkich</button>
            </form>
        </section>
    @endif

    @if ($woocommercePendingCount > 0)
        <section class="card invoice-config-alert" aria-label="Faktury oczekujące na wysyłkę do WooCommerce">
            <strong>Faktury oczekujące na wysyłkę do WooCommerce: {{ $woocommercePendingCount }}</strong>
            <div class="muted">System wyśle tylko poprawne faktury powiązane z zamówieniami i pominie dokumenty, które mają błędy walidacji.</div>
            <form method="POST" action="{{ route('invoices.woocommerce.upload-pending') }}">
                @csrf
                <button class="button secondary" type="submit">Wyślij zaległe faktury do WooCommerce</button>
            </form>
        </section>
    @endif

    <section class="invoice-validation-summary" aria-label="Podsumowanie walidacji faktur">
        <article class="card invoice-validation-card">
            <span>Gotowe do wysyłki</span>
            <strong>{{ $validationSummary['ready'] }}</strong>
        </article>
        <article class="card invoice-validation-card blocking">
            <span>Do poprawy</span>
            <strong>{{ $validationSummary['blocking'] }}</strong>
        </article>
        <article class="card invoice-validation-card warnings">
            <span>Z ostrzeżeniami</span>
            <strong>{{ $validationSummary['warnings'] }}</strong>
        </article>
        <article class="card invoice-validation-card">
            <span>Komunikaty walidacji</span>
            <strong>{{ $validationSummary['messages'] }}</strong>
        </article>
    </section>

    <article class="card">
        <div class="panel-header">
            <span>Faktury sprzedaży</span>
            <span>{{ $invoices->count() }} rekordów</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Numer</th>
                        <th>Typ</th>
                        <th>Status</th>
                        <th>Walidacja</th>
                        <th>Brutto</th>
                        <th>WooCommerce</th>
                        <th>KSeF</th>
                        <th>Szablon</th>
                        <th>Pliki</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        @php
                            $latestKsef = $invoice->ksefSubmissions->sortByDesc('id')->first();
                            $htmlFile = $invoice->files->firstWhere('type', 'html');
                            $pdfFile = $invoice->files->firstWhere('type', 'pdf');
                            $validationState = $validation->get($invoice->id, ['errors' => [], 'warnings' => [], 'is_blocking' => false]);
                            $hasSellerErrors = collect($validationState['errors'])
                                ->contains(fn (string $message): bool => str_contains($message, 'sprzedawcy'));
                            $isKsefAccepted = filled($invoice->ksef_number)
                                || $invoice->ksefSubmissions->contains(fn ($submission): bool => $submission->status === 'accepted');
                            $ksefState = $ksefEligibility->get($invoice->id, ['label' => 'Auto', 'reason' => '', 'tone' => '']);
                            $ksefTone = $ksefState['tone'] ?? '';
                        @endphp
                        <tr>
                            <td>{{ $invoice->number }}</td>
                            <td>{{ $invoiceTypeLabel[$invoice->type] ?? $invoice->type }}</td>
                            <td>{{ $invoiceStatusLabel[$invoice->status] ?? $invoice->status }}</td>
                            <td class="invoice-validation-cell">
                                @if ($validationState['is_blocking'])
                                    <span class="status red" title="{{ implode(' ', $validationState['errors']) }}">Do poprawy</span>
                                @elseif ($validationState['warnings'])
                                    <span class="status orange" title="{{ implode(' ', $validationState['warnings']) }}">Ostrzeżenia</span>
                                @else
                                    <span class="status">OK</span>
                                @endif
                                @if ($validationState['errors'] || $validationState['warnings'])
                                    <details class="invoice-validation-details">
                                        <summary>Komunikaty ({{ count($validationState['errors']) + count($validationState['warnings']) }})</summary>
                                        <ul class="invoice-validation-list">
                                            @foreach ($validationState['errors'] as $message)
                                                <li class="error">{{ $message }}</li>
                                            @endforeach
                                            @foreach ($validationState['warnings'] as $message)
                                                <li class="warning">{{ $message }}</li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </td>
                            <td>{{ number_format((float) $invoice->gross_total, 2, ',', ' ') }} {{ $invoice->currency }}</td>
                            <td>
                                @if (data_get($invoice->metadata, 'woocommerce_upload.status') === 'failed')
                                    <span class="status red" title="{{ data_get($invoice->metadata, 'woocommerce_upload.error') }}">Błąd wysyłki</span>
                                    @if (data_get($invoice->metadata, 'woocommerce_upload.error'))
                                        <details class="invoice-validation-details">
                                            <summary>Szczegóły</summary>
                                            <ul class="invoice-validation-list">
                                                <li class="error">{{ data_get($invoice->metadata, 'woocommerce_upload.error') }}</li>
                                            </ul>
                                        </details>
                                    @endif
                                @elseif (data_get($invoice->metadata, 'woocommerce_upload.requires_resend'))
                                    Do ponownej wysyłki
                                @elseif (data_get($invoice->metadata, 'woocommerce_upload.status') === 'success')
                                    Wysłana
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                @if ($invoice->ksef_number)
                                    <span class="status">Nr KSeF</span>
                                    <div class="muted">{{ $invoice->ksef_number }}</div>
                                @elseif ($latestKsef)
                                    <span @class(['status', 'red' => in_array($latestKsef->status, ['failed', 'rejected'], true), 'orange' => in_array($latestKsef->status, ['missing_configuration', 'requires_configuration'], true)])>{{ $ksefStatusLabel[$latestKsef->status] ?? $latestKsef->status }}</span>
                                @else
                                    <span @class(['status', 'orange' => $ksefTone === 'orange', 'red' => $ksefTone === 'red', 'blue' => $ksefTone === 'blue']) title="{{ $ksefState['reason'] ?? '' }}">{{ $ksefState['label'] ?? '-' }}</span>
                                @endif
                            </td>
                            <td>{{ $invoice->invoiceTemplate?->name ?? $template->name }}</td>
                            <td>
                                @if ($htmlFile)
                                    <a class="status" href="{{ route('invoices.files.download', [$invoice, $htmlFile]) }}">HTML</a>
                                @else
                                    <span class="status red">HTML</span>
                                @endif
                                @if ($pdfFile)
                                    <a class="status" href="{{ route('invoices.files.download', [$invoice, $pdfFile]) }}">PDF</a>
                                @else
                                    <span class="status red">PDF</span>
                                @endif
                            </td>
                            <td>
                                <div class="inline-actions">
                                    <a class="button secondary" href="{{ route('invoices.edit', $invoice) }}">Edytuj dane</a>
                                    <a class="button secondary" href="{{ route('invoices.preview', $invoice) }}" target="_blank" rel="noopener">Podgląd</a>
                                    <form method="POST" action="{{ route('invoices.regenerate', $invoice) }}">
                                        @csrf
                                        <button class="button" type="submit">Regeneruj</button>
                                    </form>
                                    @if ($sellerStatus['is_ready'] && $hasSellerErrors && ! $isKsefAccepted)
                                        <form method="POST" action="{{ route('invoices.seller.apply', $invoice) }}">
                                            @csrf
                                            <button class="button secondary" type="submit">Uzupełnij sprzedawcę</button>
                                        </form>
                                    @endif
                                    @if ($invoice->external_order_id)
                                        @if ($validationState['is_blocking'])
                                            <button class="button secondary" type="button" disabled title="{{ implode(' ', $validationState['errors']) }}">Popraw fakturę</button>
                                        @else
                                            <form method="POST" action="{{ route('invoices.woocommerce.upload', $invoice) }}">
                                                @csrf
                                                <button class="button secondary" type="submit">
                                                    {{ data_get($invoice->metadata, 'woocommerce_upload.status') === 'success' ? 'Wyślij ponownie' : 'Wyślij do WooCommerce' }}
                                                </button>
                                            </form>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10">Brak faktur. Fakturę wystawisz z poziomu zamówienia po zaksięgowaniu WZ.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
@endsection
