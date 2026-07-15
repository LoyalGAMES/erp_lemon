@extends('layouts.app', [
    'title' => $title,
    'subtitle' => $subtitle,
    'module' => 'product-parameters',
])

@push('styles')
    <style>
        .product-config-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .product-config-panel { margin-bottom: 16px; }
        .parameter-form { padding: 16px; display: grid; grid-template-columns: minmax(150px, 1fr) minmax(150px, 1fr) minmax(120px, .75fr) minmax(130px, .7fr) minmax(80px, .4fr) minmax(170px, 1fr) minmax(170px, 1fr) minmax(130px, .65fr) auto; gap: 12px; align-items: end; }
        .parameter-list { display: grid; }
        .parameter-row { display: grid; grid-template-columns: minmax(150px, 1fr) minmax(150px, 1fr) minmax(120px, .75fr) minmax(130px, .7fr) minmax(80px, .4fr) minmax(170px, 1fr) minmax(170px, 1fr) minmax(130px, .65fr) auto; gap: 10px; align-items: end; padding: 12px 16px; border-top: 1px solid var(--border); }
        .parameter-row:first-child { border-top: 0; }
        .parameter-row.header { color: #4b423b; font-size: 12px; font-weight: 780; align-items: center; }
        .parameter-flags { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; min-height: 39px; }
        .parameter-actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
        .discovered-table table { min-width: 1320px; }
        .discovered-table textarea { min-height: 76px; }
        @media (max-width: 1480px) {
            .parameter-form, .parameter-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .parameter-row.header { display: none; }
            .parameter-actions { justify-content: flex-start; }
        }
        @media (max-width: 720px) {
            .parameter-form, .parameter-row { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')
    @php
        $knownParameterNames = $definitions
            ->flatMap(fn ($definition): array => [$definition->name, $definition->name_en])
            ->filter()
            ->map(fn (string $name): string => mb_strtolower($name))
            ->all();
    @endphp

    <div class="product-config-toolbar">
        <div class="inline-actions">
            <a class="button secondary" href="{{ route('products.index') }}">Lista produktów</a>
            <a class="button secondary" href="{{ route('products.categories.index') }}">Kategorie</a>
        </div>
        <div class="toolbar-note">Wartości PL i EN są łączone wierszami. Pusty wiersz EN oznacza użycie wartości PL. Dla parametrów wariantowych kolejność wierszy jest globalną kolejnością na sklepie, np. S przed M.</div>
    </div>

    <section class="card product-config-panel">
        <div class="panel-header">Dodaj parametr do słownika</div>
        <form class="parameter-form" method="POST" action="{{ route('products.parameters.store') }}">
            @csrf
            <label>Nazwa (PL)
                <input name="name" value="{{ old('name') }}" required placeholder="np. Rozmiar">
            </label>
            <label>Nazwa (EN)
                <input name="name_en" value="{{ old('name_en') }}" placeholder="e.g. Size">
            </label>
            <label>Slug
                <input name="slug" value="{{ old('slug') }}" placeholder="rozmiar">
            </label>
            <label>Typ pola
                <select name="input_type">
                    @foreach (['text' => 'Tekst', 'number' => 'Liczba', 'select' => 'Lista', 'multiselect' => 'Wiele wartości', 'boolean' => 'Tak/Nie'] as $type => $label)
                        <option value="{{ $type }}" @selected(old('input_type', 'text') === $type)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Kolejność
                <input name="sort_order" type="number" min="0" max="65000" value="{{ old('sort_order', 100) }}">
            </label>
            <label>Dozwolone wartości (PL)
                <textarea name="values_text" placeholder="Jedna wartość w wierszu">{{ old('values_text') }}</textarea>
            </label>
            <label>Dozwolone wartości (EN)
                <textarea name="values_text_en" placeholder="W tym samym układzie co wartości PL">{{ old('values_text_en') }}</textarea>
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
                <span>Nazwa (PL)</span>
                <span>Nazwa (EN)</span>
                <span>Slug</span>
                <span>Typ</span>
                <span>Kolejność</span>
                <span>Wartości (PL)</span>
                <span>Wartości (EN)</span>
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
                    <label>Nazwa (PL)
                        <input name="name" form="{{ $updateFormId }}" value="{{ $definition->name }}" required>
                    </label>
                    <label>Nazwa (EN)
                        <input name="name_en" form="{{ $updateFormId }}" value="{{ $definition->name_en }}" placeholder="Opcjonalnie">
                    </label>
                    <label>Slug
                        <input name="slug" form="{{ $updateFormId }}" value="{{ $definition->slug }}">
                    </label>
                    <label>Typ pola
                        <select name="input_type" form="{{ $updateFormId }}">
                            @foreach (['text' => 'Tekst', 'number' => 'Liczba', 'select' => 'Lista', 'multiselect' => 'Wiele wartości', 'boolean' => 'Tak/Nie'] as $type => $label)
                                <option value="{{ $type }}" @selected($definition->input_type === $type)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Kolejność
                        <input name="sort_order" form="{{ $updateFormId }}" type="number" min="0" max="65000" value="{{ $definition->sort_order }}">
                    </label>
                    <label>Dozwolone wartości (PL) @if ($definition->is_variant) — kolejność w sklepie @endif
                        <textarea name="values_text" form="{{ $updateFormId }}">{{ implode("\n", (array) $definition->values) }}</textarea>
                    </label>
                    <label>Dozwolone wartości (EN)
                        <textarea name="values_text_en" form="{{ $updateFormId }}" placeholder="Kolejność jak w PL">{{ implode("\n", (array) $definition->values_en) }}</textarea>
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
        <div class="table-scroll discovered-table">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Nazwa (PL)</th>
                        <th>Nazwa (EN)</th>
                        <th>Typ</th>
                        <th>Wartości (PL)</th>
                        <th>Wartości (EN)</th>
                        <th>Użycie</th>
                        <th>Flagi</th>
                        <th>Akcja</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($discoveredParameters as $index => $parameter)
                        @php
                            $isKnown = in_array(mb_strtolower($parameter['name']), $knownParameterNames, true);
                        @endphp
                        <tr>
                            <td><input name="name" form="discovered-parameter-{{ $index }}" value="{{ $parameter['name'] }}" @disabled($isKnown)></td>
                            <td><input name="name_en" form="discovered-parameter-{{ $index }}" placeholder="Opcjonalnie" @disabled($isKnown)></td>
                            <td>
                                <select name="input_type" form="discovered-parameter-{{ $index }}" @disabled($isKnown)>
                                    @foreach (['text' => 'Tekst', 'number' => 'Liczba', 'select' => 'Lista', 'multiselect' => 'Wiele wartości', 'boolean' => 'Tak/Nie'] as $type => $label)
                                        <option value="{{ $type }}" @selected($type === 'select')>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><textarea name="values_text" form="discovered-parameter-{{ $index }}" @disabled($isKnown)>{{ implode("\n", $parameter['values']) }}</textarea></td>
                            <td><textarea name="values_text_en" form="discovered-parameter-{{ $index }}" placeholder="Kolejność jak w PL" @disabled($isKnown)></textarea></td>
                            <td><span class="status">{{ $parameter['usage'] }}</span></td>
                            <td>
                                <label class="toggle-row">
                                    <input name="is_variant" form="discovered-parameter-{{ $index }}" type="checkbox" value="1" @disabled($isKnown)>
                                    Wariant
                                </label>
                            </td>
                            <td>
                                @if ($isKnown)
                                    <span class="status">Już w słowniku</span>
                                @else
                                    <form id="discovered-parameter-{{ $index }}" method="POST" action="{{ route('products.parameters.store') }}">
                                        @csrf
                                        <input type="hidden" name="sort_order" value="100">
                                    </form>
                                    <button class="button secondary" type="submit" form="discovered-parameter-{{ $index }}">Dodaj do słownika</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">Nie wykryto jeszcze parametrów w produktach.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
