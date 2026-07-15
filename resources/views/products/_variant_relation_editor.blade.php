@php
    $lookupBySku = collect($productLookupOptions ?? [])->keyBy('sku');
    $variantRows = $product->relationLoaded('variantChildren')
        ? $product->variantChildren->map(fn ($variant): array => [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'label' => $variant->sku . ' | ' . $variant->name,
            'name' => $variant->name,
            'ean' => $variant->ean,
            'regular_price' => data_get($variant->masterData(), 'prices.retail_price_pln'),
            'sale_price' => data_get($variant->masterData(), 'prices.sale_price_pln'),
            'sort_order' => max(1, (int) ($variant->pivot?->sort_order ?? 100)),
            'existing' => true,
        ])->values()->all()
        : [];

    $oldVariantSortOrders = (array) old('variant_sort_order', []);

    if (old('variant_skus')) {
        $variantRows = collect((array) old('variant_skus'))
            ->map(function ($sku, $index) use ($lookupBySku, $oldVariantSortOrders): array {
                $sku = trim((string) $sku);
                $lookup = $lookupBySku->get($sku);

                return [
                    'id' => null,
                    'sku' => $sku,
                    'label' => $lookup['label'] ?? $sku,
                    'name' => $lookup['name'] ?? '',
                    'ean' => $lookup['ean'] ?? null,
                    'regular_price' => null,
                    'sale_price' => null,
                    'sort_order' => isset($oldVariantSortOrders[$index])
                        ? max(1, (int) $oldVariantSortOrders[$index])
                        : null,
                    'existing' => $sku !== '',
                ];
            })
            ->filter(fn (array $row): bool => $row['sku'] !== '')
            ->values()
            ->all();
    }

    $emptyVariantRows = max(3, 6 - count($variantRows));
    $nextVariantSortOrder = (int) (collect($variantRows)
        ->pluck('sort_order')
        ->filter(fn ($sortOrder): bool => is_numeric($sortOrder))
        ->map(fn ($sortOrder): int => (int) $sortOrder)
        ->max() ?? 0);

    for ($index = 0; $index < $emptyVariantRows; $index++) {
        $nextVariantSortOrder = min(65535, $nextVariantSortOrder + 10);
        $variantRows[] = ['id' => null, 'sku' => '', 'label' => '', 'name' => '', 'ean' => null, 'regular_price' => null, 'sale_price' => null, 'sort_order' => $nextVariantSortOrder, 'existing' => false];
    }
@endphp

