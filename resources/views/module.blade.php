@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@section('content')
    @php
        $recordSource = (($module ?? null) === 'orders' && isset($orders)) ? $orders : $rows;
        $rowsCount = count($recordSource);
        $hasPagination = is_object($recordSource) && method_exists($recordSource, 'hasPages');
        $recordLabel = $rowsCount.' rekordów';

        if (is_object($recordSource) && method_exists($recordSource, 'total')) {
            $recordLabel = number_format((int) $recordSource->total(), 0, ',', ' ').' rekordów';
        } elseif (is_object($recordSource) && method_exists($recordSource, 'firstItem') && $recordSource->firstItem() !== null) {
            $recordLabel = $recordSource->firstItem().' - '.$recordSource->lastItem();
        }
    @endphp

    @if (! empty($summaryCards))
        <section class="metrics" aria-label="Stan kolejek">
            @foreach ($summaryCards as $card)
                <article class="card metric">
                    <div class="metric-label">{{ $card['label'] }}</div>
                    <div @class(['metric-value', 'metric-value-blue' => $card['tone'] === 'blue', 'metric-value-red' => $card['tone'] === 'red'])>{{ $card['value'] }}</div>
                    <div class="metric-caption">{{ $card['caption'] }}</div>
                </article>
            @endforeach
        </section>
    @endif

    @if (($module ?? null) === 'sync')
        <article class="card" style="margin-bottom: 18px;">
            <div class="panel-header">
                <span>Pełna synchronizacja stanów</span>
                <span>ERP → WooCommerce</span>
            </div>
            <form method="POST" action="{{ route('sync.rebuild') }}" class="grid-form" style="padding: 16px;">
                @csrf
                <label>Kanał sprzedaży
                    <select name="sales_channel_id">
                        <option value="">Wszystkie kanały z eksportem stanów</option>
                        @foreach (($syncChannels ?? collect()) as $channel)
                            <option value="{{ $channel->id }}">{{ $channel->code }} - {{ $channel->name }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="form-actions">
                    <button class="button" type="submit">Przelicz i dodaj do kolejki</button>
                </div>
            </form>
        </article>
    @endif

    @if (($module ?? null) === 'orders' && isset($orders))
        @include('modules.orders-list', [
            'orders' => $orders,
            'recordLabel' => $recordLabel,
            'activeReservationSums' => $activeReservationSums ?? [],
            'latestWzDocuments' => $latestWzDocuments ?? [],
            'orderFilters' => $orderFilters ?? [],
            'orderStatusOptions' => $orderStatusOptions ?? collect(),
        ])
    @else
    <article class="card">
        <div class="panel-header">
            <span>{{ $title }}</span>
            <span>{{ $recordLabel }}</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            @foreach ($row as $index => $cell)
                                <td>
                                    @if (($html_last_column ?? false) && $loop->last)
                                        {!! $cell !!}
                                    @else
                                        {{ $cell }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) }}">Brak danych w module.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($hasPagination && $rows->hasPages())
            <div class="module-pagination">
                <div class="module-pagination-range">
                    @if (method_exists($rows, 'firstItem') && $rows->firstItem() !== null)
                        Wyświetlono {{ $rows->firstItem() }} - {{ $rows->lastItem() }}
                    @else
                        Wyświetlono {{ $rowsCount }} rekordów
                    @endif
                </div>
                <div class="inline-actions">
                    @if (method_exists($rows, 'onFirstPage') && $rows->onFirstPage())
                        <span class="button secondary disabled">Poprzednie</span>
                    @elseif (method_exists($rows, 'previousPageUrl') && $rows->previousPageUrl())
                        <a class="button secondary" href="{{ $rows->previousPageUrl() }}">Poprzednie</a>
                    @endif

                    @if (method_exists($rows, 'hasMorePages') && $rows->hasMorePages() && method_exists($rows, 'nextPageUrl'))
                        <a class="button secondary" href="{{ $rows->nextPageUrl() }}">Następne</a>
                    @else
                        <span class="button secondary disabled">Następne</span>
                    @endif
                </div>
            </div>
        @endif
    </article>
    @endif
@endsection

