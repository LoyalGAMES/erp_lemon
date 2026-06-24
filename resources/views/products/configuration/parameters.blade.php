@extends('layouts.app', [
    'title' => $title,
    'subtitle' => $subtitle,
    'module' => 'product-parameters',
])

@push('styles')
    <style>
        .product-config-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .product-config-panel { margin-bottom: 16px; }
        .parameter-form { padding: 16px; display: grid; grid-template-columns: minmax(180px, 1fr) minmax(130px, .7fr) minmax(140px, .7fr) minmax(90px, .45fr) minmax(160px, .8fr) auto; gap: 12px; align-items: end; }
        .parameter-form .wide { grid-column: span 2; }
        .parameter-list { display: grid; }
        .parameter-row { display: grid; grid-template-columns: minmax(180px, 1fr) minmax(130px, .7fr) minmax(140px, .7fr) minmax(90px, .45fr) minmax(180px, 1fr) minmax(130px, .65fr) auto; gap: 10px; align-items: end; padding: 12px 16px; border-top: 1px solid var(--border); }
        .parameter-row:first-child { border-top: 0; }
        .parameter-row.header { color: #4b423b; font-size: 12px; font-weight: 780; align-items: center; }
        .parameter-flags { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; min-height: 39px; }
        .parameter-actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
        .discovered-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; padding: 16px; border-top: 1px solid var(--border); }
        .discovered-item { border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: #fffdfb; display: grid; gap: 5px; }
        .discovered-item strong { overflow-wrap: anywhere; }
        @media (max-width: 1180px) {
            .parameter-form, .parameter-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .parameter-form .wide { grid-column: 1 / -1; }
            .parameter-row.header { display: none; }
            .parameter-actions { justify-content: flex-start; }
        }
        @media (max-width: 720px) {
            .parameter-form, .parameter-row { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')
    <div class="product-config-toolbar">
        <div class="inline-actions">
            <a class="button secondary" href="{{ route('products.index') }}">Lista produktów</a>
            <a class="button secondary" href="{{ route('products.categories.index') }}">Kategorie</a>
        </div>
        <div class="toolbar-note">Parametry oznaczone jako wariantowe trafiają do atrybutów wariantów WooCommerce.</div>
    </div>

    <section class="card product-config-panel">
        <div class="panel-header">Dodaj parametr</div>
        <form class="parameter-form" method="POST" action="{{ route('products.parameters.store') }}">
            @csrf
            <label>Nazwa
                <input name="name" value="{{ old('name') }}" required placeholder="np. Rozmiar">
            </label>
            <label>Slug
                <input name="slug" value="{{ old('slug') }}" placeholder="rozmiar">
            </label>
            <label>Typ
                <select name="input_type">
                    @foreach (['text' => 'Tekst', 'number' => 'Liczba', 'select' => 'Lista', 'multiselect' => 'Wiele wartości', 'boolean' => 'Tak/Nie'] as $type => $label)
                        <option value="{{ $type }}" @selected(old('input_type', 'text') === $type)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Kolejność
                <input name="sort_order" type="number" min="0" max="65000" value="{{ old('sort_order', 100) }}">
            </label>
            <label class="wide">Wartości
                <textarea name="values_text" placeholder="Jedna wartość w wierszu">{{ old('values_text') }}</textarea>
            </label>
            <div class="parameter-flags">
                <label class="toggle-row"><input name="is_variant" type="checkbox" value="1" @checked(old('is_variant'))> Wariant</label>
                <label class="toggle-row"><input name="is_required" type="checkbox" value="1" @checked(old('is_required'))> Wymagany</label>
            </div>
            <button class="button" type="submit">Dodaj</button>
        </form>
    </section>

    <section class="card product-config-panel">
        <div class="panel-header">
            <span>Słownik parametrów</span>
            <span>{{ $definitions->count() }} pozycji</span>
        </div>
        <div class="parameter-list">
            <div class="parameter-row header">
                <span>Nazwa</span>
                <span>Slug</span>
                <span>Typ</span>
                <span>Kolejność</span>
                <span>Wartości</span>
                <span>Flagi</span>
                <span></span>
            </div>
            @forelse ($definitions as $definition)
                @php
                    $updateFormId = 'parameter-update-' . $definition->id;
                    $deleteFormId = 'parameter-delete-' . $definition->id;
                @endphp
                <form id="{{ $updateFormId }}" method="POST" action="{{ route('products.parameters.update', $definition) }}">
                    @csrf
                    @method('PUT')
                </form>
                <form id="{{ $deleteFormId }}" method="POST" action="{{ route('products.parameters.destroy', $definition) }}" onsubmit="return confirm('Usunąć parametr produktu?');">
                    @csrf
                    @method('DELETE')
                </form>
                <div class="parameter-row">
                    <label>Nazwa
                        <input name="name" form="{{ $updateFormId }}" value="{{ $definition->name }}" required>
                    </label>
                    <label>Slug
                        <input name="slug" form="{{ $updateFormId }}" value="{{ $definition->slug }}">
                    </label>
                    <label>Typ
                        <select name="input_type" form="{{ $updateFormId }}">
                            @foreach (['text' => 'Tekst', 'number' => 'Liczba', 'select' => 'Lista', 'multiselect' => 'Wiele wartości', 'boolean' => 'Tak/Nie'] as $type => $label)
                                <option value="{{ $type }}" @selected($definition->input_type === $type)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Kolejność
                        <input name="sort_order" form="{{ $updateFormId }}" type="number" min="0" max="65000" value="{{ $definition->sort_order }}">
                    </label>
                    <label>Wartości
                        <textarea name="values_text" form="{{ $updateFormId }}">{{ implode("\n", (array) $definition->values) }}</textarea>
                    </label>
                    <div class="parameter-flags">
                        <label class="toggle-row"><input name="is_variant" form="{{ $updateFormId }}" type="checkbox" value="1" @checked($definition->is_variant)> Wariant</label>
                        <label class="toggle-row"><input name="is_required" form="{{ $updateFormId }}" type="checkbox" value="1" @checked($definition->is_required)> Wymagany</label>
                    </div>
                    <div class="parameter-actions">
                        <button class="button secondary" type="submit" form="{{ $updateFormId }}">Zapisz</button>
                        <button class="button" style="background: var(--red);" type="submit" form="{{ $deleteFormId }}">Usuń</button>
                    </div>
                </div>
            @empty
                <div class="parameter-row">
                    <div class="toolbar-note">Brak parametrów w słowniku. Dodaj najczęściej używane atrybuty produktu.</div>
                </div>
            @endforelse
        </div>
    </section>

    <section class="card">
        <div class="panel-header">
            <span>Parametry wykryte w produktach</span>
            <span>{{ $discoveredParameters->count() }} pozycji</span>
        </div>
        <div class="discovered-grid">
            @forelse ($discoveredParameters as $parameter)
                <div class="discovered-item">
                    <strong>{{ $parameter['name'] }}</strong>
                    <span class="toolbar-note">Użycie: {{ $parameter['usage'] }}</span>
                    <span>{{ implode(', ', array_slice($parameter['values'], 0, 8)) ?: '-' }}</span>
                </div>
            @empty
                <div class="toolbar-note">Nie wykryto jeszcze parametrów w produktach.</div>
            @endforelse
        </div>
    </section>
@endsection
