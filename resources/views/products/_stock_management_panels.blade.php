@php
    $stockOwner = $stockOwner ?? $product;
    $stockVariants = $stockOwner->relationLoaded('variantChildren')
        ? $stockOwner->variantChildren
        : $stockOwner->variantChildren()->with(['stockBalances.warehouse'])->get();
@endphp

@once
    @push('styles')
        <style>
            .variant-stock-management { display: grid; gap: 14px; }
            .variant-stock-management-head { display: flex; justify-content: space-between; gap: 10px; align-items: end; flex-wrap: wrap; }
            .variant-stock-management-list { display: grid; gap: 14px; }
            .variant-stock-management-item { display: grid; gap: 9px; }
            .variant-stock-management-title { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
            .variant-stock-management-title span { color: var(--muted); font-size: 13px; }
        </style>
    @endpush
@endonce

@if ($stockVariants->isNotEmpty())
    <div class="variant-stock-management">
        <div class="variant-stock-management-head">
            <div>
                <strong>Stany magazynowe wariantów</strong>
                <div class="toolbar-note">Każdy rozmiar ma własne SKU i stan. Wszystkie stany produktu edytujesz w tej sekcji.</div>
            </div>
        </div>
        <div class="variant-stock-management-list">
            @foreach ($stockVariants as $variant)
                <section class="variant-stock-management-item">
                    <div class="variant-stock-management-title">
                        <div>
                            <strong>{{ $variant->name }}</strong>
                            <span>SKU: {{ $variant->sku }} · EAN: {{ $variant->ean ?: '-' }}</span>
                        </div>
                        <a class="button secondary" href="{{ route('products.edit', $variant) }}">Edytuj dane wariantu</a>
                    </div>
                    @include('products._stock_readonly_panel', [
                        'stockProduct' => $variant,
                        'stockWarehouses' => $warehouses ?? collect(),
                    ])
                </section>
            @endforeach
        </div>
    </div>
@else
    @include('products._stock_readonly_panel', [
        'stockProduct' => $stockOwner,
        'stockWarehouses' => $warehouses ?? collect(),
    ])
@endif
