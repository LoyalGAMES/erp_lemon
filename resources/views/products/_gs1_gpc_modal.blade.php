@php
    $productMaster = $product->masterData();
    $selectedGpcCode = old('gpc_code', data_get($productMaster, 'gs1.gpc_code') ?: ($gs1Settings['default_gpc_code'] ?? ''));
    $selectedGpcLabel = old('gpc_label', data_get($productMaster, 'gs1.gpc_label') ?: '');
    $gpcOptions = collect($gs1Settings['gpc_options'] ?? []);
    if ($selectedGpcLabel === '' && $selectedGpcCode !== '') {
        $selectedGpcLabel = (string) data_get($gpcOptions->firstWhere('code', $selectedGpcCode), 'label', '');
    }
@endphp

@once
    @push('styles')
        <style>
            .gs1-modal { position: fixed; inset: 0; z-index: 110; display: none; align-items: center; justify-content: center; padding: 18px; background: rgba(37, 31, 26, .58); }
            .gs1-modal.open { display: flex; }
            .gs1-modal-card { width: min(760px, 96vw); max-height: 90vh; overflow: hidden; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 24px 70px rgba(0, 0, 0, .22); display: grid; grid-template-rows: auto 1fr auto; }
            .gs1-modal-header, .gs1-modal-footer { padding: 14px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
            .gs1-modal-footer { border-top: 1px solid var(--border); border-bottom: 0; }
            .gs1-modal-header strong { font-size: 18px; }
            .gs1-modal-close { width: 38px; height: 38px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); font: inherit; font-size: 22px; line-height: 1; cursor: pointer; }
            .gs1-modal-body { padding: 16px; overflow: auto; display: grid; gap: 12px; }
            .gs1-gpc-search { display: grid; gap: 8px; }
            .gs1-gpc-list { display: grid; gap: 8px; max-height: 340px; overflow: auto; padding-right: 4px; }
            .gs1-filter-counter { color: var(--muted); font-size: 12px; font-weight: 720; }
            .gs1-gpc-option { width: 100%; text-align: left; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; padding: 11px 12px; cursor: pointer; display: grid; gap: 4px; }
            .gs1-gpc-option[hidden] { display: none; }
            .gs1-gpc-option:hover, .gs1-gpc-option.selected { border-color: rgba(134, 115, 100, .52); background: rgba(134, 115, 100, .09); }
            .gs1-gpc-option strong { font-size: 14px; }
            .gs1-gpc-option span { color: var(--muted); font-size: 12px; line-height: 1.35; }
            .gs1-selected { border: 1px solid rgba(134, 115, 100, .28); border-radius: 8px; padding: 10px 12px; background: rgba(134, 115, 100, .08); color: var(--text); font-weight: 760; }
            .gs1-empty { border: 1px dashed var(--border); border-radius: 8px; padding: 14px; color: var(--muted); background: #fffdfb; }
            @media (max-width: 640px) {
                .gs1-modal { padding: 0; align-items: stretch; }
                .gs1-modal-card { width: 100vw; max-height: 100vh; border-radius: 0; }
                .gs1-modal-footer { align-items: stretch; flex-direction: column; }
                .gs1-modal-footer .inline-actions { width: 100%; }
                .gs1-modal-footer .button { width: 100%; }
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            (() => {
                const modal = document.querySelector('[data-gs1-modal]');
                const openButtons = Array.from(document.querySelectorAll('[data-gs1-open-modal]'));
                const closeButtons = Array.from(document.querySelectorAll('[data-gs1-close-modal]'));
                const searchInput = document.querySelector('[data-gs1-search]');
                const manualInput = document.querySelector('[data-gs1-manual-code]');
                const form = document.querySelector('[data-gs1-form]');
                const selectedCodeInput = document.querySelector('[data-gs1-selected-code]');
                const selectedLabelInput = document.querySelector('[data-gs1-selected-label]');
                const selectedText = document.querySelector('[data-gs1-selected-text]');
                const options = Array.from(document.querySelectorAll('[data-gs1-gpc-option]'));
                const emptyFilterResult = document.querySelector('[data-gs1-filter-empty]');
                const filterCounter = document.querySelector('[data-gs1-filter-count]');
                const registerProducts = modal?.dataset.registerProducts === '1';

                if (!modal || !form || !selectedCodeInput || !selectedLabelInput || !selectedText) {
                    return;
                }

                function normalizeSearch(value) {
                    return String(value || '')
                        .toLocaleLowerCase('pl-PL')
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .replace(/ł/g, 'l')
                        .replace(/[^a-z0-9]+/g, ' ')
                        .replace(/\s+/g, ' ')
                        .trim();
                }

                function compactSearch(value) {
                    return normalizeSearch(value).replace(/\s+/g, '');
                }

                function tokenVariants(token) {
                    if (/^\d+$/.test(token) || token.length <= 3) {
                        return [token];
                    }

                    return [token, token.slice(0, -1)];
                }

                const searchableOptions = options.map((option) => ({
                    option,
                    haystack: normalizeSearch(`${option.dataset.code || ''} ${option.dataset.label || ''} ${option.dataset.description || ''}`),
                    compactHaystack: compactSearch(`${option.dataset.code || ''} ${option.dataset.label || ''} ${option.dataset.description || ''}`),
                }));

                function setSelected(code, label) {
                    selectedCodeInput.value = code || '';
                    selectedLabelInput.value = label || '';
                    selectedText.textContent = code ? `${code} - ${label || 'kod wpisany ręcznie'}` : 'Nie wybrano kodu GPC';
                    options.forEach((option) => option.classList.toggle('selected', option.dataset.code === code));
                }

                function openModal() {
                    modal.classList.add('open');
                    modal.setAttribute('aria-hidden', 'false');
                    window.setTimeout(() => searchInput?.focus(), 30);
                }

                function closeModal() {
                    modal.classList.remove('open');
                    modal.setAttribute('aria-hidden', 'true');
                }

                function filterOptions() {
                    const query = normalizeSearch(searchInput?.value || '');
                    const compactQuery = compactSearch(searchInput?.value || '');
                    const tokens = query.split(' ').filter(Boolean);
                    let visibleCount = 0;

                    searchableOptions.forEach(({ option, haystack, compactHaystack }) => {
                        const visible = query === ''
                            || haystack.includes(query)
                            || (compactQuery !== '' && compactHaystack.includes(compactQuery))
                            || tokens.every((token) => tokenVariants(token).some((variant) => (
                                haystack.includes(variant) || compactHaystack.includes(variant)
                            )));
                        option.hidden = !visible;

                        if (visible) {
                            visibleCount += 1;
                        }
                    });

                    if (filterCounter) {
                        filterCounter.textContent = query === ''
                            ? `Wszystkie kody: ${options.length}`
                            : `Znaleziono: ${visibleCount} z ${options.length}`;
                    }

                    if (emptyFilterResult) {
                        emptyFilterResult.hidden = query === '' || visibleCount > 0;
                    }
                }

                openButtons.forEach((button) => button.addEventListener('click', openModal));
                closeButtons.forEach((button) => button.addEventListener('click', closeModal));
                ['input', 'search', 'change', 'keyup'].forEach((eventName) => searchInput?.addEventListener(eventName, filterOptions));

                options.forEach((option) => {
                    option.addEventListener('click', () => {
                        setSelected(option.dataset.code || '', option.dataset.label || '');
                        if (manualInput) {
                            manualInput.value = '';
                        }
                    });
                });

                manualInput?.addEventListener('input', () => {
                    const code = manualInput.value.replace(/\D+/g, '').slice(0, 8);
                    manualInput.value = code;

                    if (code.length === 8) {
                        setSelected(code, 'kod wpisany ręcznie');
                    }
                });

                modal.addEventListener('click', (event) => {
                    if (event.target === modal) {
                        closeModal();
                    }
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && modal.classList.contains('open')) {
                        closeModal();
                    }
                });

                form.addEventListener('submit', (event) => {
                    if (registerProducts && !selectedCodeInput.value) {
                        event.preventDefault();
                        selectedText.textContent = 'Wybierz kod GPC albo wpisz ręcznie 8 cyfr.';
                        openModal();
                    }
                });

                setSelected(selectedCodeInput.value, selectedLabelInput.value);
                filterOptions();
            })();
        </script>
    @endpush
@endonce

<form
    id="product-gs1-ean-form"
    method="POST"
    action="{{ route('products.gs1.ean.generate', $product) }}"
    data-gs1-form
>
    @csrf
    <input type="hidden" name="gpc_code" value="{{ $selectedGpcCode }}" data-gs1-selected-code>
    <input type="hidden" name="gpc_label" value="{{ $selectedGpcLabel }}" data-gs1-selected-label>
</form>

<div class="gs1-modal" data-gs1-modal data-register-products="{{ ($gs1Settings['register_products'] ?? true) ? '1' : '0' }}" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Wybór kodu GPC">
    <div class="gs1-modal-card">
        <div class="gs1-modal-header">
            <div>
                <strong>Wybierz kod GPC dla produktu</strong>
                <div class="toolbar-note">{{ $product->sku }} · {{ $product->name }}</div>
            </div>
            <button class="gs1-modal-close" type="button" data-gs1-close-modal aria-label="Zamknij">×</button>
        </div>
        <div class="gs1-modal-body">
            <div class="gs1-selected" data-gs1-selected-text>Nie wybrano kodu GPC</div>
            <label class="gs1-gpc-search">Szukaj w kodach GPC
                <input type="search" data-gs1-search placeholder="Wpisz kod, nazwę albo fragment opisu" autocomplete="off">
            </label>
            <div class="gs1-filter-counter" data-gs1-filter-count></div>
            <div class="gs1-gpc-list">
                @forelse ($gpcOptions as $option)
                    <button
                        type="button"
                        class="gs1-gpc-option"
                        data-gs1-gpc-option
                        data-code="{{ $option['code'] }}"
                        data-label="{{ $option['label'] }}"
                        data-description="{{ $option['description'] }}"
                    >
                        <strong>{{ $option['code'] }} - {{ $option['label'] }}</strong>
                        @if ($option['description'] !== '')
                            <span>{{ $option['description'] }}</span>
                        @endif
                    </button>
                @empty
                    <div class="gs1-empty">Brak zapisanej listy GPC. Dodaj ją w Integracje -> Konto GS1 albo wpisz kod ręcznie poniżej.</div>
                @endforelse
                <div class="gs1-empty" data-gs1-filter-empty hidden>Brak kodów GPC pasujących do wpisanej frazy.</div>
            </div>
            <label>Wpisz kod ręcznie
                <input type="text" data-gs1-manual-code inputmode="numeric" maxlength="8" pattern="\d{8}" placeholder="8 cyfr, np. 10008067" autocomplete="off">
            </label>
        </div>
        <div class="gs1-modal-footer">
            <div class="toolbar-note">Kod GPC jest wymagany, gdy produkt ma zostać zarejestrowany w MojeGS1.</div>
            <div class="inline-actions">
                <button class="button secondary" type="button" data-gs1-close-modal>Anuluj</button>
                <button class="button" type="submit" form="product-gs1-ean-form">Wygeneruj EAN GS1</button>
            </div>
        </div>
    </div>
</div>