@push('styles')
    <style>
        .module-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 12px 16px;
            border-top: 1px solid var(--border);
        }
        .module-pagination-range {
            color: var(--muted);
            font-size: 13px;
        }
        .module-pagination .disabled {
            cursor: default;
            opacity: .55;
        }
        .orders-list-card {
            overflow: hidden;
        }
        .orders-filter {
            display: grid;
            grid-template-columns: minmax(260px, 1fr) minmax(180px, 240px) minmax(120px, 150px) auto;
            gap: 12px;
            align-items: end;
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 255, 255, .52);
        }
        .orders-mobile-filter-toggle, .orders-mobile-filter-trigger, .orders-mobile-filter-backdrop, .orders-filter-drawer-header { display: none; }
        .orders-filter-search input {
            min-width: 0;
        }
        .orders-filter-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            min-height: 42px;
        }
        .orders-table-scroll table {
            min-width: 1320px;
        }
        .orders-table th,
        .orders-table td {
            white-space: normal;
            vertical-align: top;
        }
        .orders-table th.numeric,
        .orders-table td.numeric {
            text-align: right;
        }
        .order-main-cell > *,
        .order-customer-cell > *,
        .order-delivery-cell > *,
        .order-date-cell > * {
            display: block;
        }
        .order-main-cell > * + *,
        .order-customer-cell > * + *,
        .order-delivery-cell > * + *,
        .order-date-cell > * + * {
            margin-top: 3px;
        }
        .order-number-link {
            color: var(--text);
            text-decoration: none;
            font-weight: 850;
            font-size: 15px;
        }
        .order-number-link:hover {
            color: var(--green-dark);
            text-decoration: underline;
        }
        .order-meta,
        .order-customer-cell span,
        .order-delivery-cell span,
        .order-date-cell span {
            color: var(--muted);
            font-size: 12px;
        }
        .order-items-cell {
            min-width: 330px;
            max-width: 430px;
        }
        .order-items-stack {
            display: grid;
            gap: 8px;
        }
        .order-item-row {
            display: grid;
            grid-template-columns: 54px minmax(0, 1fr);
            gap: 10px;
            align-items: center;
            min-width: 0;
        }
        .order-item-thumb {
            width: 54px;
            height: 68px;
            border: 1px solid var(--border);
            border-radius: 7px;
            overflow: hidden;
            background: var(--surface-soft);
            display: grid;
            place-items: center;
            color: var(--muted);
            font-weight: 850;
        }
        .order-item-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .order-item-copy {
            min-width: 0;
            display: grid;
            gap: 2px;
        }
        .order-item-copy strong {
            font-size: 13px;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }
        .order-item-copy span,
        .order-more-lines {
            color: var(--muted);
            font-size: 12px;
        }
        .order-total-cell {
            font-weight: 850;
            font-variant-numeric: tabular-nums;
        }
        .order-actions-cell {
            min-width: 250px;
        }
        .order-actions-cell .inline-actions {
            align-items: flex-start;
        }
        @media (max-width: 980px) {
            .orders-filter {
                grid-template-columns: 1fr 1fr;
            }
            .orders-filter-search,
            .orders-filter-actions {
                grid-column: 1 / -1;
            }
        }
        @media (max-width: 900px) {
            .orders-mobile-filter-trigger { display: inline-flex; margin: 12px 16px; }
            .orders-mobile-filter-backdrop { position: fixed; inset: 0; z-index: 80; background: rgba(37, 31, 26, .42); }
            .orders-filter-drawer { position: fixed; top: 0; right: 0; z-index: 90; width: min(420px, 94vw); height: 100dvh; overflow-y: auto; padding: 14px; border-left: 1px solid var(--border); background: var(--surface); box-shadow: -24px 0 48px rgba(37, 31, 26, .18); transform: translateX(105%); transition: transform .18s ease; }
            .orders-filter-drawer-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; font-weight: 850; }
            .orders-filter-drawer-close { width: 34px; height: 34px; display: grid; place-items: center; border: 1px solid var(--border); border-radius: 8px; color: var(--muted); font-size: 22px; cursor: pointer; }
            .orders-filter-drawer .orders-filter { padding: 0; border: 0; background: transparent; }
            #orders-mobile-filter-toggle:checked ~ .orders-mobile-filter-backdrop { display: block; }
            #orders-mobile-filter-toggle:checked ~ .orders-filter-drawer { transform: translateX(0); }
            .orders-filter {
                grid-template-columns: 1fr;
            }
            .orders-filter-search,
            .orders-filter-actions {
                grid-column: auto;
            }
            .orders-table-scroll {
                overflow: visible;
            }
            .orders-table-scroll .orders-table {
                display: block;
                width: 100%;
                min-width: 0;
                border-collapse: separate;
            }
            .orders-table thead {
                display: none;
            }
            .orders-table tbody {
                display: grid;
                gap: 12px;
                padding: 12px;
            }
            .orders-table tbody tr {
                display: block;
                min-width: 0;
                overflow: hidden;
                border: 1px solid var(--border);
                border-radius: 10px;
                background: var(--surface);
            }
            .orders-table tbody td {
                display: block;
                width: 100%;
                min-width: 0;
                max-width: none;
                padding: 11px 12px;
                border: 0;
                border-bottom: 1px solid var(--border);
                box-sizing: border-box;
                text-align: left;
                overflow-wrap: anywhere;
            }
            .orders-table tbody td:last-child {
                border-bottom: 0;
            }
            .orders-table tbody td::before {
                content: attr(data-label);
                display: block;
                margin-bottom: 6px;
                color: var(--muted);
                font-size: 10px;
                font-weight: 850;
                letter-spacing: .055em;
                line-height: 1.2;
                text-transform: uppercase;
            }
            .orders-table td.numeric {
                text-align: left;
            }
            .orders-table .order-item-row {
                grid-template-columns: 46px minmax(0, 1fr);
            }
            .orders-table .order-item-thumb {
                width: 46px;
                height: 58px;
            }
            .orders-table .order-actions-cell .inline-actions {
                display: flex;
                flex-wrap: wrap;
                width: 100%;
                gap: 8px;
            }
            .orders-table .order-actions-cell .inline-actions > * {
                flex: 1 1 132px;
                justify-content: center;
            }
            .orders-table .orders-empty-row td::before {
                content: none;
            }
        }
    </style>
@endpush
