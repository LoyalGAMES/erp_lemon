@php
    $stockOwner = $stockOwner ?? $product;
    $stockVariants = $stockOwner->relationLoaded('variantChildren')
        ? $stockOwner->variantChildren
        : $stockOwner->variantChildren()->with(['stockBalances.warehouse'])->get();
    $stockWarehouses = collect($stockWarehouses ?? $warehouses ?? \App\Models\Warehouse::query()->where('is_active', true)->orderBy('code')->get());
    $stockQty = fn ($value): string => rtrim(rtrim(number_format((float) $value, 4, ',', ' '), '0'), ',') ?: '0';
    $variantAttribute = trim((string) data_get($stockOwner->masterData(), 'variant_attribute', '')) ?: 'Wariant';
    $variantLabel = function ($variant) use ($variantAttribute): string {
        $parameters = collect(data_get($variant->masterData(), 'parameters', []))
            ->filter(fn ($parameter): bool => is_array($parameter));
        $parameter = $parameters->first(
            fn (array $parameter): bool => mb_strtolower(trim((string) ($parameter['name'] ?? ''))) === mb_strtolower($variantAttribute)
        ) ?? $parameters->first(fn (array $parameter): bool => (bool) ($parameter['variation'] ?? false));
        $value = trim((string) data_get($parameter, 'value', ''));

        if ($value === '') {
            $wooAttribute = collect($variant->wooVariationAttributes())->first(
                fn (array $attribute): bool => mb_strtolower(trim((string) ($attribute['name'] ?? ''))) === mb_strtolower($variantAttribute)
            ) ?? collect($variant->wooVariationAttributes())->first();
            $value = trim((string) ($wooAttribute['option'] ?? ''));
        }

        return $value !== '' ? $value : $variant->name;
    };
@endphp

@include('products._stock_adjust_assets')

@if ($stockVariants->isNotEmpty())
    <div class="variant-stock-management">
        <div class="variant-stock-management-head">
            <div>
                <strong>Stany magazynowe wariantów</strong>
                <div class="toolbar-note">Każdy wariant i magazyn edytujesz w jednym wierszu. Wpisujesz stan ogółem (fizyczny). ERP odejmuje rezerwacje i synchronizuje z WooCommerce wyłącznie stan dostępny do sprzedaży. Ustawienie stanu tworzy korektę KOR.</div>
            </div>
        </div>
        <div class="table-scroll">
            <table class="dense-table stock-adjust-table variant-stock-table" data-variant-stock-table>
                <thead>
                    <tr>
                        <th>{{ $variantAttribute }}</th>
                        <th>SKU / EAN</th>
                        <th>Magazyn</th>
                        <th class="numeric">Stan</th>
                        <th class="numeric">Rezerwacje</th>
                        <th class="numeric">Dostępne</th>
                        <th>Nowy stan ogółem</th>
                        <th>Edycja</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stockVariants as $variant)
                        @php
                            $balanceByWarehouse = $variant->stockBalances->keyBy(fn ($balance) => (string) $balance->warehouse_id);
                            $displayVariantLabel = $variantLabel($variant);
                            $warehouseRowspan = max(1, $stockWarehouses->count());
                        @endphp
                        @forelse ($stockWarehouses as $warehouse)
                            <tr data-variant-stock-row>
                                @if ($loop->first)
                                    <td class="variant-stock-name" rowspan="{{ $warehouseRowspan }}">
                                        <strong>{{ $displayVariantLabel }}</strong>
                                        @if ($displayVariantLabel !== $variant->name)
                                            <small>{{ $variant->name }}</small>
                                        @endif
                                    </td>
                                    <td class="variant-stock-identifiers" rowspan="{{ $warehouseRowspan }}">
                                        <strong>{{ $variant->displaySku() ?: '-' }}</strong>
                                        <small>EAN: {{ $variant->ean ?: '-' }}</small>
                                    </td>
                                @endif
                                @php
                                    $balance = $balanceByWarehouse->get((string) $warehouse->id);
                                    $currentOnHand = (float) ($balance?->quantity_on_hand ?? 0);
                                @endphp
                                <td class="variant-stock-warehouse"><strong>{{ $warehouse->code }}</strong><small>{{ $warehouse->name }}</small></td>
                                <td class="numeric variant-stock-quantity">{{ $stockQty($currentOnHand) }}</td>
                                <td class="numeric variant-stock-quantity">{{ $stockQty($balance?->quantity_reserved ?? 0) }}</td>
                                <td class="numeric variant-stock-quantity">{{ $stockQty($balance?->quantity_available ?? 0) }}</td>
                                <td class="variant-stock-adjust" data-stock-adjust-row>
                                    <div class="variant-stock-quick-action">
                                        <input
                                            data-stock-adjust-quantity
                                            type="number"
                                            min="0"
                                            step="0.0001"
                                            value="{{ $currentOnHand }}"
                                            aria-label="Nowy stan ogółem {{ $variant->sku }} w {{ $warehouse->code }}"
                                        >
                                        <button
                                            class="button secondary"
                                            type="button"
                                            data-stock-adjust-submit
                                            data-action="{{ route('products.stock.adjust', $variant) }}"
                                            data-warehouse-id="{{ $warehouse->id }}"
                                            data-warehouse-code="{{ $warehouse->code }}"
                                            data-product-sku="{{ $variant->sku }}"
                                            data-redirect-url="{{ request()->fullUrl() }}"
                                        >Ustaw</button>
                                    </div>
                                    <div class="stock-adjust-error" data-stock-adjust-error></div>
                                </td>
                                @if ($loop->first)
                                    <td rowspan="{{ $warehouseRowspan }}"><a class="button secondary" href="{{ route('products.edit', $variant) }}">Edytuj</a></td>
                                @endif
                            </tr>
                        @empty
                            <tr data-variant-stock-row>
                                <td class="variant-stock-name">
                                    <strong>{{ $displayVariantLabel }}</strong>
                                    @if ($displayVariantLabel !== $variant->name)
                                        <small>{{ $variant->name }}</small>
                                    @endif
                                </td>
                                <td class="variant-stock-identifiers">
                                    <strong>{{ $variant->displaySku() ?: '-' }}</strong>
                                    <small>EAN: {{ $variant->ean ?: '-' }}</small>
                                </td>
                                <td colspan="5" class="toolbar-note">Brak aktywnego magazynu do ręcznej korekty stanu.</td>
                                <td><a class="button secondary" href="{{ route('products.edit', $variant) }}">Edytuj</a></td>
                            </tr>
                        @endforelse
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@else
    @include('products._stock_readonly_panel', [
        'stockProduct' => $stockOwner,
        'stockWarehouses' => $stockWarehouses,
    ])
@endif
