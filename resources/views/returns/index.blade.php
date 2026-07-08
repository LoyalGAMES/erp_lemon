@extends('layouts.app', ['title' => 'Zwroty', 'subtitle' => 'Zwrot przyjmuje towar na wskazany magazyn przez dokument RX. Po zaksięgowaniu RX można wystawić fakturę korygującą do zamówienia.', 'module' => 'returns'])

@section('content')
    @php
        $quantityLabel = static function (float $quantity): string {
            $formatted = number_format($quantity, 4, ',', ' ');

            return rtrim(rtrim($formatted, '0'), ',');
        };

        $returnReasons = $returnSettings['return_reasons'] ?? [];
        $returnConditions = $returnSettings['conditions'] ?? [];
        $returnDispositions = $returnSettings['dispositions'] ?? [];
        $conditionLabels = collect($returnConditions)->pluck('label', 'code');
        $dispositionLabels = collect($returnDispositions)->pluck('label', 'code');
        $defaultTargetWarehouseId = old('target_warehouse_id', $returnSettings['default_target_warehouse_id'] ?? null);
        $defaultCondition = $returnSettings['default_condition'] ?? 'unchecked';
        $defaultDisposition = $returnSettings['default_disposition'] ?? 'restock';
        $oldReturnLines = collect(old('lines', []))
            ->filter(fn ($line): bool => is_array($line) && filled($line['product_id'] ?? null))
            ->values();
        $productLabels = $products
            ->mapWithKeys(fn ($product): array => [$product->id => trim($product->sku . ' - ' . $product->name)])
            ->all();
    @endphp

    <input id="return-drawer" class="drawer-toggle" type="checkbox" @checked($errors->any())>

    <div class="page-toolbar">
        <div class="toolbar-note">Zwrot wybierasz po zamówieniu, a pozycje dodajesz tylko z produktów faktycznie zamówionych i jeszcze możliwych do zwrotu.</div>
        <label class="button" for="return-drawer">Dodaj zwrot</label>
    </div>

    <label class="drawer-backdrop" for="return-drawer"></label>
    <aside class="drawer-panel return-drawer-panel" aria-label="Dodaj zwrot">
        <div class="drawer-header">
            <div class="drawer-title">Dodaj zwrot</div>
            <label class="drawer-close" for="return-drawer">&times;</label>
        </div>
        <form class="form-grid" method="POST" action="{{ route('returns.store') }}" data-return-form autocomplete="off">
            @csrf
            <div class="return-order-field">
                <label>Znajdź zamówienie
                    <input name="order_lookup_display" value="{{ old('external_order_number') }}" placeholder="Numer, klient, email, telefon, adres lub produkt" data-return-order-lookup data-return-order-lookup-url="{{ route('returns.orders.lookup') }}" autocomplete="new-password" spellcheck="false">
                    <input name="external_order_number" type="hidden" value="{{ old('external_order_number') }}" data-return-order-number>
                    <input name="external_order_id" type="hidden" value="{{ old('external_order_id') }}" data-return-order-id>
                </label>
                <div class="return-order-results" data-return-order-results hidden></div>
            </div>
            <div class="return-order-preview" data-return-order-preview>Wpisz cokolwiek z zamówienia. W podpowiedzi zobaczysz klienta i towary, żeby od razu wybrać właściwe zamówienie.</div>

            <label>Magazyn domyślny zwrotu
                <select name="target_warehouse_id" required>
                    <option value="">Wybierz magazyn</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected((string) $defaultTargetWarehouseId === (string) $warehouse->id)>{{ $warehouse->code }} - {{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Powód
                <select name="reason">
                    <option value="">Wybierz powód</option>
                    @foreach ($returnReasons as $reason)
                        <option value="{{ $reason }}" @selected(old('reason') === $reason)>{{ $reason }}</option>
                    @endforeach
                </select>
            </label>
            <label>Email klienta <input name="customer_email" type="email" value="{{ old('customer_email') }}" autocomplete="off"></label>
            <label>Notatka <textarea name="notes" rows="3">{{ old('notes') }}</textarea></label>

            <section class="return-lines-editor" data-return-lines>
                <div class="section-header">
                    <strong>Pozycje zwrotu</strong>
                    <button class="button secondary" type="button" data-return-product-open>Dodaj pozycję z zamówienia</button>
                </div>
                <div class="return-lines-list" data-return-line-list>
                    @foreach ($oldReturnLines as $lineIndex => $oldLine)
                        <div class="return-line-card" data-return-line>
                            <input name="lines[{{ $lineIndex }}][external_order_line_id]" type="hidden" value="{{ $oldLine['external_order_line_id'] ?? '' }}">
                            <input name="lines[{{ $lineIndex }}][product_id]" type="hidden" value="{{ $oldLine['product_id'] ?? '' }}">
                            <div class="return-line-head">
                                <div>
                                    <strong>{{ $productLabels[$oldLine['product_id']] ?? 'Produkt z zamówienia' }}</strong>
                                    <span class="muted">pozycja zwrotu</span>
                                </div>
                                <button class="button secondary" type="button" data-return-line-remove>Usuń</button>
                            </div>
                            <div class="return-line-grid">
                                <label>Ilość
                                    <input name="lines[{{ $lineIndex }}][quantity]" type="number" step="1" min="1" value="{{ $oldLine['quantity'] ?? 1 }}" required>
                                </label>
                                <label>Stan towaru
                                    <select name="lines[{{ $lineIndex }}][condition]" required>
                                        @foreach ($returnConditions as $condition)
                                            <option value="{{ $condition['code'] }}" @selected(($oldLine['condition'] ?? $defaultCondition) === $condition['code'])>{{ $condition['label'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Dyspozycja
                                    <select name="lines[{{ $lineIndex }}][disposition]" required>
                                        @foreach ($returnDispositions as $disposition)
                                            <option value="{{ $disposition['code'] }}" @selected(($oldLine['disposition'] ?? $defaultDisposition) === $disposition['code'])>{{ $disposition['label'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                            <label>Notatka do pozycji
                                <input name="lines[{{ $lineIndex }}][notes]" value="{{ $oldLine['notes'] ?? '' }}" placeholder="np. brak metki, plama, uszkodzone opakowanie">
                            </label>
                        </div>
                    @endforeach
                </div>
                <div class="empty-state" data-return-lines-empty @if ($oldReturnLines->isNotEmpty()) hidden @endif>Najpierw wybierz zamówienie, potem dodaj z niego produkty zwracane przez klienta.</div>
            </section>
            <button class="button" type="submit">Zapisz zwrot</button>
        </form>
    </aside>

    @php
        $statusLabels = [
            'pending' => ['label' => 'Oczekujący', 'class' => 'orange'],
            'opened' => ['label' => 'Otwarty', 'class' => 'blue'],
            'document_created' => ['label' => 'Dokument RX', 'class' => 'blue'],
            'completed' => ['label' => 'Zrealizowany', 'class' => ''],
            'rejected' => ['label' => 'Odrzucony', 'class' => 'red'],
            'cancelled' => ['label' => 'Anulowany', 'class' => 'red'],
        ];
    @endphp

    <article class="card">
        <div class="panel-header">
            <span>Zwroty w systemie</span>
            <span>{{ $returns->count() }} rekordów</span>
        </div>
        <div class="returns-filters">
            <nav class="returns-tabs">
                <a class="returns-tab {{ $activeTab === 'all' ? 'active' : '' }}" href="{{ route('returns.index', array_filter(['q' => $searchTerm])) }}">Wszystkie</a>
                <a class="returns-tab {{ $activeTab === 'pending' ? 'active' : '' }}" href="{{ route('returns.index', array_filter(['tab' => 'pending', 'q' => $searchTerm])) }}">
                    Oczekujące ze sklepu
                    @if ($pendingCount > 0)
                        <span class="returns-tab-count">{{ $pendingCount }}</span>
                    @endif
                </a>
            </nav>
            <form class="returns-search" method="GET" action="{{ route('returns.index') }}">
                @if ($activeTab === 'pending')
                    <input type="hidden" name="tab" value="pending">
                @endif
                <input name="q" value="{{ $searchTerm }}" placeholder="Szukaj: numer zwrotu, zgłoszenia, zamówienia, e-mail, telefon" aria-label="Szukaj zwrotów">
                <button class="button secondary" type="submit">Szukaj</button>
                @if ($searchTerm !== '')
                    <a class="button secondary" href="{{ route('returns.index', array_filter(['tab' => $activeTab === 'pending' ? 'pending' : null])) }}">Wyczyść</a>
                @endif
            </form>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Numer</th>
                        <th>Status</th>
                        <th>Zamówienie</th>
                        <th>Magazyn</th>
                        <th>Pozycje</th>
                        <th>Powód</th>
                        <th>Klient</th>
                        <th>Akcja</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($returns as $returnCase)
                        @php
                            $automationWarnings = collect(data_get($returnCase->metadata, 'automation_warnings', []))
                                ->filter(fn ($warning): bool => is_array($warning) && filled($warning['message'] ?? null))
                                ->values();
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $returnCase->number }}</strong>
                                @if (data_get($returnCase->metadata, 'source') === 'store_form')
                                    <br><span class="muted">{{ data_get($returnCase->metadata, 'return_reference') }}</span>
                                @endif
                            </td>
                            <td>
                                @php $statusMeta = $statusLabels[$returnCase->status] ?? ['label' => $returnCase->status, 'class' => 'blue']; @endphp
                                <span class="status {{ $statusMeta['class'] }}">{{ $statusMeta['label'] }}</span>
                                @if (data_get($returnCase->metadata, 'return_method') === 'wygodne_zwroty')
                                    <br><span class="muted">Wygodne Zwroty</span>
                                @endif
                                @if ($automationWarnings->isNotEmpty())
                                    <details class="return-automation-details">
                                        <summary>Automatyzacja ({{ $automationWarnings->count() }})</summary>
                                        <ul class="return-automation-list">
                                            @foreach ($automationWarnings as $warning)
                                                <li>{{ $warning['message'] }}</li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </td>
                            <td>{{ $returnCase->externalOrder?->external_number ?? '-' }}</td>
                            <td>
                                @php
                                    $returnWarehouses = $returnCase->lines
                                        ->map(fn ($line) => $line->targetWarehouse?->code ?? $returnCase->targetWarehouse?->code)
                                        ->filter()
                                        ->unique()
                                        ->values();
                                @endphp
                                {{ $returnWarehouses->isNotEmpty() ? $returnWarehouses->implode(', ') : '-' }}
                            </td>
                            <td>
                                @foreach ($returnCase->lines as $line)
                                    {{ $line->product?->sku }} x {{ $quantityLabel((float) $line->quantity_accepted) }}
                                    <span class="muted">
                                        ({{ $conditionLabels[$line->condition] ?? $line->condition }},
                                        {{ $dispositionLabels[$line->disposition] ?? $line->disposition }},
                                        {{ $line->targetWarehouse?->code ?? $returnCase->targetWarehouse?->code ?? '-' }})
                                    </span><br>
                                @endforeach
                            </td>
                            <td>{{ $returnCase->reason ?? '-' }}</td>
                            <td>{{ $returnCase->customer_email ?? '-' }}</td>
                            <td>@include('partials.return-actions', ['returnCase' => $returnCase])</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                @if ($searchTerm !== '')
                                    Brak zwrotów pasujących do „{{ $searchTerm }}".
                                @elseif ($activeTab === 'pending')
                                    Brak oczekujących zgłoszeń ze sklepu. Nowe zgłoszenia z formularza zwrotów pojawią się tutaj automatycznie.
                                @else
                                    Brak zwrotów. Dodaj zwrot, wybierz zamówienie i produkty przyjmowane na magazyn.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>

    <div class="return-modal" data-return-product-modal hidden>
        <div class="return-modal-backdrop" data-return-product-close></div>
        <div class="return-modal-panel" role="dialog" aria-modal="true" aria-label="Dodaj produkty ze zwracanego zamówienia">
            <div class="return-modal-header">
                <div>
                    <strong>Produkty z zamówienia</strong>
                    <span data-return-product-order-label>Najpierw wybierz zamówienie</span>
                </div>
                <button class="button secondary" type="button" data-return-product-close>Zamknij</button>
            </div>
            <div class="return-product-list" data-return-product-list></div>
            <div class="return-modal-footer">
                <button class="button" type="button" data-return-product-add>Dodaj zaznaczone</button>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .return-drawer-panel { width: min(880px, 96vw); }
        .returns-filters { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--border); }
        .returns-tabs { display: flex; gap: 6px; }
        .returns-tab { display: inline-flex; align-items: center; gap: 7px; border: 1px solid var(--border); border-radius: 8px; padding: 7px 13px; font-weight: 700; color: var(--muted); text-decoration: none; background: var(--surface); }
        .returns-tab.active { border-color: var(--brand); color: var(--brand-dark); background: var(--brand-soft); }
        .returns-tab-count { display: inline-flex; align-items: center; justify-content: center; min-width: 21px; min-height: 21px; border-radius: 999px; padding: 0 6px; background: var(--orange); color: #fff; font-size: 12px; }
        .returns-search { display: flex; gap: 8px; flex: 1 1 340px; max-width: 560px; }
        .returns-search input { flex: 1; min-width: 0; }
        .return-label-form { display: grid; gap: 6px; margin-top: 6px; }
        .return-label-form select { min-height: 38px; max-width: 220px; }
        @media (max-width: 760px) {
            .returns-filters { align-items: stretch; flex-direction: column; }
            .returns-search { max-width: none; }
        }
        .return-order-field { position: relative; }
        .return-order-results { position: absolute; z-index: 40; inset-inline: 0; top: calc(100% + 6px); display: grid; gap: 8px; max-height: 420px; overflow: auto; border: 1px solid var(--border); border-radius: 8px; padding: 8px; background: var(--surface); box-shadow: var(--shadow); }
        .return-order-results[hidden] { display: none; }
        .return-order-result { display: grid; gap: 6px; width: 100%; text-align: left; border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: #fff; color: inherit; cursor: pointer; }
        .return-order-result:hover { border-color: var(--green); background: rgba(134, 115, 100, .07); }
        .return-order-result.has-returns { border-color: #e03131; background: #fff5f5; }
        .return-order-result strong { font-size: 16px; }
        .return-order-lines { display: grid; gap: 4px; color: var(--muted); font-size: 13px; }
        .return-order-preview { border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; color: var(--muted); background: #fffdfb; font-weight: 650; }
        .return-order-preview.selected { display: grid; gap: 8px; border-color: var(--green); background: rgba(134, 115, 100, .07); color: var(--text); }
        .return-order-preview.warning { border-color: #e03131; background: #fff5f5; color: #9d1c1c; }
        .return-order-preview .return-order-lines { color: inherit; }
        .return-lines-editor { display: grid; gap: 10px; border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: #fffdfb; }
        .section-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .return-lines-list { display: grid; gap: 10px; }
        .return-line-card { display: grid; gap: 10px; border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: var(--surface); }
        .return-line-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .return-line-head div { display: grid; gap: 3px; }
        .return-line-grid { display: grid; grid-template-columns: minmax(110px, .4fr) minmax(180px, .8fr) minmax(180px, .8fr); gap: 10px; }
        .return-modal[hidden] { display: none; }
        .return-modal { position: fixed; inset: 0; z-index: 80; display: grid; place-items: center; padding: 20px; }
        .return-modal-backdrop { position: absolute; inset: 0; background: rgb(16 24 20 / 46%); }
        .return-modal-panel { position: relative; z-index: 1; display: grid; gap: 14px; width: min(920px, 96vw); max-height: min(780px, 92vh); overflow: auto; border-radius: 8px; background: var(--surface); padding: 16px; box-shadow: var(--shadow); }
        .return-modal-header,
        .return-modal-footer { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .return-modal-header div { display: grid; gap: 4px; }
        .return-modal-header span { color: var(--muted); }
        .return-product-list { display: grid; gap: 10px; }
        .return-product-row { display: grid; grid-template-columns: auto minmax(0, 1fr) 110px; gap: 12px; align-items: center; border: 1px solid var(--border); border-radius: 8px; padding: 12px; }
        .return-product-row[aria-disabled="true"] { opacity: .55; background: #f5f6f5; }
        .return-product-row strong { display: block; }
        .return-automation-details { margin-top: 7px; min-width: 220px; white-space: normal; }
        .return-automation-details summary { cursor: pointer; color: var(--red); font-size: 12px; font-weight: 760; }
        .return-automation-list { margin: 7px 0 0; padding-left: 18px; color: var(--red); font-size: 12px; line-height: 1.35; }
        .return-automation-list li + li { margin-top: 4px; }
        @media (max-width: 760px) {
            .return-line-grid,
            .return-product-row { grid-template-columns: 1fr; }
            .section-header,
            .return-modal-header,
            .return-modal-footer { align-items: stretch; flex-direction: column; }
            .section-header .button,
            .return-modal-footer .button { width: 100%; }
        }
    </style>
@endpush

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('[data-return-form]');
            const orderLookup = document.querySelector('[data-return-order-lookup]');
            const orderNumberInput = document.querySelector('[data-return-order-number]');
            const orderIdInput = document.querySelector('[data-return-order-id]');
            const orderResults = document.querySelector('[data-return-order-results]');
            const orderPreview = document.querySelector('[data-return-order-preview]');
            const customerEmailInput = document.querySelector('input[name="customer_email"]');
            const lineList = document.querySelector('[data-return-line-list]');
            const emptyState = document.querySelector('[data-return-lines-empty]');
            const productOpen = document.querySelector('[data-return-product-open]');
            const productModal = document.querySelector('[data-return-product-modal]');
            const productList = document.querySelector('[data-return-product-list]');
            const productOrderLabel = document.querySelector('[data-return-product-order-label]');
            const productAdd = document.querySelector('[data-return-product-add]');
            const defaultCondition = @json($defaultCondition);
            const defaultDisposition = @json($defaultDisposition);
            const conditionOptions = @json($returnConditions);
            const dispositionOptions = @json($returnDispositions);
            let selectedOrder = null;
            let lookupTimer = null;
            let latestOrders = [];

            const escapeHtml = (value) => String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');

            const quantityLabel = (value) => {
                const number = Number(value || 0);

                return Number.isInteger(number) ? String(number) : number.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
            };

            const requestJson = (url) => new Promise((resolve, reject) => {
                const request = new XMLHttpRequest();
                request.open('GET', url, true);
                request.setRequestHeader('Accept', 'application/json');
                request.onreadystatechange = () => {
                    if (request.readyState !== XMLHttpRequest.DONE) {
                        return;
                    }

                    if (request.status < 200 || request.status >= 300) {
                        reject(new Error(`Lookup failed: ${request.status}`));
                        return;
                    }

                    try {
                        resolve(JSON.parse(request.responseText));
                    } catch (error) {
                        reject(error);
                    }
                };
                request.onerror = () => reject(new Error('Lookup request failed'));
                request.send();
            });

            const optionHtml = (options, selected) => options
                .map((option) => `<option value="${escapeHtml(option.code)}"${option.code === selected ? ' selected' : ''}>${escapeHtml(option.label)}</option>`)
                .join('');

            const refreshEmptyState = () => {
                if (!emptyState || !lineList) {
                    return;
                }

                emptyState.hidden = lineList.querySelectorAll('[data-return-line]').length > 0;
            };

            const selectedOrderLineIds = () => new Set([...document.querySelectorAll('input[name$="[external_order_line_id]"]')]
                .map((input) => String(input.value))
                .filter(Boolean));

            const addLine = (line) => {
                if (!lineList) {
                    return;
                }

                const index = `${Date.now()}_${Math.floor(Math.random() * 10000)}`;
                const remaining = Math.max(1, Number(line.remaining_quantity || line.quantity || 1));
                lineList.insertAdjacentHTML('beforeend', `
                    <div class="return-line-card" data-return-line data-return-order-line-id="${escapeHtml(line.id)}">
                        <input name="lines[${index}][external_order_line_id]" type="hidden" value="${escapeHtml(line.id)}">
                        <input name="lines[${index}][product_id]" type="hidden" value="${escapeHtml(line.product_id)}">
                        <div class="return-line-head">
                            <div>
                                <strong>${escapeHtml(line.name || 'Produkt z zamówienia')}</strong>
                                <span class="muted">${escapeHtml(line.sku || 'brak SKU')} · do zwrotu maks. ${quantityLabel(remaining)} szt.</span>
                            </div>
                            <button class="button secondary" type="button" data-return-line-remove>Usuń</button>
                        </div>
                        <div class="return-line-grid">
                            <label>Ilość
                                <input name="lines[${index}][quantity]" type="number" step="1" min="1" max="${escapeHtml(remaining)}" value="${escapeHtml(remaining)}" required>
                            </label>
                            <label>Stan towaru
                                <select name="lines[${index}][condition]" required>${optionHtml(conditionOptions, defaultCondition)}</select>
                            </label>
                            <label>Dyspozycja
                                <select name="lines[${index}][disposition]" required>${optionHtml(dispositionOptions, defaultDisposition)}</select>
                            </label>
                        </div>
                        <label>Notatka do pozycji
                            <input name="lines[${index}][notes]" placeholder="np. brak metki, plama, uszkodzone opakowanie">
                        </label>
                    </div>
                `);
                refreshEmptyState();
            };

            const selectOrder = (order) => {
                selectedOrder = order;

                if (orderLookup) {
                    orderLookup.value = `#${order.number} · ${order.customer || order.email || ''}`.trim();
                }
                if (orderNumberInput) {
                    orderNumberInput.value = order.number || order.external_id || '';
                }
                if (orderIdInput) {
                    orderIdInput.value = order.id || '';
                }
                if (customerEmailInput && !customerEmailInput.value) {
                    customerEmailInput.value = order.email || '';
                }
                if (orderResults) {
                    orderResults.hidden = true;
                    orderResults.innerHTML = '';
                }
                orderLookup?.blur();
                if (orderPreview) {
                    const returnableCount = (order.lines || []).filter((line) => line.returnable).length;
                    const lines = (order.lines || []).slice(0, 6).map((line) => {
                        const suffix = line.returnable
                            ? `do zwrotu ${quantityLabel(line.remaining_quantity)} z ${quantityLabel(line.quantity)} szt.`
                            : `już zwrócono ${quantityLabel(line.returned_quantity)} z ${quantityLabel(line.quantity)} szt.`;

                        return `<span>${escapeHtml(line.sku || '')} ${escapeHtml(line.name || '')} · ${suffix}</span>`;
                    }).join('');

                    orderPreview.classList.add('selected');
                    orderPreview.classList.toggle('warning', Boolean(order.has_returns));
                    orderPreview.innerHTML = `
                        <strong>${order.has_returns ? 'Wybrano zamówienie z istniejącym zwrotem' : 'Wybrano zamówienie'} ${escapeHtml(order.number)}</strong>
                        <span>${escapeHtml(order.customer || 'Klient bez nazwy')} · ${escapeHtml(order.email || order.phone || 'brak kontaktu')} · pozycji do zwrotu: ${returnableCount}</span>
                        <span class="return-order-lines">${lines || 'Brak pozycji możliwych do zwrotu.'}</span>
                    `;
                }
            };

            const renderOrderResults = (orders) => {
                latestOrders = orders;

                if (!orderResults) {
                    return;
                }

                if (orders.length === 0) {
                    orderResults.innerHTML = '<div class="empty-state">Nie znaleziono zamówienia.</div>';
                    orderResults.hidden = false;
                    return;
                }

                orderResults.innerHTML = orders.map((order, index) => {
                    const lines = (order.lines || []).slice(0, 4).map((line) => {
                        const suffix = line.returnable
                            ? `do zwrotu ${quantityLabel(line.remaining_quantity)} z ${quantityLabel(line.quantity)}`
                            : `zwrócono ${quantityLabel(line.returned_quantity)} z ${quantityLabel(line.quantity)}`;

                        return `<span>${escapeHtml(line.sku || '')} ${escapeHtml(line.name || '')} · ${suffix}</span>`;
                    }).join('');
                    const returnNotice = order.has_returns ? `<span class="status red">ma już zwrot: ${escapeHtml(order.return_count)}</span>` : '';

                    return `
                        <button class="return-order-result ${order.has_returns ? 'has-returns' : ''}" type="button" data-return-order-pick="${index}">
                            <strong>Zamówienie ${escapeHtml(order.number || order.external_id)}</strong>
                            <span>${escapeHtml(order.customer || 'Klient bez nazwy')} · ${escapeHtml(order.email || order.phone || '')} ${returnNotice}</span>
                            <span class="return-order-lines">${lines || 'Brak pozycji możliwych do zwrotu.'}</span>
                        </button>
                    `;
                }).join('');
                orderResults.hidden = false;
            };

            const fetchOrders = async () => {
                const value = (orderLookup?.value || '').trim();
                const lookupUrl = orderLookup?.dataset.returnOrderLookupUrl || '';

                if (!value || value.length < 2 || !lookupUrl) {
                    if (orderResults) {
                        orderResults.hidden = true;
                    }
                    if (orderPreview) {
                        orderPreview.classList.remove('selected', 'warning');
                        orderPreview.textContent = 'Wpisz cokolwiek z zamówienia. W podpowiedzi zobaczysz klienta i towary, żeby od razu wybrać właściwe zamówienie.';
                    }
                    return;
                }

                try {
                    const payload = await requestJson(`${lookupUrl}?q=${encodeURIComponent(value)}`);
                    renderOrderResults(Array.isArray(payload.orders) ? payload.orders : []);
                } catch (error) {
                    if (orderResults) {
                        orderResults.innerHTML = '<div class="empty-state">Nie udało się pobrać zamówień. Spróbuj ponownie.</div>';
                        orderResults.hidden = false;
                    }
                }
            };

            const openProductModal = () => {
                if (!productModal || !productList) {
                    return;
                }

                if (!selectedOrder) {
                    productList.innerHTML = '<div class="empty-state">Najpierw wybierz zamówienie z wyszukiwarki.</div>';
                } else {
                    const selectedIds = selectedOrderLineIds();
                    const rows = (selectedOrder.lines || []).map((line, index) => {
                        const alreadySelected = selectedIds.has(String(line.id));
                        const disabled = !line.returnable || alreadySelected;
                        const suffix = alreadySelected
                            ? 'już dodano'
                            : (line.returnable ? `do zwrotu ${quantityLabel(line.remaining_quantity)} szt.` : 'brak ilości do zwrotu');

                        return `
                            <label class="return-product-row" aria-disabled="${disabled ? 'true' : 'false'}">
                                <input type="checkbox" value="${index}" ${disabled ? 'disabled' : ''}>
                                <span>
                                    <strong>${escapeHtml(line.name || 'Produkt z zamówienia')}</strong>
                                    <span class="muted">${escapeHtml(line.sku || 'brak SKU')} · zamówiono ${quantityLabel(line.quantity)} · ${suffix}</span>
                                </span>
                                <span>${quantityLabel(line.remaining_quantity || 0)} szt.</span>
                            </label>
                        `;
                    }).join('');

                    productList.innerHTML = rows || '<div class="empty-state">To zamówienie nie ma produktów możliwych do zwrotu.</div>';
                    if (productOrderLabel) {
                        productOrderLabel.textContent = `Zamówienie ${selectedOrder.number}`;
                    }
                }

                productModal.hidden = false;
            };

            form?.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' || event.target.tagName === 'TEXTAREA') {
                    return;
                }

                event.preventDefault();

                if (event.target === orderLookup && latestOrders[0]) {
                    selectOrder(latestOrders[0]);
                }
            });

            form?.addEventListener('submit', (event) => {
                if (lineList?.querySelector('[data-return-line]')) {
                    return;
                }

                event.preventDefault();
                alert('Wybierz co najmniej jeden towar z listy pozycji zamówienia.');

                if (selectedOrder) {
                    openProductModal();
                } else {
                    orderLookup?.focus();
                }
            });

            orderLookup?.addEventListener('input', () => {
                if (orderNumberInput) {
                    orderNumberInput.value = '';
                }
                if (orderIdInput) {
                    orderIdInput.value = '';
                }
                if (orderPreview) {
                    orderPreview.classList.remove('selected', 'warning');
                    orderPreview.textContent = 'Szukam zamówienia...';
                }
                selectedOrder = null;
                window.clearTimeout(lookupTimer);
                lookupTimer = window.setTimeout(fetchOrders, 220);
            });

            document.addEventListener('click', (event) => {
                const orderButton = event.target.closest('[data-return-order-pick]');
                const removeButton = event.target.closest('[data-return-line-remove]');
                const closeProduct = event.target.closest('[data-return-product-close]');

                if (orderButton) {
                    const order = latestOrders[Number(orderButton.dataset.returnOrderPick)];

                    if (order) {
                        selectOrder(order);
                    }
                }

                if (removeButton) {
                    removeButton.closest('[data-return-line]')?.remove();
                    refreshEmptyState();
                }

                if (closeProduct && productModal) {
                    productModal.hidden = true;
                }
            });

            productOpen?.addEventListener('click', openProductModal);
            productAdd?.addEventListener('click', () => {
                if (!selectedOrder || !productList || !productModal) {
                    return;
                }

                const checked = [...productList.querySelectorAll('input[type="checkbox"]:checked')];
                checked.forEach((checkbox) => addLine(selectedOrder.lines[Number(checkbox.value)]));
                productModal.hidden = true;
                lineList?.querySelector('input[name$="[quantity]"]')?.focus();
            });

            document.addEventListener('click', (event) => {
                if (!orderResults || !orderLookup) {
                    return;
                }

                if (!event.target.closest('.return-order-field')) {
                    orderResults.hidden = true;
                }
            });

            refreshEmptyState();
        })();
    </script>
@endpush
