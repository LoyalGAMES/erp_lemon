@extends('layouts.app', [
    'title' => $title,
    'subtitle' => $subtitle,
    'module' => $module,
    'headerBackUrl' => $headerBackUrl,
])

@php
    $number = static fn ($value, int $precision = 0): string => number_format((float) $value, $precision, ',', ' ');
    $address = static function (?array $data): string {
        $data ??= [];
        $parts = array_filter([
            $data['company'] ?? null,
            $data['address_1'] ?? null,
            $data['address_2'] ?? null,
            trim(implode(' ', array_filter([$data['postcode'] ?? null, $data['city'] ?? null]))),
            $data['country'] ?? null,
        ]);

        return implode(', ', $parts) ?: 'Brak danych adresowych';
    };
    $person = static function (?array $data): string {
        $data ??= [];

        return trim(implode(' ', array_filter([
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
        ]))) ?: '—';
    };
    $orderStatus = static fn (string $status): string => match ($status) {
        'pending' => 'Oczekuje na płatność',
        'processing' => 'W realizacji',
        'on-hold' => 'Wstrzymane',
        'completed' => 'Zrealizowane',
        'cancelled' => 'Anulowane',
        'refunded' => 'Zwrócone',
        'failed' => 'Nieudane',
        default => $status,
    };
@endphp