@once
    @push('styles')
        <style>
            .variant-editor { display: grid; gap: 12px; }
            .variant-editor-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
            .variant-editor-list { display: grid; gap: 10px; }
            .variant-editor-row { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(170px, .45fr); gap: 12px; align-items: center; padding: 12px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; }
            .variant-editor-row.is-empty { background: #fff; }
            .variant-editor-main { display: grid; gap: 7px; min-width: 0; }
            .variant-editor-main input { width: 100%; min-width: 0; }
            .variant-editor-meta { display: flex; gap: 8px; flex-wrap: wrap; color: var(--muted); font-size: 13px; }
            .variant-editor-actions { display: grid; gap: 6px; justify-items: end; }
            .variant-editor-order { display: grid; gap: 4px; justify-items: end; color: var(--muted); font-size: 13px; }
            .variant-editor-order input { width: 110px; }
            .variant-editor-reorder { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
            .variant-editor-row.is-empty .variant-editor-reorder { display: none; }
            .variant-editor-actions .toggle-row { justify-content: flex-end; }
            .related-picker-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
            @media (max-width: 820px) {
                .variant-editor-row { grid-template-columns: 1fr; }
                .variant-editor-actions { justify-items: start; }
                .variant-editor-order { justify-items: start; }
                .variant-editor-reorder { justify-content: flex-start; }
                .variant-editor-actions .toggle-row { justify-content: flex-start; }
            }
            @media (max-width: 720px) {
                .related-picker-grid { grid-template-columns: 1fr; }
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            (() => {
                function editorRows(editor) {
                    const list = editor?.querySelector('.variant-editor-list');

                    return list ? Array.from(list.children).filter((row) => row.matches('[data-variant-editor-row]')) : [];
                }

                function rowHasSku(row) {
                    return String(row.querySelector('[data-product-sku-hidden]')?.value || '').trim() !== '';
                }

                function orderedRows(editor) {
                    return editorRows(editor).filter(rowHasSku);
                }

                function refreshOrderControls(editor) {
                    const rows = orderedRows(editor);

                    editorRows(editor).forEach((row) => row.classList.toggle('is-empty', !rowHasSku(row)));
                    rows.forEach((row, index) => {
                        const up = row.querySelector('[data-variant-order-up]');
                        const down = row.querySelector('[data-variant-order-down]');

                        if (up) up.disabled = index === 0;
                        if (down) down.disabled = index === rows.length - 1;
                    });
                }

                function renumberRows(editor) {
                    const rows = orderedRows(editor);
                    const step = rows.length <= 6553 ? 10 : 1;

                    rows.forEach((row, index) => {
                        const input = row.querySelector('[data-variant-sort-order]');

                        if (input) input.value = Math.min(65535, (index + 1) * step);
                    });
                }

                function moveVariantRow(button, direction) {
                    const row = button.closest('[data-variant-editor-row]');
                    const editor = button.closest('.variant-editor');
                    const list = editor?.querySelector('.variant-editor-list');
                    const rows = orderedRows(editor);
                    const index = rows.indexOf(row);
                    const target = rows[index + direction];

                    if (!row || !list || index < 0 || !target) return;

                    if (direction < 0) {
                        list.insertBefore(row, target);
                    } else {
                        list.insertBefore(target, row);
                    }

                    renumberRows(editor);
                    refreshOrderControls(editor);
                    button.focus();
                }

                document.querySelectorAll('.variant-editor').forEach(refreshOrderControls);

                document.addEventListener('click', (event) => {
                    const up = event.target.closest('[data-variant-order-up]');
                    const down = event.target.closest('[data-variant-order-down]');
                    const button = up || down;

                    if (!button) return;

                    event.preventDefault();
                    moveVariantRow(button, up ? -1 : 1);
                });

                document.addEventListener('input', (event) => {
                    const lookup = event.target.closest('[data-product-sku-lookup]');

                    if (lookup) refreshOrderControls(lookup.closest('.variant-editor'));
                });

                document.addEventListener('change', (event) => {
                    const lookup = event.target.closest('[data-product-sku-lookup]');

                    if (lookup) refreshOrderControls(lookup.closest('.variant-editor'));
                });
            })();
        </script>
    @endpush
@endonce

<div class="variant-editor">
    <div class="variant-editor-head">
        <div>
            <strong>Warianty produktu</strong>
            <div class="toolbar-note">Atrybut oznaczony jako wariant, np. Rozmiar, określa opcje WooCommerce. Każda opcja jest osobnym SKU. Użyj przycisków, aby ustawić kolejność wariantów tej rodziny. Globalna kolejność wartości rozmiaru (np. S przed M) wynika z kolejności wierszy w słowniku parametrów.</div>
        </div>
        <a class="button secondary" href="{{ route('products.parameters.index') }}">Ustaw globalną kolejność rozmiarów</a>
    </div>

    <div class="variant-editor-list">
        @foreach ($variantRows as $index => $row)
            <div class="variant-editor-row @if ($row['sku'] === '') is-empty @endif" data-variant-editor-row>
                <div class="variant-editor-main">
                    <label>Wariant
                        <input
                            value="{{ $row['label'] }}"
                            list="product-lookup-options"
                            placeholder="Wpisz SKU, nazwę lub kategorię"
                            data-product-sku-lookup
                            autocomplete="off"
                        >
                    </label>
                    <input type="hidden" name="variant_skus[{{ $index }}]" value="{{ $row['sku'] }}" data-product-sku-hidden>
                    <div class="variant-editor-meta">
                        <span>SKU: <strong>{{ $row['sku'] ?: 'wybierz produkt' }}</strong></span>
                        <span>EAN: <strong>{{ $row['ean'] ?: '-' }}</strong></span>
                        @if ($row['existing'])
                            <span>Cena: <strong>{{ $row['regular_price'] !== null ? number_format((float) $row['regular_price'], 2, ',', ' ').' zł' : '-' }}</strong></span>
                            <span>Promocja: <strong>{{ $row['sale_price'] !== null ? number_format((float) $row['sale_price'], 2, ',', ' ').' zł' : '-' }}</strong></span>
                        @endif
                        @if ($row['name'] !== '')
                            <span>{{ $row['name'] }}</span>
                        @endif
                    </div>
                </div>
                <div class="variant-editor-actions">
                    <div class="variant-editor-reorder" aria-label="Zmień kolejność wariantu">
                        <button class="button secondary" type="button" data-variant-order-up aria-label="Przesuń wariant w górę">↑ W górę</button>
                        <button class="button secondary" type="button" data-variant-order-down aria-label="Przesuń wariant w dół">↓ W dół</button>
                    </div>
                    <label class="variant-editor-order">Kolejność w sklepie
                        <input name="variant_sort_order[{{ $index }}]" type="number" min="1" max="65535" step="1" value="{{ $row['sort_order'] }}" data-variant-sort-order>
                    </label>
                    <input type="hidden" name="variant_remove[{{ $index }}]" value="0">
                    @if ($row['sku'] !== '')
                        @if ($row['id'])
                            <a class="button secondary" href="{{ route('products.edit', $row['id']) }}">Edytuj dane wariantu</a>
                        @endif
                        <label class="toggle-row">
                            <input name="variant_remove[{{ $index }}]" type="checkbox" value="1">
                            Odłącz wariant od produktu
                        </label>
                    @else
                        <span class="toolbar-note">Wybierz produkt z listy, aby dodać wariant.</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
