@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@php
    $customerName = static function ($customer): string {
        $firstAndLastName = trim(implode(' ', array_filter([
            $customer->first_name,
            $customer->last_name,
        ])));

        return $firstAndLastName ?: ($customer->display_name ?: ($customer->email ?: 'Klient #'.$customer->id));
    };
    $number = static fn ($value, int $precision = 0): string => number_format((float) $value, $precision, ',', ' ');
@endphp

@push('styles')
    <style>
        .customer-metrics { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .customer-filters { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(180px, .35fr) minmax(210px, .45fr) auto; gap: 10px; align-items: end; padding: 16px; }
        .customer-filters-actions { display: flex; gap: 8px; align-items: center; min-height: 42px; }
        .customer-filter-summary { padding: 0 16px 14px; color: var(--muted); font-size: 12px; }
        .customer-table { min-width: 1180px; }
        .customer-person { display: grid; gap: 2px; min-width: 210px; white-space: normal; }
        .customer-person a { color: var(--text); text-decoration: none; font-weight: 820; }
        .customer-person a:hover { color: var(--green-dark); }
        .customer-contact { min-width: 220px; white-space: normal; }
        .customer-contact a { color: var(--green-dark); text-decoration: none; }
        .customer-channel-list { display: flex; flex-wrap: wrap; gap: 5px; max-width: 220px; white-space: normal; }
        .customer-value { font-variant-numeric: tabular-nums; font-weight: 780; }
        .customer-pagination { padding: 14px 16px; border-top: 1px solid var(--border); }
        .customer-pagination-bar { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
        .customer-pagination-pages { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .customer-pagination-page { min-width: 36px; min-height: 36px; border: 1px solid var(--border); border-radius: 8px; padding: 7px 10px; display: inline-flex; align-items: center; justify-content: center; color: var(--text); background: var(--surface); text-decoration: none; font-weight: 760; }
        .customer-pagination-page.active { color: var(--green-dark); background: var(--green-soft); border-color: rgba(134, 115, 100, .34); }
        .customer-pagination-page.disabled { opacity: .45; pointer-events: none; }
        .customer-pagination-summary { color: var(--muted); font-size: 12px; }
        .customer-empty { padding: 34px 18px; text-align: center; color: var(--muted); white-space: normal; }
        @media (max-width: 1080px) {
            .customer-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 760px) {
            .customer-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .customer-filters { grid-template-columns: 1fr; }
            .customer-filters-actions .button { min-height: 42px; }
        }
        @media (max-width: 430px) {
            .customer-metrics { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')
    <section class="metrics customer-metrics" aria-label="Podsumowanie klientów">
        <article class="card metric">
            <div class="metric-label">Wszyscy klienci</div>
            <div class="metric-value">{{ $number($metrics['all']) }}</div>
            <div class="metric-caption">Profile klientów z WooCommerce</div>
        </article>
        <article class="card metric">
            <div class="metric-label">Z kontem</div>
            <div class="metric-value metric-value-blue">{{ $number($metrics['registered']) }}</div>
            <div class="metric-caption">Konta zarejestrowane w sklepie</div>
        </article>
        <article class="card metric">
            <div class="metric-label">Goście</div>
            <div class="metric-value">{{ $number($metrics['guest']) }}</div>
            <div class="metric-caption">Zakupy bez konta klienta</div>
        </article>
        <article class="card metric">
            <div class="metric-label">Zamówienia klientów</div>
            <div class="metric-value">{{ $number($metrics['orders']) }}</div>
            <div class="metric-caption">Łącznie przypisane zamówienia</div>
        </article>
    </section>

    <article class="card">
        <div class="panel-header">
            <span>Lista klientów</span>
            <span>{{ $number($customers->total()) }} wyników</span>
        </div>

        <form class="customer-filters" method="GET" action="{{ route('customers.index') }}">
            <label>Szukaj klienta
                <input name="q" value="{{ $filters['q'] }}" placeholder="Imię, e-mail, telefon, login lub numer zamówienia" aria-label="Szukaj klientów">
            </label>
            <label>Rodzaj klienta
                <select name="status">
                    <option value="">Wszyscy</option>
                    <option value="registered" @selected($filters['status'] === 'registered')>Z kontem</option>
                    <option value="guest" @selected($filters['status'] === 'guest')>Gość</option>
                </select>
            </label>
            <label>Kanał sprzedaży
                <select name="channel">
                    <option value="">Wszystkie kanały</option>
                    @foreach ($channels as $channel)
                        <option value="{{ $channel->id }}" @selected((int) $filters['channel'] === $channel->id)>
                            {{ $channel->name }}{{ $channel->is_active ? '' : ' (nieaktywny)' }}
                        </option>
                    @endforeach
                </select>
            </label>
            <div class="customer-filters-actions">
                <button class="button" type="submit">Filtruj</button>
                @if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['channel'] > 0)
                    <a class="button secondary" href="{{ route('customers.index') }}">Wyczyść</a>
                @endif
            </div>
        </form>
        <div class="customer-filter-summary">
            Każdy profil jest przechowywany osobno dla konkretnej integracji WooCommerce i łączy konto klienta z jego zamówieniami w tym sklepie.
        </div>

        <div class="table-scroll">
            <table class="dense-table customer-table">
                <thead>
                    <tr>
                        <th>Klient</th>
                        <th>Kontakt</th>
                        <th>Konto</th>
                        <th>Kanał</th>
                        <th class="numeric">Zamówienia</th>
                        <th class="numeric">Wartość brutto</th>
                        <th class="numeric">Punkty</th>
                        <th>Ostatnie zamówienie</th>
                        <th>Akcja</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        @php
                            $customerChannels = $customer->externalAccounts
                                ->map(fn ($account) => $account->integration?->salesChannel)
                                ->filter()
                                ->unique('id')
                                ->values();
                        @endphp
                        <tr>
                            <td>
                                <div class="customer-person">
                                    <a href="{{ route('customers.show', $customer) }}">{{ $customerName($customer) }}</a>
                                    @if ($customer->display_name && $customer->display_name !== $customerName($customer))
                                        <span class="muted">{{ $customer->display_name }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="customer-contact">
                                @if ($customer->email)
                                    <a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a>
                                @else
                                    <span class="muted">Brak adresu e-mail</span>
                                @endif
                                @if ($customer->phone)
                                    <br><span class="muted">{{ $customer->phone }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($customer->account_status === 'registered')
                                    <span class="status">Zarejestrowane</span>
                                @else
                                    <span class="status orange">Gość</span>
                                @endif
                            </td>
                            <td>
                                <div class="customer-channel-list">
                                    @forelse ($customerChannels as $channel)
                                        <span class="status blue" title="{{ $channel->name }}">{{ $channel->code }}</span>
                                    @empty
                                        <span class="muted">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="numeric">{{ $number($customer->orders_count) }}</td>
                            <td class="numeric customer-value">{{ $number($customer->total_spent, 2) }}</td>
                            <td class="numeric">
                                {{ $customer->loyalty_points_balance !== null ? $number($customer->loyalty_points_balance, 2) : '—' }}
                            </td>
                            <td>
                                {{ $customer->last_order_at?->format('d.m.Y H:i') ?? '—' }}
                            </td>
                            <td><a class="button secondary" href="{{ route('customers.show', $customer) }}">Otwórz kartę</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td class="customer-empty" colspan="9">
                                @if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['channel'] > 0)
                                    Brak klientów pasujących do wybranych filtrów.
                                @else
                                    Brak klientów. Pojawią się tutaj po synchronizacji WooCommerce.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($customers->hasPages())
            <div class="customer-pagination">
                <nav class="customer-pagination-bar" aria-label="Paginacja klientów">
                    <div class="customer-pagination-summary">
                        Rekordy {{ $customers->firstItem() }}–{{ $customers->lastItem() }} z {{ $customers->total() }}
                    </div>
                    <div class="customer-pagination-pages">
                        <a @class(['customer-pagination-page', 'disabled' => $customers->onFirstPage()]) href="{{ $customers->previousPageUrl() ?: '#' }}">Poprzednia</a>
                        @foreach ($customers->getUrlRange(max(1, $customers->currentPage() - 2), min($customers->lastPage(), $customers->currentPage() + 2)) as $page => $url)
                            <a @class(['customer-pagination-page', 'active' => $page === $customers->currentPage()]) href="{{ $url }}">{{ $page }}</a>
                        @endforeach
                        <a @class(['customer-pagination-page', 'disabled' => ! $customers->hasMorePages()]) href="{{ $customers->nextPageUrl() ?: '#' }}">Następna</a>
                    </div>
                </nav>
            </div>
        @endif
    </article>
@endsection
