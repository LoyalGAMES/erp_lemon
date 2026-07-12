@once
    @push('styles')
        <style>
            .stock-readonly-panel { display: grid; gap: 12px; padding: 12px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; }
            .stock-readonly-summary { display: flex; gap: 8px; flex-wrap: wrap; }
            .stock-readonly-summary .stock-pill { border: 1px solid var(--border); border-radius: 8px; padding: 7px 9px; background: #fff; color: var(--muted); font-size: 12px; margin: 0; }
            .stock-readonly-summary .stock-pill strong { color: var(--text); font-size: 15px; margin-left: 4px; }
            .stock-readonly-summary .stock-pill.available strong { color: var(--green-dark); }
            .stock-readonly-note { color: var(--muted); font-size: 13px; }
            .stock-adjust-table input { min-width: 112px; min-height: 34px; }
            .stock-adjust-table .button { min-height: 34px; white-space: nowrap; }
            .stock-adjust-error { color: var(--red); font-size: 12px; min-height: 16px; }
            .variant-stock-management { display: grid; gap: 10px; }
            .variant-stock-management-head { display: flex; justify-content: space-between; gap: 10px; align-items: end; flex-wrap: wrap; }
            .variant-stock-table th { vertical-align: bottom; white-space: nowrap; }
            .variant-stock-table th small { display: block; margin-top: 2px; color: var(--muted); font-size: 10px; font-weight: 600; }
            .variant-stock-table td { vertical-align: middle; }
            .variant-stock-table .variant-stock-name { min-width: 190px; }
            .variant-stock-table .variant-stock-name strong,
            .variant-stock-table .variant-stock-identifiers strong { display: block; }
            .variant-stock-table .variant-stock-name small,
            .variant-stock-table .variant-stock-identifiers small { display: block; margin-top: 2px; color: var(--muted); font-size: 11px; }
            .variant-stock-table .variant-stock-identifiers { min-width: 150px; }
            .variant-stock-table .variant-stock-warehouse { min-width: 145px; }
            .variant-stock-table .variant-stock-warehouse small { display: block; margin-top: 2px; color: var(--muted); font-size: 11px; }
            .variant-stock-table .variant-stock-quantity { min-width: 76px; }
            .variant-stock-table .variant-stock-adjust { min-width: 190px; }
            .variant-stock-quick-action { display: flex; gap: 6px; align-items: center; }
            .variant-stock-quick-action input { width: 92px; min-width: 92px; }
            .variant-stock-table .stock-adjust-error { min-height: 0; margin-top: 3px; }
            @media (max-width: 980px) {
                .variant-stock-management > .table-scroll { overflow: visible; }
                .variant-stock-table { display: block; min-width: 0 !important; border: 0; background: transparent; }
                .variant-stock-table thead { display: none; }
                .variant-stock-table tbody { display: grid; gap: 10px; }
                .variant-stock-table tr[data-variant-stock-row] { display: block; overflow: hidden; border: 1px solid var(--border); border-radius: 8px; background: #fff; box-shadow: 0 4px 12px rgba(67, 53, 43, .05); }
                .variant-stock-table tr[data-variant-stock-row] > td { display: grid; grid-template-columns: minmax(92px, .42fr) minmax(0, 1fr); gap: 10px; align-items: center; width: 100%; min-width: 0; padding: 9px 11px; border: 0; border-bottom: 1px solid rgba(216, 206, 198, .72); text-align: left; white-space: normal; }
                .variant-stock-table tr[data-variant-stock-row] > td:last-child { border-bottom: 0; }
                .variant-stock-table tr[data-variant-stock-row] > td::before { content: attr(data-label); color: var(--muted); font-size: 11px; font-weight: 760; line-height: 1.25; }
                .variant-stock-table .variant-stock-name,
                .variant-stock-table .variant-stock-identifiers,
                .variant-stock-table .variant-stock-warehouse,
                .variant-stock-table .variant-stock-quantity,
                .variant-stock-table .variant-stock-adjust { min-width: 0; }
                .variant-stock-table .variant-stock-name { background: rgba(134, 115, 100, .06); }
                .variant-stock-table .variant-stock-quantity { font-size: 15px; font-weight: 800; }
                .variant-stock-table .variant-stock-adjust { align-items: start; }
                .variant-stock-table .variant-stock-adjust > * { min-width: 0; }
                .variant-stock-quick-action { align-items: stretch; }
                .variant-stock-quick-action input { flex: 1 1 auto; width: 100%; min-width: 0; }
                .variant-stock-table input { min-height: 42px; font-size: 16px; }
                .variant-stock-table .button { min-height: 42px; }
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            (() => {
                const token = @json(csrf_token());

                document.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter') return;

                    const field = event.target.closest('[data-stock-adjust-quantity]');

                    if (!field || field.disabled) return;

                    event.preventDefault();
                    field.blur();
                    field.closest('[data-stock-adjust-row]')?.querySelector('[data-stock-adjust-submit]')?.click();
                });

                document.addEventListener('input', (event) => {
                    const field = event.target.closest('[data-stock-adjust-quantity]');

                    if (!field) return;

                    const row = field.closest('[data-stock-adjust-row]');
                    const error = row?.querySelector('[data-stock-adjust-error]');

                    field.removeAttribute('aria-invalid');
                    if (error) error.textContent = '';
                    if (row?.dataset.stockAdjustState !== 'submitting') row.dataset.stockAdjustState = 'idle';
                });

                document.addEventListener('click', (event) => {
                    const button = event.target.closest('[data-stock-adjust-submit]');

                    if (!button || button.disabled) return;

                    const row = button.closest('[data-stock-adjust-row]');
                    const error = row?.querySelector('[data-stock-adjust-error]');
                    const quantityInput = row?.querySelector('[data-stock-adjust-quantity]');
                    const action = button.dataset.action || '';
                    const warehouseId = button.dataset.warehouseId || '';
                    const productSku = button.dataset.productSku || '';
                    const warehouseCode = button.dataset.warehouseCode || '';
                    const quantity = String(quantityInput?.value || '').trim();
                    const showError = (message) => {
                        if (error) error.textContent = message;
                        quantityInput?.setAttribute('aria-invalid', 'true');
                        if (row) row.dataset.stockAdjustState = 'error';
                        quantityInput?.focus();
                    };

                    if (!action || !warehouseId || quantity === '') {
                        showError('Uzupełnij nowy stan ogółem.');
                        return;
                    }

                    if (!quantityInput?.checkValidity() || !Number.isFinite(Number(quantity)) || Number(quantity) < 0) {
                        showError('Wpisz prawidłowy stan równy lub większy od zera.');
                        quantityInput?.reportValidity();
                        return;
                    }

                    if (!confirm(`Ustawić stan ogółem ${quantity} dla SKU ${productSku} w magazynie ${warehouseCode}? ERP odejmie rezerwacje przed synchronizacją z WooCommerce.`)) {
                        return;
                    }

                    if (error) error.textContent = '';
                    quantityInput.removeAttribute('aria-invalid');
                    quantityInput.disabled = true;
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                    button.textContent = 'Zapisywanie…';
                    if (row) row.dataset.stockAdjustState = 'submitting';

                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = action;
                    form.hidden = true;

                    const fields = {
                        _token: token,
                        warehouse_id: warehouseId,
                        new_quantity: quantity,
                        redirect_url: button.dataset.redirectUrl || window.location.href,
                    };

                    Object.entries(fields).forEach(([name, value]) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = value;
                        form.append(input);
                    });

                    document.body.append(form);
                    form.submit();
                });
            })();
        </script>
    @endpush
@endonce