@push('styles')
    <style>
        .customer-show-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
        .customer-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 16px; }
        .customer-summary-card { padding: 16px; }
        .customer-summary-card span { display: block; color: var(--muted); font-size: 12px; font-weight: 720; margin-bottom: 5px; }
        .customer-summary-card strong { display: block; font-size: 22px; line-height: 1.2; overflow-wrap: anywhere; }
        .customer-details-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-bottom: 16px; }
        .customer-section-body { padding: 16px; }
        .customer-contact-list { display: grid; gap: 10px; }
        .customer-contact-row { display: grid; gap: 2px; }
        .customer-contact-row > span { color: var(--muted); font-size: 12px; font-weight: 720; }
        .customer-contact-row a { color: var(--green-dark); text-decoration: none; overflow-wrap: anywhere; }
        .customer-addresses { display: grid; gap: 12px; }
        .customer-address { border: 1px solid var(--border); border-radius: 8px; padding: 12px; }
        .customer-address strong { display: block; margin-bottom: 4px; }
        .customer-account-table, .customer-order-table { min-width: 930px; }
        .customer-section { margin-bottom: 16px; }
        .customer-account-identity { min-width: 230px; white-space: normal; }
        .customer-order-number { color: var(--text); text-decoration: none; font-weight: 820; }
        .customer-order-number:hover { color: var(--green-dark); }
        .customer-points-note { margin-top: 8px; color: var(--muted); font-size: 12px; }
        .customer-order-pagination { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 13px 16px; border-top: 1px solid var(--border); }
        .customer-order-pagination-links { display: flex; gap: 8px; }
        @media (max-width: 1000px) {
            .customer-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .customer-details-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) {
            .customer-summary { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')
    <div class="customer-show-toolbar">
        <a class="button secondary" href="{{ route('customers.index') }}">Wróć do listy klientów</a>
        @if ($customer->account_status === 'registered')
            <span class="status">Konto WooCommerce</span>
        @else
            <span class="status orange">Zakupy jako gość</span>
        @endif
    </div>

    <section class="customer-summary" aria-label="Podsumowanie klienta">
        <article class="card customer-summary-card">
            <span>Zamówienia</span>
            <strong>{{ $number($customer->orders_count) }}</strong>
        </article>
        <article class="card customer-summary-card">
            <span>Wartość brutto</span>
            <strong>{{ $number($customer->total_spent, 2) }}</strong>
        </article>
        <article class="card customer-summary-card">
            <span>Punkty lojalnościowe</span>
            <strong>{{ $customer->loyalty_points_balance !== null ? $number($customer->loyalty_points_balance, 2) : 'Brak danych' }}</strong>
            @if ($customer->loyalty_points_source)
                <div class="customer-points-note">Źródło: {{ $customer->loyalty_points_source }}</div>
            @endif
        </article>
        <article class="card customer-summary-card">
            <span>Ostatnie zamówienie</span>
            <strong>{{ $customer->last_order_at?->format('d.m.Y') ?? '—' }}</strong>
        </article>
    </section>

    <div class="customer-details-grid">
        <article class="card">
            <div class="panel-header"><span>Dane kontaktowe</span></div>
            <div class="customer-section-body customer-contact-list">
                <div class="customer-contact-row">
                    <span>Imię i nazwisko</span>
                    <strong>{{ trim($customer->first_name.' '.$customer->last_name) ?: ($customer->display_name ?: '—') }}</strong>
                </div>
                <div class="customer-contact-row">
                    <span>E-mail</span>
                    @if ($customer->email)
                        <a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a>
                    @else
                        <strong>Brak adresu e-mail</strong>
                    @endif
                </div>
                <div class="customer-contact-row">
                    <span>Telefon</span>
                    @if ($customer->phone)
                        <a href="tel:{{ preg_replace('/\s+/', '', $customer->phone) }}">{{ $customer->phone }}</a>
                    @else
                        <strong>—</strong>
                    @endif
                </div>
                <div class="customer-contact-row">
                    <span>Pierwsze zamówienie</span>
                    <strong>{{ $customer->first_order_at?->format('d.m.Y H:i') ?? '—' }}</strong>
                </div>
                <div class="customer-contact-row">
                    <span>Ostatnia synchronizacja</span>
                    <strong>{{ $customer->last_synced_at?->format('d.m.Y H:i') ?? '—' }}</strong>
                </div>
            </div>
        </article>

        <article class="card">
            <div class="panel-header"><span>Adresy</span></div>
            <div class="customer-section-body customer-addresses">
                <div class="customer-address">
                    <strong>Rozliczeniowy · {{ $person($customer->billing_data) }}</strong>
                    <span class="muted">{{ $address($customer->billing_data) }}</span>
                </div>
                <div class="customer-address">
                    <strong>Wysyłkowy · {{ $person($customer->shipping_data) }}</strong>
                    <span class="muted">{{ $address($customer->shipping_data) }}</span>
                </div>
            </div>
        </article>
    </div>

    <article class="card customer-section">
        <div class="panel-header">
            <span>Konta w WooCommerce</span>
            <span>{{ $customer->externalAccounts->count() }}</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table customer-account-table">
                <thead>
                    <tr>
                        <th>Sklep / kanał</th>
                        <th>Identyfikator Woo</th>
                        <th>Konto</th>
                        <th>Login i e-mail</th>
                        <th class="numeric">Zamówienia</th>
                        <th>Utworzono</th>
                        <th>Synchronizacja</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customer->externalAccounts as $account)
                        <tr>
                            <td>
                                <strong>{{ $account->integration?->name ?? 'WooCommerce' }}</strong><br>
                                <span class="muted">{{ $account->integration?->salesChannel?->name ?? 'Brak kanału' }}</span>
                            </td>
                            <td>{{ $account->external_customer_id ?: 'Gość' }}</td>
                            <td>
                                @if ($account->is_registered)
                                    <span class="status">Zarejestrowane</span>
                                @else
                                    <span class="status orange">Gość</span>
                                @endif
                            </td>
                            <td class="customer-account-identity">
                                {{ $account->username ?: '—' }}<br>
                                <span class="muted">{{ $account->email ?: $customer->email }}</span>
                            </td>
                            <td class="numeric">{{ $number($account->orders_count) }}</td>
                            <td>{{ $account->account_created_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td>{{ $account->last_synced_at?->format('d.m.Y H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="muted">Brak zsynchronizowanego konta zewnętrznego.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>

    <article class="card customer-section">
        <div class="panel-header">
            <span>Historia zamówień</span>
            <span>{{ $orders->total() }}</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table customer-order-table">
                <thead>
                    <tr>
                        <th>Numer</th>
                        <th>Data</th>
                        <th>Kanał</th>
                        <th>Status</th>
                        <th class="numeric">Wartość brutto</th>
                        <th>Akcja</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr>
                            <td><a class="customer-order-number" href="{{ route('orders.show', $order) }}">{{ $order->external_number ?: $order->external_id }}</a></td>
                            <td>{{ $order->external_created_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td>{{ $order->salesChannel?->name ?? '—' }}</td>
                            <td><span class="status blue">{{ $orderStatus((string) $order->status) }}</span></td>
                            <td class="numeric customer-value">{{ $number($order->total_gross, 2) }} {{ $order->currency }}</td>
                            <td><a class="button secondary" href="{{ route('orders.show', $order) }}">Otwórz zamówienie</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted">Brak zamówień przypisanych do klienta.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($orders->hasPages())
            <nav class="customer-order-pagination" aria-label="Paginacja zamówień klienta">
                <span class="muted">Zamówienia {{ $orders->firstItem() }}–{{ $orders->lastItem() }} z {{ $orders->total() }}</span>
                <div class="customer-order-pagination-links">
                    <a @class(['button', 'secondary', 'disabled' => $orders->onFirstPage()]) href="{{ $orders->previousPageUrl() ?: '#' }}">Poprzednia</a>
                    <a @class(['button', 'secondary', 'disabled' => ! $orders->hasMorePages()]) href="{{ $orders->nextPageUrl() ?: '#' }}">Następna</a>
                </div>
            </nav>
        @endif
    </article>
@endsection
