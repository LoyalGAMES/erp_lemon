@extends('layouts.app', ['title' => 'Magazyny', 'subtitle' => 'Magazyn może wysyłać stany do wybranych sklepów albo działać tylko wewnętrznie.', 'module' => 'warehouses'])

@section('content')
    @php
        $qty = static fn ($value): string => number_format((float) $value, 0, ',', ' ');
    @endphp

    <input id="warehouse-drawer" class="drawer-toggle" type="checkbox">

    <div class="page-toolbar">
        <div class="toolbar-note">Routing decyduje, czy stan z danego magazynu jest wysyłany do wybranego sklepu.</div>
        <label class="button" for="warehouse-drawer">Dodaj magazyn</label>
    </div>

    <label class="drawer-backdrop" for="warehouse-drawer"></label>
    <aside class="drawer-panel" aria-label="Dodaj magazyn">
        <div class="drawer-header">
            <div class="drawer-title">Dodaj magazyn</div>
            <label class="drawer-close" for="warehouse-drawer">&times;</label>
        </div>
        <form class="form-grid warehouse-create-form" method="POST" action="{{ route('warehouses.store') }}">
            @csrf
            <div class="warehouse-form-grid">
                <label>Kod <input name="code" value="{{ old('code') }}" placeholder="M1" required maxlength="40"></label>
                <label>Nazwa <input name="name" value="{{ old('name') }}" required></label>
                <label>Typ
                    <select name="type" required>
                        <option value="physical">Fizyczny</option>
                        <option value="internal">Wewnętrzny</option>
                        <option value="returns">Zwroty</option>
                        <option value="virtual">Wirtualny</option>
                    </select>
                </label>
            </div>
            <label><input type="checkbox" name="allow_negative_stock" value="1"> Pozwól na ujemny stan</label>
            <div>
                <strong>Wysyłka stanów do kanałów</strong>
                <p class="subtitle">Brak zaznaczenia oznacza magazyn wewnętrzny bez synchronizacji stanów.</p>
                @forelse ($salesChannels as $channel)
                    <label><input type="checkbox" name="sales_channel_ids[]" value="{{ $channel->id }}"> {{ $channel->code }} - {{ $channel->name }}</label>
                @empty
                    <p class="subtitle">Brak kanałów. Dodaj sklep w module Integracje, aby włączyć routing stanów.</p>
                @endforelse
            </div>
            <button class="button" type="submit">Zapisz magazyn</button>
        </form>
    </aside>

    <article class="card">
        <div class="panel-header">
            <span>Magazyny w systemie</span>
            <span>{{ $warehouses->count() }} rekordów</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Kod</th>
                        <th>Nazwa</th>
                        <th>Typ</th>
                        <th>Routing</th>
                        <th class="numeric">Stan ogólny</th>
                        <th class="numeric">Rezerwacje</th>
                        <th class="numeric">Dostępne</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($warehouses as $warehouse)
                        @php
                            $onHand = $warehouse->stockBalances->sum(fn ($balance) => (float) $balance->quantity_on_hand);
                            $reserved = $warehouse->stockBalances->sum(fn ($balance) => (float) $balance->quantity_reserved);
                            $available = $warehouse->stockBalances->sum(fn ($balance) => (float) $balance->quantity_available);
                            $productCount = $warehouse->stockBalances
                                ->filter(fn ($balance) => (float) $balance->quantity_on_hand !== 0.0 || (float) $balance->quantity_reserved !== 0.0 || (float) $balance->quantity_available !== 0.0)
                                ->pluck('product_id')
                                ->unique()
                                ->count();
                        @endphp
                        <tr>
                            <td><strong>{{ $warehouse->code }}</strong></td>
                            <td>{{ $warehouse->name }}</td>
                            <td>{{ $warehouse->type }}</td>
                            <td>{{ $warehouse->routes->isEmpty() ? 'Wewnętrzny' : $warehouse->routes->pluck('salesChannel.code')->implode(', ') }}</td>
                            <td class="numeric">{{ $qty($onHand) }}</td>
                            <td class="numeric">{{ $qty($reserved) }}</td>
                            <td class="numeric">{{ $qty($available) }}</td>
                            <td>
                                <div class="inline-actions">
                                    <a class="button secondary" href="{{ route('products.index', ['warehouse' => $warehouse->id]) }}">Produkty ({{ $productCount }})</a>
                                    <a class="button secondary" href="{{ route('warehouses.edit', $warehouse) }}">Edytuj</a>
                                    <form method="POST" action="{{ route('warehouses.destroy', $warehouse) }}" onsubmit="return confirm('Usunąć magazyn?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button" style="background: var(--red);" type="submit">Usuń</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">Brak magazynów. Dodaj M1, M2 lub M3 i zdecyduj, czy mają wysyłać stany do sklepów.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>

    <article class="card" style="margin-top: 18px;">
        <div class="panel-header">
            <span>Podgląd stanów wysyłanych do kanałów</span>
            <span>{{ $stockPreview->count() }} mapowań</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Kanał</th>
                        <th>SKU</th>
                        <th>Produkt</th>
                        <th class="numeric">Do wysłania</th>
                        <th>Magazyny w kalkulacji</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stockPreview as $row)
                        <tr>
                            <td>{{ $row['channel'] ?? '-' }}</td>
                            <td>{{ $row['sku'] ?? '-' }}</td>
                            <td>{{ $row['product_name'] ?? '-' }}</td>
                            <td class="numeric">{{ $qty($row['quantity']) }}</td>
                            <td>
                                @forelse ($row['breakdown'] as $part)
                                    {{ $part['warehouse_code'] ?? ('#' . $part['warehouse_id']) }}:
                                    {{ $qty($part['available']) }}
                                    @if ((float) $part['buffer'] > 0)
                                        <span class="muted">bufor {{ $qty($part['buffer']) }}</span>
                                    @endif
                                    <span class="muted">=> {{ $qty($part['contribution']) }}</span><br>
                                @empty
                                    <span class="muted">Brak magazynów przypisanych do kanału.</span>
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">Brak mapowań produktów do kanałów. Podgląd pojawi się po imporcie produktów WooCommerce.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
@endsection

@push('styles')
    <style>
        .warehouse-form-grid { display: grid; grid-template-columns: 120px minmax(0, 1fr) 180px; gap: 10px; }
        .inline-actions .button { white-space: nowrap; }
        @media (max-width: 760px) {
            .warehouse-form-grid { grid-template-columns: 1fr; }
        }
    </style>
@endpush
