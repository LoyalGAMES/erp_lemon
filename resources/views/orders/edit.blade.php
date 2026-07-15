@extends('layouts.app', [
    'title' => $title ?? 'Edycja zamówienia '.($order->external_number ?: $order->external_id),
    'subtitle' => $subtitle ?? 'Zmiany zostaną zapisane w WooCommerce, ERP i w istniejącym szkicu WZ.',
    'module' => $module ?? 'orders',
])

@php
    $billing = (array) $order->billing_data;
    $shipping = (array) $order->shipping_data;
    $editingAllowed = (bool) ($editingAvailability['editable'] ?? false);
    $editingReason = trim((string) ($editingAvailability['reason'] ?? ''));
    $cancellation = $cancellation ?? null;
    $canCancelOrder = (bool) ($canCancelOrder ?? false);
    $canViewOrderDetails = (bool) ($canViewOrderDetails ?? false);
    $field = static fn (string $name, mixed $default = ''): mixed => old($name, $default) ?? '';
    $orderNumber = $order->external_number ?: $order->external_id;
    $cancellationStatus = $cancellation?->status;
    $cancellationStatusLabel = match ($cancellationStatus) {
        'requested' => 'zgłoszone',
        'processing' => 'w toku',
        'attention_required' => 'wymaga interwencji',
        'completed' => 'zakończone',
        default => str_replace('_', ' ', (string) $cancellationStatus),
    };
@endphp

