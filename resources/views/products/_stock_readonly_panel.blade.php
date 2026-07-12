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
    <div class="stock-readonly-note">Ręczna zmiana tworzy dokument KOR, księguje ruch magazynowy i od razu dodaje aktualny stan do synchronizacji z WooCommerce.</div>
    <div class="table-scroll">
        <table class="dense-table stock-adjust-table">
            <thead>
                <tr>
                    <th>Magazyn</th>
                    <th class="numeric">Stan</th>
                    <th class="numeric">Rezerwacje</th>
                    <th class="numeric">Dostępne</th>
                    <th>Nowy stan</th>
                    <th>Akcja</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($stockWarehouses as $warehouse)
                    @php
                        $balance = $stockBalanceByWarehouse->get((string) $warehouse->id);
                        $currentOnHand = (float) ($balance?->quantity_on_hand ?? 0);
                    @endphp
                    <tr data-stock-adjust-row>
                        <td><strong>{{ $warehouse->code }}</strong> {{ $warehouse->name }}</td>
                        <td class="numeric">{{ $stockQty($currentOnHand) }}</td>
                        <td class="numeric">{{ $stockQty($balance?->quantity_reserved ?? 0) }}</td>
                        <td class="numeric">{{ $stockQty($balance?->quantity_available ?? 0) }}</td>
                        <td>
                            <input data-stock-adjust-quantity type="number" min="0" step="0.0001" value="{{ old('new_quantity', $currentOnHand) }}" aria-label="Nowy stan {{ $stockProduct->sku }} w {{ $warehouse->code }}">
                            <div class="stock-adjust-error" data-stock-adjust-error></div>
                        </td>
                        <td>
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
