@php
    $stockProduct = $stockProduct ?? $product;
    $stockBalances = $stockProduct->stockBalances ?? collect();
    $stockQty = fn ($value): string => rtrim(rtrim(number_format((float) $value, 4, ',', ' '), '0'), ',');
    $stockOnHand = $stockBalances->sum(fn ($balance): float => (float) $balance->quantity_on_hand);
    $stockReserved = $stockBalances->sum(fn ($balance): float => (float) $balance->quantity_reserved);
    $stockAvailable = $stockBalances->sum(fn ($balance): float => (float) $balance->quantity_available);
@endphp

@once
    @push('styles')
        <style>
            .stock-readonly-panel { display: grid; gap: 12px; padding: 12px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; }
            .stock-readonly-summary { display: flex; gap: 8px; flex-wrap: wrap; }
            .stock-readonly-summary .stock-pill { border: 1px solid var(--border); border-radius: 8px; padding: 7px 9px; background: #fff; color: var(--muted); font-size: 12px; }
            .stock-readonly-summary .stock-pill strong { color: var(--text); font-size: 15px; margin-left: 4px; }
            .stock-readonly-summary .stock-pill.available strong { color: var(--green-dark); }
            .stock-readonly-summary .stock-pill { margin: 0; }
            .stock-readonly-note { color: var(--muted); font-size: 13px; }
        </style>
    @endpush
@endonce

<div class="stock-readonly-panel">
    <div class="stock-readonly-summary">
        <span class="stock-pill">Stan ogółem <strong>{{ $stockQty($stockOnHand) }}</strong></span>
        <span class="stock-pill">Rezerwacje <strong>{{ $stockQty($stockReserved) }}</strong></span>
        <span class="stock-pill available">Dostępne do sprzedaży <strong>{{ $stockQty($stockAvailable) }}</strong></span>
    </div>
    <div class="stock-readonly-note">Stan wynika z dokumentów magazynowych i rezerwacji. Zmiany ilości wykonuj przez dokumenty magazynowe.</div>
    <div class="table-scroll">
        <table class="dense-table">
            <thead>
                <tr>
                    <th>Magazyn</th>
                    <th class="numeric">Stan</th>
                    <th class="numeric">Rezerwacje</th>
                    <th class="numeric">Dostępne</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($stockBalances->sortBy(fn ($balance) => $balance->warehouse?->code ?? '') as $balance)
                    <tr>
                        <td><strong>{{ $balance->warehouse?->code ?? 'MAG?' }}</strong> {{ $balance->warehouse?->name ?? '' }}</td>
                        <td class="numeric">{{ $stockQty($balance->quantity_on_hand) }}</td>
                        <td class="numeric">{{ $stockQty($balance->quantity_reserved) }}</td>
                        <td class="numeric">{{ $stockQty($balance->quantity_available) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Brak stanów magazynowych dla tego produktu.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
