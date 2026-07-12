@php
    $filters = $orderFilters ?? [];
    $currentQuery = (string) ($filters['q'] ?? '');
    $currentStatus = (string) ($filters['status'] ?? '');
    $currentPerPage = (int) ($filters['per_page'] ?? 50);
    $thumbnailService = app(\App\Services\Products\ProductImageThumbnailService::class);
    $shippingProviderResolver = app(\App\Services\Shipping\ShippingProviderResolver::class);
    $money = fn ($amount, $currency): string => number_format((float) $amount, 2, ',', ' ').' '.$currency;
    $qty = fn ($amount): string => rtrim(rtrim(number_format((float) $amount, 4, ',', ' '), '0'), ',');
    $asArray = fn ($value): array => is_array($value) ? $value : [];
    $statusTone = function (?string $status): string {
        $status = mb_strtolower(trim((string) $status));

        return match (true) {
            in_array($status, ['cancelled', 'failed', 'refunded'], true) => 'red',
            in_array($status, ['pending', 'on-hold', 'waiting'], true) => 'orange',
            in_array($status, ['completed', 'processing', 'packed', 'posted', 'generated', 'shipped'], true) => 'blue',
            $status === 'awaiting_courier' => 'orange',
            default => '',
        };
    };
    $lineImage = function ($line) use ($thumbnailService, $asArray): ?string {
        $rawPayload = $asArray($line->raw_payload);
        $source = $line->product?->imageUrl()
            ?: data_get($rawPayload, 'image.src')
            ?: data_get($rawPayload, 'image.url')
            ?: data_get($rawPayload, 'images.0.src')
            ?: data_get($rawPayload, 'images.0.url')
            ?: data_get($rawPayload, 'parent_image.src')
            ?: data_get($rawPayload, 'parent_image.url');

        return $line->product?->thumbnailUrl(54, 68) ?: $thumbnailService->thumbnailUrl(is_string($source) ? $source : null, 54, 68);
    };
    $customerName = function ($order) use ($asArray): string {
        $billing = $asArray($order->billing_data);
        $name = trim(implode(' ', array_filter([
            $billing['first_name'] ?? null,
            $billing['last_name'] ?? null,
        ])));

        return $name !== '' ? $name : trim((string) ($billing['company'] ?? ''));
    };
    $deliveryName = function ($order, $label) use ($asArray): string {
        if ($label?->courierAccount) {
            return trim(($label->courierAccount->provider === 'blpaczka' ? 'BLPaczka' : 'InPost').': '.$label->courierAccount->name);
        }

        if (filled($label?->provider)) {
            return (string) $label->provider;
        }

        return (string) (
            data_get($asArray($order->raw_payload), 'shipping_lines.0.method_title')
            ?: data_get($asArray($order->raw_payload), 'shipping_lines.0.method_id')
            ?: 'Brak danych'
        );
    };
@endphp

