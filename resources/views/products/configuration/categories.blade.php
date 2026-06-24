@extends('layouts.app', [
    'title' => $title,
    'subtitle' => $subtitle,
    'module' => 'product-categories',
])

@push('styles')
    <style>
        .product-config-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .product-config-panel { margin-bottom: 16px; }
        .product-config-form { padding: 16px; display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)) auto; gap: 12px; align-items: end; }
        .product-config-form .wide { grid-column: span 2; }
        .product-config-list { display: grid; }
        .product-config-row { display: grid; grid-template-columns: minmax(130px, .7fr) minmax(150px, 1fr) minmax(180px, 1.2fr) minmax(130px, .8fr) minmax(90px, .55fr) auto; gap: 10px; align-items: end; padding: 12px 16px; border-top: 1px solid var(--border); }
        .product-config-row:first-child { border-top: 0; }
        .product-config-row.header { color: #4b423b; font-size: 12px; font-weight: 780; align-items: center; }
        .product-config-row .product-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .product-config-actions { display: flex; gap: 8px; align-items: center; justify-content: flex-end; }
        @media (max-width: 1180px) {
            .product-config-form, .product-config-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .product-config-row .product-form-grid { grid-template-columns: 1fr; }
            .product-config-form .wide { grid-column: 1 / -1; }
            .product-config-row.header { display: none; }
            .product-config-actions { justify-content: flex-start; }
        }
        @media (max-width: 720px) {
            .product-config-form, .product-config-row { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')
    <div class="product-config-toolbar">
        <div class="inline-actions">
            <a class="button secondary" href="{{ route('products.index') }}">Lista produktów</a>
            <a class="button secondary" href="{{ route('products.parameters.index') }}">Parametry</a>
        </div>
        <div class="toolbar-note">Kategorie z ID WooCommerce są wysyłane w eksporcie produktu do sklepu.</div>
    </div>

    <section class="card product-config-panel">
        <div class="panel-header">Dodaj kategorię</div>
        <form class="product-config-form" method="POST" action="{{ route('products.categories.store') }}">
            @csrf
            <label>Kanał
                <select name="sales_channel_id">
                    <option value="">Globalna ERP</option>
                    @foreach ($salesChannels as $channel)
                        <option value="{{ $channel->id }}" @selected(old('sales_channel_id') == $channel->id)>{{ $channel->code }} - {{ $channel->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>ID Woo
                <input name="external_id" value="{{ old('external_id') }}" placeholder="np. 44">
            </label>
            <label class="wide">Nazwa
                <input name="name" value="{{ old('name') }}" required>
            </label>
            <label class="wide">Ścieżka
                <input name="path" value="{{ old('path') }}" placeholder="Odzież > Koszule">
            </label>
            <label>Slug
                <input name="slug" value="{{ old('slug') }}">
            </label>
            <button class="button" type="submit">Dodaj</button>
        </form>
    </section>

    <section class="card">
        <div class="panel-header">
            <span>Kategorie PIM</span>
            <span>{{ $categories->count() }} pozycji</span>
        </div>
        <div class="product-config-list">
            <div class="product-config-row header">
                <span>Kanał</span>
                <span>ID / slug</span>
                <span>Nazwa</span>
                <span>Ścieżka</span>
                <span>Użycie</span>
                <span></span>
            </div>
            @forelse ($categories as $category)
                @php
                    $usage = $categoryUsage->get(mb_strtolower($category->path ?: $category->name), $categoryUsage->get(mb_strtolower($category->name), 0));
                    $updateFormId = 'category-update-' . $category->id;
                    $deleteFormId = 'category-delete-' . $category->id;
                @endphp
                <form id="{{ $updateFormId }}" method="POST" action="{{ route('products.categories.update', $category) }}">
                    @csrf
                    @method('PUT')
                </form>
                <form id="{{ $deleteFormId }}" method="POST" action="{{ route('products.categories.destroy', $category) }}" onsubmit="return confirm('Usunąć kategorię produktu?');">
                    @csrf
                    @method('DELETE')
                </form>
                <div class="product-config-row">
                    <label>Kanał
                        <select name="sales_channel_id" form="{{ $updateFormId }}">
                            <option value="">Globalna ERP</option>
                            @foreach ($salesChannels as $channel)
                                <option value="{{ $channel->id }}" @selected((int) $category->sales_channel_id === (int) $channel->id)>{{ $channel->code }} - {{ $channel->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="product-form-grid">
                        <label>ID Woo
                            <input name="external_id" form="{{ $updateFormId }}" value="{{ $category->external_id }}">
                        </label>
                        <label>Slug
                            <input name="slug" form="{{ $updateFormId }}" value="{{ $category->slug }}">
                        </label>
                    </div>
                    <label>Nazwa
                        <input name="name" form="{{ $updateFormId }}" value="{{ $category->name }}" required>
                    </label>
                    <label>Ścieżka
                        <input name="path" form="{{ $updateFormId }}" value="{{ $category->path }}">
                    </label>
                    <div>
                        <span class="status">{{ $usage }}</span>
                    </div>
                    <div class="product-config-actions">
                        <button class="button secondary" type="submit" form="{{ $updateFormId }}">Zapisz</button>
                        <button class="button" style="background: var(--red);" type="submit" form="{{ $deleteFormId }}">Usuń</button>
                    </div>
                </div>
            @empty
                <div class="product-config-row">
                    <div class="toolbar-note">Brak kategorii. Import produktów z WooCommerce albo formularz powyżej uzupełni słownik.</div>
                </div>
            @endforelse
        </div>
    </section>
@endsection
