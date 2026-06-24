@extends('layouts.app', [
    'title' => 'Produkty',
    'subtitle' => 'Katalog głównych produktów ERP z wariantami, ceną PLN, stanami i szybkim przejściem do szczegółów.',
    'module' => 'products',
])

@push('styles')
    <style>
        .products-table table { min-width: 1180px; }
        .product-filter-panel { margin-bottom: 14px; padding: 14px; display: grid; gap: 12px; }
        .product-filters { display: grid; grid-template-columns: minmax(260px, 2fr) repeat(6, minmax(130px, 1fr)) auto auto; gap: 10px; align-items: end; }
        .product-filters label { min-width: 0; }
        .product-filters .button { min-height: 40px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; white-space: nowrap; }
        .filter-result-line { color: var(--muted); font-size: 12px; }
        .product-cell { display: grid; grid-template-columns: 62px minmax(260px, 1fr); gap: 12px; align-items: center; white-space: normal; }
        .product-cell.variant { padding-left: 42px; grid-template-columns: 48px minmax(220px, 1fr); }
        .product-thumb-button { width: 58px; height: 72px; border: 1px solid var(--border); border-radius: 8px; padding: 0; overflow: hidden; background: #f4f1ef; cursor: pointer; display: grid; place-items: center; color: var(--muted); font-weight: 850; font-size: 11px; }
        .product-cell.variant .product-thumb-button { width: 44px; height: 56px; }
        .product-thumb-button img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .product-thumb-button:disabled { cursor: default; }
        .product-title { color: var(--text); text-decoration: none; font-size: 15px; font-weight: 850; }
        .product-title:hover { color: var(--green-dark); }
        .product-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 5px; color: var(--muted); font-size: 12px; }
        .variant-toggle { margin-top: 8px; border: 1px solid rgba(134, 115, 100, .28); border-radius: 999px; padding: 5px 9px; background: var(--green-soft); color: var(--green-dark); font: inherit; font-size: 12px; font-weight: 850; cursor: pointer; }
        .variant-toggle:hover { border-color: rgba(134, 115, 100, .45); }
        .variant-row { background: rgba(134, 115, 100, .06); }
        .variant-row[hidden] { display: none; }
        .price-cell { display: grid; gap: 5px; min-width: 120px; }
        .price-cell strong { font-size: 15px; }
        .price-cell span { color: var(--muted); font-size: 12px; }
        .stock-summary { display: grid; gap: 7px; min-width: 290px; }
        .stock-pills { display: flex; gap: 7px; flex-wrap: wrap; }
        .stock-pill { border: 1px solid var(--border); border-radius: 8px; padding: 5px 8px; background: #fffdfb; color: var(--muted); font-size: 12px; }
        .stock-pill strong { color: var(--text); font-size: 14px; margin-left: 4px; }
        .stock-pill.available strong { color: var(--green-dark); }
        .warehouse-details summary { cursor: pointer; color: var(--green-dark); font-size: 12px; font-weight: 760; }
        .warehouse-tile-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); gap: 7px; margin-top: 8px; }
        .warehouse-tile { border: 1px solid var(--border); border-radius: 8px; padding: 8px; background: #fff; display: grid; gap: 4px; color: var(--muted); font-size: 12px; }
        .warehouse-tile strong { color: var(--text); font-size: 13px; }
        .warehouse-tile b { color: var(--green-dark); }
        .channel-badges { display: flex; gap: 6px; flex-wrap: wrap; max-width: 220px; }
        .products-table .inline-actions { align-items: stretch; }
        .products-table .inline-actions form { display: flex; }
        .products-table .inline-actions .button { min-height: 34px; min-width: 78px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; white-space: nowrap; }
        .pagination-wrapper { padding: 14px 16px; border-top: 1px solid var(--border); }
        .pagination-bar { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
        .pagination-pages { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .pagination-page { min-width: 36px; min-height: 36px; border: 1px solid var(--border); border-radius: 8px; padding: 7px 10px; display: inline-flex; align-items: center; justify-content: center; color: var(--text); background: var(--surface); text-decoration: none; font-weight: 760; }
        .pagination-page.active { color: var(--green-dark); background: var(--green-soft); border-color: rgba(134, 115, 100, .34); }
        .pagination-page.disabled { opacity: .45; pointer-events: none; }
        .pagination-summary { color: var(--muted); font-size: 12px; }
        .image-modal { position: fixed; inset: 0; z-index: 90; display: none; align-items: center; justify-content: center; padding: 24px; background: rgba(37, 31, 26, .72); }
        .image-modal.open { display: flex; }
        .image-modal-card { max-width: min(760px, 94vw); max-height: 92vh; background: var(--surface); border-radius: 8px; overflow: hidden; box-shadow: 0 24px 70px rgba(0, 0, 0, .32); }
        .image-modal-header { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 10px 12px; border-bottom: 1px solid var(--border); font-weight: 780; }
        .image-modal-close { border: 0; background: transparent; font: inherit; font-size: 22px; cursor: pointer; color: var(--muted); }
        .image-modal img { display: block; width: 100%; max-height: 78vh; object-fit: contain; background: #f4f1ef; }
        .product-drawer { width: min(780px, 96vw); }
        .product-create-form { padding: 0; }
        .drawer-step-nav { display: flex; gap: 8px; flex-wrap: wrap; padding: 14px 16px 0; }
        .drawer-step-nav button { border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; color: var(--text); background: var(--surface); font: inherit; font-weight: 760; cursor: pointer; }
        .drawer-step-nav button.active { color: var(--green-dark); background: var(--green-soft); border-color: rgba(134, 115, 100, .34); }
        .drawer-step[hidden] { display: none; }
        .drawer-step { padding: 16px; display: grid; gap: 16px; }
        .drawer-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .drawer-form-grid.three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .drawer-form-grid .wide { grid-column: 1 / -1; }
        .toggle-row { display: flex; gap: 8px; align-items: center; color: var(--text); font-weight: 760; }
        .drawer-table input { min-width: 150px; }
        .drawer-step-actions { padding: 0 16px 16px; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .drawer-step-actions .inline-actions { justify-content: flex-end; }
        .media-upload-panel { border: 1px dashed rgba(134, 115, 100, .34); border-radius: 8px; padding: 16px; background: #fffdfb; display: grid; gap: 12px; }
        textarea.product-html { min-height: 150px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size: 12px; line-height: 1.45; }
        .button[disabled] { opacity: .55; cursor: not-allowed; }
        @media (max-width: 1400px) {
            .product-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            .product-filters { grid-template-columns: 1fr; }
            .drawer-form-grid, .drawer-form-grid.three { grid-template-columns: 1fr; }
            .drawer-step-actions { align-items: stretch; flex-direction: column; }
        }
    </style>
@endpush

@section('content')
    @php
        $qty = function ($value, $product = null): string {
            $number = (float) $value;
            $precision = max(0, min(4, (int) ($product?->quantity_precision ?? 0)));

            if ($precision === 0 || abs($number - round($number)) < 0.00001) {
                return number_format($number, 0, ',', ' ');
            }

            return rtrim(rtrim(number_format($number, $precision, ',', ' '), '0'), ',');
        };
        $percent = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', ' '), '0'), ',') . '%';
        $money = function ($value): string {
            if ($value === null || $value === '') {
                return '-';
            }

            return number_format((float) $value, 2, ',', ' ') . ' PLN';
        };
        $retailPrice = function ($product, $variants) {
            $ownPrice = data_get($product->masterData(), 'prices.retail_price_pln');

            if ($ownPrice !== null && $ownPrice !== '') {
                return $ownPrice;
            }

            return $variants
                ->map(fn ($variant) => data_get($variant->masterData(), 'prices.retail_price_pln'))
                ->first(fn ($price) => $price !== null && $price !== '');
        };
        $stockTotals = function ($products): array {
            $balances = collect($products)->flatMap(fn ($product) => $product->stockBalances);

            return [
                'on_hand' => $balances->sum(fn ($balance) => (float) $balance->quantity_on_hand),
                'reserved' => $balances->sum(fn ($balance) => (float) $balance->quantity_reserved),
                'available' => $balances->sum(fn ($balance) => (float) $balance->quantity_available),
            ];
        };
        $warehouseTiles = function ($products) {
            return collect($products)
                ->flatMap(fn ($product) => $product->stockBalances)
                ->groupBy(fn ($balance) => (string) ($balance->warehouse_id ?? 'none'))
                ->map(function ($balances) {
                    $first = $balances->first();

                    return [
                        'code' => $first?->warehouse?->code ?? 'MAG?',
                        'name' => $first?->warehouse?->name ?? '',
                        'on_hand' => $balances->sum(fn ($balance) => (float) $balance->quantity_on_hand),
                        'reserved' => $balances->sum(fn ($balance) => (float) $balance->quantity_reserved),
                        'available' => $balances->sum(fn ($balance) => (float) $balance->quantity_available),
                    ];
                })
                ->sortBy('code')
                ->values();
        };
        $channels = function ($products) {
            return collect($products)
                ->flatMap(fn ($product) => $product->channelMappings)
                ->map(fn ($mapping) => $mapping->salesChannel?->code)
                ->filter()
                ->unique()
                ->values();
        };

        $createParameterRows = collect((array) old('parameters.name', []))
            ->map(fn ($name, $index): array => [
                'name' => (string) $name,
                'value' => (string) old("parameters.value.{$index}", ''),
                'variation' => filter_var(old("parameters.variation.{$index}", false), FILTER_VALIDATE_BOOLEAN),
            ])
            ->all();

        while (count($createParameterRows) < 4) {
            $createParameterRows[] = ['name' => '', 'value' => '', 'variation' => false];
        }
    @endphp

    <input id="product-drawer" class="drawer-toggle" type="checkbox" @checked($errors->any())>

    <div class="page-toolbar">
        <div class="toolbar-note">Pokazujemy produkty główne. Warianty są pod przyciskiem „Warianty”, tak jak drzewo w Base.</div>
        <label class="button" for="product-drawer">Dodaj produkt</label>
    </div>

    <article class="card product-filter-panel">
        <form class="product-filters" method="GET" action="{{ route('products.index') }}" data-product-filters>
            <label>Szybkie wyszukiwanie
                <input
                    name="q"
                    type="search"
                    value="{{ $filters['q'] }}"
                    placeholder="Nazwa, SKU, EAN, parametr, opis..."
                    autocomplete="off"
                    data-product-search
                >
            </label>
            <label>Kanał
                <select name="channel" data-product-filter-control>
                    <option value="">Wszystkie</option>
                    @foreach ($channelOptions as $channelOption)
                        <option value="{{ $channelOption }}" @selected($filters['channel'] === $channelOption)>{{ $channelOption }}</option>
                    @endforeach
                </select>
            </label>
            <label>Magazyn
                <select name="warehouse" data-product-filter-control>
                    <option value="">Wszystkie</option>
                    @foreach ($warehouseOptions as $warehouseOption)
                        <option value="{{ $warehouseOption->id }}" @selected($filters['warehouse'] === (string) $warehouseOption->id)>{{ $warehouseOption->code }} - {{ $warehouseOption->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Stan
                <select name="stock" data-product-filter-control>
                    <option value="">Wszystkie</option>
                    <option value="available" @selected($filters['stock'] === 'available')>Dostępne</option>
                    <option value="reserved" @selected($filters['stock'] === 'reserved')>Z rezerwacją</option>
                    <option value="out_of_stock" @selected($filters['stock'] === 'out_of_stock')>Brak na stanie</option>
                    <option value="no_stock" @selected($filters['stock'] === 'no_stock')>Bez ruchu/stanu</option>
                </select>
            </label>
            <label>Typ
                <select name="type" data-product-filter-control>
                    <option value="">Wszystkie</option>
                    <option value="with_variants" @selected($filters['type'] === 'with_variants')>Z wariantami</option>
                    <option value="without_variants" @selected($filters['type'] === 'without_variants')>Bez wariantów</option>
                </select>
            </label>
            <label>Status
                <select name="status" data-product-filter-control>
                    <option value="">Wszystkie</option>
                    <option value="active" @selected($filters['status'] === 'active')>Aktywne</option>
                    <option value="inactive" @selected($filters['status'] === 'inactive')>Nieaktywne</option>
                    <option value="publish" @selected($filters['status'] === 'publish')>Opublikowane Woo</option>
                    <option value="draft" @selected($filters['status'] === 'draft')>Szkice Woo</option>
                </select>
            </label>
            <label>Kategoria
                <input name="category" list="product-filter-category-options" value="{{ $filters['category'] }}" placeholder="Dowolna">
            </label>
            <button class="button secondary" type="submit">Filtruj</button>
            <a class="button secondary" href="{{ route('products.index') }}">Wyczyść</a>
        </form>
        <div class="filter-result-line">
            Wynik: {{ $productRows->total() }} produktów głównych po filtrach / {{ $productsCount }} SKU w systemie.
            Wyszukiwarka działa po nazwie, SKU, EAN, kategorii, parametrach i opisach.
        </div>
    </article>

    <label class="drawer-backdrop" for="product-drawer"></label>
    <aside class="drawer-panel product-drawer" aria-label="Dodaj produkt">
        <div class="drawer-header">
            <div class="drawer-title">Dodaj produkt</div>
            <label class="drawer-close" for="product-drawer">&times;</label>
        </div>
        <form class="product-create-form" method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data" data-product-create-form>
            @csrf
            <input type="hidden" name="producer" value="SEMPRE">
            <nav class="drawer-step-nav" aria-label="Kroki dodawania produktu">
                <button class="active" type="button" data-create-tab="produkt" aria-selected="true">Produkt</button>
                <button type="button" data-create-tab="sprzedaz" aria-selected="false">Sprzedaż i magazyn</button>
                <button type="button" data-create-tab="informacje" aria-selected="false">Informacje</button>
                <button type="button" data-create-tab="media" aria-selected="false">Media</button>
            </nav>

            <section class="drawer-step" data-create-step="produkt">
                <div class="drawer-form-grid">
                    <label class="wide">Nazwa produktu (PL)
                        <input name="name" value="{{ old('name') }}" required>
                    </label>
                    <label>Katalog
                        <select name="catalog">
                            @foreach ($catalogOptions as $catalog)
                                <option value="{{ $catalog }}" @selected(old('catalog', 'Domyślny') === $catalog)>{{ $catalog }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Kategoria
                        <input name="category" list="product-category-options" value="{{ old('category') }}" placeholder="Wyszukaj kategorię z WooCommerce">
                    </label>
                    <label>Tagi
                        <input name="tags" value="{{ old('tags') }}" placeholder="tag 1, tag 2">
                    </label>
                    <div class="toolbar-note wide">Producent jest ustawiany automatycznie jako SEMPRE.</div>
                </div>

                <div class="drawer-form-grid three">
                    <label>SKU
                        <input name="sku" value="{{ old('sku') }}" required>
                    </label>
                    <label>EAN
                        <input name="ean" value="{{ old('ean') }}">
                    </label>
                    <label>ASIN
                        <input name="asin" value="{{ old('asin') }}">
                    </label>
                </div>

                <div class="drawer-form-grid three">
                    <label>Waga (kg)
                        <input name="weight_kg" type="number" step="0.0001" min="0" value="{{ old('weight_kg') }}">
                    </label>
                    <label>Wysokość (cm)
                        <input name="height_cm" type="number" step="0.01" min="0" value="{{ old('height_cm') }}">
                    </label>
                    <label>Szerokość (cm)
                        <input name="width_cm" type="number" step="0.01" min="0" value="{{ old('width_cm') }}">
                    </label>
                    <label>Długość (cm)
                        <input name="length_cm" type="number" step="0.01" min="0" value="{{ old('length_cm') }}">
                    </label>
                    <label>Jednostka
                        <input name="unit" value="{{ old('unit', 'szt') }}" required maxlength="16">
                    </label>
                    <label>Status
                        <input type="hidden" name="is_active" value="0">
                        <span class="toggle-row"><input name="is_active" type="checkbox" value="1" @checked(old('is_active', '1'))> Aktywny</span>
                    </label>
                    <label>Status WooCommerce
                        <select name="publication_status">
                            @foreach (['publish' => 'Opublikowany', 'draft' => 'Szkic', 'pending' => 'Oczekujący', 'private' => 'Prywatny'] as $status => $label)
                                <option value="{{ $status }}" @selected(old('publication_status', 'publish') === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Widoczność
                        <select name="catalog_visibility">
                            @foreach (['visible' => 'Sklep i wyszukiwarka', 'catalog' => 'Tylko sklep', 'search' => 'Tylko wyszukiwarka', 'hidden' => 'Ukryty'] as $visibility => $label)
                                <option value="{{ $visibility }}" @selected(old('catalog_visibility', 'visible') === $visibility)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Typ produktu
                        <select name="product_type">
                            @foreach (['simple' => 'Prosty', 'variable' => 'Wariantowy', 'variation' => 'Wariant'] as $type => $label)
                                <option value="{{ $type }}" @selected(old('product_type', 'simple') === $type)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Atrybut wariantu
                        <input name="variant_attribute" value="{{ old('variant_attribute') }}" placeholder="np. Rozmiar">
                    </label>
                    <label>Opracowane
                        <input type="hidden" name="developed" value="0">
                        <span class="toggle-row"><input name="developed" type="checkbox" value="1" @checked(old('developed'))> Tak</span>
                    </label>
                </div>
            </section>

            <section class="drawer-step" data-create-step="sprzedaz" hidden>
                <div class="drawer-form-grid three">
                    <label>Cena hurt (PLN)
                        <input name="wholesale_price_pln" type="number" step="0.01" min="0" value="{{ old('wholesale_price_pln') }}">
                    </label>
                    <label>Cena detal brutto (PLN)
                        <input name="retail_price_pln" type="number" step="0.01" min="0" value="{{ old('retail_price_pln') }}">
                    </label>
                    <label>VAT %
                        <select name="vat_rate" required>
                            @foreach ([23, 8, 5, 0] as $rate)
                                <option value="{{ $rate }}" @selected((float) old('vat_rate', 23) === (float) $rate)>{{ $rate }}%</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Ilość stanu magazynowego
                        <input name="stock_quantity" type="number" step="1" min="0" value="{{ old('stock_quantity') }}">
                    </label>
                    <label>Stan zamówiony
                        <input name="ordered_quantity" type="number" step="1" min="0" value="{{ old('ordered_quantity') }}">
                    </label>
                    <label>Próg stanu
                        <input name="stock_threshold" type="number" step="1" min="0" value="{{ old('stock_threshold') }}">
                    </label>
                    <label>Lokalizacja
                        <input name="warehouse_location" value="{{ old('warehouse_location') }}" placeholder="np. A-01-03">
                    </label>
                    <label>Cena zakupu (średnia)
                        <input name="purchase_price_pln" type="number" step="0.01" min="0" value="{{ old('purchase_price_pln') }}">
                    </label>
                </div>
            </section>

            <section class="drawer-step" data-create-step="informacje" hidden>
                <div class="drawer-form-grid">
                    <label>Nazwa produktu (EN)
                        <input name="name_en" value="{{ old('name_en') }}">
                    </label>
                </div>
                <label>Opis PL HTML
                    <textarea class="product-html" name="description_pl" data-rich-product-editor>{{ old('description_pl') }}</textarea>
                </label>
                <label>Opis EN HTML
                    <textarea class="product-html" name="description_en" data-rich-product-editor>{{ old('description_en') }}</textarea>
                </label>
                <label>Krótki opis PL HTML
                    <textarea class="product-html" name="short_description_pl" data-rich-product-editor>{{ old('short_description_pl', old('additional_description_pl')) }}</textarea>
                </label>
                <label>Krótki opis EN HTML
                    <textarea class="product-html" name="short_description_en" data-rich-product-editor>{{ old('short_description_en') }}</textarea>
                </label>
                <div class="drawer-form-grid">
                    <label>Produkty sprzedaży dodatkowej (SKU)
                        <textarea name="related_upsell_skus" placeholder="Jedno SKU w wierszu">{{ old('related_upsell_skus', '') }}</textarea>
                    </label>
                    <label>Produkty sprzedaży krzyżowej (SKU)
                        <textarea name="related_cross_sell_skus" placeholder="Jedno SKU w wierszu">{{ old('related_cross_sell_skus', '') }}</textarea>
                    </label>
                </div>
                @include('products._relation_sku_pickers')

                <div class="table-scroll drawer-table">
                    <table class="dense-table">
                        <thead>
                            <tr>
                                <th>Nazwa parametru</th>
                                <th>Wartość</th>
                                <th>Wariant</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($createParameterRows as $index => $row)
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
            </section>

            <section class="drawer-step" data-create-step="media" hidden>
                <div class="media-upload-panel">
                    <label>Dodaj zdjęcia z komputera
                        <input name="new_media[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                    </label>
                    <label>Alt dla nowych zdjęć
                        <input name="new_media_alt" value="{{ old('new_media_alt') }}" placeholder="Nazwa produktu">
                    </label>
                    <div class="toolbar-note">Zdjęcia są zapisywane na serwerze ERP w katalogu produktu.</div>
                </div>
            </section>

            <div class="drawer-step-actions">
                <button class="button secondary" type="button" data-create-prev disabled>Wstecz</button>
                <div class="inline-actions">
                    <button class="button secondary" type="button" data-create-next>Dalej</button>
                    <button class="button" type="submit">Zapisz produkt</button>
                </div>
            </div>
        </form>
    </aside>

    <article class="card products-table">
        <div class="panel-header">
            <span>Produkty w systemie</span>
            <span>{{ $productRows->total() }} produktów głównych / {{ $productsCount }} SKU</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Towar</th>
                        <th>VAT / cena</th>
                        <th>Stan</th>
                        <th>Kanały</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($productRows as $row)
                        @php
                            $product = $row['product'];
                            $variants = $row['variants'];
                            $rowKey = 'product-' . $product->id;
                            $familyProducts = collect([$product])->merge($variants);
                            $imageUrl = $product->imageUrl();
                            $thumbnailUrl = $product->thumbnailUrl();
                            $stock = $stockTotals($familyProducts);
                            $warehouses = $warehouseTiles($familyProducts);
                            $channelNames = $channels($familyProducts);
                            $price = $retailPrice($product, $variants);
                            $displaySku = $product->displaySku();
                            $externalId = $product->externalDisplayId();
                        @endphp
                        <tr class="parent-row">
                            <td>
                                <div class="product-cell">
                                    <button
                                        class="product-thumb-button"
                                        type="button"
                                        @disabled(! $imageUrl)
                                        data-image-preview="{{ $imageUrl }}"
                                        data-image-title="{{ $product->sku }} - {{ $product->name }}"
                                        aria-label="{{ $imageUrl ? 'Powiększ zdjęcie produktu ' . $product->sku : 'Brak zdjęcia produktu ' . $product->sku }}"
                                    >
                                        @if ($imageUrl)
                                            <img src="{{ $thumbnailUrl ?: $imageUrl }}" alt="{{ $product->name }}" width="58" height="72" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                                        @else
                                            Brak
                                        @endif
                                    </button>
                                    <div>
                                        <a class="product-title" href="{{ route('products.show', $product) }}">{{ $product->name }}</a>
                                        <div class="product-meta">
                                            @if ($displaySku)
                                                <span><strong>SKU:</strong> {{ $displaySku }}</span>
                                            @elseif ($externalId)
                                                <span><strong>ID Woo:</strong> {{ $externalId }}</span>
                                            @endif
                                            @if ($product->ean)
                                                <span><strong>EAN:</strong> {{ $product->ean }}</span>
                                            @endif
                                            <span><strong>JM:</strong> {{ $product->unit }}</span>
                                        </div>
                                        @if ($variants->isNotEmpty())
                                            <button class="variant-toggle" type="button" data-toggle-variants="{{ $rowKey }}" aria-expanded="false">Warianty: {{ $variants->count() }}</button>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="price-cell">
                                    <strong>{{ $money($price) }}</strong>
                                    <span>VAT {{ $percent($product->vat_rate) }}</span>
                                </div>
                            </td>
                            <td>
                                <div class="stock-summary">
                                    <div class="stock-pills">
                                        <span class="stock-pill">Ogółem <strong>{{ $qty($stock['on_hand'], $product) }}</strong></span>
                                        <span class="stock-pill">Rezerwacje <strong>{{ $qty($stock['reserved'], $product) }}</strong></span>
                                        <span class="stock-pill available">Dostępne <strong>{{ $qty($stock['available'], $product) }}</strong></span>
                                    </div>
                                    <details class="warehouse-details">
                                        <summary>Magazyny</summary>
                                        <div class="warehouse-tile-grid">
                                            @forelse ($warehouses as $warehouse)
                                                <div class="warehouse-tile">
                                                    <strong>{{ $warehouse['code'] }} {{ $warehouse['name'] }}</strong>
                                                    <span>Stan: <b>{{ $qty($warehouse['on_hand'], $product) }}</b></span>
                                                    <span>Rez.: {{ $qty($warehouse['reserved'], $product) }}</span>
                                                    <span>Dost.: {{ $qty($warehouse['available'], $product) }}</span>
                                                </div>
                                            @empty
                                                <span class="muted">Brak stanu w magazynach</span>
                                            @endforelse
                                        </div>
                                    </details>
                                </div>
                            </td>
                            <td>
                                <div class="channel-badges">
                                    @forelse ($channelNames as $channelName)
                                        <span class="status">{{ $channelName }}</span>
                                    @empty
                                        <span class="muted">-</span>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                <div class="inline-actions">
                                    <a class="button secondary" href="{{ route('products.show', $product) }}">Szczegóły</a>
                                    <a class="button secondary" href="{{ route('products.edit', $product) }}">Edytuj</a>
                                    <form method="POST" action="{{ route('products.duplicate', $product) }}">
                                        @csrf
                                        <button class="button secondary" type="submit">Kopiuj</button>
                                    </form>
                                    <form method="POST" action="{{ route('products.destroy', $product) }}" onsubmit="return confirm('Usunąć produkt?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button" style="background: var(--red);" type="submit">Usuń</button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        @foreach ($variants as $variant)
                            @php
                                $variantImage = $variant->imageUrl();
                                $variantThumbnailUrl = $variant->thumbnailUrl(88, 112);
                                $variantStock = $stockTotals(collect([$variant]));
                                $variantWarehouses = $warehouseTiles(collect([$variant]));
                                $variantChannels = $channels(collect([$variant]));
                                $variantPrice = $retailPrice($variant, collect());
                                $variantDisplaySku = $variant->displaySku();
                                $variantExternalId = $variant->externalDisplayId();
                                $variation = collect($variant->wooVariationAttributes())
                                    ->map(function (array $attribute): string {
                                        $name = trim((string) ($attribute['name'] ?? ''));
                                        $option = trim((string) ($attribute['option'] ?? ''));

                                        if ($option === '') {
                                            return '';
                                        }

                                        return $name !== '' ? "{$name}: {$option}" : $option;
                                    })
                                    ->filter()
                                    ->implode(' | ');
                            @endphp
                            <tr class="variant-row" data-variant-parent="{{ $rowKey }}" hidden>
                                <td>
                                    <div class="product-cell variant">
                                        <button
                                            class="product-thumb-button"
                                            type="button"
                                            @disabled(! $variantImage)
                                            data-image-preview="{{ $variantImage }}"
                                            data-image-title="{{ $variant->sku }} - {{ $variant->name }}"
                                        >
                                            @if ($variantImage)
                                                <img src="{{ $variantThumbnailUrl ?: $variantImage }}" alt="{{ $variant->name }}" width="44" height="56" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                                            @else
                                                Brak
                                            @endif
                                        </button>
                                        <div>
                                            <a class="product-title" href="{{ route('products.show', $variant) }}">{{ $variant->name }}</a>
                                            <div class="product-meta">
                                                @if ($variantDisplaySku)
                                                    <span><strong>SKU:</strong> {{ $variantDisplaySku }}</span>
                                                @elseif ($variantExternalId)
                                                    <span><strong>ID Woo:</strong> {{ $variantExternalId }}</span>
                                                @endif
                                                @if ($variant->ean)
                                                    <span><strong>EAN:</strong> {{ $variant->ean }}</span>
                                                @endif
                                                @if ($variation)
                                                    <span>{{ $variation }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="price-cell">
                                        <strong>{{ $money($variantPrice) }}</strong>
                                        <span>VAT {{ $percent($variant->vat_rate) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="stock-summary">
                                        <div class="stock-pills">
                                            <span class="stock-pill">Ogółem <strong>{{ $qty($variantStock['on_hand'], $variant) }}</strong></span>
                                            <span class="stock-pill">Rezerwacje <strong>{{ $qty($variantStock['reserved'], $variant) }}</strong></span>
                                            <span class="stock-pill available">Dostępne <strong>{{ $qty($variantStock['available'], $variant) }}</strong></span>
                                        </div>
                                        <details class="warehouse-details">
                                            <summary>Magazyny</summary>
                                            <div class="warehouse-tile-grid">
                                                @forelse ($variantWarehouses as $warehouse)
                                                    <div class="warehouse-tile">
                                                        <strong>{{ $warehouse['code'] }} {{ $warehouse['name'] }}</strong>
                                                        <span>Stan: <b>{{ $qty($warehouse['on_hand'], $variant) }}</b></span>
                                                        <span>Rez.: {{ $qty($warehouse['reserved'], $variant) }}</span>
                                                        <span>Dost.: {{ $qty($warehouse['available'], $variant) }}</span>
                                                    </div>
                                                @empty
                                                    <span class="muted">Brak stanu w magazynach</span>
                                                @endforelse
                                            </div>
                                        </details>
                                    </div>
                                </td>
                                <td>
                                    <div class="channel-badges">
                                        @forelse ($variantChannels as $channelName)
                                            <span class="status">{{ $channelName }}</span>
                                        @empty
                                            <span class="muted">-</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td>
                                    <div class="inline-actions">
                                        <a class="button secondary" href="{{ route('products.show', $variant) }}">Szczegóły</a>
                                        <a class="button secondary" href="{{ route('products.edit', $variant) }}">Edytuj</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="5">Brak produktów. Dodaj produkt ręcznie albo zaimportuj produkty z WooCommerce w module Integracje.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination-wrapper">
            @if ($productRows->hasPages())
                @php
                    $currentPage = $productRows->currentPage();
                    $lastPage = $productRows->lastPage();
                    $firstPageLink = max(1, $currentPage - 2);
                    $lastPageLink = min($lastPage, $currentPage + 2);
                @endphp
                <nav class="pagination-bar" aria-label="Paginacja produktów">
                    <div class="pagination-summary">
                        Strona {{ $currentPage }} z {{ $lastPage }} | rekordy {{ $productRows->firstItem() }}-{{ $productRows->lastItem() }} z {{ $productRows->total() }}
                    </div>
                    <div class="pagination-pages">
                        <a @class(['pagination-page', 'disabled' => $productRows->onFirstPage()]) href="{{ $productRows->previousPageUrl() ?: '#' }}">Poprzednia</a>
                        @if ($firstPageLink > 1)
                            <a class="pagination-page" href="{{ $productRows->url(1) }}">1</a>
                            @if ($firstPageLink > 2)
                                <span class="pagination-page disabled">...</span>
                            @endif
                        @endif
                        @for ($page = $firstPageLink; $page <= $lastPageLink; $page++)
                            <a @class(['pagination-page', 'active' => $page === $currentPage]) href="{{ $productRows->url($page) }}">{{ $page }}</a>
                        @endfor
                        @if ($lastPageLink < $lastPage)
                            @if ($lastPageLink < $lastPage - 1)
                                <span class="pagination-page disabled">...</span>
                            @endif
                            <a class="pagination-page" href="{{ $productRows->url($lastPage) }}">{{ $lastPage }}</a>
                        @endif
                        <a @class(['pagination-page', 'disabled' => ! $productRows->hasMorePages()]) href="{{ $productRows->nextPageUrl() ?: '#' }}">Następna</a>
                    </div>
                </nav>
            @else
                <div class="pagination-summary">Wszystkie wyniki mieszczą się na jednej stronie.</div>
            @endif
        </div>
    </article>

    <div class="image-modal" data-product-image-modal aria-hidden="true">
        <div class="image-modal-card">
            <div class="image-modal-header">
                <span data-product-image-title>Podgląd produktu</span>
                <button class="image-modal-close" type="button" data-product-image-close aria-label="Zamknij">&times;</button>
            </div>
            <img data-product-image-large alt="">
        </div>
    </div>

    <datalist id="product-category-options">
        @foreach ($categoryOptions as $category)
            <option value="{{ $category['path'] }}">{{ $category['sales_channel'] ? $category['sales_channel'] . ' · ' : '' }}{{ $category['name'] }}</option>
        @endforeach
    </datalist>

    <datalist id="product-filter-category-options">
        @foreach ($categoryOptions as $category)
            <option value="{{ $category['path'] }}">{{ $category['sales_channel'] ? $category['sales_channel'] . ' · ' : '' }}{{ $category['name'] }}</option>
        @endforeach
    </datalist>
    @include('products._parameter_datalists', ['parameterOptions' => $parameterOptions])
    @include('products._product_lookup_datalist', ['productLookupOptions' => $productLookupOptions])
    @include('products._rich_editor_assets')
@endsection

@push('scripts')
    <script>
        const productFiltersForm = document.querySelector('[data-product-filters]');
        const productSearchInput = document.querySelector('[data-product-search]');
        let productFilterTimer = null;

        function submitProductFiltersWithDelay(delay = 250) {
            if (!productFiltersForm) {
                return;
            }

            window.clearTimeout(productFilterTimer);
            productFilterTimer = window.setTimeout(() => {
                productFiltersForm.requestSubmit();
            }, delay);
        }

        productSearchInput?.addEventListener('input', () => submitProductFiltersWithDelay(380));
        document.querySelectorAll('[data-product-filter-control]').forEach((control) => {
            control.addEventListener('change', () => submitProductFiltersWithDelay(0));
        });

        const createTabs = Array.from(document.querySelectorAll('[data-create-tab]'));
        const createSteps = Array.from(document.querySelectorAll('[data-create-step]'));
        const createPreviousButton = document.querySelector('[data-create-prev]');
        const createNextButton = document.querySelector('[data-create-next]');
        let activeCreateStepIndex = 0;

        function showCreateStep(index) {
            if (createSteps.length === 0) {
                return;
            }

            activeCreateStepIndex = Math.max(0, Math.min(index, createSteps.length - 1));

            createSteps.forEach((step, stepIndex) => {
                step.hidden = stepIndex !== activeCreateStepIndex;
            });

            createTabs.forEach((tab, tabIndex) => {
                const active = tabIndex === activeCreateStepIndex;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            if (createPreviousButton) {
                createPreviousButton.disabled = activeCreateStepIndex === 0;
            }

            if (createNextButton) {
                createNextButton.hidden = activeCreateStepIndex === createSteps.length - 1;
            }
        }

        createTabs.forEach((tab, index) => {
            tab.addEventListener('click', () => showCreateStep(index));
        });

        createPreviousButton?.addEventListener('click', () => showCreateStep(activeCreateStepIndex - 1));
        createNextButton?.addEventListener('click', () => showCreateStep(activeCreateStepIndex + 1));
        showCreateStep(0);

        document.querySelectorAll('[data-toggle-variants]').forEach((button) => {
            button.addEventListener('click', () => {
                const key = button.dataset.toggleVariants;
                const rows = Array.from(document.querySelectorAll(`[data-variant-parent="${key}"]`));
                const expanded = button.getAttribute('aria-expanded') === 'true';

                rows.forEach((row) => {
                    row.hidden = expanded;
                });

                button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            });
        });

        const productImageModal = document.querySelector('[data-product-image-modal]');
        const productImageLarge = document.querySelector('[data-product-image-large]');
        const productImageTitle = document.querySelector('[data-product-image-title]');
        const productImageClose = document.querySelector('[data-product-image-close]');

        document.querySelectorAll('[data-image-preview]').forEach((button) => {
            button.addEventListener('click', () => {
                const src = button.dataset.imagePreview || '';

                if (!src || !productImageModal || !productImageLarge || !productImageTitle) {
                    return;
                }

                productImageLarge.src = src;
                productImageLarge.alt = button.dataset.imageTitle || 'Podgląd produktu';
                productImageTitle.textContent = button.dataset.imageTitle || 'Podgląd produktu';
                productImageModal.classList.add('open');
                productImageModal.setAttribute('aria-hidden', 'false');
            });
        });

        function closeProductImageModal() {
            if (!productImageModal || !productImageLarge) {
                return;
            }

            productImageModal.classList.remove('open');
            productImageModal.setAttribute('aria-hidden', 'true');
            productImageLarge.removeAttribute('src');
        }

        productImageClose?.addEventListener('click', closeProductImageModal);
        productImageModal?.addEventListener('click', (event) => {
            if (event.target === productImageModal) {
                closeProductImageModal();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeProductImageModal();
            }
        });
    </script>
@endpush
