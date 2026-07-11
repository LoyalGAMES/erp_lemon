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
            'stock' => (float) $variant->stockBalances->sum('quantity_available'),
            'existing' => true,
        ])->values()->all()
        : [];

    if (old('variant_skus')) {
        $variantRows = collect((array) old('variant_skus'))
            ->map(function ($sku) use ($lookupBySku): array {
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
                    'stock' => null,
                    'existing' => $sku !== '',
                ];
            })
            ->filter(fn (array $row): bool => $row['sku'] !== '')
            ->values()
            ->all();
    }

    $emptyVariantRows = max(3, 6 - count($variantRows));

    for ($index = 0; $index < $emptyVariantRows; $index++) {
        $variantRows[] = ['id' => null, 'sku' => '', 'label' => '', 'name' => '', 'ean' => null, 'regular_price' => null, 'sale_price' => null, 'stock' => null, 'existing' => false];
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
            .variant-editor-actions .toggle-row { justify-content: flex-end; }
            .related-picker-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
            @media (max-width: 820px) {
                .variant-editor-row { grid-template-columns: 1fr; }
                .variant-editor-actions { justify-items: start; }
                .variant-editor-actions .toggle-row { justify-content: flex-start; }
            }
            @media (max-width: 720px) {
                .related-picker-grid { grid-template-columns: 1fr; }
            }
        </style>
    @endpush
@endonce

<div class="variant-editor">
    <div class="variant-editor-head">
        <div>
            <strong>Warianty produktu</strong>
            <div class="toolbar-note">Wyszukaj istniejące SKU i zapisz produkt. Odłączenie wariantu usuwa tylko powiązanie wariantowe, nie usuwa produktu z ERP.</div>
        </div>
    </div>

    <div class="variant-editor-list">
        @foreach ($variantRows as $index => $row)
            <div class="variant-editor-row @if ($row['sku'] === '') is-empty @endif">
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
                            <span>Stan: <strong>{{ number_format((float) $row['stock'], 0, ',', ' ') }}</strong></span>
                        @endif
                        @if ($row['name'] !== '')
                            <span>{{ $row['name'] }}</span>
                        @endif
                    </div>
                </div>
                <div class="variant-editor-actions">
                    <input type="hidden" name="variant_remove[{{ $index }}]" value="0">
                    @if ($row['sku'] !== '')
                        @if ($row['id'])
                            <a class="button secondary" href="{{ route('products.edit', $row['id']) }}">Edytuj cenę, EAN, SKU i stan</a>
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
