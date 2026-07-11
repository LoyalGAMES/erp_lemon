@php
    $showRelatedUpsells = $showRelatedUpsells ?? true;
    $showRelatedCrossSells = $showRelatedCrossSells ?? true;
    $relatedProductOptions = collect($productLookupOptions ?? [])
        ->map(fn (array $product): array => [
            'sku' => (string) ($product['sku'] ?? ''),
            'name' => (string) ($product['name'] ?? ''),
            'ean' => isset($product['ean']) ? (string) $product['ean'] : null,
            'category' => isset($product['category']) ? (string) $product['category'] : null,
        ])
        ->filter(fn (array $product): bool => $product['sku'] !== '')
        ->values();
@endphp

@once
    @push('styles')
        <style>
            .related-products-manager { display: grid; gap: 12px; }
            .related-products-actions { display: flex; gap: 10px; flex-wrap: wrap; }
            .related-products-selected { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
            .related-products-selection { min-height: 94px; padding: 10px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; display: grid; align-content: start; gap: 8px; }
            .related-products-selection strong { font-size: 13px; }
            .related-products-pills { display: flex; gap: 6px; flex-wrap: wrap; }
            .related-product-pill { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; padding: 5px 7px 5px 9px; border: 1px solid var(--border); border-radius: 999px; background: #fff; color: var(--text); font-size: 12px; }
            .related-product-pill span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .related-product-pill button { width: 20px; height: 20px; border: 0; border-radius: 50%; background: var(--surface-soft); color: var(--muted); font: inherit; cursor: pointer; }
            .related-products-empty { color: var(--muted); font-size: 12px; }
            .related-products-modal { position: fixed; inset: 0; z-index: 120; display: grid; place-items: center; padding: 20px; background: rgba(37, 31, 26, .62); }
            .related-products-modal[hidden] { display: none; }
            .related-products-modal-card { width: min(780px, 96vw); max-height: min(760px, 92vh); overflow: hidden; display: grid; grid-template-rows: auto auto minmax(0, 1fr) auto; border-radius: 10px; background: var(--surface); box-shadow: 0 24px 70px rgba(0, 0, 0, .32); }
            .related-products-modal-header, .related-products-modal-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--border); }
            .related-products-modal-footer { border-top: 1px solid var(--border); border-bottom: 0; }
            .related-products-modal-search { padding: 12px 16px; border-bottom: 1px solid var(--border); }
            .related-products-modal-search input { width: 100%; }
            .related-products-modal-list { overflow: auto; display: grid; align-content: start; }
            .related-products-modal-row { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 11px; align-items: start; padding: 12px 16px; border-bottom: 1px solid var(--border); cursor: pointer; }
            .related-products-modal-row:hover { background: var(--green-soft); }
            .related-products-modal-row input { margin-top: 4px; }
            .related-products-modal-row strong, .related-products-modal-row span { display: block; }
            .related-products-modal-row span { margin-top: 3px; color: var(--muted); font-size: 12px; }
            .related-products-modal-row[hidden] { display: none; }
            .related-products-modal-close { width: 34px; height: 34px; border: 1px solid var(--border); border-radius: 8px; background: #fff; color: var(--muted); font: inherit; font-size: 22px; cursor: pointer; }
            @media (max-width: 720px) {
                .related-products-selected { grid-template-columns: 1fr; }
                .related-products-modal { padding: 0; align-items: end; }
                .related-products-modal-card { width: 100vw; max-height: 94dvh; border-radius: 14px 14px 0 0; }
                .related-products-modal-header, .related-products-modal-footer { padding: 12px; }
                .related-products-modal-row { padding: 12px; }
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            (() => {
                document.querySelectorAll('[data-related-products-manager]').forEach((manager) => {
                    const options = JSON.parse(manager.dataset.relatedProducts || '[]');
                    const optionBySku = new Map(options.map((option) => [option.sku, option]));
                    const modal = manager.querySelector('[data-related-products-modal]');
                    const title = manager.querySelector('[data-related-products-title]');
                    const search = manager.querySelector('[data-related-products-search]');
                    const rows = Array.from(manager.querySelectorAll('[data-related-products-row]'));
                    const selectedContainers = {
                        upsell: manager.querySelector('[data-related-products-selected="upsell"]'),
                        cross_sell: manager.querySelector('[data-related-products-selected="cross_sell"]'),
                    };
                    const inputs = {
                        upsell: manager.querySelector('[data-related-products-input="upsell"]'),
                        cross_sell: manager.querySelector('[data-related-products-input="cross_sell"]'),
                    };
                    const labels = {
                        upsell: 'sprzedaży dodatkowej',
                        cross_sell: 'sprzedaży krzyżowej',
                    };
                    const values = {
                        upsell: splitSkus(inputs.upsell?.value),
                        cross_sell: splitSkus(inputs.cross_sell?.value),
                    };
                    let activeKind = 'upsell';

                    function splitSkus(value) {
                        return Array.from(new Set(String(value || '')
                            .split(/[\r\n,;]+/)
                            .map((sku) => sku.trim())
                            .filter(Boolean)));
                    }

                    function labelForSku(sku) {
                        const option = optionBySku.get(sku);
                        return option ? `${option.sku} · ${option.name}` : sku;
                    }

                    function render(kind) {
                        const container = selectedContainers[kind];
                        const input = inputs[kind];

                        if (!container || !input) return;

                        input.value = values[kind].join('\n');
                        container.innerHTML = '';

                        if (values[kind].length === 0) {
                            const empty = document.createElement('span');
                            empty.className = 'related-products-empty';
                            empty.textContent = 'Nie wybrano produktów.';
                            container.append(empty);
                            return;
                        }

                        values[kind].forEach((sku) => {
                            const pill = document.createElement('span');
                            pill.className = 'related-product-pill';
                            const label = document.createElement('span');
                            label.textContent = labelForSku(sku);
                            const remove = document.createElement('button');
                            remove.type = 'button';
                            remove.textContent = '×';
                            remove.setAttribute('aria-label', `Usuń ${labelForSku(sku)}`);
                            remove.addEventListener('click', () => {
                                values[kind] = values[kind].filter((item) => item !== sku);
                                render(kind);
                            });
                            pill.append(label, remove);
                            container.append(pill);
                        });
                    }

                    function filterRows() {
                        const needle = String(search?.value || '').trim().toLocaleLowerCase('pl');

                        rows.forEach((row) => {
                            row.hidden = needle !== '' && ! String(row.dataset.relatedProductsSearch || '').includes(needle);
                        });
                    }

                    function close() {
                        if (!modal) return;
                        modal.hidden = true;
                        modal.setAttribute('aria-hidden', 'true');
                    }

                    function open(kind) {
                        activeKind = kind;
                        if (!modal) return;
                        title.textContent = `Wybierz produkty ${labels[kind]}`;
                        rows.forEach((row) => {
                            const checkbox = row.querySelector('[data-related-products-checkbox]');
                            if (checkbox) checkbox.checked = values[kind].includes(checkbox.value);
                        });
                        if (search) search.value = '';
                        filterRows();
                        modal.hidden = false;
                        modal.setAttribute('aria-hidden', 'false');
                        window.setTimeout(() => search?.focus(), 0);
                    }

                    manager.querySelectorAll('[data-related-products-open]').forEach((button) => {
                        button.addEventListener('click', () => open(button.dataset.relatedProductsOpen || 'upsell'));
                    });
                    manager.querySelectorAll('[data-related-products-close]').forEach((button) => button.addEventListener('click', close));
                    manager.querySelector('[data-related-products-apply]')?.addEventListener('click', () => {
                        values[activeKind] = rows
                            .map((row) => row.querySelector('[data-related-products-checkbox]'))
                            .filter((checkbox) => checkbox?.checked)
                            .map((checkbox) => checkbox.value);
                        render(activeKind);
                        close();
                    });
                    search?.addEventListener('input', filterRows);
                    modal?.addEventListener('click', (event) => {
                        if (event.target === modal) close();
                    });
                    document.addEventListener('keydown', (event) => {
                        if (event.key === 'Escape' && modal && !modal.hidden) close();
                    });

                    render('upsell');
                    render('cross_sell');
                });
            })();
        </script>
    @endpush
@endonce

<div class="related-products-manager" data-related-products-manager data-related-products='@json($relatedProductOptions)'>
    @if ($showRelatedUpsells)
        <input type="hidden" name="related_upsell_skus" value="{{ $relatedUpsells }}" data-related-products-input="upsell">
    @endif
    @if ($showRelatedCrossSells)
        <input type="hidden" name="related_cross_sell_skus" value="{{ $relatedCrossSells }}" data-related-products-input="cross_sell">
    @endif

    <div class="related-products-actions">
        @if ($showRelatedUpsells)
            <button class="button secondary" type="button" data-related-products-open="upsell">Wybierz sprzedaż dodatkową</button>
        @endif
        @if ($showRelatedCrossSells)
            <button class="button secondary" type="button" data-related-products-open="cross_sell">Wybierz sprzedaż krzyżową</button>
        @endif
    </div>

    <div class="related-products-selected">
        @if ($showRelatedUpsells)
            <section class="related-products-selection">
                <strong>Sprzedaż dodatkowa</strong>
                <div class="related-products-pills" data-related-products-selected="upsell"></div>
            </section>
        @endif
        @if ($showRelatedCrossSells)
            <section class="related-products-selection">
                <strong>Sprzedaż krzyżowa</strong>
                <div class="related-products-pills" data-related-products-selected="cross_sell"></div>
            </section>
        @endif
    </div>

    <div class="related-products-modal" data-related-products-modal hidden aria-hidden="true">
        <div class="related-products-modal-card" role="dialog" aria-modal="true" aria-labelledby="related-products-modal-title">
            <div class="related-products-modal-header">
                <strong id="related-products-modal-title" data-related-products-title>Wybierz produkty</strong>
                <button class="related-products-modal-close" type="button" data-related-products-close aria-label="Zamknij">×</button>
            </div>
            <div class="related-products-modal-search">
                <input type="search" data-related-products-search placeholder="Szukaj po SKU, EAN, nazwie lub kategorii" autocomplete="off">
            </div>
            <div class="related-products-modal-list">
                @forelse ($relatedProductOptions as $lookupProduct)
                    @php
                        $searchText = mb_strtolower(implode(' ', array_filter([
                            $lookupProduct['sku'],
                            $lookupProduct['name'],
                            $lookupProduct['ean'],
                            $lookupProduct['category'],
                        ])));
                    @endphp
                    <label class="related-products-modal-row" data-related-products-row data-related-products-search="{{ $searchText }}">
                        <input type="checkbox" value="{{ $lookupProduct['sku'] }}" data-related-products-checkbox>
                        <span>
                            <strong>{{ $lookupProduct['sku'] }} · {{ $lookupProduct['name'] }}</strong>
                            <span>EAN: {{ $lookupProduct['ean'] ?: '—' }}@if ($lookupProduct['category']) · {{ $lookupProduct['category'] }}@endif</span>
                        </span>
                    </label>
                @empty
                    <div class="toolbar-note" style="padding: 16px;">Brak innych produktów do wyboru.</div>
                @endforelse
            </div>
            <div class="related-products-modal-footer">
                <button class="button secondary" type="button" data-related-products-close>Anuluj</button>
                <button class="button" type="button" data-related-products-apply>Dodaj zaznaczone</button>
            </div>
        </div>
    </div>
</div>
