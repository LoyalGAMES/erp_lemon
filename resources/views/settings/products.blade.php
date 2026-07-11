@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@section('content')
    <div class="page-toolbar">
        <a class="button secondary" href="{{ route('settings.index') }}">Wróć do ustawień</a>
    </div>

    <article class="card product-field-settings">
        <div class="panel-header">
            <span>Widoczne pola w edycji produktu</span>
            <span>Ustawienie wspólne dla ERP</span>
        </div>
        <form method="POST" action="{{ route('settings.products.update') }}" class="product-field-settings-form">
            @csrf
            @method('PUT')

            @foreach (collect($productEditFieldDefinitions)->groupBy('section') as $section => $fields)
                <section class="product-field-settings-section">
                    <h2>{{ $section }}</h2>
                    <div class="product-field-settings-grid">
                        @foreach ($fields as $field)
                            <label class="toggle-row product-field-setting">
                                <input
                                    name="visible_fields[]"
                                    type="checkbox"
                                    value="{{ $field['key'] }}"
                                    @checked(old('visible_fields') === null ? ($visibleProductEditFields[$field['key']] ?? true) : in_array($field['key'], (array) old('visible_fields'), true))
                                >
                                {{ $field['label'] }}
                            </label>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <button class="button" type="submit">Zapisz widoczność pól</button>
        </form>
    </article>
@endsection

@push('styles')
    <style>
        .product-field-settings-form { display: grid; gap: 18px; padding: 16px; }
        .product-field-settings-section { display: grid; gap: 10px; }
        .product-field-settings-section h2 { margin: 0; font-size: 16px; }
        .product-field-settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(235px, 1fr)); gap: 8px; }
        .product-field-setting { min-height: 42px; padding: 9px 10px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; }
    </style>
@endpush
