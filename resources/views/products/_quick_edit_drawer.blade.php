@php
    $quickMaster = $product->masterData();
    $quickField = fn (string $name, mixed $default = null): mixed => old($name, $default) ?? '';
    $quickMasterField = fn (string $name, string $path, mixed $default = null): mixed => old($name, data_get($quickMaster, $path, $default)) ?? '';
    $quickTags = old('tags', implode(', ', (array) data_get($quickMaster, 'tags', [])));
    $quickInitialStep = old('quick_edit_step', 'produkt');
    $quickSelectedCategoryIds = collect(old('category_ids', data_get($quickMaster, 'category_ids', [])))->map(fn ($id) => (int) $id)->all();

    $quickParameterRows = collect(data_get($quickMaster, 'parameters', []))
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
        $quickParameterRows = collect($names)->map(fn ($name, $index): array => [
            'name' => (string) $name,
            'value' => (string) ($values[$index] ?? ''),
            'variation' => filter_var($variations[$index] ?? false, FILTER_VALIDATE_BOOLEAN),
        ])->all();
    }

    while (count($quickParameterRows) < 6) {
        $quickParameterRows[] = ['name' => '', 'value' => '', 'variation' => false];
    }

    $quickMediaRows = collect(data_get($quickMaster, 'media', []))
        ->map(fn ($row): array => [
            'src' => is_array($row) ? (string) ($row['src'] ?? $row['url'] ?? '') : '',
            'alt' => is_array($row) ? (string) ($row['alt'] ?? '') : '',
            'name' => is_array($row) ? (string) ($row['name'] ?? '') : '',
        ])
        ->values()
        ->all();

    if (old('existing_media')) {
        $quickMediaRows = collect((array) old('existing_media'))
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

    $quickRelatedUpsells = old('related_upsell_skus', implode("\n", (array) data_get($quickMaster, 'related_products.upsell_skus', [])));
    $quickRelatedCrossSells = old('related_cross_sell_skus', implode("\n", (array) data_get($quickMaster, 'related_products.cross_sell_skus', [])));
@endphp

<input
    id="product-quick-edit-drawer"
    class="drawer-toggle"
    type="checkbox"
    @checked($errors->any() && old('sku') !== null)
    data-product-quick-edit-toggle
>
<label class="drawer-backdrop" for="product-quick-edit-drawer" aria-label="Zamknij szybką edycję"></label>
<aside class="drawer-panel product-quick-edit-drawer" aria-label="Szybka edycja produktu" data-product-quick-edit-drawer>
    <div class="drawer-header">
        <div>
            <div class="drawer-title">Szybka edycja produktu</div>
            <div class="toolbar-note">{{ $product->sku }} | {{ $product->name }}</div>
        </div>
        <label class="drawer-close" for="product-quick-edit-drawer" aria-label="Zamknij">&times;</label>
    </div>

    <form class="product-quick-edit-form" method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data" data-product-quick-edit-form>
        @csrf
        @method('PUT')
        <input type="hidden" name="producer" value="SEMPRE">
        <input type="hidden" name="quick_edit_step" value="{{ $quickInitialStep }}" data-product-quick-edit-step-input>

        <nav class="product-quick-edit-nav" aria-label="Sekcje szybkiej edycji produktu">
            <button class="active" type="button" data-product-quick-edit-tab="produkt" aria-selected="true">Produkt</button>
            <button type="button" data-product-quick-edit-tab="sprzedaz" aria-selected="false">Sprzedaż i magazyn</button>
            <button type="button" data-product-quick-edit-tab="informacje" aria-selected="false">Informacje</button>
            <button type="button" data-product-quick-edit-tab="warianty" aria-selected="false">Warianty i relacje</button>
            <button type="button" data-product-quick-edit-tab="media" aria-selected="false">Media</button>
        </nav>

        <section class="product-quick-edit-step" data-product-quick-edit-step="produkt">
            <div class="product-quick-edit-body">
                <div class="product-quick-form-grid two">
                    <label class="wide">Nazwa produktu (PL)
                        <input name="name" value="{{ $quickField('name', $product->name) }}" required>
                    </label>
                    <label>Katalog
                        <select name="catalog">
                            @foreach ($catalogOptions as $catalog)
                                <option value="{{ $catalog }}" @selected((string) $quickMasterField('catalog', 'catalog', 'Domyślny') === $catalog)>{{ $catalog }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Kategorie produktu
                        @include('products._category_checkbox_list', [
                            'categoryOptions' => $categoryOptions,
                            'selectedCategoryIds' => $quickSelectedCategoryIds,
                        ])
                    </label>
                    <label>Tagi
                        <input name="tags" value="{{ $quickTags }}" placeholder="tag 1, tag 2">
                    </label>
                    <div class="toolbar-note wide">Producent jest ustawiany automatycznie jako SEMPRE.</div>
                </div>

                <div class="product-quick-form-grid">
                    <label>SKU
                        <input name="sku" value="{{ $quickField('sku', $product->displaySku()) }}" placeholder="Automatycznie po zapisie">
                    </label>
                    <label>EAN
                        <input name="ean" value="{{ $quickField('ean', $product->ean) }}">
                    </label>
                    <label>ASIN
                        <input name="asin" value="{{ $quickMasterField('asin', 'asin') }}">
                    </label>
                </div>

                <div class="product-quick-form-grid">
                    <label>Waga (kg)
                        <input name="weight_kg" type="number" step="0.0001" min="0" value="{{ $quickField('weight_kg', $product->weight_kg) }}">
                    </label>
                    <label>Wysokość (cm)
                        <input name="height_cm" type="number" step="0.01" min="0" value="{{ $quickMasterField('height_cm', 'dimensions.height_cm') }}">
                    </label>
                    <label>Szerokość (cm)
                        <input name="width_cm" type="number" step="0.01" min="0" value="{{ $quickMasterField('width_cm', 'dimensions.width_cm') }}">
                    </label>
                    <label>Długość (cm)
                        <input name="length_cm" type="number" step="0.01" min="0" value="{{ $quickMasterField('length_cm', 'dimensions.length_cm') }}">
                    </label>
                    <label>Jednostka
                        <input name="unit" value="{{ $quickField('unit', $product->unit) }}" required maxlength="16">
                    </label>
                    <label>Status
                        <input type="hidden" name="is_active" value="0">
                        <span class="product-quick-toggle-row"><input name="is_active" type="checkbox" value="1" @checked(old('is_active', $product->is_active))> Aktywny</span>
                    </label>
                    <label>Status publikacji w sklepie
                        <select name="publication_status">
                            @foreach (['publish' => 'Opublikowany', 'draft' => 'Szkic', 'pending' => 'Oczekujący', 'private' => 'Prywatny'] as $status => $label)
                                <option value="{{ $status }}" @selected(old('publication_status', data_get($quickMaster, 'publication_status', 'publish')) === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Data publikacji w sklepie
                        <input name="publication_date" type="datetime-local" value="{{ $quickMasterField('publication_date', 'publication_date') }}">
                    </label>
                    <label>Widoczność w WooCommerce
                        <select name="catalog_visibility">
                            @foreach (['visible' => 'Widoczny w katalogu i wyszukiwarce', 'catalog' => 'Widoczny tylko w katalogu', 'search' => 'Widoczny tylko w wyszukiwarce', 'hidden' => 'Ukryty w sklepie'] as $visibility => $label)
                                <option value="{{ $visibility }}" @selected(old('catalog_visibility', data_get($quickMaster, 'catalog_visibility', 'visible')) === $visibility)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Typ produktu
                        <select name="product_type">
                            @foreach (['simple' => 'Prosty', 'variable' => 'Wariantowy', 'variation' => 'Wariant'] as $type => $label)
                                <option value="{{ $type }}" @selected(old('product_type', data_get($quickMaster, 'product_type', 'simple')) === $type)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Atrybut wariantu
                        @include('products._variant_attribute_select', [
                            'parameterOptions' => $parameterOptions,
                            'value' => $quickMasterField('variant_attribute', 'variant_attribute'),
                        ])
                    </label>
                    <label>Kompletność danych PIM
                        <input type="hidden" name="developed" value="0">
                        <span class="product-quick-toggle-row"><input name="developed" type="checkbox" value="1" @checked(old('developed', (bool) data_get($quickMaster, 'developed'))) > Dane gotowe do publikacji</span>
                        <small class="pim-ready-help">Flaga potwierdza komplet danych PIM; nie publikuje produktu samodzielnie.</small>
                    </label>
                </div>
            </div>
        </section>

        <section class="product-quick-edit-step" data-product-quick-edit-step="sprzedaz" hidden>
            <div class="product-quick-edit-body">
                <div class="product-quick-form-grid">
                    <label>Cena hurt (PLN)
                        <input name="wholesale_price_pln" type="number" step="0.01" min="0" value="{{ $quickMasterField('wholesale_price_pln', 'prices.wholesale_price_pln') }}">
                    </label>
                    <label>Cena detal brutto (PLN)
                        <input name="retail_price_pln" type="number" step="0.01" min="0" value="{{ $quickMasterField('retail_price_pln', 'prices.retail_price_pln') }}">
                    </label>
                    <label>Cena promocyjna brutto (PLN)
                        <input name="sale_price_pln" type="number" step="0.01" min="0" value="{{ $quickMasterField('sale_price_pln', 'prices.sale_price_pln') }}">
                    </label>
                    <label>Promocja od
                        <input name="sale_price_starts_at" type="date" value="{{ $quickMasterField('sale_price_starts_at', 'prices.sale_price_starts_at') }}">
                    </label>
                    <label>Promocja do
                        <input name="sale_price_ends_at" type="date" value="{{ $quickMasterField('sale_price_ends_at', 'prices.sale_price_ends_at') }}">
                    </label>
                    <label>VAT %
                        <select name="vat_rate" required>
                            @foreach ([23, 8, 5, 0] as $rate)
                                <option value="{{ $rate }}" @selected((float) old('vat_rate', $product->vat_rate) === (float) $rate)>{{ $rate }}%</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Lokalizacja
                        <input name="warehouse_location" value="{{ $quickMasterField('warehouse_location', 'stock.location') }}" placeholder="np. A-01-03">
                    </label>
                    <label>Cena zakupu (średnia)
                        <input name="purchase_price_pln" type="number" step="0.01" min="0" value="{{ $quickMasterField('purchase_price_pln', 'prices.purchase_price_pln') }}">
                    </label>
                    <label>Koszt dodatkowy (PLN)
                        <input name="extra_cost_pln" type="number" step="0.01" min="0" value="{{ $quickMasterField('extra_cost_pln', 'prices.extra_cost_pln') }}">
                    </label>
                    <label>Zarządzanie stanem
                        <input type="hidden" name="manage_stock" value="0">
                        <span class="product-quick-toggle-row"><input name="manage_stock" type="checkbox" value="1" @checked(old('manage_stock', data_get($quickMaster, 'inventory.manage_stock', true)))> Włączone</span>
                    </label>
                    <label>Zamówienia oczekujące
                        <select name="backorders">
                            @foreach (['no' => 'Nie zezwalaj', 'notify' => 'Zezwalaj i informuj', 'yes' => 'Zezwalaj'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('backorders', data_get($quickMaster, 'inventory.backorders', 'no')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Niski próg stanu
                        <input name="low_stock_amount" type="number" step="1" min="0" value="{{ $quickMasterField('low_stock_amount', 'inventory.low_stock_amount') }}">
                    </label>
                    <label>Sprzedawany pojedynczo
                        <input type="hidden" name="sold_individually" value="0">
                        <span class="product-quick-toggle-row"><input name="sold_individually" type="checkbox" value="1" @checked(old('sold_individually', data_get($quickMaster, 'inventory.sold_individually', false)))> Maks. 1 szt.</span>
                    </label>
                </div>
                @include('products._supplier_fields', ['supplierMaster' => $quickMaster])
                @include('products._stock_management_panels', ['stockOwner' => $product])
            </div>
        </section>

        <section class="product-quick-edit-step" data-product-quick-edit-step="informacje" hidden>
            <div class="product-quick-edit-body">
                <div class="product-quick-form-grid two">
                    <label>Nazwa produktu (EN)
                        <input name="name_en" value="{{ $quickMasterField('name_en', 'content.en.name') }}">
                    </label>
                    <label>Custom label (PL)
                        <input name="custom_label_pl" value="{{ $quickMasterField('custom_label_pl', 'custom_label.pl') }}">
                    </label>
                    <label>Custom label (EN)
                        <input name="custom_label_en" value="{{ $quickMasterField('custom_label_en', 'custom_label.en') }}">
                    </label>
                    <label>Tło etykiety
                        <input name="custom_label_bg_color" type="color" value="{{ $quickMasterField('custom_label_bg_color', 'custom_label.bg_color', '#111111') ?: '#111111' }}">
                    </label>
                    <label>Tekst etykiety
                        <input name="custom_label_text_color" type="color" value="{{ $quickMasterField('custom_label_text_color', 'custom_label.text_color', '#ffffff') ?: '#ffffff' }}">
                    </label>
                    <label>Dni kalendarzowe do wysyłki
                        <input name="lemon_shipping_days" type="number" step="1" min="0" value="{{ $quickMasterField('lemon_shipping_days', 'shipping.days') }}" placeholder="np. 11">
                    </label>
                    <label>Tekst terminu wysyłki (PL)
                        <input name="lemon_shipping_text" value="{{ $quickMasterField('lemon_shipping_text', 'shipping.text') }}" placeholder="Planowana wysyłka: {date}">
                        <small>Znaczniki: <code>{date}</code>, <code>{days}</code>.</small>
                    </label>
                    <label>Tekst terminu wysyłki (EN)
                        <input name="lemon_shipping_text_en" value="{{ $quickMasterField('lemon_shipping_text_en', 'shipping.text_en') }}" placeholder="Planned shipping: {date}">
                        <small>Znaczniki: <code>{date}</code>, <code>{days}</code>.</small>
                    </label>
                    <label>Przedsprzedaż
                        <input type="hidden" name="lemon_preorder" value="0">
                        <span class="product-quick-toggle-row"><input name="lemon_preorder" type="checkbox" value="1" @checked(old('lemon_preorder', data_get($quickMaster, 'shipping.preorder', false)))> Włączona</span>
                    </label>
                </div>
                <div class="product-rich-field">
                    <div class="product-rich-label">Opis PL HTML</div>
                    <textarea class="product-html" name="description_pl" data-rich-product-editor>{{ $quickMasterField('description_pl', 'content.pl.description') }}</textarea>
                </div>
                <div class="product-rich-field">
                    <div class="product-rich-label">Opis EN HTML</div>
                    <textarea class="product-html" name="description_en" data-rich-product-editor>{{ $quickMasterField('description_en', 'content.en.description') }}</textarea>
                </div>
                <div class="product-rich-field">
                    <div class="product-rich-label">Krótki opis PL HTML</div>
                    <textarea class="product-html" name="short_description_pl" data-rich-product-editor>{{ $quickMasterField('short_description_pl', 'content.pl.additional_description') }}</textarea>
                </div>
                <div class="product-rich-field">
                    <div class="product-rich-label">Krótki opis EN HTML</div>
                    <textarea class="product-html" name="short_description_en" data-rich-product-editor>{{ $quickMasterField('short_description_en', 'content.en.additional_description') }}</textarea>
                </div>
                <div class="product-quick-form-grid two">
                    <label>Produkty sprzedaży dodatkowej (SKU)
                        <textarea name="related_upsell_skus" placeholder="Jedno SKU w wierszu">{{ $quickRelatedUpsells }}</textarea>
                    </label>
                    <label>Produkty sprzedaży krzyżowej (SKU)
                        <textarea name="related_cross_sell_skus" placeholder="Jedno SKU w wierszu">{{ $quickRelatedCrossSells }}</textarea>
                    </label>
                </div>
                @include('products._relation_sku_pickers')

                <div class="table-scroll product-quick-repeat-table">
                    <table class="dense-table">
                        <thead>
                            <tr>
                                <th>Nazwa parametru</th>
                                <th>Wartość</th>
                                <th>Wariant</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($quickParameterRows as $index => $row)
                                <tr>
                                    <td><input name="parameters[name][]" value="{{ $row['name'] }}" list="product-parameter-name-options" placeholder="np. Rozmiar"></td>
                                    <td><input name="parameters[value][]" value="{{ $row['value'] }}" list="product-parameter-value-options" placeholder="np. One size"></td>
                                    <td>
                                        <input type="hidden" name="parameters[variation][{{ $index }}]" value="0">
                                        <label class="product-quick-toggle-row"><input name="parameters[variation][{{ $index }}]" type="checkbox" value="1" @checked($row['variation'])> Tak</label>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="product-quick-edit-step" data-product-quick-edit-step="warianty" hidden>
            <div class="product-quick-edit-body">
                @include('products._new_variant_values_fields', [
                    'variantProduct' => $product,
                    'selectedVariantAttribute' => data_get($quickMaster, 'variant_attribute'),
                ])
                @include('products._variant_relation_editor', [
                    'product' => $product,
                    'productLookupOptions' => $productLookupOptions,
                ])
            </div>
        </section>

        <section class="product-quick-edit-step" data-product-quick-edit-step="media" hidden>
            <div class="product-quick-edit-body">
                <div class="toolbar-note">Pierwsze zachowane lub dodane zdjęcie jest traktowane jako miniatura główna produktu w ERP. Pliki trafiają na serwer aplikacji.</div>

                @if ($quickMediaRows !== [])
                    <div class="product-quick-media-grid">
                        @foreach ($quickMediaRows as $index => $row)
                            <div class="product-quick-media-item">
                                <img src="{{ $row['src'] }}" alt="{{ $row['alt'] ?: $product->name }}" loading="lazy" referrerpolicy="no-referrer">
                                <input type="hidden" name="existing_media[{{ $index }}][src]" value="{{ $row['src'] }}">
                                <input type="hidden" name="existing_media[{{ $index }}][name]" value="{{ $row['name'] ?? '' }}">
                                <label>Alt
                                    <input name="existing_media[{{ $index }}][alt]" value="{{ $row['alt'] }}">
                                </label>
                                <label class="product-quick-toggle-row">
                                    <input name="existing_media[{{ $index }}][remove]" type="checkbox" value="1">
                                    Usuń przy zapisie
                                </label>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="toolbar-note">Ten produkt nie ma jeszcze mediów zapisanych w ERP.</div>
                @endif

                <div class="product-quick-media-upload">
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

        <div class="product-quick-step-actions">
            <button class="button secondary" type="button" data-product-quick-edit-prev disabled>Wstecz</button>
            <div class="inline-actions">
                <button class="button secondary" type="button" data-product-quick-edit-next>Dalej</button>
                <button class="button" type="submit">Zapisz zmiany</button>
            </div>
        </div>
    </form>
</aside>

@include('products._parameter_datalists', ['parameterOptions' => $parameterOptions])
@include('products._product_lookup_datalist', ['productLookupOptions' => $productLookupOptions])
@include('products._rich_editor_assets')
