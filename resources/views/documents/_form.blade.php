@php
    $isEdit = isset($document);
    $selectedType = old('type', $isEdit ? $document->type : ($types[0] ?? 'PZ'));
    $productById = $products->keyBy('id');
    $oldLines = $oldLines ?? old('lines', $isEdit
        ? $document->lines->map(fn ($line): array => [
            'product_id' => $line->product_id,
            'quantity' => (string) $line->quantity,
            'unit_gross_price' => $line->unit_gross_price !== null ? (string) $line->unit_gross_price : '',
            'location' => (string) (data_get($line->metadata, 'location') ?? $line->notes ?? ''),
        ])->values()->all()
        : [['product_id' => '', 'quantity' => '', 'unit_gross_price' => '', 'location' => '']]
    );

    if (! is_array($oldLines) || $oldLines === []) {
        $oldLines = [['product_id' => '', 'quantity' => '', 'unit_gross_price' => '', 'location' => '']];
    }

    $sourceWarehouseId = old('source_warehouse_id', $isEdit ? $document->source_warehouse_id : null);
    $destinationWarehouseId = old('destination_warehouse_id', $isEdit ? $document->destination_warehouse_id : null);
    $documentDate = old('document_date', $isEdit ? $document->document_date?->toDateString() : now()->toDateString());
    $locations = $locations ?? [];
    $productStock = $productStock ?? [];
    $typeLabels = $typeLabels ?? [];
    $typeHelpTexts = $typeHelpTexts ?? [];
@endphp

