@extends('layouts.app', [
    'title' => $title,
    'subtitle' => $subtitle,
    'module' => 'products',
])

@push('styles')
    <style>
        .product-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .product-tabs button { border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; color: var(--text); background: var(--surface); font: inherit; font-weight: 760; cursor: pointer; }
        .product-tabs button:hover { color: var(--green-dark); border-color: rgba(134, 115, 100, .34); }
        .product-tabs button.active { color: var(--green-dark); background: var(--green-soft); border-color: rgba(134, 115, 100, .34); }
        .product-section { margin-bottom: 16px; }
        .product-section[hidden] { display: none; }
        .product-section-body { padding: 16px; display: grid; gap: 16px; }
        .detail-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .detail-item { border: 1px solid var(--border); border-radius: 8px; padding: 11px; background: #fffdfb; min-height: 70px; }
        .detail-item span { display: block; color: var(--muted); font-size: 12px; font-weight: 720; margin-bottom: 3px; }
        .detail-item strong { font-size: 14px; white-space: normal; overflow-wrap: anywhere; }
        .product-hero { display: grid; grid-template-columns: 180px minmax(0, 1fr); gap: 16px; }
        .product-photo-button { width: 180px; aspect-ratio: 4 / 5; border: 1px solid var(--border); border-radius: 8px; padding: 0; overflow: hidden; background: #f4f1ef; display: grid; place-items: center; color: var(--muted); font-weight: 850; cursor: pointer; }
        .product-photo-button img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .product-photo-button:disabled { cursor: default; }
        .product-title { color: var(--text); text-decoration: none; font-weight: 850; }
        .product-title:hover { color: var(--green-dark); }
        .stock-pills { display: flex; gap: 7px; flex-wrap: wrap; }
        .stock-pill { border: 1px solid var(--border); border-radius: 8px; padding: 7px 9px; background: #fffdfb; color: var(--muted); font-size: 12px; }
        .stock-pill strong { color: var(--text); font-size: 15px; margin-left: 4px; }
        .stock-pill.available strong { color: var(--green-dark); }
        .html-preview { border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; padding: 12px; white-space: pre-wrap; overflow-wrap: anywhere; color: #312b25; }
        .media-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 12px; }
        .media-button { border: 1px solid var(--border); border-radius: 8px; background: #f4f1ef; padding: 0; overflow: hidden; cursor: pointer; aspect-ratio: 4 / 5; }
        .media-button img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .channel-create-grid { padding: 16px; display: grid; gap: 10px; border-top: 1px solid var(--border); }
        .channel-create-row { border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: #fffdfb; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .channel-create-row strong { display: block; }
        .channel-create-row span { color: var(--muted); font-size: 12px; }
        .variant-relation-form { padding: 16px; border-top: 1px solid var(--border); display: grid; gap: 10px; background: #fffdfb; }
        .variant-relation-form form { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(180px, .7fr) auto; gap: 10px; align-items: end; }
        .image-modal { position: fixed; inset: 0; z-index: 90; display: none; align-items: center; justify-content: center; padding: 24px; background: rgba(37, 31, 26, .72); }
        .image-modal.open { display: flex; }
        .image-modal-card { max-width: min(760px, 94vw); max-height: 92vh; background: var(--surface); border-radius: 8px; overflow: hidden; box-shadow: 0 24px 70px rgba(0, 0, 0, .32); }
        .image-modal-header { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 10px 12px; border-bottom: 1px solid var(--border); font-weight: 780; }
        .image-modal-close { border: 0; background: transparent; font: inherit; font-size: 22px; cursor: pointer; color: var(--muted); }
        .image-modal img { display: block; width: 100%; max-height: 78vh; object-fit: contain; background: #f4f1ef; }
        @media (max-width: 980px) {
            .detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .product-hero { grid-template-columns: 1fr; }
            .product-photo-button { width: min(220px, 100%); }
            .variant-relation-form form { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .detail-grid { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')
    @php
        $master = $product->masterData();
        $qty = function ($value) use ($product): string {
            $number = (float) $value;
            $precision = max(0, min(4, (int) $product->quantity_precision));

            if ($precision === 0 || abs($number - round($number)) < 0.00001) {
                return number_format($number, 0, ',', ' ');
            }

            return rtrim(rtrim(number_format($number, $precision, ',', ' '), '0'), ',');
        };
        $money = function ($value, string $currency = 'PLN'): string {
            if ($value === null || $value === '') {
                return '-';
            }

            return number_format((float) $value, 2, ',', ' ') . ' ' . $currency;
        };
        $percent = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', ' '), '0'), ',') . '%';
        $images = $product->mediaImages();
        $imageUrl = $product->imageUrl();
        $onHand = $product->stockBalances->sum(fn ($balance) => (float) $balance->quantity_on_hand);
        $reserved = $product->stockBalances->sum(fn ($balance) => (float) $balance->quantity_reserved);
        $available = $product->stockBalances->sum(fn ($balance) => (float) $balance->quantity_available);
        $ordered = (float) data_get($master, 'stock.ordered_quantity', 0);
        $parameters = collect(data_get($master, 'parameters', []))->filter(fn ($row) => is_array($row));
        $tags = implode(', ', (array) data_get($master, 'tags', []));
        $sourceLabel = $product->isErpMaster() ? 'ERP' : 'Import WooCommerce';
        $displaySku = $product->displaySku();
        $externalId = $product->externalDisplayId();
    @endphp

    <div class="page-toolbar">
        <div class="inline-actions">
            <a class="button secondary" href="{{ route('products.index') }}">Wróć do produktów</a>
            <a class="button" href="{{ route('products.edit', $product) }}">Edytuj dane ERP</a>
            @if (! $product->ean)
                <button class="button secondary" type="button" data-gs1-open-modal>Wygeneruj EAN GS1</button>
            @endif
            <form method="POST" action="{{ route('products.duplicate', $product) }}">
                @csrf
                <button class="button secondary" type="submit">Kopiuj produkt</button>
            </form>
            @foreach ($availableWooCommerceCreateIntegrations as $integration)
                <form method="POST" action="{{ route('products.woocommerce.create', [$product, $integration]) }}" onsubmit="return confirm('Utworzyć ten produkt w kanale WooCommerce {{ $integration->salesChannel?->code ?? $integration->name }}?');">
                    @csrf
                    <button class="button" type="submit">Wyślij do sklepu {{ $integration->salesChannel?->code ?? 'WooCommerce' }}</button>
                </form>
            @endforeach
            @if ($product->channelMappings->isNotEmpty())
                <form method="POST" action="{{ route('products.woocommerce.export', $product) }}" onsubmit="return confirm('Wysłać dane produktu z ERP do powiązanych kanałów WooCommerce?');">
                    @csrf
                    <button class="button secondary" type="submit">Wyślij dane do WooCommerce</button>
                </form>
            @endif
            @if ($product->externalProductUrl())
                <a class="button secondary" href="{{ $product->externalProductUrl() }}" target="_blank" rel="noopener">Otwórz w sklepie</a>
            @endif
        </div>
        <div class="toolbar-note">Źródło danych głównych: {{ $sourceLabel }}</div>
    </div>

    @if (! $product->ean)
        @include('products._gs1_gpc_modal', ['product' => $product, 'gs1Settings' => $gs1Settings])
    @endif

    @if ($product->channelMappings->isEmpty() && $availableWooCommerceCreateIntegrations->isNotEmpty())
        <div class="alert">
            Ten produkt istnieje tylko w ERP. Żeby pojawił się w sklepie, użyj przycisku „Wyślij do sklepu” dla wybranego kanału.
        </div>
    @elseif ($product->channelMappings->isEmpty())
        <div class="alert">
            Ten produkt istnieje tylko w ERP, ale nie ma aktywnej integracji WooCommerce, do której można go wysłać.
        </div>
    @endif

    <nav class="product-tabs" aria-label="Zakładki produktu">
        <button class="active" type="button" data-product-view-tab="produkt" aria-selected="true">Produkt</button>
        <button type="button" data-product-view-tab="sprzedaz" aria-selected="false">Sprzedaż i magazyn</button>
        <button type="button" data-product-view-tab="informacje" aria-selected="false">Informacje</button>
        <button type="button" data-product-view-tab="media" aria-selected="false">Media</button>
        <button type="button" data-product-view-tab="warianty" aria-selected="false">Warianty <span class="counter green">{{ $relatedVariants->count() }}</span></button>
        <button type="button" data-product-view-tab="relacje" aria-selected="false">Relacje</button>
        <button type="button" data-product-view-tab="ruchy" aria-selected="false">Ruchy</button>
    </nav>

    <section class="card product-section" id="produkt" data-product-view-panel="produkt">
        <div class="panel-header">Produkt</div>
        <div class="product-section-body">
            <div class="product-hero">
                <button
                    class="product-photo-button"
                    type="button"
                    @disabled(! $imageUrl)
                    data-image-preview="{{ $imageUrl }}"
                    data-image-title="{{ $product->sku }} - {{ $product->name }}"
                >
                    @if ($imageUrl)
                        <img src="{{ $imageUrl }}" alt="{{ $product->name }}" referrerpolicy="no-referrer">
                    @else
                        Brak zdjęcia
                    @endif
                </button>
                <div class="detail-grid">
                    <div class="detail-item"><span>Nazwa produktu (PL)</span><strong>{{ $product->name }}</strong></div>
                    <div class="detail-item"><span>Katalog</span><strong>{{ data_get($master, 'catalog') ?: '-' }}</strong></div>
                    <div class="detail-item"><span>Kategoria</span><strong>{{ data_get($master, 'category') ?: '-' }}</strong></div>
                    <div class="detail-item"><span>Producent</span><strong>{{ data_get($master, 'producer') ?: '-' }}</strong></div>
                    <div class="detail-item"><span>Tagi</span><strong>{{ $tags !== '' ? $tags : '-' }}</strong></div>
                    <div class="detail-item"><span>SKU</span><strong>{{ $displaySku ?: '-' }}</strong></div>
                    <div class="detail-item"><span>ID WooCommerce</span><strong>{{ $externalId ?: '-' }}</strong></div>
                    <div class="detail-item"><span>EAN</span><strong>{{ $product->ean ?: '-' }}</strong></div>
                    <div class="detail-item"><span>ASIN</span><strong>{{ data_get($master, 'asin') ?: '-' }}</strong></div>
                    <div class="detail-item"><span>Waga</span><strong>{{ $product->weight_kg ? $product->weight_kg . ' kg' : '-' }}</strong></div>
                    <div class="detail-item"><span>Wymiary</span><strong>{{ data_get($master, 'dimensions.height_cm') ?: '0' }} x {{ data_get($master, 'dimensions.width_cm') ?: '0' }} x {{ data_get($master, 'dimensions.length_cm') ?: '0' }} cm</strong></div>
                    <div class="detail-item"><span>Jednostka</span><strong>{{ $product->unit }}</strong></div>
                    <div class="detail-item"><span>Status WooCommerce</span><strong>{{ data_get($master, 'publication_status', $product->is_active ? 'publish' : 'draft') }}</strong></div>
                    <div class="detail-item"><span>Widoczność</span><strong>{{ data_get($master, 'catalog_visibility', 'visible') }}</strong></div>
                    <div class="detail-item"><span>Typ produktu</span><strong>{{ data_get($master, 'product_type', 'simple') }}</strong></div>
                    <div class="detail-item"><span>Atrybut wariantu</span><strong>{{ data_get($master, 'variant_attribute') ?: '-' }}</strong></div>
                    <div class="detail-item"><span>Opracowane</span><strong>{{ data_get($master, 'developed') ? 'Tak' : 'Nie' }}</strong></div>
                </div>
            </div>
        </div>
    </section>

    <section class="card product-section" id="sprzedaz" data-product-view-panel="sprzedaz" hidden>
        <div class="panel-header">Sprzedaż i magazyn</div>
        <div class="product-section-body">
            <div class="detail-grid">
                <div class="detail-item"><span>Cena hurt</span><strong>{{ $money(data_get($master, 'prices.wholesale_price_pln')) }}</strong></div>
                <div class="detail-item"><span>Cena detal</span><strong>{{ $money(data_get($master, 'prices.retail_price_pln')) }}</strong></div>
                <div class="detail-item"><span>VAT</span><strong>{{ $percent($product->vat_rate) }}</strong></div>
                <div class="detail-item"><span>Ilość stanu z karty</span><strong>{{ data_get($master, 'stock.quantity') !== null ? $qty(data_get($master, 'stock.quantity')) : '-' }}</strong></div>
                <div class="detail-item"><span>Lokalizacja</span><strong>{{ data_get($master, 'stock.location') ?: '-' }}</strong></div>
                <div class="detail-item"><span>Próg stanu</span><strong>{{ data_get($master, 'stock.threshold') !== null ? $qty(data_get($master, 'stock.threshold')) : '-' }}</strong></div>
                <div class="detail-item"><span>Cena zakupu</span><strong>{{ $money(data_get($master, 'prices.purchase_price_pln')) }}</strong></div>
            </div>

            <div class="stock-pills">
                <span class="stock-pill">Stan ogólny <strong>{{ $qty($onHand) }}</strong></span>
                <span class="stock-pill">Rezerwacje <strong>{{ $qty($reserved) }}</strong></span>
                <span class="stock-pill">Stan zamówiony <strong>{{ $qty($ordered) }}</strong></span>
                <span class="stock-pill available">Dostępne do sprzedaży <strong>{{ $qty($available) }}</strong></span>
            </div>

            <div class="table-scroll">
                <table class="dense-table">
                    <thead>
                        <tr>
                            <th>Magazyn</th>
                            <th class="numeric">Stan</th>
                            <th class="numeric">Rezerwacja</th>
                            <th class="numeric">Dostępne</th>
                            <th>Przeliczono</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($product->stockBalances->sortBy(fn ($balance) => $balance->warehouse?->code ?? '') as $balance)
                            <tr>
                                <td><strong>{{ $balance->warehouse?->code ?? 'MAG?' }}</strong> {{ $balance->warehouse?->name ?? '' }}</td>
                                <td class="numeric">{{ $qty($balance->quantity_on_hand) }}</td>
                                <td class="numeric">{{ $qty($balance->quantity_reserved) }}</td>
                                <td class="numeric">{{ $qty($balance->quantity_available) }}</td>
                                <td>{{ $balance->recalculated_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">Brak stanów magazynowych dla tego produktu.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </section>

    <section class="card product-section" id="informacje" data-product-view-panel="informacje" hidden>
        <div class="panel-header">Informacje</div>
        <div class="product-section-body">
            <div class="detail-grid">
                <div class="detail-item"><span>Nazwa PL</span><strong>{{ data_get($master, 'content.pl.name') ?: $product->name }}</strong></div>
                <div class="detail-item"><span>Nazwa EN</span><strong>{{ data_get($master, 'content.en.name') ?: '-' }}</strong></div>
                <div class="detail-item"><span>Sprzedaż dodatkowa SKU</span><strong>{{ implode(', ', (array) data_get($master, 'related_products.upsell_skus', [])) ?: '-' }}</strong></div>
                <div class="detail-item"><span>Sprzedaż krzyżowa SKU</span><strong>{{ implode(', ', (array) data_get($master, 'related_products.cross_sell_skus', [])) ?: '-' }}</strong></div>
            </div>

            <div>
                <strong>Opis PL HTML</strong>
                <div class="html-preview">{{ data_get($master, 'content.pl.description') ?: '-' }}</div>
            </div>
            <div>
                <strong>Krótki opis PL HTML</strong>
                <div class="html-preview">{{ data_get($master, 'content.pl.additional_description') ?: '-' }}</div>
            </div>
            <div>
                <strong>Opis EN HTML</strong>
                <div class="html-preview">{{ data_get($master, 'content.en.description') ?: '-' }}</div>
            </div>
            <div>
                <strong>Krótki opis EN HTML</strong>
                <div class="html-preview">{{ data_get($master, 'content.en.additional_description') ?: '-' }}</div>
            </div>

            <div class="table-scroll">
                <table class="dense-table">
                    <thead>
                        <tr>
                            <th>Nazwa parametru</th>
                            <th>Wartość</th>
                            <th>Wariant</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($parameters as $parameter)
                            <tr>
                                <td>{{ $parameter['name'] ?? '-' }}</td>
                                <td>{{ $parameter['value'] ?? '-' }}</td>
                                <td>{{ ($parameter['variation'] ?? false) ? 'Tak' : 'Nie' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3">Brak parametrów produktu.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card product-section" id="media" data-product-view-panel="media" hidden>
        <div class="panel-header">
            <span>Media</span>
            <span>{{ count($images) }} zdjęć</span>
        </div>
        <div class="product-section-body">
            @if ($images === [])
                <div class="toolbar-note">Brak zdjęć produktu.</div>
            @else
                <div class="media-gallery">
                    @foreach ($images as $image)
                        <button class="media-button" type="button" data-image-preview="{{ $image['src'] }}" data-image-title="{{ $image['alt'] ?: $product->name }}">
                            <img src="{{ $image['src'] }}" alt="{{ $image['alt'] ?: $product->name }}" loading="lazy" referrerpolicy="no-referrer">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section class="card product-section" id="warianty" data-product-view-panel="warianty" hidden>
        <div class="panel-header">
            <span>Warianty</span>
            <span>{{ $relatedVariants->count() }} rekordów</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Zdjęcie</th>
                        <th>Produkt</th>
                        <th>SKU</th>
                        <th>EAN</th>
                        <th class="numeric">Stan</th>
                        <th class="numeric">Dostępne</th>
                        <th>Relacja</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($relatedVariants as $variant)
                        @php
                            $variantImage = $variant->imageUrl();
                            $variantOnHand = $variant->stockBalances->sum(fn ($balance) => (float) $balance->quantity_on_hand);
                            $variantAvailable = $variant->stockBalances->sum(fn ($balance) => (float) $balance->quantity_available);
                            $variantRelation = $variantRelationByChildId->get($variant->id);
                        @endphp
                        <tr>
                            <td>
                                @if ($variantImage)
                                    <button class="media-button" style="width: 54px; height: 66px;" type="button" data-image-preview="{{ $variantImage }}" data-image-title="{{ $variant->name }}">
                                        <img src="{{ $variantImage }}" alt="{{ $variant->name }}" loading="lazy" referrerpolicy="no-referrer">
                                    </button>
                                @else
                                    -
                                @endif
                            </td>
                            <td><a class="product-title" href="{{ route('products.show', $variant) }}">{{ $variant->name }}</a></td>
                            <td>{{ $variant->sku }}</td>
                            <td>{{ $variant->ean ?: '-' }}</td>
                            <td class="numeric">{{ $qty($variantOnHand) }}</td>
                            <td class="numeric">{{ $qty($variantAvailable) }}</td>
                            <td>
                                @if ($variantRelation)
                                    <form method="POST" action="{{ route('products.relations.destroy', [$product, $variantRelation]) }}" onsubmit="return confirm('Odłączyć ten wariant od produktu głównego?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button secondary" type="submit">Odłącz</button>
                                    </form>
                                @else
                                    <span class="muted">WooCommerce</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">Brak powiązanych wariantów. Dodaj wariant po SKU albo zaimportuj warianty z WooCommerce.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="variant-relation-form">
            <div>
                <strong>Dodaj istniejący produkt jako wariant</strong>
                <div class="toolbar-note">Wariant pozostaje osobnym SKU w ERP, ale przy tworzeniu produktu w WooCommerce zostanie wysłany jako wariant produktu głównego.</div>
            </div>
            <form method="POST" action="{{ route('products.relations.store', $product) }}">
                @csrf
                <input type="hidden" name="relation_type" value="variant">
                <label>SKU wariantu
                    <input name="child_sku" placeholder="np. BLS..." required>
                </label>
                <label>Atrybut wariantu
                    <input name="variant_attribute" value="{{ data_get($master, 'variant_attribute', 'Rozmiar') }}" placeholder="np. Rozmiar">
                </label>
                <button class="button secondary" type="submit">Dodaj wariant</button>
            </form>
        </div>
        @if ($availableWooCommerceCreateIntegrations->isNotEmpty())
            <div class="channel-create-grid">
                <div>
                    <strong>Utwórz produkt w kanale WooCommerce</strong>
                    <div class="toolbar-note">ERP wyśle dane główne produktu, zapisze ID z WooCommerce i od tego momentu ten kanał będzie używał standardowej synchronizacji.</div>
                </div>
                @foreach ($availableWooCommerceCreateIntegrations as $integration)
                    <div class="channel-create-row">
                        <div>
                            <strong>{{ $integration->salesChannel?->code ?? 'Kanał' }} - {{ $integration->salesChannel?->name ?? $integration->name }}</strong>
                            <span>{{ $integration->name }} | {{ $integration->base_url }}</span>
                        </div>
                        <form method="POST" action="{{ route('products.woocommerce.create', [$product, $integration]) }}" onsubmit="return confirm('Utworzyć ten produkt w kanale WooCommerce {{ $integration->salesChannel?->code ?? $integration->name }}?');">
                            @csrf
                            <button class="button" type="submit">Wyślij do sklepu</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @elseif ($product->channelMappings->isEmpty())
            <div class="channel-create-grid">
                <div class="toolbar-note">Brak aktywnych integracji WooCommerce, do których można utworzyć ten produkt.</div>
            </div>
        @endif
    </section>

    <section class="card product-section" id="relacje" data-product-view-panel="relacje" hidden>
        <div class="panel-header">Relacje i kanały</div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Kanał</th>
                        <th>Woo product ID</th>
                        <th>Woo variation ID</th>
                        <th>SKU w kanale</th>
                        <th>Sync stanu</th>
                        <th>Ostatni eksport danych</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($product->channelMappings as $mapping)
                        <tr>
                            <td>{{ $mapping->salesChannel?->code ?? '-' }} - {{ $mapping->salesChannel?->name ?? '' }}</td>
                            <td>{{ $mapping->external_product_id }}</td>
                            <td>{{ $mapping->external_variation_id ?: '-' }}</td>
                            <td>{{ $mapping->external_sku ?: '-' }}</td>
                            <td>{{ $mapping->stock_sync_enabled ? 'Tak' : 'Nie' }}</td>
                            <td>
                                @if (data_get($mapping->metadata, 'last_product_export_at'))
                                    {{ data_get($mapping->metadata, 'last_product_export_at') }}
                                @else
                                    <span class="muted">Nie wysłano</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">Brak mapowania do kanałów sprzedaży.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card product-section" id="ruchy" data-product-view-panel="ruchy" hidden>
        <div class="panel-header">
            <span>Ostatnie ruchy magazynowe</span>
            <span>{{ $ledgerEntries->count() }} wpisów</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Dokument</th>
                        <th>Magazyn</th>
                        <th>Kierunek</th>
                        <th class="numeric">Zmiana</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ledgerEntries as $entry)
                        <tr>
                            <td>{{ $entry->posted_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>{{ $entry->document?->number ?? '-' }}</td>
                            <td>{{ $entry->warehouse?->code ?? '-' }}</td>
                            <td>{{ $entry->direction }}</td>
                            <td class="numeric">{{ $qty($entry->quantity_change) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">Brak ruchów magazynowych dla tego produktu.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="image-modal" data-product-image-modal aria-hidden="true">
        <div class="image-modal-card">
            <div class="image-modal-header">
                <span data-product-image-title>Podgląd produktu</span>
                <button class="image-modal-close" type="button" data-product-image-close aria-label="Zamknij">&times;</button>
            </div>
            <img data-product-image-large alt="">
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const productViewTabs = Array.from(document.querySelectorAll('[data-product-view-tab]'));
        const productViewPanels = Array.from(document.querySelectorAll('[data-product-view-panel]'));

        function showProductViewPanel(tabName) {
            if (!tabName) {
                return;
            }

            productViewPanels.forEach((panel) => {
                panel.hidden = panel.dataset.productViewPanel !== tabName;
            });

            productViewTabs.forEach((tab) => {
                const active = tab.dataset.productViewTab === tabName;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }

        productViewTabs.forEach((tab) => {
            tab.addEventListener('click', () => showProductViewPanel(tab.dataset.productViewTab));
        });
        showProductViewPanel(productViewTabs[0]?.dataset.productViewTab);

        const productImageModal = document.querySelector('[data-product-image-modal]');
        const productImageLarge = document.querySelector('[data-product-image-large]');
        const productImageTitle = document.querySelector('[data-product-image-title]');
        const productImageClose = document.querySelector('[data-product-image-close]');

        document.querySelectorAll('[data-image-preview]').forEach((button) => {
            button.addEventListener('click', () => {
                const src = button.dataset.imagePreview || '';

                if (!src || !productImageModal || !productImageLarge || !productImageTitle) {
                    return;
                }

                productImageLarge.src = src;
                productImageLarge.alt = button.dataset.imageTitle || 'Podgląd produktu';
                productImageTitle.textContent = button.dataset.imageTitle || 'Podgląd produktu';
                productImageModal.classList.add('open');
                productImageModal.setAttribute('aria-hidden', 'false');
            });
        });

        function closeProductImageModal() {
            if (!productImageModal || !productImageLarge) {
                return;
            }

            productImageModal.classList.remove('open');
            productImageModal.setAttribute('aria-hidden', 'true');
            productImageLarge.removeAttribute('src');
        }

        productImageClose?.addEventListener('click', closeProductImageModal);
        productImageModal?.addEventListener('click', (event) => {
            if (event.target === productImageModal) {
                closeProductImageModal();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeProductImageModal();
            }
        });
    </script>
@endpush
