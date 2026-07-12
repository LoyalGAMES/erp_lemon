@php
    $stockProduct = $stockProduct ?? $product;
    $stockBalances = $stockProduct->stockBalances ?? collect();
    $stockBalanceByWarehouse = $stockBalances->keyBy(fn ($balance) => (string) $balance->warehouse_id);
    $stockWarehouses = collect($stockWarehouses ?? $warehouses ?? \App\Models\Warehouse::query()->where('is_active', true)->orderBy('code')->get());
    $stockQty = fn ($value): string => rtrim(rtrim(number_format((float) $value, 4, ',', ' '), '0'), ',') ?: '0';
    $stockOnHand = $stockBalances->sum(fn ($balance): float => (float) $balance->quantity_on_hand);
    $stockReserved = $stockBalances->sum(fn ($balance): float => (float) $balance->quantity_reserved);
    $stockAvailable = $stockBalances->sum(fn ($balance): float => (float) $balance->quantity_available);
    $stockPanelId = 'stock-adjust-' . $stockProduct->id . '-' . substr(md5((string) spl_object_id($stockProduct) . request()->fullUrl()), 0, 8);
@endphp

@include('products._stock_adjust_assets')

<div class="stock-readonly-panel" id="{{ $stockPanelId }}">
    <div class="stock-readonly-summary">
        <span class="stock-pill">Stan ogółem <strong>{{ $stockQty($stockOnHand) }}</strong></span>
        <span class="stock-pill">Rezerwacje <strong>{{ $stockQty($stockReserved) }}</strong></span>
        <span class="stock-pill available">Dostępne do sprzedaży <strong>{{ $stockQty($stockAvailable) }}</strong></span>
    </div>
    <div class="stock-readonly-note">Podaj stan fizyczny. Ręczna zmiana tworzy dokument KOR, a ERP odejmuje rezerwacje i od razu dodaje stan dostępny do synchronizacji z WooCommerce.</div>
    <div class="table-scroll stock-adjust-table-scroll">
        <table class="dense-table stock-adjust-table">
            <thead>
                <tr>
                    <th>Magazyn</th>
                    <th class="numeric">Stan</th>
                    <th class="numeric">Rezerwacje</th>
                    <th class="numeric">Dostępne</th>
                    <th>Nowy stan ogółem</th>
                    <th>Akcja</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($stockWarehouses as $warehouse)
                    @php
                        $balance = $stockBalanceByWarehouse->get((string) $warehouse->id);
                        $currentOnHand = (float) ($balance?->quantity_on_hand ?? 0);
                        $stockInputId = $stockPanelId . '-warehouse-' . $warehouse->id . '-quantity';
                        $stockErrorId = $stockInputId . '-error';
                    @endphp
                    <tr data-stock-adjust-row data-stock-adjust-state="idle" data-warehouse-id="{{ $warehouse->id }}">
                        <td class="stock-adjust-warehouse" data-stock-label="Magazyn"><strong>{{ $warehouse->code }}</strong> {{ $warehouse->name }}</td>
                        <td class="numeric stock-adjust-metric" data-stock-label="Stan">{{ $stockQty($currentOnHand) }}</td>
                        <td class="numeric stock-adjust-metric" data-stock-label="Rezerwacje">{{ $stockQty($balance?->quantity_reserved ?? 0) }}</td>
                        <td class="numeric stock-adjust-metric" data-stock-label="Dostępne">{{ $stockQty($balance?->quantity_available ?? 0) }}</td>
                        <td class="stock-adjust-field" data-stock-label="Nowy stan ogółem">
                            <input id="{{ $stockInputId }}" data-stock-adjust-quantity type="number" min="0" step="0.0001" inputmode="decimal" enterkeyhint="done" value="{{ $currentOnHand }}" aria-label="Nowy stan ogółem {{ $stockProduct->sku }} w {{ $warehouse->code }}" aria-describedby="{{ $stockErrorId }}">
                            <div class="stock-adjust-error" id="{{ $stockErrorId }}" data-stock-adjust-error role="alert" aria-live="polite"></div>
                        </td>
                        <td class="stock-adjust-action">
                            <button
                                class="button secondary"
                                type="button"
                                data-stock-adjust-submit
                                data-action="{{ route('products.stock.adjust', $stockProduct) }}"
                                data-warehouse-id="{{ $warehouse->id }}"
                                data-warehouse-code="{{ $warehouse->code }}"
                                data-product-sku="{{ $stockProduct->sku }}"
                                data-redirect-url="{{ request()->fullUrl() }}"
                            >Ustaw</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">Brak aktywnych magazynów do ręcznej korekty stanu.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
