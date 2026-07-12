@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@php
    $qty = fn ($value): string => number_format((float) $value, floor((float) $value) === (float) $value ? 0 : 2, ',', ' ');
    $money = fn ($value, ?string $currency = null): string => number_format((float) $value, 2, ',', ' ') . ($currency ? ' ' . $currency : '');
    $person = function (?array $data): string {
        $data ??= [];

        return trim(implode(' ', array_filter([
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
            $data['company'] ?? null,
        ]))) ?: '-';
    };
    $address = function (?array $data): string {
        $data ??= [];
        $parts = array_filter([
            $data['address_1'] ?? null,
            $data['address_2'] ?? null,
            trim(implode(' ', array_filter([
                $data['postcode'] ?? null,
                $data['city'] ?? null,
            ]))),
            $data['country'] ?? null,
        ]);

        return implode(', ', $parts) ?: '-';
    };
    $shippingProviderResolver = app(\App\Services\Shipping\ShippingProviderResolver::class);
    $thumbnailService = app(\App\Services\Products\ProductImageThumbnailService::class);
    $lineImage = function ($line) use ($thumbnailService): ?string {
        $raw = is_array($line->raw_payload) ? $line->raw_payload : [];
        $source = $line->product?->imageUrl()
            ?: data_get($raw, 'image.src')
            ?: data_get($raw, 'image.url')
            ?: data_get($raw, 'images.0.src')
            ?: data_get($raw, 'images.0.url')
            ?: data_get($raw, 'parent_image.src')
            ?: data_get($raw, 'parent_image.url');

        return $line->product?->thumbnailUrl(72, 88)
            ?: $thumbnailService->thumbnailUrl(is_string($source) ? $source : null, 72, 88);
    };
    $shipmentLabels = $order->shippingLabels->where('purpose', 'shipment');
    $fulfillmentStatusLabel = match ($order->fulfillment_status) {
        'awaiting_courier' => 'Oczekuje na kuriera',
        'ready_to_pack' => 'Do pakowania',
        'shipped' => 'Wysłane',
        default => $order->fulfillment_status ?: 'Nie rozpoczęto',
    };
@endphp

