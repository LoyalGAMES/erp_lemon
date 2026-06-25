@once
    @push('scripts')
        <script>
            (() => {
                function skuFromLookupValue(value) {
                    return String(value || '').split('|')[0].trim();
                }

                function syncSkuLookup(input) {
                    const hidden = input.closest('.variant-editor-main')?.querySelector('[data-product-sku-hidden]')
                        || input.parentElement?.querySelector('[data-product-sku-hidden]');

                    if (hidden) {
                        hidden.value = skuFromLookupValue(input.value);
                    }
                }

                document.querySelectorAll('[data-product-sku-lookup]').forEach((input) => {
                    input.addEventListener('change', () => syncSkuLookup(input));
                    input.addEventListener('input', () => syncSkuLookup(input));
                });

                document.querySelectorAll('[data-product-related-picker]').forEach((input) => {
                    input.addEventListener('change', () => {
                        const sku = skuFromLookupValue(input.value);
                        const targetName = input.dataset.productRelatedPicker;
                        const form = input.closest('form');
                        const target = form?.querySelector(`[name="${targetName}"]`);

                        if (!sku || !target) {
                            return;
                        }

                        const existing = String(target.value || '')
                            .split(/[\r\n,;]+/)
                            .map((item) => item.trim())
                            .filter(Boolean);

                        if (!existing.includes(sku)) {
                            existing.push(sku);
                        }

                        target.value = existing.join("\n");
                        input.value = '';
                    });
                });

                document.querySelectorAll('form').forEach((form) => {
                    form.addEventListener('submit', () => {
                        form.querySelectorAll('[data-product-sku-lookup]').forEach(syncSkuLookup);
                    });
                });
            })();
        </script>
    @endpush
@endonce

<div class="related-picker-grid">
    <label>Dodaj produkt upsell
        <input list="product-lookup-options" data-product-related-picker="related_upsell_skus" placeholder="Wpisz SKU lub nazwę" autocomplete="off">
    </label>
    <label>Dodaj produkt cross-sell
        <input list="product-lookup-options" data-product-related-picker="related_cross_sell_skus" placeholder="Wpisz SKU lub nazwę" autocomplete="off">
    </label>
</div>
