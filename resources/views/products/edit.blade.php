@extends('layouts.app', [
    'title' => $title,
    'subtitle' => $subtitle,
    'module' => 'products',
])

@push('styles')
    <style>
        .product-edit-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .product-edit-nav button { border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; color: var(--text); background: var(--surface); font: inherit; font-weight: 760; cursor: pointer; }
        .product-edit-nav button.active { color: var(--green-dark); background: var(--green-soft); border-color: rgba(134, 115, 100, .34); }
        .product-edit-card { margin-bottom: 16px; }
        .product-edit-step[hidden] { display: none; }
        .product-edit-body { padding: 16px; display: grid; gap: 16px; }
        .product-form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .product-form-grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .product-form-grid.five { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        .product-form-grid .wide { grid-column: 1 / -1; }
        .toggle-row { display: flex; gap: 8px; align-items: center; color: var(--text); font-weight: 760; }
        .repeat-table input { min-width: 180px; }
        textarea.product-html { min-height: 190px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size: 12px; line-height: 1.45; }
        .media-upload-panel { border: 1px dashed rgba(134, 115, 100, .34); border-radius: 8px; padding: 16px; background: #fffdfb; display: grid; gap: 12px; }
        .media-edit-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; }
        .media-edit-item { border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: #fffdfb; display: grid; gap: 8px; }
        .media-edit-item img { width: 100%; aspect-ratio: 4 / 5; object-fit: cover; border-radius: 7px; background: #f4f1ef; border: 1px solid var(--border); }
        .product-step-actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 14px; }
        .product-step-actions .inline-actions { justify-content: flex-end; }
        .button[disabled] { opacity: .55; cursor: not-allowed; }
        @media (max-width: 980px) {
            .product-edit-nav { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 6px; scrollbar-width: thin; }
            .product-edit-nav button { flex: 0 0 auto; min-height: 44px; }
            .product-form-grid, .product-form-grid.two, .product-form-grid.five { grid-template-columns: 1fr; }
            .product-step-actions { position: sticky; z-index: 20; bottom: 0; margin: 14px -10px -10px; padding: 10px; background: rgba(255, 253, 251, .96); border-top: 1px solid var(--border); box-shadow: 0 -8px 22px rgba(32, 25, 20, .08); }
            .product-step-actions .button { min-height: 46px; }
            .product-edit-body { padding: 12px; }
            .product-form-grid input, .product-form-grid select, .product-form-grid textarea { min-height: 44px; font-size: 16px; }
        }
    </style>
@endpush

@section('content')
    @php
        $master = $product->masterData();
        $field = fn (string $name, mixed $default = null): mixed => old($name, $default) ?? '';
        $masterField = fn (string $name, string $path, mixed $default = null): mixed => old($name, data_get($master, $path, $default)) ?? '';
        $tags = old('tags', implode(', ', (array) data_get($master, 'tags', [])));
        $selectedCategoryIds = collect(old('category_ids', data_get($master, 'category_ids', [])))->map(fn ($id) => (int) $id)->all();

        $parameterRows = collect(data_get($master, 'parameters', []))
            ->map(fn ($row): array => [
                'name' => is_array($row) ? (string) ($row['name'] ?? '') : '',
                'value' => is_array($row) ? (string) ($row['value'] ?? '') : '',
                'variation' => is_array($row) ? (bool) ($row['variation'] ?? false) : false,
            ])
            ->values()
            ->all();

        if (old('parameters')) {
            $names = (array) old('parameters.name', []);
            $values = (array) old('parameters.value', []);
            $variations = (array) old('parameters.variation', []);
            $parameterRows = collect($names)->map(fn ($name, $index): array => [
                'name' => (string) $name,
                'value' => (string) ($values[$index] ?? ''),
                'variation' => filter_var($variations[$index] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])->all();
        }

        while (count($parameterRows) < 6) {
            $parameterRows[] = ['name' => '', 'value' => '', 'variation' => false];
        }

        $mediaRows = collect(data_get($master, 'media', []))
            ->map(fn ($row): array => [
                'src' => is_array($row) ? (string) ($row['src'] ?? $row['url'] ?? '') : '',
                'alt' => is_array($row) ? (string) ($row['alt'] ?? '') : '',
                'name' => is_array($row) ? (string) ($row['name'] ?? '') : '',
            ])
            ->values()
            ->all();

        if (old('existing_media')) {
            $mediaRows = collect((array) old('existing_media'))
                ->filter(fn ($row) => is_array($row))
                ->map(fn (array $row): array => [
                    'src' => (string) ($row['src'] ?? ''),
                    'alt' => (string) ($row['alt'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                ])
                ->filter(fn (array $row): bool => $row['src'] !== '')
                ->values()
                ->all();
        }

        $relatedUpsells = old('related_upsell_skus', implode("\n", (array) data_get($master, 'related_products.upsell_skus', [])));
        $relatedCrossSells = old('related_cross_sell_skus', implode("\n", (array) data_get($master, 'related_products.cross_sell_skus', [])));
    @endphp

    @if ($errors->any())
        <div class="alert error">
            Nie zapisano produktu. Popraw pola formularza oznaczone przez walidację.
        </div>
    @endif

    <div class="page-toolbar">
        <div class="inline-actions">
            <a class="button secondary" href="{{ route('products.show', $product) }}">Wróć do szczegółów</a>
            <a class="button secondary" href="{{ route('products.index') }}">Lista produktów</a>
        </div>
        <div class="toolbar-note">Po zapisie ERP przejmuje produkt jako źródło prawdy i import WooCommerce nie nadpisuje tych pól.</div>
    </div>

    <nav class="product-edit-nav" aria-label="Sekcje edycji produktu">
        <button class="active" type="button" data-product-tab="produkt" aria-selected="true">Produkt</button>
        <button type="button" data-product-tab="sprzedaz" aria-selected="false">Sprzedaż i magazyn</button>
        <button type="button" data-product-tab="informacje" aria-selected="false">Informacje</button>
        <button type="button" data-product-tab="warianty" aria-selected="false">Warianty i relacje</button>
        <button type="button" data-product-tab="media" aria-selected="false">Media</button>
    </nav>

    <form method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        <input type="hidden" name="producer" value="SEMPRE">

        <section class="card product-edit-card product-edit-step" data-product-step="produkt">
            <div class="panel-header">Produkt</div>
            <div class="product-edit-body">
                <div class="product-form-grid two">
                    <label class="wide">Nazwa produktu (PL)
                        <input name="name" value="{{ $field('name', $product->name) }}" required>
                    </label>
                    <label>Katalog
                        <select name="catalog">
                            @foreach ($catalogOptions as $catalog)
                                <option value="{{ $catalog }}" @selected((string) $masterField('catalog', 'catalog', 'Domyślny') === $catalog)>{{ $catalog }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Kategorie produktu
                        <select name="category_ids[]" multiple size="6" aria-describedby="product-category-help">
                            @foreach ($categoryOptions as $category)
                                @if ($category['id'] ?? null)
                                    <option value="{{ $category['id'] }}" @selected(in_array((int) $category['id'], $selectedCategoryIds, true))>{{ $category['path'] }}{{ ($category['gs1_gpc_code'] ?? null) ? ' · GS1 '.$category['gs1_gpc_code'] : '' }}</option>
                                @endif
                            @endforeach
                        </select>
                        <small id="product-category-help">Wyszukaj kategorię z WooCommerce; możesz zaznaczyć kilka. Pierwsza z mapowaniem GS1 posłuży do automatycznego EAN.</small>
                    </label>
                    <label>Tagi
                        <input name="tags" value="{{ $tags }}" placeholder="tag 1, tag 2">
                    </label>
                    <div class="toolbar-note wide">Producent jest ustawiany automatycznie jako SEMPRE.</div>
                </div>

                <div class="product-form-grid">
                    <label>SKU
                        <input name="sku" value="{{ $field('sku', $product->displaySku()) }}" placeholder="Zostaw puste — ERP wygeneruje SKU">
                    </label>
                    <label>EAN
                        <input name="ean" value="{{ $field('ean', $product->ean) }}" placeholder="Zostaw puste — ERP pobierze EAN z GS1">
                    </label>
                    <label>ASIN
                        <input name="asin" value="{{ $masterField('asin', 'asin') }}">
                    </label>
                </div>

                <div class="product-form-grid">
                    <label>Waga (kg)
                        <input name="weight_kg" type="number" step="0.0001" min="0" value="{{ $field('weight_kg', $product->weight_kg) }}">
                    </label>
                    <label>Wysokość (cm)
                        <input name="height_cm" type="number" step="0.01" min="0" value="{{ $masterField('height_cm', 'dimensions.height_cm') }}">
                    </label>
                    <label>Szerokość (cm)
                        <input name="width_cm" type="number" step="0.01" min="0" value="{{ $masterField('width_cm', 'dimensions.width_cm') }}">
                    </label>
                    <label>Długość (cm)
                        <input name="length_cm" type="number" step="0.01" min="0" value="{{ $masterField('length_cm', 'dimensions.length_cm') }}">
                    </label>
                    <label>Jednostka
                        <input name="unit" value="{{ $field('unit', $product->unit) }}" required maxlength="16">
                    </label>
                    <label>Status
                        <input type="hidden" name="is_active" value="0">
                        <span class="toggle-row"><input name="is_active" type="checkbox" value="1" @checked(old('is_active', $product->is_active))> Aktywny</span>
                    </label>
                    <label>Status publikacji w sklepie
                        <select name="publication_status">
                            @foreach (['publish' => 'Opublikowany', 'draft' => 'Szkic', 'pending' => 'Oczekujący', 'private' => 'Prywatny'] as $status => $label)
                                <option value="{{ $status }}" @selected(old('publication_status', data_get($master, 'publication_status', 'publish')) === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Data publikacji w sklepie
                        <input name="publication_date" type="datetime-local" value="{{ $masterField('publication_date', 'publication_date') }}">
                    </label>
                    <label>Widoczność w WooCommerce
                        <select name="catalog_visibility">
                            @foreach (['visible' => 'Widoczny w katalogu i wyszukiwarce', 'catalog' => 'Widoczny tylko w katalogu', 'search' => 'Widoczny tylko w wyszukiwarce', 'hidden' => 'Ukryty w sklepie'] as $visibility => $label)
                                <option value="{{ $visibility }}" @selected(old('catalog_visibility', data_get($master, 'catalog_visibility', 'visible')) === $visibility)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Typ produktu
                        <select name="product_type">
                            @foreach (['simple' => 'Prosty', 'variable' => 'Wariantowy', 'variation' => 'Wariant'] as $type => $label)
                                <option value="{{ $type }}" @selected(old('product_type', data_get($master, 'product_type', 'simple')) === $type)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Atrybut wariantu
                        @include('products._variant_attribute_select', [
                            'parameterOptions' => $parameterOptions,
                            'value' => $masterField('variant_attribute', 'variant_attribute'),
                        ])
                    </label>
                    <label>Gotowe do publikacji
                        <input type="hidden" name="developed" value="0">
                        <span class="toggle-row"><input name="developed" type="checkbox" value="1" @checked(old('developed', (bool) data_get($master, 'developed'))) > Dane PIM kompletne</span>
                    </label>
                </div>
            </div>
        </section>

        <section class="card product-edit-card product-edit-step" data-product-step="sprzedaz" hidden>
            <div class="panel-header">Sprzedaż i magazyn</div>
            <div class="product-edit-body">
                <div class="product-form-grid">
                    <label>Cena hurt (PLN)
                        <input name="wholesale_price_pln" type="number" step="0.01" min="0" value="{{ $masterField('wholesale_price_pln', 'prices.wholesale_price_pln') }}">
                    </label>
                    <label>Cena detal brutto (PLN)
                        <input name="retail_price_pln" type="number" step="0.01" min="0" value="{{ $masterField('retail_price_pln', 'prices.retail_price_pln') }}">
                    </label>
                    <label>Cena promocyjna brutto (PLN)
                        <input name="sale_price_pln" type="number" step="0.01" min="0" value="{{ $masterField('sale_price_pln', 'prices.sale_price_pln') }}">
                    </label>
                    <label>Promocja od
                        <input name="sale_price_starts_at" type="date" value="{{ $masterField('sale_price_starts_at', 'prices.sale_price_starts_at') }}">
                    </label>
                    <label>Promocja do
                        <input name="sale_price_ends_at" type="date" value="{{ $masterField('sale_price_ends_at', 'prices.sale_price_ends_at') }}">
                    </label>
                </div>

                <div class="product-form-grid">
                    <label>VAT %
                        <select name="vat_rate" required>
                            @foreach ([23, 8, 5, 0] as $rate)
                                <option value="{{ $rate }}" @selected((float) old('vat_rate', $product->vat_rate) === (float) $rate)>{{ $rate }}%</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Lokalizacja
                        <input name="warehouse_location" value="{{ $masterField('warehouse_location', 'stock.location') }}" placeholder="np. A-01-03">
                    </label>
                    <label>Cena zakupu (średnia)
                        <input name="purchase_price_pln" type="number" step="0.01" min="0" value="{{ $masterField('purchase_price_pln', 'prices.purchase_price_pln') }}">
                    </label>
                    <label>Zarządzanie stanem
                        <input type="hidden" name="manage_stock" value="0">
                        <span class="toggle-row"><input name="manage_stock" type="checkbox" value="1" @checked(old('manage_stock', data_get($master, 'inventory.manage_stock', true)))> Włączone w WooCommerce</span>
                    </label>
                    <label>Zamówienia oczekujące
                        <select name="backorders">
                            @foreach (['no' => 'Nie zezwalaj', 'notify' => 'Zezwalaj i informuj', 'yes' => 'Zezwalaj'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('backorders', data_get($master, 'inventory.backorders', 'no')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Niski próg stanu
                        <input name="low_stock_amount" type="number" step="1" min="0" value="{{ $masterField('low_stock_amount', 'inventory.low_stock_amount') }}">
                    </label>
                    <label>Sprzedawany pojedynczo
                        <input type="hidden" name="sold_individually" value="0">
                        <span class="toggle-row"><input name="sold_individually" type="checkbox" value="1" @checked(old('sold_individually', data_get($master, 'inventory.sold_individually', false)))> Maks. 1 szt. w zamówieniu</span>
                    </label>
                </div>
                @include('products._stock_readonly_panel', ['stockProduct' => $product])
            </div>
        </section>

        <section class="card product-edit-card product-edit-step" data-product-step="informacje" hidden>
            <div class="panel-header">Informacje</div>
            <div class="product-edit-body">
                <div class="product-form-grid two">
                    <label>Nazwa produktu (EN)
                        <input name="name_en" value="{{ $masterField('name_en', 'content.en.name') }}">
                    </label>
                    <label>Custom label (PL)
                        <input name="custom_label_pl" value="{{ $masterField('custom_label_pl', 'custom_label.pl') }}" placeholder="np. Bestseller">
                    </label>
                    <label>Custom label (EN)
                        <input name="custom_label_en" value="{{ $masterField('custom_label_en', 'custom_label.en') }}" placeholder="np. Bestseller">
                    </label>
                    <label>Tło etykiety
                        <input name="custom_label_bg_color" type="color" value="{{ $masterField('custom_label_bg_color', 'custom_label.bg_color', '#111111') ?: '#111111' }}">
                    </label>
                    <label>Kolor tekstu etykiety
                        <input name="custom_label_text_color" type="color" value="{{ $masterField('custom_label_text_color', 'custom_label.text_color', '#ffffff') ?: '#ffffff' }}">
                    </label>
                </div>
                <div class="product-rich-field">
                    <div class="product-rich-label">Opis PL HTML</div>
                    <textarea class="product-html" name="description_pl" data-rich-product-editor>{{ $masterField('description_pl', 'content.pl.description') }}</textarea>
                </div>
                <div class="product-rich-field">
                    <div class="product-rich-label">Opis EN HTML</div>
                    <textarea class="product-html" name="description_en" data-rich-product-editor>{{ $masterField('description_en', 'content.en.description') }}</textarea>
                </div>
                <div class="product-rich-field">
                    <div class="product-rich-label">Krótki opis PL HTML</div>
                    <textarea class="product-html" name="short_description_pl" data-rich-product-editor>{{ $masterField('short_description_pl', 'content.pl.additional_description') }}</textarea>
                </div>
                <div class="product-rich-field">
                    <div class="product-rich-label">Krótki opis EN HTML</div>
                    <textarea class="product-html" name="short_description_en" data-rich-product-editor>{{ $masterField('short_description_en', 'content.en.additional_description') }}</textarea>
                </div>
                <div class="product-form-grid two">
                    <label>Produkty sprzedaży dodatkowej (SKU)
                        <textarea name="related_upsell_skus" placeholder="Jedno SKU w wierszu">{{ $relatedUpsells }}</textarea>
                    </label>
                    <label>Produkty sprzedaży krzyżowej (SKU)
                        <textarea name="related_cross_sell_skus" placeholder="Jedno SKU w wierszu">{{ $relatedCrossSells }}</textarea>
                    </label>
                </div>
                @include('products._relation_sku_pickers')

                <div class="table-scroll">
                    <table class="dense-table repeat-table">
                        <thead>
                            <tr>
                                <th>Nazwa parametru</th>
                                <th>Wartość</th>
                                <th>Wariant</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($parameterRows as $index => $row)
                                <tr>
                                    <td><input name="parameters[name][]" value="{{ $row['name'] }}" list="product-parameter-name-options" placeholder="np. Rozmiar"></td>
                                    <td><input name="parameters[value][]" value="{{ $row['value'] }}" list="product-parameter-value-options" placeholder="np. One size"></td>
                                    <td>
                                        <input type="hidden" name="parameters[variation][{{ $index }}]" value="0">
                                        <label class="toggle-row"><input name="parameters[variation][{{ $index }}]" type="checkbox" value="1" @checked($row['variation'])> Tak</label>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="card product-edit-card product-edit-step" data-product-step="warianty" hidden>
            <div class="panel-header">Warianty i relacje</div>
            <div class="product-edit-body">
                @include('products._variant_relation_editor', [
                    'product' => $product,
                    'productLookupOptions' => $productLookupOptions,
                ])
            </div>
        </section>

        <section class="card product-edit-card product-edit-step" data-product-step="media" hidden>
            <div class="panel-header">Media</div>
            <div class="product-edit-body">
                <div class="toolbar-note">Pierwsze zachowane lub dodane zdjęcie jest traktowane jako miniatura główna produktu w ERP. Pliki trafiają na serwer aplikacji.</div>

                @if ($mediaRows !== [])
                    <div class="media-edit-grid">
                        @foreach ($mediaRows as $index => $row)
                            <div class="media-edit-item">
                                <img src="{{ $row['src'] }}" alt="{{ $row['alt'] ?: $product->name }}" loading="lazy" referrerpolicy="no-referrer">
                                <input type="hidden" name="existing_media[{{ $index }}][src]" value="{{ $row['src'] }}">
                                <input type="hidden" name="existing_media[{{ $index }}][name]" value="{{ $row['name'] ?? '' }}">
                                <label>Alt
                                    <input name="existing_media[{{ $index }}][alt]" value="{{ $row['alt'] }}">
                                </label>
                                <label class="toggle-row">
                                    <input name="existing_media[{{ $index }}][remove]" type="checkbox" value="1">
                                    Usuń przy zapisie
                                </label>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="toolbar-note">Ten produkt nie ma jeszcze mediów zapisanych w ERP.</div>
                @endif

                <div class="media-upload-panel">
                    <label>Dodaj zdjęcia z komputera
                        <input name="new_media[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                    </label>
                    <label>Alt dla nowych zdjęć
                        <input name="new_media_alt" value="{{ old('new_media_alt') }}" placeholder="{{ $product->name }}">
                    </label>
                    <div class="toolbar-note">Obsługiwane formaty: JPG, PNG, WebP, GIF. Maksymalnie 8 MB na plik.</div>
                </div>
            </div>
        </section>

        <div class="product-step-actions">
            <button class="button secondary" type="button" data-step-prev disabled>Wstecz</button>
            <div class="inline-actions">
                <button class="button secondary" type="button" data-step-next>Dalej</button>
                <button class="button" type="submit">Zapisz dane główne produktu</button>
            </div>
        </div>
    </form>

    @include('products._parameter_datalists', ['parameterOptions' => $parameterOptions])
    @include('products._product_lookup_datalist', ['productLookupOptions' => $productLookupOptions])
    @include('products._rich_editor_assets')
@endsection

@push('scripts')
    <script>
        const productTabs = Array.from(document.querySelectorAll('[data-product-tab]'));
        const productSteps = Array.from(document.querySelectorAll('[data-product-step]'));
        const previousStepButton = document.querySelector('[data-step-prev]');
        const nextStepButton = document.querySelector('[data-step-next]');
        let activeStepIndex = 0;

        function showProductStep(index) {
            activeStepIndex = Math.max(0, Math.min(index, productSteps.length - 1));

            productSteps.forEach((step, stepIndex) => {
                step.hidden = stepIndex !== activeStepIndex;
            });

            productTabs.forEach((tab, tabIndex) => {
                const active = tabIndex === activeStepIndex;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            if (previousStepButton) {
                previousStepButton.disabled = activeStepIndex === 0;
            }

            if (nextStepButton) {
                nextStepButton.hidden = activeStepIndex === productSteps.length - 1;
            }
        }

        productTabs.forEach((tab, index) => {
            tab.addEventListener('click', () => showProductStep(index));
        });

        previousStepButton?.addEventListener('click', () => showProductStep(activeStepIndex - 1));
        nextStepButton?.addEventListener('click', () => showProductStep(activeStepIndex + 1));
        showProductStep(0);

    </script>
@endpush