@push('styles')
    <style>
        .order-edit-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .order-edit-toolbar-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
        .order-cancel-button { border-color: #b92424; background: var(--red); color: #fff; }
        .order-cancel-button:hover, .order-cancel-button:focus-visible { border-color: #941d1d; background: #b92424; color: #fff; }
        .order-cancel-modal[hidden] { display: none; }
        .order-cancel-modal { position: fixed; inset: 0; z-index: 140; display: grid; place-items: center; padding: 20px; background: rgba(37, 31, 26, .64); }
        .order-cancel-modal-card { width: min(620px, 96vw); max-height: 92dvh; overflow: auto; border: 1px solid #efb6b6; border-radius: 10px; background: var(--surface); box-shadow: 0 24px 70px rgba(0, 0, 0, .3); }
        .order-cancel-modal-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 16px 18px; border-bottom: 1px solid var(--border); }
        .order-cancel-modal-header strong { color: var(--red); font-size: 18px; }
        .order-cancel-modal-close { width: 40px; height: 40px; border: 0; border-radius: 8px; background: transparent; color: var(--muted); font: inherit; font-size: 25px; cursor: pointer; }
        .order-cancel-modal-close:hover, .order-cancel-modal-close:focus-visible { background: #fff0f0; color: var(--red); }
        .order-cancel-modal-body { display: grid; gap: 14px; padding: 18px; }
        .order-cancel-consequences { display: grid; gap: 7px; margin: 0; padding-left: 20px; color: var(--muted); font-size: 13px; }
        .order-cancel-confirmation { display: flex; align-items: flex-start; gap: 9px; padding: 12px; border: 1px solid #efc5c5; border-radius: 8px; background: #fff5f5; color: #8d1e1e; cursor: pointer; }
        .order-cancel-confirmation input { width: auto; margin-top: 3px; }
        .order-cancel-modal-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; }
        .field-error { color: var(--red); font-size: 12px; font-weight: 700; }
        .order-edit-intro { display: grid; gap: 5px; margin-bottom: 16px; padding: 14px 16px; }
        .order-edit-intro strong { font-size: 16px; }
        .order-edit-form { display: grid; gap: 16px; }
        .order-edit-fieldset { min-width: 0; margin: 0; padding: 0; border: 0; display: grid; gap: 16px; }
        .order-edit-card { overflow: visible; }
        .order-edit-body { display: grid; gap: 14px; padding: 16px; }
        .order-edit-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .order-edit-grid.three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .order-edit-grid .wide { grid-column: 1 / -1; }
        .order-edit-help { margin: 0; color: var(--muted); font-size: 12px; font-weight: 520; }
        .order-edit-lines { display: grid; gap: 12px; }
        .order-edit-line { display: grid; gap: 12px; border: 1px solid var(--border); border-radius: 8px; padding: 13px; background: #fffdfb; }
        .order-edit-line-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
        .order-edit-line-title { display: grid; gap: 2px; min-width: 0; }
        .order-edit-line-title strong { font-size: 15px; overflow-wrap: anywhere; }
        .order-edit-line-title span { color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }
        .order-edit-line-grid { display: grid; grid-template-columns: minmax(240px, 1.45fr) minmax(100px, .45fr) minmax(145px, .65fr) minmax(145px, .65fr); gap: 10px; align-items: end; }
        .order-edit-product-picker { display: grid; gap: 6px; }
        .order-edit-remove { display: inline-flex; align-items: center; gap: 7px; color: var(--red); cursor: pointer; font-weight: 760; }
        .order-edit-remove input { width: auto; }
        .order-edit-new-line { border-style: dashed; background: var(--surface); }
        .order-edit-actions { position: sticky; z-index: 20; bottom: 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; background: rgba(255, 254, 253, .97); box-shadow: 0 -8px 24px rgba(33, 28, 24, .09); backdrop-filter: blur(10px); }
        .order-edit-actions .button { min-height: 46px; padding-inline: 18px; }
        .order-edit-disabled { opacity: .72; }
        .order-edit-disabled input, .order-edit-disabled select, .order-edit-disabled textarea { background: #f4f1ef; }
        @media (max-width: 1040px) {
            .order-edit-line-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            .order-edit-grid, .order-edit-grid.three, .order-edit-line-grid { grid-template-columns: 1fr; }
            .order-edit-grid .wide { grid-column: auto; }
            .order-edit-body { padding: 12px; }
            .order-edit-line-head, .order-edit-actions { align-items: stretch; display: grid; }
            .order-edit-actions .button { width: 100%; }
            .order-edit-toolbar, .order-edit-toolbar-actions, .order-cancel-modal-actions { align-items: stretch; display: grid; width: 100%; }
            .order-edit-toolbar .button, .order-cancel-modal-actions .button { width: 100%; }
            .order-cancel-modal { padding: 10px; }
            .order-edit-form input, .order-edit-form select, .order-edit-form textarea { min-height: 44px; font-size: 16px; }
        }
    </style>
@endpush

@section('content')
    <div class="order-edit-toolbar">
        <a class="button secondary" href="{{ $backUrl }}">Wróć bez zapisywania</a>
        <div class="order-edit-toolbar-actions">
            <span class="status {{ in_array($order->status, ['cancelled', 'refunded'], true) ? 'red' : 'blue' }}">
                WooCommerce: {{ $order->status ?: '-' }}
            </span>
            @if ($canCancelOrder)
                <button class="button order-cancel-button" type="button" data-order-cancel-open>
                    {{ $cancellation ? 'Dokończ anulowanie' : 'Anuluj zamówienie' }}
                </button>
            @elseif ($cancellation && $canViewOrderDetails)
                <a class="button secondary" href="{{ route('orders.show', $cancellation->external_order_id ?: $order) }}">
                    {{ $cancellationStatus === 'completed' ? 'Zamówienie anulowane' : 'Sprawdź anulowanie' }}
                </a>
            @endif
        </div>
    </div>

    @if ($canViewOrderDetails && $editingAllowed && ! in_array($order->status, ['cancellation-pending', 'cancelled', 'refunded'], true))
        <article class="card order-edit-intro">
            <strong>Status zamówienia</strong>
            <form class="order-edit-grid" method="POST" action="{{ route('orders.status.update', $order) }}">
                @csrf
                @method('PATCH')
                <label>Status WooCommerce
                    <select name="status" required>
                        @foreach ($orderStatusOptions as $statusValue => $statusLabel)
                            <option value="{{ $statusValue }}" @selected(old('status', $order->status) === $statusValue)>{{ $statusLabel }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Powód anulowania
                    <input name="cancellation_reason" maxlength="1000" value="{{ old('cancellation_reason') }}" placeholder="Wymagany po wybraniu „Anulowane”">
                </label>
                <div class="wide"><button class="button" type="submit">Zapisz status</button></div>
            </form>
        </article>
    @endif

    @if ($editingAllowed)
        <article class="card order-edit-intro">
            <strong>Ręczna przesyłka</strong>
            <p class="order-edit-help">Jeśli etykieta została utworzona poza ERP, wpisz jej numer. System nie będzie miał pliku do wydruku, ale przesyłka będzie automatycznie śledzona.</p>
            <form class="order-edit-grid" method="POST" action="{{ route('orders.labels.manual.store', $order) }}">
                @csrf
                <label>Przewoźnik
                    <select name="provider" required>
                        <option value="inpost" @selected(old('provider') === 'inpost')>InPost</option>
                        <option value="gls" @selected(old('provider') === 'gls')>GLS</option>
                    </select>
                </label>
                <label>Numer etykiety / przesyłki
                    <input name="tracking_number" maxlength="40" value="{{ old('tracking_number') }}" required placeholder="Numer przesyłki od przewoźnika">
                </label>
                <div style="align-self: end;"><button class="button" type="submit">Zapisz numer do śledzenia</button></div>
            </form>
        </article>
    @endif

    @if ($errors->any())
        <div class="alert error" role="alert">
            <strong>Nie zapisano zamówienia. Popraw wskazane dane:</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! $editingAllowed)
        <div class="alert warning" role="alert">
            <strong>To zamówienie jest teraz tylko do odczytu.</strong>
            @if ($editingReason !== '')
                <div>{{ $editingReason }}</div>
            @endif
        </div>
    @elseif ((bool) ($editingAvailability['resets_picking'] ?? false))
        <div class="alert warning" role="status">
            Zmiana produktów lub ich ilości cofnie dotychczasową kompletację tego zamówienia, aby magazyn ponownie sprawdził właściwe pozycje.
        </div>
    @endif

    @if ($cancellation && $cancellationStatus !== 'completed')
        <div class="alert {{ $cancellationStatus === 'attention_required' ? 'error' : 'warning' }}" role="status">
            <strong>Anulowanie: {{ $cancellationStatusLabel }}.</strong>
            @if (filled($cancellation->last_error))
                <div>{{ $cancellation->last_error }}</div>
            @endif
        </div>
    @endif

    <article class="card order-edit-intro">
        <strong>Zamówienie {{ $orderNumber }} · {{ $order->salesChannel?->code ?? 'WooCommerce' }}</strong>
        <span class="muted">Jedno zapisanie aktualizuje dane w sklepie i lokalnie, odświeża rezerwacje oraz pakowanie, a także synchronizuje istniejący szkic WZ. Dane i ceny sprawdź przed zatwierdzeniem.</span>
    </article>

    <form
        class="order-edit-form{{ $editingAllowed ? '' : ' order-edit-disabled' }}"
        method="POST"
        action="{{ route('orders.update', $order) }}"
        data-order-edit-form
        data-product-lookup-url="{{ $productLookupUrl }}"
        autocomplete="off"
    >
        @csrf
        @method('PUT')
        <input type="hidden" name="expected_version" value="{{ $field('expected_version', $expectedVersion) }}">
        <input type="hidden" name="expected_remote_modified_at" value="{{ $field('expected_remote_modified_at', $expectedRemoteModifiedAt) }}">
        <input type="hidden" name="return_to" value="{{ $field('return_to', $returnTo) }}">

        <fieldset class="order-edit-fieldset" @disabled(! $editingAllowed)>
            <article class="card order-edit-card">
                <div class="panel-header">
                    <span>Produkty i ceny</span>
                    <span>{{ $order->lines->count() }} pozycji</span>
                </div>
                <div class="order-edit-body">
                    <p class="order-edit-help">Wyszukuj po SKU, nazwie lub EAN. Po wyborze innego produktu kwoty starego SKU zostaną wyczyszczone — wpisz świadomie sumę przed rabatem i po rabacie dla całej pozycji.</p>
                    <div class="order-edit-lines">
                        @foreach ($order->lines as $line)
                            @php
                                $oldLine = (array) old("lines.{$line->id}", []);
                                $lineProductId = $oldLine['product_id'] ?? $line->product_id;
                                $lineQuantity = $oldLine['quantity'] ?? $line->quantity;
                                $lineSubtotal = $oldLine['subtotal'] ?? data_get($line->raw_payload, 'subtotal');
                                $lineTotal = $oldLine['total'] ?? data_get($line->raw_payload, 'total');
                                $selectedProduct = (int) $lineProductId === (int) $line->product_id
                                    ? $line->product
                                    : \App\Models\Product::query()->find($lineProductId);
                                $lineSku = $selectedProduct?->sku ?: $line->sku;
                                $lineName = $selectedProduct?->name ?: $line->name;
                            @endphp
                            <section class="order-edit-line" data-order-line>
                                <div class="order-edit-line-head">
                                    <div class="order-edit-line-title">
                                        <strong data-order-product-name>{{ $lineName ?: 'Produkt' }}</strong>
                                        <span>SKU Woo: {{ $line->sku ?: '-' }} · ID pozycji Woo: {{ $line->external_line_id ?: '-' }}</span>
                                    </div>
                                    <label class="order-edit-remove">
                                        <input name="lines[{{ $line->id }}][remove]" type="checkbox" value="1" @checked((bool) ($oldLine['remove'] ?? false)) data-order-line-remove>
                                        Usuń pozycję
                                    </label>
                                </div>
                                <div class="order-edit-line-grid">
                                    <label class="order-edit-product-picker">Produkt
                                        <input
                                            list="order-edit-product-options"
                                            value="{{ $lineSku }}"
                                            placeholder="Wpisz SKU lub nazwę"
                                            autocomplete="off"
                                            data-order-product-lookup
                                            data-original-unmapped="{{ filled($lineProductId) ? '0' : '1' }}"
                                            data-original-sku="{{ $lineSku }}"
                                        >
                                        <input
                                            name="lines[{{ $line->id }}][product_id]"
                                            type="hidden"
                                            value="{{ $lineProductId }}"
                                            data-order-product-id
                                            data-original-product-id="{{ $line->product_id }}"
                                        >
                                    </label>
                                    <label>Ilość
                                        <input name="lines[{{ $line->id }}][quantity]" type="number" min="0.0001" max="999999.9999" step="0.0001" value="{{ $lineQuantity }}" required>
                                    </label>
                                    <label>Suma przed rabatem
                                        <input name="lines[{{ $line->id }}][subtotal]" type="number" min="0" max="99999999.99" step="0.01" inputmode="decimal" value="{{ $lineSubtotal }}">
                                    </label>
                                    <label>Suma po rabacie
                                        <input name="lines[{{ $line->id }}][total]" type="number" min="0" max="99999999.99" step="0.01" inputmode="decimal" value="{{ $lineTotal }}">
                                    </label>
                                </div>
                            </section>
                        @endforeach

                        @php
                            $oldNewLine = (array) old('new_line', []);
                            $newLineProduct = filled($oldNewLine['product_id'] ?? null)
                                ? \App\Models\Product::query()->find((int) $oldNewLine['product_id'])
                                : null;
                        @endphp
                        <section class="order-edit-line order-edit-new-line" data-order-line>
                            <div class="order-edit-line-head">
                                <div class="order-edit-line-title">
                                    <strong data-order-product-name>{{ $newLineProduct?->name ?: 'Dodaj nową pozycję' }}</strong>
                                    <span>Pozostaw produkt pusty, jeśli nie chcesz nic dodawać.</span>
                                </div>
                            </div>
                            <div class="order-edit-line-grid">
                                <label class="order-edit-product-picker">Produkt
                                    <input
                                        list="order-edit-product-options"
                                        value="{{ $newLineProduct?->sku ?? '' }}"
                                        placeholder="Wpisz SKU lub nazwę"
                                        autocomplete="off"
                                        data-order-product-lookup
                                    >
                                    <input name="new_line[product_id]" type="hidden" value="{{ $oldNewLine['product_id'] ?? '' }}" data-order-product-id>
                                </label>
                                <label>Ilość
                                    <input name="new_line[quantity]" type="number" min="0.0001" max="999999.9999" step="0.0001" value="{{ $oldNewLine['quantity'] ?? 1 }}">
                                </label>
                                <label>Suma przed rabatem
                                    <input name="new_line[subtotal]" type="number" min="0" max="99999999.99" step="0.01" inputmode="decimal" value="{{ $oldNewLine['subtotal'] ?? '' }}" placeholder="Opcjonalnie">
                                </label>
                                <label>Suma po rabacie
                                    <input name="new_line[total]" type="number" min="0" max="99999999.99" step="0.01" inputmode="decimal" value="{{ $oldNewLine['total'] ?? '' }}" placeholder="Opcjonalnie">
                                </label>
                            </div>
                        </section>
                    </div>
                    <datalist id="order-edit-product-options"></datalist>
                </div>
            </article>

            <div class="order-edit-grid">
                <article class="card order-edit-card">
                    <div class="panel-header">Dane osoby zamawiającej</div>
                    <div class="order-edit-body">
                        <div class="order-edit-grid">
                            <label>Imię
                                <input name="billing[first_name]" value="{{ $field('billing.first_name', data_get($billing, 'first_name')) }}" maxlength="100">
                            </label>
                            <label>Nazwisko
                                <input name="billing[last_name]" value="{{ $field('billing.last_name', data_get($billing, 'last_name')) }}" maxlength="100">
                            </label>
                            <label class="wide">Firma
                                <input name="billing[company]" value="{{ $field('billing.company', data_get($billing, 'company')) }}" maxlength="200">
                            </label>
                            <label class="wide">Adres
                                <input name="billing[address_1]" value="{{ $field('billing.address_1', data_get($billing, 'address_1')) }}" maxlength="200">
                            </label>
                            <label class="wide">Dalsza część adresu
                                <input name="billing[address_2]" value="{{ $field('billing.address_2', data_get($billing, 'address_2')) }}" maxlength="200">
                            </label>
                            <label>Kod pocztowy
                                <input name="billing[postcode]" value="{{ $field('billing.postcode', data_get($billing, 'postcode')) }}" maxlength="32">
                            </label>
                            <label>Miasto
                                <input name="billing[city]" value="{{ $field('billing.city', data_get($billing, 'city')) }}" maxlength="120">
                            </label>
                            <label>Województwo / region
                                <input name="billing[state]" value="{{ $field('billing.state', data_get($billing, 'state')) }}" maxlength="120">
                            </label>
                            <label>Kraj (kod ISO)
                                <input name="billing[country]" value="{{ $field('billing.country', data_get($billing, 'country')) }}" maxlength="2" placeholder="PL">
                            </label>
                            <label>E-mail
                                <input name="billing[email]" type="email" value="{{ $field('billing.email', data_get($billing, 'email')) }}" maxlength="254">
                            </label>
                            <label>Telefon
                                <input name="billing[phone]" type="tel" value="{{ $field('billing.phone', data_get($billing, 'phone')) }}" maxlength="50">
                            </label>
                            <label class="wide">NIP
                                <input name="billing_tax_id" value="{{ $field('billing_tax_id', $billingTaxId) }}" maxlength="32" inputmode="numeric">
                            </label>
                        </div>
                    </div>
                </article>

                <article class="card order-edit-card">
                    <div class="panel-header">Dane odbiorcy przesyłki</div>
                    <div class="order-edit-body">
                        <div class="order-edit-grid">
                            <label>Imię
                                <input name="shipping[first_name]" value="{{ $field('shipping.first_name', data_get($shipping, 'first_name')) }}" maxlength="100">
                            </label>
                            <label>Nazwisko
                                <input name="shipping[last_name]" value="{{ $field('shipping.last_name', data_get($shipping, 'last_name')) }}" maxlength="100">
                            </label>
                            <label class="wide">Firma
                                <input name="shipping[company]" value="{{ $field('shipping.company', data_get($shipping, 'company')) }}" maxlength="200">
                            </label>
                            <label class="wide">Adres
                                <input name="shipping[address_1]" value="{{ $field('shipping.address_1', data_get($shipping, 'address_1')) }}" maxlength="200">
                            </label>
                            <label class="wide">Dalsza część adresu
                                <input name="shipping[address_2]" value="{{ $field('shipping.address_2', data_get($shipping, 'address_2')) }}" maxlength="200">
                            </label>
                            <label>Kod pocztowy
                                <input name="shipping[postcode]" value="{{ $field('shipping.postcode', data_get($shipping, 'postcode')) }}" maxlength="32">
                            </label>
                            <label>Miasto
                                <input name="shipping[city]" value="{{ $field('shipping.city', data_get($shipping, 'city')) }}" maxlength="120">
                            </label>
                            <label>Województwo / region
                                <input name="shipping[state]" value="{{ $field('shipping.state', data_get($shipping, 'state')) }}" maxlength="120">
                            </label>
                            <label>Kraj (kod ISO)
                                <input name="shipping[country]" value="{{ $field('shipping.country', data_get($shipping, 'country')) }}" maxlength="2" placeholder="PL">
                            </label>
                            <label>Telefon odbiorcy
                                <input name="shipping[phone]" type="tel" value="{{ $field('shipping.phone', data_get($shipping, 'phone')) }}" maxlength="50">
                            </label>
                            <label>Paczkomat / punkt odbioru
                                <input name="target_point" value="{{ $field('target_point', $targetPoint) }}" maxlength="40" placeholder="np. KRA010">
                            </label>
                        </div>
                    </div>
                </article>
            </div>

            <article class="card order-edit-card">
                <div class="panel-header">Wysyłka, płatność i uwagi</div>
                <div class="order-edit-body">
                    <div class="order-edit-grid three">
                        @if (is_array($shippingLine))
                            <input name="shipping_line[id]" type="hidden" value="{{ $field('shipping_line.id', data_get($shippingLine, 'id')) }}">
                            <label>Identyfikator metody wysyłki
                                <input name="shipping_line[method_id]" value="{{ $field('shipping_line.method_id', data_get($shippingLine, 'method_id')) }}" maxlength="100" placeholder="np. flat_rate:1">
                            </label>
                            <label>Nazwa metody wysyłki
                                <input name="shipping_line[method_title]" value="{{ $field('shipping_line.method_title', data_get($shippingLine, 'method_title')) }}" maxlength="160">
                            </label>
                            <label>Koszt wysyłki
                                <input name="shipping_line[total]" type="number" min="0" max="99999999.99" step="0.01" inputmode="decimal" value="{{ $field('shipping_line.total', data_get($shippingLine, 'total')) }}">
                            </label>
                        @else
                            <p class="order-edit-help wide">WooCommerce nie przekazało istniejącej pozycji wysyłki, dlatego nie można jej utworzyć z tego formularza.</p>
                        @endif
                        <label>Identyfikator płatności
                            <input name="payment_method" value="{{ $field('payment_method', data_get($order->raw_payload, 'payment_method')) }}" maxlength="100" placeholder="np. cod, bacs, przelewy24" @readonly($paymentMethodLocked)>
                        </label>
                        <label>Nazwa płatności
                            <input name="payment_method_title" value="{{ $field('payment_method_title', data_get($order->raw_payload, 'payment_method_title')) }}" maxlength="160" @readonly($paymentMethodLocked)>
                        </label>
                        @if ($paymentMethodLocked)
                            <p class="order-edit-help wide">Metoda płatności jest zablokowana po opłaceniu zamówienia, aby ewentualny zwrot trafił do pierwotnej bramki.</p>
                        @endif
                        <label class="wide">Uwagi klienta do zamówienia
                            <textarea name="customer_note" rows="4" maxlength="5000">{{ $field('customer_note', data_get($order->raw_payload, 'customer_note')) }}</textarea>
                        </label>
                    </div>
                </div>
            </article>

            <div class="order-edit-actions">
                <span class="muted">Przed zapisem sprawdź adres, wybraną metodę dostawy, punkt odbioru, produkty oraz kwoty po rabacie.</span>
                <button class="button" type="submit">Zapisz i zsynchronizuj zamówienie</button>
            </div>
        </fieldset>
    </form>

    @if ($canCancelOrder)
        <div
            class="order-cancel-modal"
            data-order-cancel-modal
            data-open-on-load="{{ $errors->has('reason') || $errors->has('confirm_cancellation') ? '1' : '0' }}"
            aria-hidden="true"
            hidden
        >
            <section class="order-cancel-modal-card" role="dialog" aria-modal="true" aria-labelledby="order-cancel-title">
                <div class="order-cancel-modal-header">
                    <strong id="order-cancel-title">Anuluj zamówienie {{ $orderNumber }}</strong>
                    <button class="order-cancel-modal-close" type="button" data-order-cancel-close aria-label="Zamknij">&times;</button>
                </div>
                <form class="order-cancel-modal-body" method="POST" action="{{ route('orders.cancel', $order) }}" data-order-cancel-form>
                    @csrf
                    <div class="alert error" role="alert">
                        To jest operacja finansowa i magazynowa. Nie zamykaj karty po zatwierdzeniu, dopóki nie zobaczysz wyniku.
                    </div>
                    <ul class="order-cancel-consequences">
                        <li>WooCommerce spróbuje zwrócić opłaconą kwotę przez pierwotną bramkę. Metody bez automatycznych zwrotów zostaną oznaczone do obsługi ręcznej.</li>
                        <li>Aktywne WZ, rezerwacje, pakowanie i etykiety kurierskie zostaną cofnięte; wydrukowane lub nieodwracalne etykiety pokażą ostrzeżenie.</li>
                        <li>Szkice dokumentów zostaną anulowane, a do wystawionej faktury powstanie pełna korekta.</li>
                        <li>Status w ERP i WooCommerce zostanie ustawiony na anulowany. Paczki odebranej przez kuriera nie można anulować tym procesem.</li>
                    </ul>
                    <label>Powód anulowania
                        <textarea name="reason" rows="4" minlength="3" maxlength="1000" required>{{ old('reason', $cancellation?->reason ?? '') }}</textarea>
                    </label>
                    @error('reason')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                    <label class="order-cancel-confirmation">
                        <input name="confirm_cancellation" type="checkbox" value="1" required @checked(old('confirm_cancellation'))>
                        <span>Potwierdzam anulowanie zamówienia i uruchomienie zwrotu środków oraz cofania dokumentów.</span>
                    </label>
                    @error('confirm_cancellation')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                    <div class="order-cancel-modal-actions">
                        <button class="button secondary" type="button" data-order-cancel-close>Wróć</button>
                        <button class="button order-cancel-button" type="submit" data-order-cancel-submit>Anuluj zamówienie</button>
                    </div>
                </form>
            </section>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('[data-order-edit-form]');
            const datalist = document.getElementById('order-edit-product-options');
            const cancelModal = document.querySelector('[data-order-cancel-modal]');
            const cancelForm = cancelModal?.querySelector('[data-order-cancel-form]');
            const cancelOpen = document.querySelector('[data-order-cancel-open]');
            const cancelCloseButtons = cancelModal?.querySelectorAll('[data-order-cancel-close]') || [];

            const closeCancelModal = () => {
                if (!cancelModal) return;
                cancelModal.hidden = true;
                cancelModal.setAttribute('aria-hidden', 'true');
                cancelOpen?.focus();
            };

            const openCancelModal = () => {
                if (!cancelModal) return;
                cancelModal.hidden = false;
                cancelModal.setAttribute('aria-hidden', 'false');
                cancelModal.querySelector('textarea[name="reason"]')?.focus();
            };

            cancelOpen?.addEventListener('click', openCancelModal);
            cancelCloseButtons.forEach((button) => button.addEventListener('click', closeCancelModal));
            cancelModal?.addEventListener('click', (event) => {
                if (event.target === cancelModal) closeCancelModal();
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && cancelModal && !cancelModal.hidden) closeCancelModal();
            });
            cancelForm?.addEventListener('submit', () => {
                const submit = cancelForm.querySelector('[data-order-cancel-submit]');
                if (!submit) return;
                submit.disabled = true;
                submit.textContent = 'Anulowanie…';
            });

            if (cancelModal?.dataset.openOnLoad === '1') {
                openCancelModal();
            }

            if (!form || !datalist) {
                return;
            }

            const products = new Map();
            let lookupTimer = null;
            let lookupRequest = null;

            const applySelection = (input) => {
                const product = products.get(input.value.trim());
                const line = input.closest('[data-order-line]');
                const productId = line?.querySelector('[data-order-product-id]');
                const productName = line?.querySelector('[data-order-product-name]');

                if (!product || !productId) {
                    return;
                }

                const selectedProductId = String(product.id);
                const originalProductId = String(productId.dataset.originalProductId || '');
                const previousProductId = String(productId.dataset.previousProductId || productId.value || originalProductId);
                const changesExistingProduct = originalProductId !== ''
                    && previousProductId !== ''
                    && selectedProductId !== previousProductId;

                productId.value = selectedProductId;
                productId.dataset.previousProductId = selectedProductId;
                input.setCustomValidity('');

                if (changesExistingProduct) {
                    line?.querySelectorAll('input[name$="[subtotal]"], input[name$="[total]"]').forEach((priceInput) => {
                        priceInput.value = '';
                    });
                }

                if (productName) {
                    productName.textContent = String(product.name || product.sku || 'Produkt');
                }
            };

            const loadProducts = async (query) => {
                if (query.length < 2) {
                    return;
                }

                lookupRequest?.abort();
                lookupRequest = new AbortController();

                try {
                    const separator = form.dataset.productLookupUrl.includes('?') ? '&' : '?';
                    const response = await fetch(`${form.dataset.productLookupUrl}${separator}q=${encodeURIComponent(query)}`, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        signal: lookupRequest.signal,
                    });

                    if (!response.ok) {
                        return;
                    }

                    const result = await response.json();
                    products.clear();
                    datalist.replaceChildren();

                    result.forEach((product) => {
                        const sku = String(product.sku || '').trim();

                        if (!sku) {
                            return;
                        }

                        products.set(sku, product);
                        const option = document.createElement('option');
                        option.value = sku;
                        option.label = String(product.name || '');
                        datalist.appendChild(option);
                    });

                    form.querySelectorAll('[data-order-product-lookup]').forEach(applySelection);
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        // Pozostałe pola formularza nadal można poprawiać; zapis pokaże błąd walidacji produktu.
                    }
                }
            };

            form.querySelectorAll('[data-order-product-lookup]').forEach((input) => {
                input.addEventListener('input', () => {
                    const productId = input.closest('[data-order-line]')?.querySelector('[data-order-product-id]');

                    if (productId) {
                        if (productId.value) {
                            productId.dataset.previousProductId = productId.value;
                        }

                        productId.value = '';
                    }

                    window.clearTimeout(lookupTimer);
                    lookupTimer = window.setTimeout(() => loadProducts(input.value.trim()), 220);
                });
                input.addEventListener('change', () => applySelection(input));
            });

            form.addEventListener('submit', (event) => {
                let firstInvalidInput = null;

                form.querySelectorAll('[data-order-product-lookup]').forEach((input) => {
                    const line = input.closest('[data-order-line]');
                    const productId = line?.querySelector('[data-order-product-id]');
                    const removing = line?.querySelector('[data-order-line-remove]')?.checked === true;
                    const keepsOriginalUnmappedProduct = input.dataset.originalUnmapped === '1'
                        && input.value.trim() === String(input.dataset.originalSku || '').trim();
                    const invalid = !removing
                        && input.value.trim() !== ''
                        && !productId?.value
                        && !keepsOriginalUnmappedProduct;
                    input.setCustomValidity(invalid ? 'Wybierz produkt z listy podpowiedzi.' : '');

                    if (invalid && !firstInvalidInput) {
                        firstInvalidInput = input;
                    }

                    const originalProductId = String(productId?.dataset.originalProductId || '');
                    const changesExistingProduct = !removing
                        && originalProductId !== ''
                        && String(productId?.value || '') !== ''
                        && String(productId?.value || '') !== originalProductId;
                    const priceInputs = line?.querySelectorAll('input[name$="[subtotal]"], input[name$="[total]"]') || [];

                    priceInputs.forEach((priceInput) => priceInput.setCustomValidity(''));

                    if (changesExistingProduct) {
                        priceInputs.forEach((priceInput) => {
                            const missingPrice = priceInput.value.trim() === '';
                            priceInput.setCustomValidity(missingPrice
                                ? 'Po zmianie produktu podaj obie kwoty pozycji.'
                                : '');

                            if (missingPrice && !firstInvalidInput) {
                                firstInvalidInput = priceInput;
                            }
                        });
                    }
                });

                if (firstInvalidInput) {
                    event.preventDefault();
                    firstInvalidInput.reportValidity();
                }
            });

            form.querySelectorAll('[data-order-line-remove]').forEach((checkbox) => {
                const syncRemovedState = () => {
                    const line = checkbox.closest('[data-order-line]');
                    line?.classList.toggle('order-edit-disabled', checkbox.checked);

                    if (checkbox.checked) {
                        line?.querySelector('[data-order-product-lookup]')?.setCustomValidity('');
                    }
                };

                checkbox.addEventListener('change', syncRemovedState);
                syncRemovedState();
            });
        })();
    </script>
@endpush
