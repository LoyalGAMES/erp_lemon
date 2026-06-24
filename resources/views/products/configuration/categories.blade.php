@extends('layouts.app', [
    'title' => $title,
    'subtitle' => $subtitle,
    'module' => 'product-categories',
])

@push('styles')
    <style>
        .product-config-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .product-config-panel { margin-bottom: 16px; }
        .category-create-form { padding: 16px; display: grid; grid-template-columns: minmax(160px, .8fr) minmax(220px, 1.1fr) minmax(260px, 1.4fr) minmax(180px, .8fr) auto; gap: 12px; align-items: end; }
        .category-create-form .wide { grid-column: span 2; }
        .category-create-form .full { grid-column: 1 / -2; }
        .category-table table { min-width: 1240px; }
        .category-table td { vertical-align: top; }
        .category-table input, .category-table select, .category-table textarea { min-width: 0; width: 100%; }
        .category-table textarea { min-height: 86px; resize: vertical; }
        .category-name-stack { display: grid; gap: 8px; min-width: 240px; }
        .category-id-stack { display: grid; gap: 8px; min-width: 160px; }
        .category-description-cell { min-width: 280px; }
        .category-channel-cell { min-width: 160px; }
        .category-usage { display: inline-flex; align-items: center; justify-content: center; min-width: 40px; }
        .category-actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; min-width: 150px; }
        @media (max-width: 1180px) {
            .category-create-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .category-create-form .wide, .category-create-form .full { grid-column: 1 / -1; }
        }
        @media (max-width: 720px) {
            .category-create-form { grid-template-columns: 1fr; }
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
        <div class="panel-header">Dodaj kategorię do PIM</div>
        <form class="category-create-form" method="POST" action="{{ route('products.categories.store') }}">
            @csrf
            <label>Kanał
                <select name="sales_channel_id">
                    <option value="">Globalna ERP</option>
                    @foreach ($salesChannels as $channel)
                        <option value="{{ $channel->id }}" @selected(old('sales_channel_id') == $channel->id)>{{ $channel->code }} - {{ $channel->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Nazwa kategorii
                <input name="name" value="{{ old('name') }}" required placeholder="np. Koszule">
            </label>
            <label class="wide">Ścieżka w drzewie
                <input name="path" value="{{ old('path') }}" placeholder="Odzież > Koszule">
            </label>
            <label>ID Woo / ERP
                <input name="external_id" value="{{ old('external_id') }}" placeholder="np. 44">
            </label>
            <label>ID nadrzędnej
                <input name="parent_external_id" value="{{ old('parent_external_id') }}" placeholder="np. 12">
            </label>
            <label>Slug
                <input name="slug" value="{{ old('slug') }}" placeholder="koszule">
            </label>
            <label class="full">Opis kategorii
                <textarea name="description" placeholder="Opis widoczny lub gotowy do wysłania do sklepu">{{ old('description') }}</textarea>
            </label>
            <button class="button" type="submit">Dodaj</button>
        </form>
    </section>

    <section class="card">
        <div class="panel-header">
            <span>Kategorie PIM</span>
            <span>{{ $categories->count() }} pozycji</span>
        </div>
        <div class="table-scroll category-table">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Kanał</th>
                        <th>Kategoria i ścieżka</th>
                        <th>ID Woo / ERP</th>
                        <th>Opis</th>
                        <th>Użycie</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $category)
                        @php
                            $usage = $categoryUsage->get(mb_strtolower($category->path ?: $category->name), $categoryUsage->get(mb_strtolower($category->name), 0));
                            $updateFormId = 'category-update-' . $category->id;
                            $deleteFormId = 'category-delete-' . $category->id;
                        @endphp
                        <tr>
                            <td class="category-channel-cell">
                                <form id="{{ $updateFormId }}" method="POST" action="{{ route('products.categories.update', $category) }}">
                                    @csrf
                                    @method('PUT')
                                </form>
                                <form id="{{ $deleteFormId }}" method="POST" action="{{ route('products.categories.destroy', $category) }}" onsubmit="return confirm('Usunąć kategorię produktu?');">
                                    @csrf
                                    @method('DELETE')
                                </form>
                                <select name="sales_channel_id" form="{{ $updateFormId }}">
                                    <option value="" @selected($category->sales_channel_id === null)>Globalna ERP</option>
                                    @foreach ($salesChannels as $channel)
                                        <option value="{{ $channel->id }}" @selected((int) $category->sales_channel_id === (int) $channel->id)>{{ $channel->code }} - {{ $channel->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <div class="category-name-stack">
                                    <input name="name" form="{{ $updateFormId }}" value="{{ $category->name }}" required placeholder="Nazwa kategorii">
                                    <input name="path" form="{{ $updateFormId }}" value="{{ $category->path }}" placeholder="Ścieżka, np. Odzież > Koszule">
                                    <input name="slug" form="{{ $updateFormId }}" value="{{ $category->slug }}" placeholder="Slug">
                                </div>
                            </td>
                            <td>
                                <div class="category-id-stack">
                                    <input name="external_id" form="{{ $updateFormId }}" value="{{ $category->external_id }}" placeholder="ID Woo / ERP">
                                    <input name="parent_external_id" form="{{ $updateFormId }}" value="{{ $category->parent_external_id }}" placeholder="ID nadrzędnej">
                                </div>
                            </td>
                            <td class="category-description-cell">
                                <textarea name="description" form="{{ $updateFormId }}" placeholder="Opis kategorii">{{ $category->description }}</textarea>
                            </td>
                            <td>
                                <span class="status category-usage">{{ $usage }}</span>
                            </td>
                            <td>
                                <div class="category-actions">
                                    <button class="button secondary" type="submit" form="{{ $updateFormId }}">Zapisz</button>
                                    <button class="button" style="background: var(--red);" type="submit" form="{{ $deleteFormId }}">Usuń</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">Brak kategorii. Import produktów z WooCommerce albo formularz powyżej uzupełni słownik.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
