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

@once
    @push('styles')
        <style>
            .stock-readonly-panel { display: grid; gap: 12px; padding: 12px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; }
            .stock-readonly-summary { display: flex; gap: 8px; flex-wrap: wrap; }
            .stock-readonly-summary .stock-pill { border: 1px solid var(--border); border-radius: 8px; padding: 7px 9px; background: #fff; color: var(--muted); font-size: 12px; margin: 0; }
            .stock-readonly-summary .stock-pill strong { color: var(--text); font-size: 15px; margin-left: 4px; }
            .stock-readonly-summary .stock-pill.available strong { color: var(--green-dark); }
            .stock-readonly-note { color: var(--muted); font-size: 13px; }
            .stock-adjust-table input { min-width: 112px; min-height: 34px; }
            .stock-adjust-table .stock-adjust-notes { min-width: 150px; }
            .stock-adjust-table .button { min-height: 34px; white-space: nowrap; }
            .stock-adjust-error { color: var(--red); font-size: 12px; min-height: 16px; }
        </style>
    @endpush

    @push('scripts')
        <script>
            (() => {
                const token = @json(csrf_token());

                document.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter') return;

                    const field = event.target.closest('[data-stock-adjust-quantity], [data-stock-adjust-notes]');

                    if (!field) return;

                    event.preventDefault();
                    field.closest('[data-stock-adjust-row]')?.querySelector('[data-stock-adjust-submit]')?.click();
                });

                document.addEventListener('click', (event) => {
                    const button = event.target.closest('[data-stock-adjust-submit]');

                    if (!button) return;

                    const row = button.closest('[data-stock-adjust-row]');
                    const error = row?.querySelector('[data-stock-adjust-error]');
                    const quantityInput = row?.querySelector('[data-stock-adjust-quantity]');
                    const notesInput = row?.querySelector('[data-stock-adjust-notes]');
                    const action = button.dataset.action || '';
                    const warehouseId = button.dataset.warehouseId || '';
                    const productSku = button.dataset.productSku || '';
                    const warehouseCode = button.dataset.warehouseCode || '';
                    const quantity = String(quantityInput?.value || '').trim();

                    if (!action || !warehouseId || quantity === '') {
                        if (error) error.textContent = 'Uzupełnij nowy stan magazynowy.';
                        quantityInput?.focus();
                        return;
                    }

                    if (!confirm(`Zaksięgować ręczną korektę stanu SKU ${productSku} w magazynie ${warehouseCode}?`)) {
                        return;
                    }

                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = action;
                    form.hidden = true;

                    const fields = {
                        _token: token,
                        warehouse_id: warehouseId,
                        new_quantity: quantity,
                        notes: String(notesInput?.value || ''),
                        redirect_url: button.dataset.redirectUrl || window.location.href,
                    };

                    Object.entries(fields).forEach(([name, value]) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = value;
                        form.append(input);
                    });

                    document.body.append(form);
                    form.submit();
                });
            })();
        </script>
    @endpush
@endonce

<div class="stock-readonly-panel" id="{{ $stockPanelId }}">
    <div class="stock-readonly-summary">
        <span class="stock-pill">Stan ogółem <strong>{{ $stockQty($stockOnHand) }}</strong></span>
        <span class="stock-pill">Rezerwacje <strong>{{ $stockQty($stockReserved) }}</strong></span>
        <span class="stock-pill available">Dostępne do sprzedaży <strong>{{ $stockQty($stockAvailable) }}</strong></span>
    </div>
    <div class="stock-readonly-note">Ręczna zmiana tworzy dokument KOR, księguje ruch magazynowy, aktualizuje stan i zapisuje audyt operacji.</div>
    <div class="table-scroll">
        <table class="dense-table stock-adjust-table">
            <thead>
                <tr>
                    <th>Magazyn</th>
                    <th class="numeric">Stan</th>
                    <th class="numeric">Rezerwacje</th>
                    <th class="numeric">Dostępne</th>
                    <th>Nowy stan</th>
                    <th>Powód</th>
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
                            <input class="stock-adjust-notes" data-stock-adjust-notes value="{{ old('notes') }}" placeholder="np. korekta po imporcie">
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
                        <td colspan="7">Brak aktywnych magazynów do ręcznej korekty stanu.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