<article class="card orders-list-card">
    <div class="panel-header">
        <span>Zamówienia</span>
        <span>{{ $recordLabel }}</span>
    </div>

    <input id="orders-mobile-filter-toggle" class="orders-mobile-filter-toggle" type="checkbox">
    <label class="button secondary orders-mobile-filter-trigger" for="orders-mobile-filter-toggle">Filtry i wyszukiwanie</label>
    <label class="orders-mobile-filter-backdrop" for="orders-mobile-filter-toggle"></label>
    <div class="orders-filter-drawer">
        <div class="orders-filter-drawer-header">
            <span>Filtry zamówień</span>
            <label class="orders-filter-drawer-close" for="orders-mobile-filter-toggle" aria-label="Zamknij filtry">×</label>
        </div>
        <form class="orders-filter" method="GET" action="{{ route('modules.show', 'orders') }}">
        <label class="orders-filter-search">Szukaj
            <input
                name="q"
                value="{{ $currentQuery }}"
                placeholder="Imię, nazwisko, telefon, e-mail, status lub ID zamówienia"
                autocomplete="off"
            >
        </label>
        <label>Status
            <select name="status">
                <option value="">Wszystkie statusy</option>
                @foreach ($orderStatusOptions as $status)
                    <option value="{{ $status }}" @selected($currentStatus === $status)>{{ $status }}</option>
                @endforeach
            </select>
        </label>
        <label>Na stronie
            <select name="per_page">
                @foreach ([25, 50, 75, 100] as $size)
                    <option value="{{ $size }}" @selected($currentPerPage === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </label>
        <div class="orders-filter-actions">
            <button class="button" type="submit">Szukaj</button>
            @if ($currentQuery !== '' || $currentStatus !== '')
                <a class="button secondary" href="{{ route('modules.show', 'orders') }}">Wyczyść</a>
            @endif
        </div>
        </form>
    </div>

    <div class="table-scroll orders-table-scroll">
        <table class="dense-table orders-table">
            <thead>
                <tr>
                    <th>Zamówienie</th>
                    <th>Klient</th>
                    <th>Przedmioty</th>
                    <th>Dostawa</th>
                    <th>Status</th>
                    <th class="numeric">Kwota</th>
                    <th>Utworzone</th>
                    <th>Akcja</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    @php
                        $billing = $asArray($order->billing_data);
                        $shipping = $asArray($order->shipping_data);
                        $customer = $customerName($order) ?: 'Klient bez nazwy';
                        $email = (string) ($billing['email'] ?? data_get($shipping, 'email', ''));
                        $phone = (string) ($billing['phone'] ?? data_get($shipping, 'phone', ''));
                        $label = $order->shipmentLabels->first();
                        $trackingUrl = $label ? $shippingProviderResolver->trackingUrl($label) : null;
                        $activeReservations = (float) ($activeReservationSums[$order->sales_channel_id.'|'.$order->external_id] ?? 0);
                        $invoice = $order->invoices
                            ->reject(fn ($invoice): bool => $invoice->type === 'proforma')
                            ->sortByDesc('id')
                            ->first();
                        $proforma = $order->invoices
                            ->where('type', 'proforma')
                            ->sortByDesc('id')
                            ->first();
                        $visibleLines = $order->lines->take(4);
                        $hiddenLinesCount = max(0, $order->lines->count() - $visibleLines->count());
                    @endphp
                    <tr>
                        <td class="order-main-cell" data-label="Zamówienie">
                            <a class="order-number-link" href="{{ route('orders.show', $order) }}">
                                {{ $order->external_number ?: $order->external_id }}
                            </a>
                            <div class="order-meta">ID Woo: {{ $order->external_id }}</div>
                            <div class="order-meta">ERP: #{{ $order->id }} · {{ $order->salesChannel?->code ?? '-' }}</div>
                        </td>
                        <td class="order-customer-cell" data-label="Klient">
                            <strong>{{ $customer }}</strong>
                            <span>{{ $email !== '' ? $email : 'brak e-maila' }}</span>
                            <span>{{ $phone !== '' ? $phone : 'brak telefonu' }}</span>
                        </td>
                        <td class="order-items-cell" data-label="Przedmioty">
                            <div class="order-items-stack">
                                @forelse ($visibleLines as $line)
                                    @php
                                        $thumb = $lineImage($line);
                                        $sku = $line->sku ?: $line->product?->sku;
                                    @endphp
                                    <div class="order-item-row">
                                        <div class="order-item-thumb">
                                            @if ($thumb)
                                                <img src="{{ $thumb }}" alt="{{ $line->name }}" width="54" height="68" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                                            @else
                                                <span>{{ mb_strtoupper(mb_substr($line->name, 0, 1)) }}</span>
                                            @endif
                                        </div>
                                        <div class="order-item-copy">
                                            <strong>{{ $line->name }}</strong>
                                            <span>{{ $sku ?: 'bez SKU' }} · {{ $qty($line->quantity) }} szt.</span>
                                        </div>
                                    </div>
                                @empty
                                    <span class="muted">Brak pozycji zamówienia.</span>
                                @endforelse
                                @if ($hiddenLinesCount > 0)
                                    <div class="order-more-lines">+{{ $hiddenLinesCount }} kolejne pozycje</div>
                                @endif
                            </div>
                        </td>
                        <td class="order-delivery-cell" data-label="Dostawa">
                            <strong>{{ $deliveryName($order, $label) }}</strong>
                            @if ($label)
                                @if ($trackingUrl)
                                    <a href="{{ $trackingUrl }}" target="_blank" rel="noopener noreferrer" aria-label="Śledź przesyłkę {{ $label->trackingIdentifier() }}">
                                        {{ $label->trackingIdentifier() }}
                                    </a>
                                @else
                                    <span>{{ $label->trackingIdentifier() ?: 'etykieta bez numeru' }}</span>
                                @endif
                                <span @class(['status', $statusTone($label->status)])>{{ $label->status }}</span>
                            @else
                                <span class="muted">Brak etykiety</span>
                            @endif
                        </td>
                        <td data-label="Status">
                            <span @class(['status', $statusTone($order->status)])>{{ $order->status }}</span>
                            @if ($order->fulfillment_status)
                                <div class="order-meta">ERP: <span @class(['status', $statusTone($order->fulfillment_status)])>{{ match ($order->fulfillment_status) {
                                    'awaiting_courier' => 'oczekuje na kuriera',
                                    'ready_to_pack' => 'do pakowania',
                                    'shipped' => 'wysłane',
                                    default => $order->fulfillment_status,
                                } }}</span></div>
                            @endif
                            <div class="order-meta">Rezerwacje: {{ number_format($activeReservations, 0, ',', ' ') }}</div>
                        </td>
                        <td class="numeric order-total-cell" data-label="Kwota">{{ $money($order->total_gross, $order->currency) }}</td>
                        <td class="order-date-cell" data-label="Utworzone">
                            {{ $order->external_created_at?->format('Y-m-d') ?? $order->created_at?->format('Y-m-d') ?? '-' }}
                            <span>{{ $order->external_created_at?->format('H:i') ?? $order->created_at?->format('H:i') ?? '' }}</span>
                        </td>
                        <td class="order-actions-cell" data-label="Akcje">
                            {!! view('partials.order-actions', [
                                'order' => $order,
                                'wzDocument' => $latestWzDocuments[$order->id] ?? null,
                                'invoice' => $invoice,
                                'proforma' => $proforma,
                                'activeReservations' => $activeReservations,
                            ])->render() !!}
                        </td>
                    </tr>
                @empty
                    <tr class="orders-empty-row">
                        <td colspan="8">Brak zamówień dla wybranych filtrów.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="module-pagination">
        <div class="module-pagination-range">
            @if (method_exists($orders, 'firstItem') && $orders->firstItem() !== null)
                Wyświetlono {{ $orders->firstItem() }} - {{ $orders->lastItem() }}
            @else
                Wyświetlono {{ count($orders) }} rekordów
            @endif
        </div>
        <div class="inline-actions">
            @if (method_exists($orders, 'onFirstPage') && $orders->onFirstPage())
                <span class="button secondary disabled">Poprzednie</span>
            @elseif (method_exists($orders, 'previousPageUrl') && $orders->previousPageUrl())
                <a class="button secondary" href="{{ $orders->previousPageUrl() }}">Poprzednie</a>
            @endif

            @if (method_exists($orders, 'hasMorePages') && $orders->hasMorePages() && method_exists($orders, 'nextPageUrl'))
                <a class="button secondary" href="{{ $orders->nextPageUrl() }}">Następne</a>
            @else
                <span class="button secondary disabled">Następne</span>
            @endif
        </div>
    </div>
</article>
