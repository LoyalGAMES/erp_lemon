@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@push('styles')
    <style>
        .warehouse-edit-card { max-width: 960px; }
        .warehouse-edit-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .warehouse-edit-grid .wide { grid-column: 1 / -1; }
        .warehouse-channel-box { border: 1px solid var(--border); border-radius: 8px; padding: 12px; display: grid; gap: 10px; background: #fffdfb; }
        .warehouse-checkbox-row { display: flex; gap: 10px; align-items: center; font-weight: 760; color: var(--text); }
        .warehouse-edit-actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
        @media (max-width: 760px) {
            .warehouse-edit-grid { grid-template-columns: 1fr; }
            .warehouse-edit-actions { justify-content: stretch; }
            .warehouse-edit-actions .button { width: 100%; }
        }
    </style>
@endpush

@section('content')
    @php
        $selectedSalesChannelIds = collect(old('sales_channel_ids', $warehouse->routes->pluck('sales_channel_id')->all()))
            ->map(fn ($id): string => (string) $id);
    @endphp

    <div class="page-toolbar">
        <div class="inline-actions">
            <a class="button secondary" href="{{ route('warehouses.index') }}">Wróć do magazynów</a>
            <a class="button secondary" href="{{ route('products.index', ['warehouse' => $warehouse->id]) }}">Produkty w tym magazynie</a>
        </div>
        <span class="status {{ $warehouse->is_active ? '' : 'red' }}">{{ $warehouse->is_active ? 'Aktywny' : 'Nieaktywny' }}</span>
    </div>

    <article class="card warehouse-edit-card">
        <div class="panel-header">
            <span>Konfiguracja magazynu</span>
            <span>{{ $warehouse->code }}</span>
        </div>
        <form class="form-grid" method="POST" action="{{ route('warehouses.update', $warehouse) }}">
            @csrf
            @method('PUT')

            <div class="warehouse-edit-grid">
                <label>Kod
                    <input name="code" value="{{ old('code', $warehouse->code) }}" required maxlength="40">
                </label>
                <label>Nazwa
                    <input name="name" value="{{ old('name', $warehouse->name) }}" required>
                </label>
                <label>Typ
                    <select name="type" required>
                        @foreach (['physical' => 'Fizyczny', 'internal' => 'Wewnętrzny', 'returns' => 'Zwroty', 'virtual' => 'Wirtualny'] as $type => $label)
                            <option value="{{ $type }}" @selected(old('type', $warehouse->type) === $type)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="warehouse-checkbox-row">
                    <input type="hidden" name="allow_negative_stock" value="0">
                    <input type="checkbox" name="allow_negative_stock" value="1" @checked(old('allow_negative_stock', $warehouse->allow_negative_stock))>
                    Ujemny stan
                </label>
                <label class="warehouse-checkbox-row">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $warehouse->is_active))>
                    Aktywny
                </label>
                <div class="warehouse-channel-box wide">
                    <strong>Kanały stanów</strong>
                    @forelse ($salesChannels as $channel)
                        <label class="warehouse-checkbox-row">
                            <input
                                type="checkbox"
                                name="sales_channel_ids[]"
                                value="{{ $channel->id }}"
                                @checked($selectedSalesChannelIds->contains((string) $channel->id))
                            >
                            {{ $channel->code }} - {{ $channel->name }}
                        </label>
                    @empty
                        <span class="muted">Brak aktywnych kanałów sprzedaży.</span>
                    @endforelse
                </div>
            </div>

            <div class="warehouse-edit-actions">
                <a class="button secondary" href="{{ route('warehouses.index') }}">Anuluj</a>
                <button class="button" type="submit">Zapisz konfigurację</button>
            </div>
        </form>
    </article>
@endsection
