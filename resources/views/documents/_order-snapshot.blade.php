@php
    $orderSnapshot = (array) data_get($document->metadata, 'order_snapshot', []);
    $billingSnapshot = (array) ($orderSnapshot['billing'] ?? []);
    $shippingSnapshot = (array) ($orderSnapshot['shipping'] ?? []);
    $paymentSnapshot = (array) ($orderSnapshot['payment'] ?? []);
    $deliverySnapshot = (array) ($orderSnapshot['delivery'] ?? []);
    $customerNoteSnapshot = trim((string) ($orderSnapshot['customer_note'] ?? ''));
    $taxIdSnapshot = trim((string) ($orderSnapshot['nip'] ?? ''));
    $pickupPointSnapshot = trim((string) ($orderSnapshot['pickup_point'] ?? ''));
    $printMode = (bool) ($printMode ?? false);
    $hasOrderSnapshot = $billingSnapshot !== []
        || $shippingSnapshot !== []
        || $paymentSnapshot !== []
        || $deliverySnapshot !== []
        || $customerNoteSnapshot !== ''
        || $taxIdSnapshot !== ''
        || $pickupPointSnapshot !== '';
    $addressLines = static function (array $address): array {
        $name = trim(implode(' ', array_filter([
            $address['first_name'] ?? null,
            $address['last_name'] ?? null,
        ])));
        $street = trim(implode(' ', array_filter([
            $address['address_1'] ?? null,
            $address['address_2'] ?? null,
        ])));
        $city = trim(implode(' ', array_filter([
            $address['postcode'] ?? null,
            $address['city'] ?? null,
        ])));
        $region = trim(implode(', ', array_filter([
            $address['state'] ?? null,
            $address['country'] ?? null,
        ])));

        return array_values(array_filter([
            $address['company'] ?? null,
            $name !== '' ? $name : null,
            $street !== '' ? $street : null,
            $city !== '' ? $city : null,
            $region !== '' ? $region : null,
        ]));
    };
    $billingAddressLines = $addressLines($billingSnapshot);
    $shippingAddressLines = $addressLines($shippingSnapshot);
    $paymentMethodSnapshot = trim((string) (
        $paymentSnapshot['method_title']
        ?? $paymentSnapshot['method']
        ?? ''
    ));
    $deliveryMethodSnapshot = trim((string) (
        $deliverySnapshot['method_title']
        ?? $deliverySnapshot['method_id']
        ?? ''
    ));
    $deliveryCostSnapshot = trim((string) ($deliverySnapshot['total'] ?? ''));
    $deliveryCurrencySnapshot = trim((string) ($deliverySnapshot['currency'] ?? ''));
    $formattedDeliveryCostSnapshot = $deliveryCostSnapshot !== '' && is_numeric($deliveryCostSnapshot)
        ? number_format((float) $deliveryCostSnapshot, 2, ',', ' ')
        : $deliveryCostSnapshot;
@endphp

@if ($hasOrderSnapshot)
    @if ($printMode)
        <div class="section-title">Dane zamówienia WooCommerce</div>
        <section class="order-snapshot-grid" aria-label="Dane zamówienia WooCommerce">
    @else
        <section class="card document-section" aria-label="Dane zamówienia WooCommerce">
            <div class="panel-header">
                <span>Dane zamówienia WooCommerce</span>
                <span>Snapshot WZ</span>
            </div>
            <div class="order-snapshot-grid">
    @endif

        <article class="order-snapshot-card">
            <strong>Dane rozliczeniowe</strong>
            @forelse ($billingAddressLines as $line)
                <div>{{ $line }}</div>
            @empty
                <div class="muted">Brak danych adresowych</div>
            @endforelse
            @if ($taxIdSnapshot !== '')
                <div><span class="order-snapshot-label">NIP:</span> {{ $taxIdSnapshot }}</div>
            @endif
            @if (filled($billingSnapshot['email'] ?? null))
                <div><span class="order-snapshot-label">E-mail:</span> {{ $billingSnapshot['email'] }}</div>
            @endif
            @if (filled($billingSnapshot['phone'] ?? null))
                <div><span class="order-snapshot-label">Telefon:</span> {{ $billingSnapshot['phone'] }}</div>
            @endif
        </article>

        <article class="order-snapshot-card">
            <strong>Dane wysyłki</strong>
            @forelse ($shippingAddressLines as $line)
                <div>{{ $line }}</div>
            @empty
                <div class="muted">Brak osobnego adresu wysyłki</div>
            @endforelse
            @if (filled($shippingSnapshot['email'] ?? null))
                <div><span class="order-snapshot-label">E-mail:</span> {{ $shippingSnapshot['email'] }}</div>
            @endif
            @if (filled($shippingSnapshot['phone'] ?? null))
                <div><span class="order-snapshot-label">Telefon:</span> {{ $shippingSnapshot['phone'] }}</div>
            @endif
            @if ($pickupPointSnapshot !== '')
                <div><span class="order-snapshot-label">Punkt odbioru:</span> {{ $pickupPointSnapshot }}</div>
            @endif
            @if ($deliveryMethodSnapshot !== '')
                <div><span class="order-snapshot-label">Metoda dostawy:</span> {{ $deliveryMethodSnapshot }}</div>
            @endif
            @if ($formattedDeliveryCostSnapshot !== '')
                <div>
                    <span class="order-snapshot-label">Koszt dostawy:</span>
                    {{ $formattedDeliveryCostSnapshot }}{{ $deliveryCurrencySnapshot !== '' ? ' '.$deliveryCurrencySnapshot : '' }}
                </div>
            @endif
        </article>

        <article class="order-snapshot-card">
            <strong>Płatność</strong>
            <div>{{ $paymentMethodSnapshot !== '' ? $paymentMethodSnapshot : 'Brak danych o metodzie' }}</div>
            @if (filled($paymentSnapshot['transaction_id'] ?? null))
                <div><span class="order-snapshot-label">Transakcja:</span> {{ $paymentSnapshot['transaction_id'] }}</div>
            @endif
            @if (filled($paymentSnapshot['paid_at'] ?? null))
                <div><span class="order-snapshot-label">Opłacono:</span> {{ $paymentSnapshot['paid_at'] }}</div>
            @endif
        </article>

        @if ($customerNoteSnapshot !== '')
            <article class="order-snapshot-card order-snapshot-note">
                <strong>Uwagi klienta</strong>
                <div>{{ $customerNoteSnapshot }}</div>
            </article>
        @endif

    @if ($printMode)
        </section>
    @else
            </div>
        </section>
    @endif
@endif