@push('styles')
    <style>
        .order-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .order-jump-nav { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .order-jump-nav a { display: inline-flex; align-items: center; min-height: 36px; padding: 7px 11px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface); color: var(--muted); text-decoration: none; font-weight: 760; font-size: 13px; }
        .order-jump-nav a:hover { color: var(--green-dark); border-color: var(--green); }
        .order-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .order-summary-card { padding: 14px 16px; }
        .order-summary-card span { display: block; color: var(--muted); font-size: 12px; font-weight: 720; margin-bottom: 4px; }
        .order-summary-card strong { display: block; font-size: 20px; line-height: 1.15; }
        .order-grid { display: grid; grid-template-columns: minmax(320px, .9fr) minmax(0, 1.4fr); gap: 16px; margin-bottom: 16px; }
        .order-section { margin-bottom: 16px; }
        .order-section-body { padding: 16px; }
        .order-label-form { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .order-label-form select { min-height: 44px; min-width: 260px; }
        .shipping-label-list { display: grid; gap: 9px; }
        .shipping-label-card { border: 1px solid var(--border); border-radius: 8px; padding: 11px; background: #fffdfb; display: grid; gap: 6px; }
        .shipping-label-card-header, .shipping-label-card-actions { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; }
        .shipping-label-number { font-weight: 850; overflow-wrap: anywhere; }
        .shipping-label-meta { color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }
        .address-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .address-box { border: 1px solid var(--border); border-radius: 8px; padding: 13px; }
        .address-box strong { display: block; margin-bottom: 6px; }
        .order-command-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-bottom: 16px; }
        .order-command-card { padding: 16px; display: grid; gap: 10px; align-content: start; }
        .order-command-card h2 { margin: 0; font-size: 18px; }
        .order-command-card p { margin: 0; }
        .order-command-form { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: end; }
        .order-command-form label { margin: 0; }
        .order-command-form .button { min-height: 44px; white-space: nowrap; }
        .order-product-table { min-width: 1040px; }
        .order-product-table th.numeric, .order-product-table td.numeric { text-align: right; }
        .order-product-photo { width: 86px; }
        .order-product-thumb { width: 64px; height: 78px; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; display: grid; place-items: center; background: #f4f1ef; color: var(--muted); font-size: 11px; font-weight: 780; }
        .order-product-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .order-product-picker { min-width: 290px; display: grid; gap: 5px; }
        .order-product-picker input { min-height: 42px; }
        .order-product-name { display: block; font-weight: 820; white-space: normal; }
        .order-product-meta { color: var(--muted); font-size: 12px; white-space: normal; }
        .order-quantity-input { width: 92px; min-height: 42px; text-align: right; }
        .order-remove-control { display: inline-flex; align-items: center; gap: 7px; color: var(--red); font-weight: 720; }
        .order-product-footer { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 13px 16px; border-top: 1px solid var(--border); background: #fffdfb; }
        .order-new-line { display: grid; grid-template-columns: minmax(280px, 1fr) 110px; gap: 8px; align-items: end; }
        .order-edit-lock { margin: 0; padding: 12px 16px; border-top: 1px solid var(--border); background: #fff8e8; color: var(--orange); font-weight: 720; }
        .note-list { display: grid; gap: 10px; }
        .note-card { border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: var(--surface); }
        .note-card-header { display: flex; justify-content: space-between; gap: 10px; color: var(--muted); font-size: 12px; margin-bottom: 6px; }
        .customer-message-form { display: grid; gap: 10px; margin-bottom: 16px; }
        .customer-message-form-actions { display: flex; justify-content: flex-end; }
        .customer-message-history { display: grid; gap: 10px; }
        .customer-message-card { border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: var(--surface); }
        .customer-message-card header { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 7px; }
        .customer-message-card strong { display: block; }
        .customer-message-meta { color: var(--muted); font-size: 12px; }
        .customer-message-preview { margin-top: 7px; white-space: pre-wrap; }
        .compact-form { display: grid; gap: 10px; }
        .compact-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .payment-balance { font-size: 22px; font-weight: 780; }
        .payment-history { display: grid; gap: 9px; margin-top: 14px; }
        .payment-row { border-top: 1px solid var(--border); padding-top: 9px; display: flex; justify-content: space-between; gap: 12px; }
        .payment-row strong { display: block; }
        .wrap-cell { white-space: normal; min-width: 220px; }
        .split-line-grid { display: grid; grid-template-columns: minmax(0, 1fr) 120px 160px; gap: 10px; align-items: end; padding: 10px 0; border-bottom: 1px solid var(--border); }
        .split-line-grid:last-of-type { border-bottom: 0; }
        .split-line-grid label { margin: 0; }
        @media (max-width: 1000px) {
            .order-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .order-grid { grid-template-columns: 1fr; }
            .order-command-grid { grid-template-columns: 1fr; }
            .address-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) {
            .order-summary { grid-template-columns: 1fr; }
            .split-line-grid { grid-template-columns: 1fr; }
            .compact-form-grid { grid-template-columns: 1fr; }
            .order-command-form, .order-new-line { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')
    <section class="order-toolbar">
        <a class="button secondary" href="{{ route('modules.show', 'orders') }}">Wróć do zamówień</a>
        @include('partials.order-actions', [
            'order' => $order,
            'wzDocument' => $latestWz,
            'invoice' => $latestInvoice,
            'proforma' => $latestProforma,
            'activeReservations' => $activeReservations,
            'showDetailsLink' => false,
        ])
    </section>

    <nav class="order-jump-nav" aria-label="Sekcje zamówienia">
        <a href="#obsluga-zamowienia">Obsługa</a>
        <a href="#pozycje-zamowienia">Produkty</a>
        <a href="#dane-klienta">Klient i dostawa</a>
        <a href="#logistyka-zamowienia">Logistyka</a>
        <a href="#komunikacja-z-klientem">Komunikacja</a>
        <a href="#rozliczenia-zamowienia">Rozliczenia</a>
    </nav>

    <section class="order-summary" aria-label="Podsumowanie zamówienia">
        <article class="card order-summary-card">
            <span>Kanał</span>
            <strong>{{ $order->salesChannel?->code ?? '-' }}</strong>
        </article>
        <article class="card order-summary-card">
            <span>Status WooCommerce</span>
            <strong>{{ $order->status }}</strong>
        </article>
        <article class="card order-summary-card">
            <span>Realizacja ERP</span>
            <strong>{{ $fulfillmentStatusLabel }}</strong>
        </article>
        <article class="card order-summary-card">
            <span>Kwota brutto</span>
            <strong>{{ $money($order->total_gross, $order->currency) }}</strong>
        </article>
        <article class="card order-summary-card">
            <span>Aktywne rezerwacje</span>
            <strong>{{ $qty($activeReservations) }}</strong>
        </article>
        <article class="card order-summary-card">
            <span>Oczekuje na towar</span>
            <strong>{{ $qty($waitingReservations) }}</strong>
        </article>
    </section>

    <section id="obsluga-zamowienia" class="order-command-grid" aria-label="Obsługa zamówienia">
        <article class="card order-command-card">
            <h2>Zmień status zamówienia</h2>
            <p class="muted">Zmiana zostanie zapisana w WooCommerce i od razu odświeży rezerwacje oraz kolejkę pakowania w ERP.</p>
            <form class="order-command-form" method="POST" action="{{ route('orders.status.update', $order) }}">
                @csrf
                @method('PATCH')
                <label>Status WooCommerce
                    <select name="status" required>
                        @foreach ($orderStatusOptions as $statusValue => $statusLabel)
                            <option value="{{ $statusValue }}" @selected(old('status', $order->status) === $statusValue)>{{ $statusLabel }} ({{ $statusValue }})</option>
                        @endforeach
                    </select>
                </label>
                <button class="button" type="submit">Zapisz status</button>
            </form>
            <span class="muted">Status realizacji ERP: <strong>{{ $fulfillmentStatusLabel }}</strong> — jest wyliczany z kompletacji i wysyłki.</span>
        </article>

        <article class="card order-command-card">
            <h2>Ponów prośbę o wpłatę</h2>
            <p class="muted">Klient otrzyma gotowy mail z przyciskiem „Przejdź do płatności”. Link możesz uzupełnić, jeśli WooCommerce nie przekazał go automatycznie.</p>
            <form class="order-command-form" method="POST" action="{{ route('orders.payment-reminder.send', $order) }}">
                @csrf
                <label>Link do płatności
                    <input name="payment_url" type="url" maxlength="1000" value="{{ old('payment_url', $paymentUrl) }}" placeholder="https://sklep.pl/checkout/order-pay/..." required>
                </label>
                <button class="button" type="submit">Wyślij przypomnienie</button>
            </form>
            <span class="muted">Odbiorca: {{ data_get($order->billing_data, 'email') ?: data_get($order->shipping_data, 'email') ?: 'brak adresu e-mail' }}</span>
        </article>
    </section>

    <article id="pozycje-zamowienia" class="card order-section">
        <div class="panel-header">
            <span>Pozycje zamówienia</span>
            <span>{{ $order->lines->count() }} pozycji · pełna szerokość</span>
        </div>
        <form method="POST" action="{{ route('orders.lines.update', $order) }}" data-order-lines-form data-product-lookup-url="{{ $productLookupUrl }}">
            @csrf
            @method('PUT')
            <div class="table-scroll">
                <table class="dense-table order-product-table">
                    <thead>
                        <tr>
                            <th>Zdjęcie</th>
                            <th>Produkt</th>
                            <th>Ilość</th>
                            <th class="numeric">Cena netto</th>
                            <th class="numeric">Cena brutto</th>
                            <th class="numeric">Wartość</th>
                            @if ($lineEditing['editable'])
                                <th>Usuń</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($order->lines as $line)
                            @php $productImage = $lineImage($line); @endphp
                            <tr>
                                <td class="order-product-photo">
                                    <div class="order-product-thumb">
                                        @if ($productImage)
                                            <img src="{{ $productImage }}" alt="{{ $line->name }}" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                                        @else
                                            Brak foto
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    @if ($lineEditing['editable'])
                                        <div class="order-product-picker">
                                            <input list="order-product-options" value="{{ $line->product?->sku }}" placeholder="Wpisz SKU lub nazwę" autocomplete="off" data-order-product-lookup>
                                            <input type="hidden" name="lines[{{ $line->id }}][product_id]" value="{{ $line->product_id }}" data-order-product-id>
                                            <span class="order-product-name">{{ $line->name }}</span>
                                            <span class="order-product-meta">Woo SKU: {{ $line->sku ?: '-' }}@if ($line->product) · <a href="{{ route('products.show', $line->product) }}">karta produktu ERP</a>@endif</span>
                                        </div>
                                    @else
                                        <span class="order-product-name">{{ $line->name }}</span>
                                        <span class="order-product-meta">{{ $line->sku ?: 'brak SKU' }}@if ($line->product) · <a href="{{ route('products.show', $line->product) }}">{{ $line->product->sku }}</a>@else · brak mapowania ERP @endif</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($lineEditing['editable'])
                                        <input class="order-quantity-input" name="lines[{{ $line->id }}][quantity]" type="number" min="0.0001" max="999999.9999" step="0.0001" value="{{ old('lines.'.$line->id.'.quantity', (float) $line->quantity) }}" required>
                                    @else
                                        {{ $qty($line->quantity) }}
                                    @endif
                                </td>
                                <td class="numeric">{{ $line->unit_net_price !== null ? $money($line->unit_net_price, $order->currency) : '-' }}</td>
                                <td class="numeric">{{ $line->unit_gross_price !== null ? $money($line->unit_gross_price, $order->currency) : '-' }}</td>
                                <td class="numeric"><strong>{{ $line->unit_gross_price !== null ? $money((float) $line->quantity * (float) $line->unit_gross_price, $order->currency) : '-' }}</strong></td>
                                @if ($lineEditing['editable'])
                                    <td>
                                        <label class="order-remove-control">
                                            <input name="lines[{{ $line->id }}][remove]" type="checkbox" value="1">
                                            Usuń
                                        </label>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $lineEditing['editable'] ? 7 : 6 }}">Brak pozycji zamówienia.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($lineEditing['editable'])
                <div class="order-product-footer">
                    <div class="order-new-line">
                        <label>Dodaj produkt
                            <input list="order-product-options" placeholder="Wpisz SKU lub nazwę" autocomplete="off" data-order-product-lookup>
                            <input type="hidden" name="new_line[product_id]" value="" data-order-product-id>
                        </label>
                        <label>Ilość
                            <input name="new_line[quantity]" type="number" min="0.0001" max="999999.9999" step="0.0001" value="1">
                        </label>
                    </div>
                    <button class="button" type="submit">Zapisz zmiany produktów</button>
                </div>
            @else
                <p class="order-edit-lock">{{ $lineEditing['reason'] }}</p>
            @endif
        </form>
        <datalist id="order-product-options"></datalist>
    </article>

    <article id="dane-klienta" class="card order-section">
        <div class="panel-header">Dane klienta i dostawy</div>
        <div class="order-section-body address-grid">
            <div class="address-box">
                <strong>Rozliczenie</strong>
                <div>{{ $person($order->billing_data) }}</div>
                <div class="muted">{{ $address($order->billing_data) }}</div>
                <div class="muted">{{ data_get($order->billing_data, 'email', '-') }} · {{ data_get($order->billing_data, 'phone', '-') }}</div>
            </div>
            <div class="address-box">
                <strong>Wysyłka</strong>
                <div>{{ $person($order->shipping_data) }}</div>
                <div class="muted">{{ $address($order->shipping_data) }}</div>
                <div class="muted">Metoda: {{ data_get($order->raw_payload, 'shipping_lines.0.method_title', '-') }}</div>
            </div>
            <div class="address-box">
                <strong>Płatność i daty</strong>
                <div>{{ data_get($order->raw_payload, 'payment_method_title', '-') }}</div>
                <div class="muted">Utworzone: {{ $order->external_created_at?->format('Y-m-d H:i') ?? '-' }}</div>
                <div class="muted">Aktualizacja Woo: {{ $order->external_updated_at?->format('Y-m-d H:i') ?? '-' }}</div>
            </div>
        </div>
    </article>

    <article id="logistyka-zamowienia" class="card order-section">
        <div class="panel-header">
            <span>Rezerwacje magazynowe</span>
            <span>{{ $reservations->count() }} rekordów</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Magazyn</th>
                        <th>SKU</th>
                        <th>Ilość</th>
                        <th>Status</th>
                        <th>Rezerwacja</th>
                        <th>Zwolnienie</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($reservations as $reservation)
                        <tr>
                            <td>{{ $reservation->warehouse?->code ?? '-' }}</td>
                            <td>{{ $reservation->product?->sku ?? '-' }}</td>
                            <td>{{ $qty($reservation->quantity) }}</td>
                            <td><span @class(['status', 'blue' => $reservation->status === 'waiting', 'orange' => $reservation->status === 'released'])>{{ $reservation->status }}</span></td>
                            <td>{{ $reservation->reserved_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>{{ $reservation->released_at?->format('Y-m-d H:i') ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Brak rezerwacji dla zamówienia.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>

    @if (count($orderSegments) > 1)
        <article class="card order-section shipping-decision-card">
            <div class="panel-header">
                <span>Wysyłka częściowa — obuwie i odzież</span>
                <span>To zamówienie łączy obuwie z odzieżą</span>
            </div>
            <div class="order-section-body">
                @if (is_array($shippingDecision))
                    <p class="shipping-decision-current">
                        Decyzja: <strong>{{ ($shippingDecision['decision'] ?? '') === 'ship_footwear_now' ? 'Wyślij buty od razu' : 'Czekaj na resztę zamówienia' }}</strong>
                        <span class="muted">
                            ({{ $shippingDecision['decided_by'] ?? 'ERP' }}, {{ \Illuminate\Support\Carbon::parse($shippingDecision['decided_at'] ?? now())->format('Y-m-d H:i') }})
                        </span>
                    </p>
                @else
                    <p class="muted" style="margin-top: 0;">Zdecyduj, czy buty mają jechać do klienta osobno, nie czekając na skompletowanie odzieży. Wydzielone buty trafią do osobnego zamówienia z własną kompletacją, pakowaniem i etykietą.</p>
                @endif
                <div class="inline-actions">
                    <form method="POST" action="{{ route('orders.shipping-decision', $order) }}" onsubmit="return confirm('Wydzielić buty do osobnego zamówienia i wysłać od razu?');">
                        @csrf
                        <input type="hidden" name="decision" value="ship_footwear_now">
                        <button class="button" type="submit">Wyślij buty od razu</button>
                    </form>
                    <form method="POST" action="{{ route('orders.shipping-decision', $order) }}">
                        @csrf
                        <input type="hidden" name="decision" value="wait_for_all">
                        <button class="button secondary" type="submit">Czekaj na resztę zamówienia</button>
                    </form>
                </div>
            </div>
        </article>
    @endif

    <article class="card order-section">
        <div class="panel-header">
            <span>Podziel zamówienie</span>
            <span>Wydziel brakujące pozycje do osobnego zamówienia ERP</span>
        </div>
        <form class="order-section-body" method="POST" action="{{ route('orders.split', $order) }}">
            @csrf
            <p class="muted" style="margin-top: 0;">Wpisz ilość, którą chcesz wysłać później. Nowe zamówienie dostanie własne rezerwacje, więc po pojawieniu się stanu na magazynie będzie traktowane jako oczekujące do realizacji.</p>
            @foreach ($order->lines as $line)
                <div class="split-line-grid">
                    <div>
                        <strong>{{ $line->name }}</strong><br>
                        <span class="muted">{{ $line->sku ?? '-' }} · dostępne w zamówieniu: {{ $qty($line->quantity) }}</span>
                    </div>
                    <label>Ilość później
                        <input name="split_lines[{{ $line->id }}][quantity]" type="number" min="0" max="{{ (float) $line->quantity }}" step="1" value="0">
                    </label>
                    <span class="status">{{ $line->product ? 'Produkt ERP' : 'Brak mapowania' }}</span>
                </div>
            @endforeach
            <label style="margin-top: 12px;">Notatka do splitu
                <textarea name="note" rows="2" placeholder="np. buty zejdą z produkcji później"></textarea>
            </label>
            <div class="inline-actions" style="margin-top: 12px;">
                <button class="button" type="submit">Utwórz zamówienie częściowe</button>
            </div>
        </form>
    </article>

    <section class="order-grid">
        <article class="card order-section">
            <div class="panel-header">
                <span>Dokumenty WZ</span>
                <span>{{ $wzDocuments->count() }} rekordów</span>
            </div>
            <div class="table-scroll">
                <table class="dense-table">
                    <thead>
                        <tr>
                            <th>Numer</th>
                            <th>Status</th>
                            <th>Magazyn</th>
                            <th>Pozycje</th>
                            <th>Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($wzDocuments as $document)
                            <tr>
                                <td><a class="status" href="{{ route('documents.show', $document) }}">{{ $document->number }}</a></td>
                                <td><span class="status {{ $document->status === 'draft' ? 'blue' : '' }}">{{ $document->status }}</span></td>
                                <td>{{ $document->sourceWarehouse?->code ?? '-' }}</td>
                                <td>{{ $document->lines->map(fn ($line) => ($line->product?->sku ?? '-') . ' x ' . $qty($line->quantity))->implode(', ') }}</td>
                                <td>{{ $document->document_date?->format('Y-m-d H:i') ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">Brak dokumentów WZ dla tego zamówienia.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="card order-section">
            <div class="panel-header">
                <span>Faktury</span>
                <span>{{ $order->invoices->count() }} rekordów</span>
            </div>
            <div class="table-scroll">
                <table class="dense-table">
                    <thead>
                        <tr>
                            <th>Numer</th>
                            <th>Status</th>
                            <th>Brutto</th>
                            <th>WooCommerce</th>
                            <th>KSeF</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($order->invoices->sortByDesc('id') as $invoice)
                            <tr>
                                <td><a class="status" href="{{ route('invoices.edit', $invoice) }}">{{ $invoice->number }}</a></td>
                                <td>{{ $invoice->status }}</td>
                                <td>{{ $money($invoice->gross_total, $invoice->currency) }}</td>
                                <td>{{ data_get($invoice->metadata, 'woocommerce_upload.status') === 'success' ? 'Wysłana' : '-' }}</td>
                                <td>{{ $invoice->ksef_number ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">Brak faktury dla tego zamówienia.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section class="order-grid">
        <article class="card order-section">
            <div class="panel-header">
                <span>Pakowanie i etykiety</span>
                <span>{{ $order->packingTasks->count() }} zadań</span>
            </div>
            <div class="table-scroll">
                <table class="dense-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Status</th>
                            <th>Kurier</th>
                            <th>Rozmiar</th>
                            <th>Zebrano</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($order->packingTasks as $task)
                            <tr>
                                <td>{{ $task->sku ?? '-' }}</td>
                                <td><span class="status {{ $task->status === 'picked' ? '' : 'orange' }}">{{ $task->status }}</span></td>
                                <td>{{ $task->courier ?: '-' }}</td>
                                <td>{{ $task->size_label ?: '-' }}</td>
                                <td>{{ $qty($task->quantity_picked) }} / {{ $qty($task->quantity_required) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">Brak zadań pakowania dla tego zamówienia.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="order-section-body">
                <div class="shipping-label-list">
                    @forelse ($order->shippingLabels as $label)
                        @php
                            $trackingUrl = $shippingProviderResolver->trackingUrl($label);
                            $purposeLabel = match ($label->purpose) {
                                'return' => 'Zwrot',
                                'exchange' => 'Wymiana',
                                default => 'Wysyłka do klienta',
                            };
                        @endphp
                        <article class="shipping-label-card">
                            <div class="shipping-label-card-header">
                                <span class="status">{{ $purposeLabel }}</span>
                                <span class="status">{{ $shippingProviderResolver->courierName($label) }}</span>
                            </div>
                            <div class="shipping-label-number">
                                Nr etykiety / przesyłki: {{ $label->trackingIdentifier() ?: '#'.$label->id }}
                            </div>
                            <div class="shipping-label-meta">
                                Status etykiety: {{ $label->status }}
                                @if ($label->tracking_status)
                                    · tracking: {{ $label->tracking_status }}
                                @endif
                                @if ($label->tracking_checked_at)
                                    · sprawdzono {{ $label->tracking_checked_at->format('Y-m-d H:i') }}
                                @endif
                            </div>
                            <div class="shipping-label-card-actions">
                                <a class="button secondary" href="{{ $label->purpose === 'shipment' ? route('packing.labels.download', $label) : route('returns.labels.download', $label) }}">Pobierz etykietę</a>
                                @if ($trackingUrl)
                                    <a class="button" href="{{ $trackingUrl }}" target="_blank" rel="noopener noreferrer" aria-label="Śledź przesyłkę {{ $label->trackingIdentifier() }}">Śledź paczkę</a>
                                @endif
                            </div>
                        </article>
                    @empty
                        <span class="muted">Brak wygenerowanej etykiety kurierskiej.</span>
                    @endforelse
                </div>

                @if ($shipmentLabels->isEmpty())
                    <form class="order-label-form" method="POST" action="{{ route('orders.label.generate', $order) }}">
                        @csrf
                        <select name="courier_account_id" aria-label="Konto nadawcze">
                            <option value="">Etykieta ze sklepu (WooCommerce)</option>
                            @foreach ($courierAccounts as $courierAccount)
                                <option value="{{ $courierAccount->id }}" @selected($courierAccount->is_default && $courierAccount->provider === 'inpost')>{{ $courierAccount->provider === 'blpaczka' ? 'BLPaczka' : 'InPost' }}: {{ $courierAccount->name }}</option>
                            @endforeach
                        </select>
                        <button class="button" type="submit">Generuj przesyłkę</button>
                    </form>
                @endif
            </div>
        </article>

        <article id="komunikacja-z-klientem" class="card order-section">
            <div class="panel-header">
                <span>Komunikacja z klientem</span>
                <span>{{ $order->customerMessages->count() }} wiadomości</span>
            </div>
            <div class="order-section-body">
                @php
                    $orderEmailTemplates = $emailTemplates ?? collect();
                    $orderTemplateContext = [
                        'order_number' => $order->external_number ?: $order->external_id,
                        'customer_name' => $person($order->billing_data),
                        'customer_email' => data_get($order->billing_data, 'email', ''),
                        'payment_url' => $paymentUrl ?? '',
                        'from_name' => config('mail.from.name', config('app.name', 'Sempre ERP')),
                    ];
                @endphp
                <form class="customer-message-form" method="POST" action="{{ route('orders.message.send', $order) }}" data-email-template-form data-email-template-context="{{ e(json_encode($orderTemplateContext, JSON_UNESCAPED_UNICODE)) }}">
                    @csrf
                    @if ($orderEmailTemplates->isNotEmpty())
                        <label>Szablon
                            <select data-email-template-select>
                                <option value="">Własna wiadomość</option>
                                @foreach ($orderEmailTemplates as $template)
                                    <option value="{{ $template->id }}" data-subject="{{ $template->subject }}" data-body="{{ $template->body }}">{{ $template->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <label>Temat
                        <input name="subject" maxlength="160" value="{{ old('subject') }}" placeholder="Np. Informacja o zamówieniu {{ $order->external_number }}" required>
                    </label>
                    <label>Wiadomość
                        <textarea name="body" rows="5" maxlength="5000" required>{{ old('body') }}</textarea>
                    </label>
                    <div class="customer-message-form-actions">
                        <button class="button" type="submit">Wyślij maila</button>
                    </div>
                </form>

                <div class="customer-message-history">
                    @forelse ($order->customerMessages->take(8) as $message)
                        <article class="customer-message-card">
                            <header>
                                <div>
                                    <strong>{{ $message->renderedSubject() }}</strong>
                                    <span class="customer-message-meta">
                                        {{ $message->recipient_email }} · {{ $message->type === 'automated' ? 'automat' : 'ręcznie' }}{{ $message->trigger ? ' · '.$message->trigger : '' }}
                                    </span>
                                </div>
                                <span @class(['status', 'blue' => $message->status === 'pending', 'red' => $message->status === 'failed', 'orange' => $message->status === 'skipped'])>{{ $message->status }}</span>
                            </header>
                            <div class="customer-message-meta">
                                {{ $message->sent_at?->format('Y-m-d H:i') ?? $message->failed_at?->format('Y-m-d H:i') ?? $message->created_at?->format('Y-m-d H:i') }}
                                @if ($message->error_message)
                                    · {{ $message->error_message }}
                                @endif
                            </div>
                            <div class="customer-message-preview">{{ \Illuminate\Support\Str::limit($message->renderedBody(), 220) }}</div>
                        </article>
                    @empty
                        <span class="muted">Brak wiadomości wysłanych do klienta tego zamówienia.</span>
                    @endforelse
                </div>
            </div>
        </article>
    </section>

    <section class="order-grid">
        <article id="rozliczenia-zamowienia" class="card order-section">
            <div class="panel-header">
                <span>Rozliczenia klienta</span>
                @php
                    $incomingPayments = (float) $order->customerPayments->where('direction', 'incoming')->sum(fn ($payment) => (float) $payment->amount);
                    $outgoingPayments = (float) $order->customerPayments->where('direction', 'outgoing')->sum(fn ($payment) => (float) $payment->amount);
                    $paymentBalance = $incomingPayments - $outgoingPayments;
                @endphp
                <span>{{ $money($paymentBalance, $order->currency) }}</span>
            </div>
            <div class="order-section-body">
                <div class="payment-balance">{{ $money($paymentBalance, $order->currency) }}</div>
                <div class="muted">Suma ręcznie zaksięgowanych dopłat pomniejszona o wypłaty/refundy zarejestrowane w ERP.</div>

                <form class="compact-form" method="POST" action="{{ route('orders.payments.store', $order) }}" style="margin-top: 14px;">
                    @csrf
                    <div class="compact-form-grid">
                        <label>Kwota
                            <input name="amount" type="number" min="0.01" step="0.01" required>
                        </label>
                        <label>Metoda
                            <select name="method" required>
                                <option value="blik">BLIK</option>
                                <option value="bank_transfer">Przelew</option>
                                <option value="cash">Gotówka</option>
                                <option value="card">Karta</option>
                                <option value="payu">PayU</option>
                                <option value="other">Inna</option>
                            </select>
                        </label>
                        <label>Data księgowania
                            <input name="booked_at" type="datetime-local">
                        </label>
                        <label>Referencja
                            <input name="reference" maxlength="160" placeholder="np. BLIK, ID transakcji">
                        </label>
                    </div>
                    <label>Opis
                        <textarea name="description" rows="2" maxlength="1000" placeholder="np. dopłata BLIK za przesyłkę wymienną"></textarea>
                    </label>
                    <button class="button secondary" type="submit">Zaksięguj wpłatę</button>
                </form>

                <div class="payment-history">
                    @forelse ($order->customerPayments->take(8) as $payment)
                        <div class="payment-row">
                            <div>
                                <strong>{{ $payment->direction === 'outgoing' ? '-' : '+' }}{{ $money($payment->amount, $payment->currency) }}</strong>
                                <span class="muted">{{ $payment->method }} · {{ $payment->reference ?: 'bez referencji' }}</span>
                                @if ($payment->description)
                                    <div class="muted">{{ $payment->description }}</div>
                                @endif
                            </div>
                            <span @class(['status', 'blue' => $payment->status === 'pending', 'red' => $payment->status === 'failed'])>{{ $payment->status }}</span>
                        </div>
                    @empty
                        <span class="muted">Brak ręcznych księgowań dla tego zamówienia.</span>
                    @endforelse
                </div>
            </div>
        </article>

        <article class="card order-section">
            <div class="panel-header">
                <span>Notatki wewnętrzne ERP</span>
                <span>{{ $order->internalNotes->count() }} wpisów</span>
            </div>
            <div class="order-section-body note-list">
                <form class="compact-form" method="POST" action="{{ route('orders.notes.store', $order) }}">
                    @csrf
                    <label>Nowa notatka
                        <textarea name="body" rows="4" maxlength="3000" required></textarea>
                    </label>
                    <button class="button secondary" type="submit">Dodaj notatkę</button>
                </form>
                @forelse ($order->internalNotes->take(8) as $note)
                    <div class="note-card">
                        <div class="note-card-header">
                            <span>{{ $note->author_name ?: 'ERP' }}</span>
                            <span>{{ $note->created_at?->format('Y-m-d H:i') }}</span>
                        </div>
                        <div>{{ $note->body }}</div>
                    </div>
                @empty
                    <span class="muted">Brak notatek wewnętrznych ERP.</span>
                @endforelse
            </div>
        </article>
    </section>

    <section class="order-grid">
        <article class="card order-section">
            <div class="panel-header">
                <span>Notatki WooCommerce</span>
                <span>{{ $orderNotes->count() }} rekordów</span>
            </div>
            <div class="order-section-body note-list">
                @forelse ($orderNotes as $note)
                    @php
                        $noteText = trim(strip_tags((string) data_get($note, 'note', data_get($note, 'content', ''))));
                    @endphp
                    <div class="note-card">
                        <div class="note-card-header">
                            <span>{{ data_get($note, 'date_created', data_get($note, 'date_created_gmt', '-')) }}</span>
                            <span>{{ data_get($note, 'customer_note') ? 'Notatka klienta' : 'Notatka wewnętrzna' }}</span>
                        </div>
                        <div>{{ $noteText !== '' ? $noteText : '-' }}</div>
                    </div>
                @empty
                    <span class="muted">Brak zaimportowanych notatek z WooCommerce.</span>
                @endforelse
            </div>
        </article>
    </section>
@endsection

@push('scripts')
    <script>
        (() => {
            const lineForm = document.querySelector('[data-order-lines-form]');
            const productOptions = document.getElementById('order-product-options');
            const productResults = new Map();
            let productLookupTimer = null;

            const applyProductSelection = (input) => {
                const hidden = input.closest('.order-product-picker, .order-new-line')?.querySelector('[data-order-product-id]');
                const selected = productResults.get(input.value.trim());

                if (hidden) {
                    hidden.value = selected ? String(selected.id) : '';
                }
            };

            const loadProducts = async (query) => {
                if (!lineForm || !productOptions || query.length < 2) {
                    return;
                }

                const url = `${lineForm.dataset.productLookupUrl}?q=${encodeURIComponent(query)}`;

                try {
                    const response = await fetch(url, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });

                    if (!response.ok) {
                        return;
                    }

                    const products = await response.json();
                    productResults.clear();
                    productOptions.replaceChildren();

                    products.forEach((product) => {
                        productResults.set(String(product.sku), product);
                        const option = document.createElement('option');
                        option.value = String(product.sku);
                        option.label = String(product.name);
                        productOptions.appendChild(option);
                    });
                } catch (error) {
                    // Formularz nadal pozwala wpisać dane; błąd sieci pokaże walidacja po zapisie.
                }
            };

            lineForm?.querySelectorAll('[data-order-product-lookup]').forEach((input) => {
                input.addEventListener('input', () => {
                    const hidden = input.closest('.order-product-picker, .order-new-line')?.querySelector('[data-order-product-id]');
                    if (hidden) {
                        hidden.value = '';
                    }

                    window.clearTimeout(productLookupTimer);
                    productLookupTimer = window.setTimeout(() => loadProducts(input.value.trim()), 220);
                });
                input.addEventListener('change', () => applyProductSelection(input));
            });

            const renderTemplate = (value, context) => String(value || '').replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (match, key) => {
                return Object.prototype.hasOwnProperty.call(context, key) ? String(context[key] ?? '') : match;
            });

            document.querySelectorAll('[data-email-template-select]').forEach((select) => {
                select.addEventListener('change', () => {
                    const form = select.closest('[data-email-template-form]');

                    if (!form) {
                        return;
                    }

                    let context = {};
                    try {
                        context = JSON.parse(form.dataset.emailTemplateContext || '{}');
                    } catch (error) {
                        context = {};
                    }

                    const option = select.selectedOptions[0];
                    const subject = form.querySelector('input[name="subject"]');
                    const body = form.querySelector('textarea[name="body"]');

                    if (!option || option.value === '') {
                        return;
                    }

                    if (subject) {
                        subject.value = renderTemplate(option.dataset.subject, context);
                    }
                    if (body) {
                        body.value = renderTemplate(option.dataset.body, context);
                    }
                });
            });
        })();
    </script>
@endpush
