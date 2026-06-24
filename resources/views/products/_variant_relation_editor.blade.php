@php
    $lookupBySku = collect($productLookupOptions ?? [])->keyBy('sku');
    $variantRows = $product->relationLoaded('variantChildren')
        ? $product->variantChildren->map(fn ($variant): array => [
            'sku' => $variant->sku,
            'label' => $variant->sku . ' | ' . $variant->name,
            'name' => $variant->name,
            'ean' => $variant->ean,
        ])->values()->all()
        : [];

    if (old('variant_skus')) {
        $variantRows = collect((array) old('variant_skus'))
            ->map(function ($sku) use ($lookupBySku): array {
                $sku = trim((string) $sku);
                $lookup = $lookupBySku->get($sku);

                return [
                    'sku' => $sku,
                    'label' => $lookup['label'] ?? $sku,
                    'name' => $lookup['name'] ?? '',
                    'ean' => $lookup['ean'] ?? null,
                ];
            })
            ->filter(fn (array $row): bool => $row['sku'] !== '')
            ->values()
            ->all();
    }

    $emptyVariantRows = max(3, 6 - count($variantRows));

    for ($index = 0; $index < $emptyVariantRows; $index++) {
        $variantRows[] = ['sku' => '', 'label' => '', 'name' => '', 'ean' => null];
    }
@endphp

@once
    @push('styles')
        <style>
            .variant-editor { display: grid; gap: 12px; }
            .variant-editor-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
            .variant-editor-table input { min-width: 180px; }
            .variant-editor-table .variant-lookup-cell { min-width: 300px; }
            .related-picker-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
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
            <div class="toolbar-note">Warianty są osobnymi SKU w ERP i zostaną utworzone jako warianty produktu w WooCommerce.</div>
        </div>
    </div>
    <div class="table-scroll variant-editor-table">
        <table class="dense-table">
            <thead>
                <tr>
                    <th>Produkt wariantowy</th>
                    <th>SKU</th>
                    <th>EAN</th>
                    <th>Usuń relację</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($variantRows as $index => $row)
                    <tr>
                        <td class="variant-lookup-cell">
                            <input
                                value="{{ $row['label'] }}"
                                list="product-lookup-options"
                                placeholder="Wpisz SKU, nazwę lub kategorię"
                                data-product-sku-lookup
                                autocomplete="off"
                            >
                            <input type="hidden" name="variant_skus[{{ $index }}]" value="{{ $row['sku'] }}" data-product-sku-hidden>
                        </td>
                        <td>{{ $row['sku'] ?: '-' }}</td>
                        <td>{{ $row['ean'] ?: '-' }}</td>
                        <td>
                            <input type="hidden" name="variant_remove[{{ $index }}]" value="0">
                            <label class="toggle-row"><input name="variant_remove[{{ $index }}]" type="checkbox" value="1" @disabled($row['sku'] === '')> Tak</label>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