@push('styles')
    <style>
        .document-form-card { max-width: 1180px; }
        .document-form-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; align-items: end; }
        .document-field-hidden { display: none !important; }
        .document-type-help { grid-column: 1 / -1; border: 1px solid rgba(134, 115, 100, .28); border-radius: 8px; padding: 10px 12px; background: var(--green-soft); color: var(--green-dark); font-weight: 760; }
        .document-lines { display: grid; gap: 10px; }
        .document-line { display: grid; grid-template-columns: minmax(280px, 1.4fr) 190px 150px minmax(160px, .8fr) auto; gap: 8px; align-items: end; border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: #fffdfb; }
        .document-line .button { min-height: 41px; }
        .quantity-with-stock { display: grid; grid-template-columns: minmax(72px, 112px) auto; gap: 12px; align-items: center; }
        .quantity-with-stock input { width: 100%; text-align: center; font-size: 18px; font-weight: 850; }
        .line-stock-current { color: var(--green); font-size: 22px; font-weight: 900; min-width: 40px; }
        .line-stock-current.empty { color: var(--muted); font-size: 14px; font-weight: 760; }
        .document-product-choice { border: 1px solid var(--border); border-radius: 8px; padding: 9px 10px; background: #fff; min-height: 42px; width: 100%; display: flex; justify-content: space-between; gap: 10px; align-items: center; color: var(--text); cursor: pointer; text-align: left; }
        .document-product-choice strong { display: block; font-size: 13px; }
        .document-product-choice span { display: block; color: var(--muted); font-size: 12px; margin-top: 2px; }
        .line-toolbar { display: flex; justify-content: space-between; gap: 10px; align-items: center; margin-top: 6px; }
        .readonly-field { border: 1px solid var(--border); border-radius: 7px; padding: 10px 11px; color: var(--text); background: #f8faf8; font-weight: 760; }
        .document-modal { position: fixed; inset: 0; z-index: 95; display: none; background: rgba(37, 31, 26, .62); padding: 24px; }
        .document-modal.open { display: grid; place-items: center; }
        .document-modal-card { width: min(1040px, 96vw); max-height: 90vh; overflow: hidden; background: var(--surface); border-radius: 8px; box-shadow: 0 24px 70px rgba(0, 0, 0, .28); display: grid; grid-template-rows: auto auto minmax(0, 1fr) auto; }
        .document-modal-header { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 14px 16px; border-bottom: 1px solid var(--border); font-weight: 850; }
        .document-modal-search { padding: 12px 16px; border-bottom: 1px solid var(--border); }
        .document-product-results { overflow-y: auto; padding: 10px 16px 16px; display: grid; gap: 8px; }
        .document-product-result { border: 1px solid var(--border); border-radius: 8px; padding: 9px 10px; background: #fff; display: grid; grid-template-columns: 24px 52px minmax(0, 1fr) auto; gap: 10px; align-items: center; text-align: left; cursor: pointer; color: var(--text); }
        .document-product-result:hover { border-color: rgba(134, 115, 100, .38); background: #fffdfb; }
        .document-product-result.selected { border-color: var(--green); background: rgba(134, 115, 100, .09); }
        .document-product-result input { width: 18px; height: 18px; }
        .document-product-thumb { width: 46px; height: 56px; border-radius: 7px; background: #f4f1ef; object-fit: cover; display: block; }
        .document-product-placeholder { width: 46px; height: 56px; border-radius: 7px; background: #f4f1ef; display: grid; place-items: center; color: var(--muted); font-size: 10px; font-weight: 850; }
        .document-product-meta { color: var(--muted); font-size: 12px; margin-top: 3px; }
        .document-close { border: 0; background: transparent; font: inherit; font-size: 24px; color: var(--muted); cursor: pointer; }
        .document-modal-footer { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 12px 16px; border-top: 1px solid var(--border); background: #fffdfb; }
        @media (max-width: 860px) {
            .document-form-grid, .document-line { grid-template-columns: 1fr; }
            .document-product-result { grid-template-columns: 22px 44px minmax(0, 1fr); }
            .document-product-result .status { grid-column: 1 / -1; width: fit-content; }
            .quantity-with-stock { grid-template-columns: 112px auto; }
        }
    </style>
@endpush

<article class="card document-form-card">
    <div class="panel-header">
        <span>{{ $isEdit ? $document->number : 'Nowy dokument' }}</span>
        <span>{{ $isEdit ? $document->type : 'Szkic' }}</span>
    </div>
    <form class="form-grid" method="POST" action="{{ $action }}" data-document-form>
        @csrf
        @if (($method ?? 'POST') !== 'POST')
            @method($method)
        @endif

        <div class="document-form-grid">
            <label>Typ dokumentu
                @if ($isEdit)
                    <input type="hidden" name="type" value="{{ $selectedType }}" data-document-type>
                    <div class="readonly-field">{{ $selectedType }}</div>
                @else
                    <select name="type" required data-document-type>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected($selectedType === $type)>{{ $type }} - {{ $typeLabels[$type] ?? $type }}</option>
                        @endforeach
                    </select>
                @endif
            </label>
            <label>Data wystawienia
                <input name="document_date" value="{{ $documentDate }}" type="date" required>
            </label>
            <label data-document-source-field>Magazyn źródłowy
                <select name="source_warehouse_id" data-document-source-select>
                    <option value="">-</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected((string) $sourceWarehouseId === (string) $warehouse->id)>{{ $warehouse->code }} - {{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </label>
            <label data-document-destination-field>Magazyn docelowy
                <select name="destination_warehouse_id" data-document-destination-select>
                    <option value="">-</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected((string) $destinationWarehouseId === (string) $warehouse->id)>{{ $warehouse->code }} - {{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </label>
            <div class="document-type-help" data-document-type-help></div>
        </div>

        <div class="line-toolbar">
            <strong>Pozycje dokumentu</strong>
            <button class="button secondary" type="button" data-add-document-line tabindex="-1">Dodaj pozycję</button>
        </div>

        <div class="document-lines" data-document-lines data-next-index="{{ count($oldLines) }}">
            @foreach ($oldLines as $index => $line)
                @php
                    $selectedProduct = ! empty($line['product_id']) ? $productById->get((int) $line['product_id']) : null;
                @endphp
                <div class="document-line" data-document-line>
                    <label>Produkt
                        <input type="hidden" name="lines[{{ $index }}][product_id]" value="{{ $line['product_id'] ?? '' }}" data-document-product-id required>
                        <button class="document-product-choice" type="button" data-open-product-picker tabindex="-1">
                            <span data-document-product-label>
                                @if ($selectedProduct)
                                    <strong>{{ $selectedProduct->sku }}</strong>
                                    <span>{{ $selectedProduct->name }}</span>
                                @else
                                    <strong>Wybierz produkt</strong>
                                    <span>Szybka wyszukiwarka po SKU, EAN lub nazwie</span>
                                @endif
                            </span>
                            <span class="status">Wybierz</span>
                        </button>
                    </label>
                    <label>Ilość
                        <span class="quantity-with-stock">
                            <input name="lines[{{ $index }}][quantity]" value="{{ $line['quantity'] ?? '' }}" type="number" step="1" min="1" required data-document-quantity>
                            <span class="line-stock-current empty" data-document-stock-current>-</span>
                        </span>
                    </label>
                    <label>Cena zakupu
                        <input name="lines[{{ $index }}][unit_gross_price]" value="{{ $line['unit_gross_price'] ?? '' }}" type="number" step="0.01" min="0" inputmode="decimal" data-document-price>
                    </label>
                    <label>Lokalizacja
                        <input name="lines[{{ $index }}][location]" value="{{ $line['location'] ?? '' }}" list="document-location-options" maxlength="120" data-document-location>
                    </label>
                    <button class="button secondary" type="button" data-remove-document-line tabindex="-1">Usuń</button>
                </div>
            @endforeach
        </div>

        <label>Notatka
            <textarea name="notes" rows="3">{{ old('notes', $isEdit ? $document->notes : null) }}</textarea>
        </label>

        <div class="inline-actions">
            <a class="button secondary" href="{{ $isEdit ? route('documents.show', $document) : route('documents.index') }}">Anuluj</a>
            <button class="button" type="submit">{{ $submitLabel }}</button>
        </div>
    </form>
</article>

<template id="document-line-template">
    <div class="document-line" data-document-line>
        <label>Produkt
            <input type="hidden" name="lines[__INDEX__][product_id]" data-document-product-id required>
            <button class="document-product-choice" type="button" data-open-product-picker tabindex="-1">
                <span data-document-product-label>
                    <strong>Wybierz produkt</strong>
                    <span>Szybka wyszukiwarka po SKU, EAN lub nazwie</span>
                </span>
                <span class="status">Wybierz</span>
            </button>
        </label>
        <label>Ilość
            <span class="quantity-with-stock">
                <input name="lines[__INDEX__][quantity]" type="number" step="1" min="1" required data-document-quantity>
                <span class="line-stock-current empty" data-document-stock-current>-</span>
            </span>
        </label>
        <label>Cena zakupu
            <input name="lines[__INDEX__][unit_gross_price]" type="number" step="0.01" min="0" inputmode="decimal" data-document-price>
        </label>
        <label>Lokalizacja
            <input name="lines[__INDEX__][location]" list="document-location-options" maxlength="120" data-document-location>
        </label>
        <button class="button secondary" type="button" data-remove-document-line tabindex="-1">Usuń</button>
    </div>
</template>

<datalist id="document-location-options">
    @foreach ($locations as $location)
        <option value="{{ $location }}"></option>
    @endforeach
</datalist>

<div class="document-modal" data-document-product-modal aria-hidden="true">
    <div class="document-modal-card" role="dialog" aria-modal="true" aria-label="Wybierz produkt">
        <div class="document-modal-header">
            <span>Wybierz produkt do dokumentu</span>
            <button class="document-close" type="button" data-close-product-picker>&times;</button>
        </div>
        <div class="document-modal-search">
            <input type="search" placeholder="Szukaj po SKU, EAN, nazwie..." data-product-picker-search autocomplete="off">
        </div>
        <div class="document-product-results" data-product-picker-results>
            @foreach ($products as $product)
                @php
                    $imageUrl = $product->imageUrl();
                    $search = mb_strtolower(trim($product->sku . ' ' . $product->name . ' ' . ($product->ean ?? '') . ' ' . ($product->displaySku() ?? '')));
                @endphp
                <label
                    class="document-product-result"
                    data-product-result
                    data-product-id="{{ $product->id }}"
                    data-product-sku="{{ $product->sku }}"
                    data-product-name="{{ $product->name }}"
                    data-product-search="{{ $search }}"
                >
                    <input type="checkbox" value="{{ $product->id }}" data-product-picker-checkbox tabindex="-1">
                    @if ($imageUrl)
                        <img class="document-product-thumb" src="{{ $imageUrl }}" alt="{{ $product->name }}" loading="lazy" referrerpolicy="no-referrer">
                    @else
                        <span class="document-product-placeholder">Brak</span>
                    @endif
                    <span>
                        <strong>{{ $product->sku }}</strong>
                        <span class="document-product-meta">{{ $product->name }}</span>
                        @if ($product->ean)
                            <span class="document-product-meta">EAN: {{ $product->ean }}</span>
                        @endif
                    </span>
                    <span class="status">Dodaj</span>
                </label>
            @endforeach
        </div>
        <div class="document-modal-footer">
            <span class="muted" data-product-picker-counter>0 zaznaczonych</span>
            <div class="inline-actions">
                <button class="button secondary" type="button" data-close-product-picker tabindex="-1">Zamknij</button>
                <button class="button" type="button" data-add-selected-products>Dodaj zaznaczone</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('[data-document-form]');
            const documentLines = document.querySelector('[data-document-lines]');
            const addDocumentLineButton = document.querySelector('[data-add-document-line]');
            const documentLineTemplate = document.getElementById('document-line-template');
            const modal = document.querySelector('[data-document-product-modal]');
            const searchInput = document.querySelector('[data-product-picker-search]');
            const productResults = Array.from(document.querySelectorAll('[data-product-result]'));
            const sourceTypes = @json($sourceTypes);
            const destinationTypes = @json($destinationTypes);
            const typeHelpTexts = @json($typeHelpTexts);
            const productStocks = @json($productStock);
            let nextDocumentLineIndex = Number(documentLines?.dataset.nextIndex || 0);
            let activeLine = null;
            let pickerMode = 'add';

            const typeControl = document.querySelector('[data-document-type]');
            const sourceField = document.querySelector('[data-document-source-field]');
            const sourceSelect = document.querySelector('[data-document-source-select]');
            const destinationField = document.querySelector('[data-document-destination-field]');
            const destinationSelect = document.querySelector('[data-document-destination-select]');
            const typeHelp = document.querySelector('[data-document-type-help]');

            function refreshDocumentTypeFields() {
                const type = typeControl?.value || 'PZ';
                const needsSource = sourceTypes.includes(type);
                const needsDestination = destinationTypes.includes(type);

                sourceField?.classList.toggle('document-field-hidden', ! needsSource);
                destinationField?.classList.toggle('document-field-hidden', ! needsDestination);

                if (sourceSelect) {
                    sourceSelect.disabled = ! needsSource;
                    sourceSelect.required = needsSource;
                    if (! needsSource) sourceSelect.value = '';
                }

                if (destinationSelect) {
                    destinationSelect.disabled = ! needsDestination;
                    destinationSelect.required = needsDestination;
                    if (! needsDestination) destinationSelect.value = '';
                }

                refreshAllLineStocks();

                if (typeHelp) typeHelp.textContent = typeHelpTexts[type] || '';
            }

            function refreshDocumentLineButtons() {
                const rows = documentLines ? documentLines.querySelectorAll('[data-document-line]') : [];
                rows.forEach((row) => {
                    const removeButton = row.querySelector('[data-remove-document-line]');
                    if (removeButton) removeButton.disabled = rows.length <= 1;
                });
            }

            function selectedStockWarehouseId() {
                const type = typeControl?.value || 'PZ';

                if (sourceTypes.includes(type) && sourceSelect?.value) {
                    return sourceSelect.value;
                }

                if (destinationTypes.includes(type) && destinationSelect?.value) {
                    return destinationSelect.value;
                }

                return sourceSelect?.value || destinationSelect?.value || '';
            }

            function refreshLineStock(line) {
                const productId = line?.querySelector('[data-document-product-id]')?.value || '';
                const stockNode = line?.querySelector('[data-document-stock-current]');
                const warehouseId = selectedStockWarehouseId();
                const value = productId && warehouseId ? productStocks[productId]?.[warehouseId] : null;

                if (! stockNode) return;

                if (value === null || value === undefined) {
                    stockNode.textContent = '-';
                    stockNode.classList.add('empty');
                    return;
                }

                stockNode.textContent = String(value);
                stockNode.classList.remove('empty');
            }

            function refreshAllLineStocks() {
                documentLines?.querySelectorAll('[data-document-line]').forEach(refreshLineStock);
            }

            function resetProductSelection() {
                productResults.forEach((result) => {
                    const checkbox = result.querySelector('[data-product-picker-checkbox]');
                    if (checkbox) checkbox.checked = false;
                    result.classList.remove('selected');
                });
                updateSelectionCounter();
            }

            function updateSelectionCounter() {
                const count = productResults.filter((result) => result.querySelector('[data-product-picker-checkbox]')?.checked).length;
                const counter = document.querySelector('[data-product-picker-counter]');
                if (counter) counter.textContent = `${count} zaznaczonych`;
            }

            function openProductPicker(line = null, mode = 'add') {
                activeLine = line;
                pickerMode = mode;
                if (searchInput) searchInput.value = '';
                resetProductSelection();
                filterProducts('');
                modal?.classList.add('open');
                modal?.setAttribute('aria-hidden', 'false');
                setTimeout(() => searchInput?.focus(), 0);
            }

            function closeProductPicker(focusLine = null) {
                modal?.classList.remove('open');
                modal?.setAttribute('aria-hidden', 'true');
                activeLine = null;
                pickerMode = 'add';

                if (focusLine) {
                    setTimeout(() => focusLine.querySelector('[data-document-quantity]')?.focus(), 0);
                }
            }

            function filterProducts(query) {
                const needle = query.trim().toLowerCase();
                let visible = 0;

                productResults.forEach((result) => {
                    const matches = needle === '' || result.dataset.productSearch.includes(needle);
                    result.hidden = ! matches || visible >= 120;
                    if (matches) visible += 1;
                });
            }

            function createLine() {
                if (! documentLines || ! documentLineTemplate) return null;

                const wrapper = document.createElement('div');
                wrapper.innerHTML = documentLineTemplate.innerHTML.replaceAll('__INDEX__', String(nextDocumentLineIndex));
                nextDocumentLineIndex += 1;

                const line = wrapper.firstElementChild;
                documentLines.appendChild(line);
                refreshDocumentLineButtons();

                return line;
            }

            function emptyReusableLine() {
                const rows = Array.from(documentLines?.querySelectorAll('[data-document-line]') || []);

                return rows.find((line) => {
                    const productId = line.querySelector('[data-document-product-id]')?.value || '';
                    const quantity = line.querySelector('[data-document-quantity]')?.value || '';
                    const price = line.querySelector('[data-document-price]')?.value || '';
                    const location = line.querySelector('[data-document-location]')?.value || '';

                    return productId === '' && quantity === '' && price === '' && location === '';
                }) || null;
            }

            function setLineProduct(line, product) {
                const input = line?.querySelector('[data-document-product-id]');
                const label = line?.querySelector('[data-document-product-label]');
                if (input) input.value = product.id;
                if (label) {
                    label.textContent = '';
                    const sku = document.createElement('strong');
                    sku.textContent = product.sku || '';
                    const name = document.createElement('span');
                    name.textContent = product.name || '';
                    label.append(sku, name);
                }
                refreshLineStock(line);
            }

            function productFromResult(result) {
                return {
                    id: result.dataset.productId || '',
                    sku: result.dataset.productSku || '',
                    name: result.dataset.productName || '',
                };
            }

            function addSelectedProducts() {
                const selected = productResults
                    .filter((result) => result.querySelector('[data-product-picker-checkbox]')?.checked)
                    .map(productFromResult);

                if (selected.length === 0) return;

                let firstLine = null;
                let targetLine = activeLine || emptyReusableLine();

                selected.forEach((product, index) => {
                    const line = index === 0 && targetLine ? targetLine : createLine();
                    if (! line) return;

                    setLineProduct(line, product);
                    firstLine ??= line;
                });

                closeProductPicker(firstLine);
            }

            function orderedLineInputs() {
                return Array.from(form?.querySelectorAll('[data-document-quantity], [data-document-price], [data-document-location]') || [])
                    .filter((input) => ! input.disabled && input.offsetParent !== null);
            }

            function handleLineInputTab(event) {
                if (event.key !== 'Tab') return;
                if (! event.target.matches('[data-document-quantity], [data-document-price], [data-document-location]')) return;

                const inputs = orderedLineInputs();
                const currentIndex = inputs.indexOf(event.target);
                if (currentIndex === -1) return;

                const nextIndex = event.shiftKey ? currentIndex - 1 : currentIndex + 1;
                const nextInput = inputs[nextIndex];
                if (! nextInput) return;

                event.preventDefault();
                nextInput.focus();
                if (typeof nextInput.select === 'function') nextInput.select();
            }

            if (typeControl) {
                typeControl.addEventListener('change', refreshDocumentTypeFields);
                refreshDocumentTypeFields();
            }

            sourceSelect?.addEventListener('change', refreshAllLineStocks);
            destinationSelect?.addEventListener('change', refreshAllLineStocks);

            if (documentLines && addDocumentLineButton && documentLineTemplate) {
                addDocumentLineButton.addEventListener('click', () => {
                    openProductPicker(null, 'add');
                });

                documentLines.addEventListener('click', (event) => {
                    const pickerButton = event.target.closest('[data-open-product-picker]');
                    if (pickerButton) {
                        openProductPicker(pickerButton.closest('[data-document-line]'), 'replace');
                        return;
                    }

                    const removeButton = event.target.closest('[data-remove-document-line]');
                    if (! removeButton) return;

                    const rows = documentLines.querySelectorAll('[data-document-line]');
                    if (rows.length <= 1) return;

                    removeButton.closest('[data-document-line]').remove();
                    refreshDocumentLineButtons();
                });

                refreshDocumentLineButtons();
            }

            searchInput?.addEventListener('input', () => filterProducts(searchInput.value));

            productResults.forEach((result) => {
                const checkbox = result.querySelector('[data-product-picker-checkbox]');

                result.addEventListener('click', (event) => {
                    if (pickerMode === 'replace' && event.detail >= 2 && activeLine) {
                        setLineProduct(activeLine, productFromResult(result));
                        closeProductPicker(activeLine);
                        return;
                    }

                    if (event.target !== checkbox && checkbox) {
                        checkbox.checked = ! checkbox.checked;
                        event.preventDefault();
                    }

                    result.classList.toggle('selected', Boolean(checkbox?.checked));
                    updateSelectionCounter();
                });
            });

            document.querySelectorAll('[data-close-product-picker]').forEach((button) => {
                button.addEventListener('click', () => closeProductPicker());
            });
            document.querySelector('[data-add-selected-products]')?.addEventListener('click', addSelectedProducts);
            modal?.addEventListener('click', (event) => {
                if (event.target === modal) closeProductPicker();
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && modal?.classList.contains('open')) closeProductPicker();
            });

            form?.addEventListener('submit', () => {
                refreshDocumentTypeFields();
            });
            form?.addEventListener('keydown', handleLineInputTab);

            refreshAllLineStocks();
        })();
    </script>
@endpush
