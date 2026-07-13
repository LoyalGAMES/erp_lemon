@extends('layouts.app', [
    'title' => match ($packingView ?? 'home') {
        'collect' => 'Kompletacja',
        'waiting' => 'Oczekuje na kuriera',
        'shipped' => 'Wysłane',
        'problems' => 'Problemy',
        'history' => 'Historia pakowania',
        default => 'Pakowanie',
    },
    'module' => 'packing',
    'hideTopActions' => true,
    'compactHeader' => in_array(($packingView ?? 'home'), ['collect', 'pack', 'waiting', 'shipped', 'problems', 'history'], true),
    'headerBackUrl' => in_array(($packingView ?? 'home'), ['collect', 'pack', 'waiting', 'shipped', 'problems', 'history'], true) ? route('packing.index', ['view' => 'home']) : null,
])

@push('styles')
    <style>
        .packing-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; }
        .packing-toolbar-summary { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .packing-toolbar-chip { display: inline-flex; align-items: center; min-height: 38px; border: 1px solid var(--border); border-radius: 8px; padding: 7px 12px; background: var(--surface); color: var(--green-dark); font-weight: 760; box-shadow: var(--shadow); }
        .packing-settings-trigger { min-height: 42px; white-space: nowrap; }
        .packing-settings-overlay[hidden] { display: none; }
        .packing-settings-overlay { position: fixed; inset: 0; z-index: 90; display: grid; grid-template-columns: minmax(0, 1fr) minmax(340px, 430px); }
        .packing-settings-backdrop { grid-column: 1 / -1; grid-row: 1; border: 0; background: rgba(33, 28, 24, .36); cursor: default; }
        .packing-settings-drawer { position: relative; z-index: 1; grid-column: 2; grid-row: 1; height: 100vh; overflow-y: auto; padding: 18px; background: var(--surface); border-left: 1px solid var(--border); box-shadow: -18px 0 38px rgba(33, 28, 24, .18); display: grid; gap: 14px; align-content: start; }
        .packing-drawer-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
        .packing-drawer-header h2 { margin: 0; font-size: 20px; line-height: 1.15; }
        .drawer-close { min-width: 42px; min-height: 42px; padding: 0; border-radius: 8px; }
        .packing-control-section { border: 1px solid var(--border); border-radius: 8px; padding: 14px; display: grid; gap: 12px; align-content: start; background: #fffdfb; }
        .packing-control-header { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; }
        .packing-control-header .button { min-height: 38px; white-space: nowrap; }
        .packing-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .packing-stat { padding: 14px 16px; }
        .packing-stat-link { color: var(--text); text-decoration: none; transition: transform .15s ease, border-color .15s ease; }
        .packing-stat-link:hover { transform: translateY(-2px); border-color: rgba(134, 115, 100, .48); }
        .packing-stat strong { display: block; font-size: 25px; line-height: 1; margin-top: 3px; }
        .packing-workflow-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .packing-workflow-tab { display: inline-flex; align-items: center; gap: 7px; min-height: 40px; border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; background: var(--surface); color: var(--muted); text-decoration: none; font-weight: 760; }
        .packing-workflow-tab.active { background: var(--green-soft); border-color: rgba(134, 115, 100, .42); color: var(--green-dark); }
        .packing-workflow-tab-count { display: inline-flex; align-items: center; justify-content: center; min-width: 21px; min-height: 21px; border-radius: 999px; padding: 0 6px; background: rgba(134, 115, 100, .14); font-size: 12px; }
        .workflow-picker { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .workflow-card { min-height: 148px; display: grid; align-content: center; gap: 7px; border: 1px solid var(--border); border-radius: 8px; padding: 22px; background: var(--surface); color: var(--text); text-decoration: none; box-shadow: var(--shadow); }
        .workflow-card span { color: var(--muted); font-weight: 780; text-transform: uppercase; letter-spacing: .04em; font-size: 12px; }
        .workflow-card strong { font-size: clamp(32px, 4vw, 50px); line-height: 1; letter-spacing: -.03em; }
        .workflow-card small { color: var(--muted); font-weight: 650; }
        .packing-home-links { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
        .packing-home-links .button { min-height: 46px; }
        .mode-copy { color: var(--muted); }
        .mode-copy strong { display: block; color: var(--text); font-size: 16px; margin-bottom: 3px; }
        .mode-actions { display: grid; gap: 8px; }
        .mode-button { width: 100%; min-height: 46px; border: 1px solid var(--border); border-radius: 8px; padding: 8px 11px; background: #fff; color: var(--text); font: inherit; font-weight: 760; cursor: pointer; text-align: left; }
        .mode-button.active { background: var(--green); border-color: var(--green); color: #fff; }
        .collection-workspace { max-width: 1040px; margin: 0 auto; }
        .queue-list { display: grid; gap: 12px; }
        .pick-card, .order-card, .courier-card, .history-card { border: 1px solid var(--border); border-radius: 8px; background: var(--surface); box-shadow: var(--shadow); }
        .collect-card { padding: 14px; display: grid; gap: 11px; }
        .collect-order-card { padding: 16px; }
        .collect-order-customer { color: var(--muted); font-size: 13px; margin-top: 4px; }
        .collect-main { display: grid; grid-template-columns: 78px minmax(0, 1fr) auto; gap: 14px; align-items: center; }
        .product-thumb { width: 58px; height: 72px; border: 1px solid var(--border); border-radius: 7px; overflow: hidden; background: #f4f1ef; display: grid; place-items: center; color: var(--muted); font-size: 11px; font-weight: 780; }
        .product-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .collect-card .product-thumb { width: 78px; height: 98px; }
        .pick-name { font-size: 17px; font-weight: 840; line-height: 1.25; }
        .pick-sku { color: var(--muted); font-size: 12px; font-weight: 760; letter-spacing: .02em; margin-top: 2px; }
        .collect-size { margin-top: 8px; display: inline-flex; align-items: baseline; gap: 8px; color: var(--muted); font-weight: 760; }
        .collect-size strong { color: var(--green-dark); font-size: clamp(38px, 8vw, 66px); line-height: .85; letter-spacing: -.04em; }
        .qty-pill { min-width: 82px; text-align: center; border-radius: 8px; padding: 10px 12px; background: var(--green-soft); color: var(--green-dark); font-weight: 850; font-size: 17px; }
        .pick-badges, .order-badges, .history-badges { display: flex; flex-wrap: wrap; gap: 6px; }
        .pick-badge { display: inline-flex; align-items: center; min-height: 26px; border-radius: 7px; padding: 2px 8px; background: rgba(134, 115, 100, .08); color: var(--muted); font-size: 12px; font-weight: 720; }
        .collect-note input { min-height: 48px; }
        .collect-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .collect-actions form { min-width: 0; display: grid; gap: 8px; }
        .collect-actions input { min-height: 42px; }
        .collect-actions .button { width: 100%; min-height: 64px; font-size: 19px; border-radius: 8px; }
        .button.danger { background: #ffecec; color: var(--red); border: 1px solid #f0c3c3; }
        .packing-empty { padding: 18px 16px; color: var(--muted); background: var(--surface); border: 1px solid var(--border); border-radius: 8px; }
        .segment-tabs { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 14px; }
        .segment-tab { display: inline-flex; align-items: center; gap: 7px; border: 1px solid var(--border); border-radius: 8px; padding: 8px 14px; font-weight: 760; color: var(--muted); text-decoration: none; background: var(--surface); }
        .segment-tab.active { border-color: var(--brand); color: var(--brand-dark); background: var(--brand-soft); }
        .segment-tab-count { display: inline-flex; align-items: center; justify-content: center; min-width: 21px; min-height: 21px; border-radius: 999px; padding: 0 6px; background: rgba(134, 115, 100, .16); font-size: 12px; }
        .station-chip { margin-left: auto; display: inline-flex; align-items: center; border: 1px solid var(--border); border-radius: 8px; padding: 8px 13px; background: #fffdfb; font-weight: 740; color: var(--green-dark); }
        .pick-badge.segment-footwear { background: #fff0e8; color: var(--orange); }
        .pick-badge.segment-clothing { background: var(--brand-soft); color: var(--brand-dark); }
        .label-account-form { display: grid; grid-template-columns: minmax(0, 1fr); gap: 6px; }
        .label-account-form select { min-height: 42px; }
        .label-account-form .button { min-height: 42px; }
        .shipment-label-panel { display: grid; gap: 7px; min-width: 0; border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: var(--green-soft); }
        .shipment-label-number { color: var(--green-dark); font-weight: 850; overflow-wrap: anywhere; }
        .shipment-label-actions { display: flex; flex-wrap: wrap; gap: 7px; }
        .shipment-label-actions .button { min-height: 42px; width: auto; font-size: 14px; }
        .shipment-label-error { border: 1px solid #f0c3c3; border-radius: 8px; padding: 9px 11px; background: #fff5f5; color: var(--red); font-size: 13px; overflow-wrap: anywhere; }
        @media (max-width: 760px) {
            .packing-toolbar { display: grid; }
            .packing-settings-overlay { grid-template-columns: 1fr; }
            .packing-settings-drawer { grid-column: 1; width: min(100vw, 430px); margin-left: auto; }
            .packing-control-header { display: grid; }
            .station-chip { margin-left: 0; }
        }
        .history-panel { margin-top: 16px; }
        .history-list { display: grid; gap: 8px; padding: 12px; }
        .history-card { padding: 10px 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; box-shadow: none; }
        .pack-workspace { display: grid; gap: 16px; }
        .order-card { padding: 18px; display: grid; gap: 13px; }
        .order-card-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .order-title { font-size: 24px; line-height: 1.1; font-weight: 880; letter-spacing: -.02em; }
        .order-title a { color: inherit; text-decoration: none; }
        .order-title a:hover { text-decoration: underline; }
        .order-meta { color: var(--muted); margin-top: 4px; font-size: 15px; }
        .order-items { display: grid; gap: 8px; }
        .order-item { display: grid; grid-template-columns: 52px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 10px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; }
        .order-item .product-thumb { width: 52px; height: 64px; font-size: 10px; }
        .order-item-name { font-weight: 820; font-size: 16px; line-height: 1.25; }
        .order-item-meta { color: var(--muted); font-size: 13px; margin-top: 2px; }
        .order-details, .order-notes { color: var(--muted); }
        .order-details summary, .order-notes summary { cursor: pointer; color: var(--green-dark); font-weight: 760; }
        .order-details-grid { display: grid; gap: 5px; margin-top: 8px; }
        .order-details-grid strong { color: var(--text); }
        .order-actions { display: grid; grid-template-columns: minmax(160px, .55fr) minmax(260px, 1fr) minmax(190px, .65fr); gap: 10px; align-items: stretch; }
        .order-actions .button { min-height: 58px; width: 100%; font-size: 17px; border-radius: 8px; }
        .order-problem-form { display: grid; grid-template-columns: minmax(120px, 1fr) auto; gap: 8px; }
        .order-problem-form input { min-height: 58px; }
        .courier-panel { margin-top: 2px; }
        .courier-panel-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 8px; }
        .courier-panel-actions .button { min-height: 38px; }
        .courier-list { display: grid; gap: 10px; padding: 12px; }
        .courier-card { padding: 14px; display: grid; gap: 12px; }
        .courier-card-header { display: flex; justify-content: space-between; align-items: center; gap: 14px; }
        .courier-title { font-size: 18px; font-weight: 850; }
        .courier-meta { color: var(--muted); margin-top: 3px; }
        .courier-card .button { min-height: 52px; min-width: 140px; border-radius: 8px; font-size: 16px; }
        .courier-orders { display: grid; gap: 8px; }
        .courier-order-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 10px; align-items: center; border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: #fffdfb; }
        .courier-order-main { display: grid; gap: 5px; min-width: 0; }
        .courier-order-actions { display: flex; flex-wrap: wrap; gap: 7px; align-items: center; }
        .courier-order-actions .button { min-width: 0; min-height: 42px; font-size: 14px; }
        .tracking-state { font-size: 12px; color: var(--muted); overflow-wrap: anywhere; }
        .order-rollback-form { display: flex; gap: 8px; align-items: center; }
        .order-rollback-form input { min-height: 46px; min-width: 210px; }
        .order-rollback-form .button { min-height: 46px; min-width: 104px; font-size: 15px; }
        .history-toolbar { display: flex; flex-wrap: wrap; align-items: end; gap: 10px; margin-bottom: 14px; }
        .history-toolbar label { display: grid; gap: 5px; font-weight: 780; color: var(--muted); }
        .history-toolbar input { min-height: 46px; }
        .packing-history-order .order-card-header { align-items: center; }
        .history-order-meta { color: var(--muted); margin-top: 5px; }
        .history-order-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 8px; }
        .history-order-actions .order-rollback-form input { min-width: min(280px, 55vw); }
        .problem-panel { margin-top: 2px; }
        .problem-list { display: grid; gap: 10px; padding: 12px; }
        .problem-card { border: 1px solid #f0c3c3; border-radius: 8px; padding: 12px; background: #fffafa; display: grid; gap: 8px; }
        .problem-card-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .problem-reason { color: var(--red); font-weight: 780; }
        @media (max-width: 1100px) {
            .packing-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .order-actions { grid-template-columns: 1fr; }
            .order-problem-form { grid-template-columns: 1fr; }
        }
        @media (max-width: 760px) {
            .packing-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .workflow-picker { grid-template-columns: 1fr; }
            .workflow-card { min-height: 118px; padding: 18px; }
            .collect-main { grid-template-columns: 72px minmax(0, 1fr); }
            .collect-card .product-thumb { width: 72px; height: 92px; }
            .qty-pill { grid-column: 1 / -1; width: max-content; }
            .history-card, .courier-card-header, .order-card-header { display: grid; justify-content: stretch; }
            .courier-order-row { grid-template-columns: 1fr; }
            .order-rollback-form { display: grid; grid-template-columns: 1fr auto; }
            .order-rollback-form input { min-width: 0; }
            .order-title { font-size: 28px; }
            .order-item { grid-template-columns: 50px minmax(0, 1fr); }
            .order-item strong { grid-column: 2; }
            .shipment-label-actions, .courier-order-actions { display: grid; grid-template-columns: 1fr; }
            .shipment-label-actions .button, .courier-order-actions .button { width: 100%; }
        }
    </style>
@endpush

@section('content')
    @php
        $qty = fn ($value) => floor((float) $value) === (float) $value
            ? number_format((float) $value, 0, ',', ' ')
            : number_format((float) $value, 4, ',', ' ');
        $money = fn ($value, $currency = 'PLN') => number_format((float) $value, 2, ',', ' ') . ' ' . ($currency ?: 'PLN');
        $person = function (array $data): string {
            $name = trim(implode(' ', array_filter([
                $data['first_name'] ?? null,
                $data['last_name'] ?? null,
            ])));
            $company = trim((string) ($data['company'] ?? ''));

            return trim(implode(' / ', array_filter([$name, $company]))) ?: '-';
        };
        $address = function (array $data): string {
            $street = trim(implode(' ', array_filter([
                $data['address_1'] ?? null,
                $data['address_2'] ?? null,
            ])));
            $city = trim(implode(' ', array_filter([
                $data['postcode'] ?? null,
                $data['city'] ?? null,
            ])));

            return trim(implode(', ', array_filter([
                $street,
                $city,
                $data['country'] ?? null,
            ]))) ?: '-';
        };
        $modeLabels = [
            'manual' => 'Bez skanera',
            'hybrid' => 'Hybrydowy',
            'scanner' => 'Skaner',
        ];
        $historyStatusLabels = [
            'picked' => 'Zebrane',
            'packed' => 'Spakowane',
            'shipped' => 'Wysłane',
        ];
        $segmentLabels = [
            'all' => 'Wszystko',
            'clothing' => 'Odzież',
            'footwear' => 'Obuwie',
        ];
        $erpUser = request()->attributes->get('erp_user') ?: auth()->user();
        $canManagePackingSettings = ! is_object($erpUser)
            || ! method_exists($erpUser, 'canAccessArea')
            || $erpUser->canAccessArea('settings');
        $waitingCourierOrders = $waitingCourierGroups->sum('orders_count');
        $segmentQuery = fn (string $segment): array => array_filter([
            'view' => $packingView,
            'segment' => $segment,
        ]);
        $activeModeLabel = $modeLabels[$packingMode] ?? $packingMode;
        $activeStationLabel = $activeStation !== null
            ? $activeStation['name'] . ($activeStation['printer_name'] !== '' ? ' · ' . $activeStation['printer_name'] : '')
            : 'Bez stanowiska';
        $shippingProviderResolver = app(\App\Services\Shipping\ShippingProviderResolver::class);
        $workflowTabs = [
            'collect' => ['label' => 'Kompletacja', 'count' => $collectOrdersCount],
            'pack' => ['label' => 'Pakowanie', 'count' => $readyOrders->count()],
            'waiting' => ['label' => 'Oczekuje na kuriera', 'count' => $waitingCourierOrders],
            'shipped' => ['label' => 'Wysłane', 'count' => $shippedOrdersCount],
            'problems' => ['label' => 'Problemy', 'count' => $problemTasks->count()],
        ];
    @endphp

    @if ($packingView === 'home')
        <section class="packing-toolbar" aria-label="Ustawienia pracy pakowania">
            <div class="packing-toolbar-summary">
                <span class="packing-toolbar-chip">Tryb: {{ $activeModeLabel }}</span>
                <span class="packing-toolbar-chip">Stanowisko: {{ $activeStationLabel }}</span>
            </div>
            <button class="button secondary packing-settings-trigger" type="button" data-packing-settings-open>
                Ustawienia pracy
            </button>
        </section>

        <div class="packing-settings-overlay" data-packing-settings-overlay hidden>
            <button class="packing-settings-backdrop" type="button" aria-label="Zamknij ustawienia pracy" data-packing-settings-close></button>
            <aside class="packing-settings-drawer" role="dialog" aria-modal="true" aria-labelledby="packing-settings-title">
                <div class="packing-drawer-header">
                    <div>
                        <h2 id="packing-settings-title">Ustawienia pracy</h2>
                        <span class="muted">Tryb kompletacji, stanowisko i domyślna drukarka dla tej sesji.</span>
                    </div>
                    <button class="button secondary drawer-close" type="button" aria-label="Zamknij" data-packing-settings-close>&times;</button>
                </div>

                <article class="packing-control-section">
                    <div class="mode-copy">
                        <strong>Sposób pracy</strong>
                        Bez skanera system sortuje kompletację po lokalizacji magazynowej. Tryb skanera zostaje jako ustawienie procesu, kiedy magazyn będzie gotowy na skanowanie.
                    </div>
                    <div class="mode-actions" aria-label="Tryb pakowania">
                        @foreach ($modeLabels as $mode => $label)
                            <form method="POST" action="{{ route('packing.mode') }}">
                                @csrf
                                <input type="hidden" name="mode" value="{{ $mode }}">
                                <button @class(['mode-button', 'active' => $packingMode === $mode]) type="submit">{{ $label }}</button>
                            </form>
                        @endforeach
                    </div>
                </article>

                <article class="packing-control-section">
                    <div class="packing-control-header">
                        <div class="mode-copy">
                            <strong>Twoje stanowisko pakowania</strong>
                            Stanowisko ustawia domyślny widok kompletacji i pakowania oraz drukarkę etykiet.
                        </div>
                        @if ($canManagePackingSettings)
                            <a class="button secondary" href="{{ route('settings.packing') }}">Drukarki i stanowiska</a>
                        @endif
                    </div>
                    <div class="mode-actions" aria-label="Stanowisko pakowania">
                        @foreach ($packingStations as $stationOption)
                            <form method="POST" action="{{ route('packing.station') }}">
                                @csrf
                                <input type="hidden" name="station" value="{{ $stationOption['code'] }}">
                                <button @class(['mode-button', 'active' => ($activeStation['code'] ?? null) === $stationOption['code']]) type="submit">
                                    {{ $stationOption['name'] }} · {{ $segmentLabels[$stationOption['segment']] ?? $stationOption['segment'] }}
                                    @if ($stationOption['printer_name'] !== '')
                                        · {{ $stationOption['printer_name'] }}
                                    @endif
                                </button>
                            </form>
                        @endforeach
                        <form method="POST" action="{{ route('packing.station') }}">
                            @csrf
                            <button @class(['mode-button', 'active' => $activeStation === null]) type="submit">Bez stanowiska (wszystkie produkty)</button>
                        </form>
                    </div>
                </article>
            </aside>
        </div>

        <section class="packing-stats" aria-label="Status wysyłki">
            <a class="card packing-stat packing-stat-link" href="{{ route('packing.index', ['view' => 'collect']) }}">
                <span class="muted">Do zebrania</span>
                <strong>{{ $collectOrdersCount }}</strong>
            </a>
            <a class="card packing-stat packing-stat-link" href="{{ route('packing.index', ['view' => 'pack']) }}">
                <span class="muted">Do pakowania</span>
                <strong>{{ $readyOrders->count() }}</strong>
            </a>
            <a class="card packing-stat packing-stat-link" href="{{ route('packing.index', ['view' => 'waiting']) }}">
                <span class="muted">Oczekuje na kuriera</span>
                <strong>{{ $waitingCourierOrders }}</strong>
            </a>
            <a class="card packing-stat packing-stat-link" href="{{ route('packing.index', ['view' => 'shipped']) }}">
                <span class="muted">Wysłane</span>
                <strong>{{ $shippedOrdersCount }}</strong>
            </a>
            <a class="card packing-stat packing-stat-link" href="{{ route('packing.index', ['view' => 'problems']) }}">
                <span class="muted">Problemy</span>
                <strong>{{ $problemTasks->count() }}</strong>
            </a>
        </section>

    @endif

    @if ($packingView !== 'home' && $packingView !== 'history')
        <nav class="packing-workflow-tabs" aria-label="Etapy realizacji zamówień">
            @foreach ($workflowTabs as $view => $tab)
                <a @class(['packing-workflow-tab', 'active' => $packingView === $view]) href="{{ route('packing.index', ['view' => $view]) }}">
                    {{ $tab['label'] }}
                    <span class="packing-workflow-tab-count">{{ $tab['count'] }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    @if (in_array($packingView, ['collect', 'pack'], true))
        <nav class="segment-tabs" aria-label="Podział asortymentu">
            @foreach ($segmentLabels as $segmentValue => $segmentLabel)
                <a @class(['segment-tab', 'active' => $activeSegment === $segmentValue]) href="{{ route('packing.index', $segmentQuery($segmentValue)) }}">
                    {{ $segmentLabel }}
                    @if ($packingView === 'collect')
                        <span class="segment-tab-count">{{ $segmentCounts[$segmentValue] ?? 0 }}</span>
                    @endif
                </a>
            @endforeach
            @if ($activeStation !== null)
                <span class="station-chip">{{ $activeStation['name'] }}@if ($activeStation['printer_name'] !== '') · {{ $activeStation['printer_name'] }}@endif</span>
            @endif
        </nav>
    @endif

    @if ($packingView === 'collect')
        <div class="collection-workspace">
            <div class="queue-list">
                @forelse ($collectOrders as $collectOrder)
                    @php
                        $problemFormId = 'problem-order-' . md5(implode('-', $collectOrder['task_ids']));
                    @endphp
                    <article class="order-card collect-order-card">
                        <div class="order-card-header">
                            <div>
                                <div class="order-title">Zamówienie {{ $collectOrder['order_number'] }}</div>
                                <div class="collect-order-customer">Odbiorca: {{ $collectOrder['customer_name'] }}</div>
                                <div class="order-meta">
                                    {{ $collectOrder['courier'] }} · {{ $collectOrder['positions_count'] }} poz. · złożone {{ $collectOrder['order_date']?->format('Y-m-d H:i') ?? '-' }}
                                </div>
                            </div>
                            <div class="order-badges">
                                @foreach ($collectOrder['segments'] as $segment)
                                    <span class="pick-badge segment-{{ $segment }}">{{ $segmentLabels[$segment] ?? $segment }}</span>
                                @endforeach
                                <span class="qty-pill">{{ $qty($collectOrder['quantity']) }} szt.</span>
                            </div>
                        </div>

                        <div class="order-items">
                            @foreach ($collectOrder['tasks'] as $task)
                                @php
                                    $taskLocation = data_get($task->metadata, 'warehouse_location')
                                        ?: data_get($task->product?->attributes, 'master.stock.location')
                                        ?: data_get($task->product?->attributes, 'warehouse_location')
                                        ?: '-';
                                @endphp
                                <div class="order-item">
                                    <div class="product-thumb">
                                        @if ($task->product?->imageUrl())
                                            <img src="{{ $task->product->imageUrl() }}" alt="{{ $task->product_name }}" loading="lazy" referrerpolicy="no-referrer">
                                        @else
                                            Foto
                                        @endif
                                    </div>
                                    <div>
                                        <div class="order-item-name">{{ $task->product_name }}</div>
                                        <div class="order-item-meta">{{ $task->sku ?: 'brak SKU' }} · Rozmiar <strong>{{ $task->size_label ?: '-' }}</strong> · Lok. {{ $taskLocation }}</div>
                                    </div>
                                    <strong>{{ $qty($task->remainingQuantity()) }} szt.</strong>
                                </div>
                            @endforeach
                        </div>

                        <div class="collect-actions">
                            <form id="{{ $problemFormId }}" method="POST" action="{{ route('packing.groups.problem') }}">
                                @csrf
                                @foreach ($collectOrder['task_ids'] as $taskId)
                                    <input type="hidden" name="task_ids[]" value="{{ $taskId }}">
                                @endforeach
                                <input name="reason" placeholder="Notatka problemu">
                                <button class="button danger" type="submit">Problem</button>
                            </form>
                            <form method="POST" action="{{ route('packing.groups.pick') }}">
                                @csrf
                                @foreach ($collectOrder['task_ids'] as $taskId)
                                    <input type="hidden" name="task_ids[]" value="{{ $taskId }}">
                                @endforeach
                                <button class="button" type="submit">Zebrane</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <div class="packing-empty">Brak produktów do zebrania. Nie ma też zamówień oczekujących na kompletację.</div>
                @endforelse
            </div>

            <section class="card history-panel">
                <div class="panel-header">
                    <span>Historia kompletacji</span>
                    <span>{{ $recentPickedTasks->count() }} ostatnich pozycji</span>
                </div>
                <div class="history-list">
                    @forelse ($recentPickedTasks as $task)
                        <article class="history-card">
                            <div>
                                <strong>{{ $task->product_name }}</strong><br>
                                <span class="muted">{{ $task->sku ?: 'brak SKU' }} · rozmiar {{ $task->size_label ?: '-' }} · zam. {{ $task->order_number }}</span>
                            </div>
                            <div class="history-badges">
                                <span @class(['status', 'blue' => $task->status === 'picked', 'orange' => $task->status === 'packed'])>{{ $historyStatusLabels[$task->status] ?? $task->status }}</span>
                                <span class="pick-badge">{{ $task->picked_at?->format('Y-m-d H:i') ?? '-' }}</span>
                            </div>
                        </article>
                    @empty
                        <div class="packing-empty">Nie ma jeszcze historii kompletacji.</div>
                    @endforelse
                </div>
            </section>
        </div>
    @endif

    @if ($packingView === 'pack')
        <div class="pack-workspace">
            @if ($activeStation === null)
                <div class="alert error" role="alert">
                    Automatyczny wydruk jest wyłączony dla tej sesji. Wróć do pulpitu pakowania, otwórz „Ustawienia pracy” i wybierz stanowisko z drukarką.
                </div>
            @elseif (trim((string) ($activeStation['printer_name'] ?? '')) === '')
                <div class="alert error" role="alert">
                    Stanowisko „{{ $activeStation['name'] }}” nie ma przypisanej drukarki. Administrator może wybrać ją z listy Windows w konfiguracji stanowisk.
                </div>
            @endif
            <section class="queue-list" aria-label="Lista do pakowania">
                @forelse ($readyOrders as $order)
                    @php
                        $tasksForOrder = $order->packingTasks;
                        $firstTask = $tasksForOrder->first();
                        $shippingLabel = $order->shipmentLabels?->firstWhere('status', 'generated');
                        $shippingTrackingUrl = $shippingLabel
                            ? $shippingProviderResolver->trackingUrl($shippingLabel)
                            : null;
                        $customerNote = trim((string) data_get($firstTask?->metadata, 'customer_note', ''));
                        $orderNotes = collect(data_get($firstTask?->metadata, 'order_notes', []))
                            ->pluck('note')
                            ->filter()
                            ->implode(' | ');
                        $notes = trim(implode(' | ', array_filter([$customerNote, $orderNotes])));
                        $shipping = (array) data_get($firstTask?->metadata, 'shipping', []);
                        $billing = (array) data_get($firstTask?->metadata, 'billing', []);
                        $phone = data_get($shipping, 'phone') ?: data_get($billing, 'phone') ?: '-';
                        $email = data_get($billing, 'email') ?: '-';
                        $payment = data_get($firstTask?->metadata, 'payment_method') ?: '-';
                        $labelAutomationStatus = data_get($firstTask?->metadata, 'label_automation.status');
                        $labelAutomationMessage = trim((string) data_get($firstTask?->metadata, 'label_automation.message', ''));
                    @endphp
                    <article class="order-card">
                        <div class="order-card-header">
                            <div>
                                <div class="order-title"><a href="{{ route('orders.show', $order) }}">Zamówienie {{ $order->external_number }}</a></div>
                                <div class="order-meta">{{ $order->salesChannel?->code ?? '-' }} · {{ $firstTask?->customer_name ?: '-' }}</div>
                            </div>
                            <div class="order-badges">
                                @php $orderSegments = $order->packing_segments ?? []; @endphp
                                @if (count($orderSegments) > 1)
                                    <span class="status orange">Mieszane</span>
                                @elseif ($orderSegments !== [])
                                    <span class="status">{{ $segmentLabels[$orderSegments[0]] ?? $orderSegments[0] }}</span>
                                @endif
                                <span class="status blue">{{ $firstTask?->courier ?: 'Kurier' }}</span>
                                <span class="status">{{ $tasksForOrder->count() }} poz.</span>
                            </div>
                        </div>

                        <div class="order-items">
                            @foreach ($tasksForOrder as $task)
                                @php
                                    $taskLocation = data_get($task->metadata, 'warehouse_location')
                                        ?: data_get($task->product?->attributes, 'master.stock.location')
                                        ?: data_get($task->product?->attributes, 'warehouse_location')
                                        ?: '-';
                                @endphp
                                <div class="order-item">
                                    <div class="product-thumb">
                                        @if ($task->product?->imageUrl())
                                            <img src="{{ $task->product->imageUrl() }}" alt="{{ $task->product_name }}" loading="lazy" referrerpolicy="no-referrer">
                                        @else
                                            Foto
                                        @endif
                                    </div>
                                    <div>
                                        <div class="order-item-name">{{ $task->product_name }}</div>
                                        <div class="order-item-meta">{{ $task->sku ?: 'brak SKU' }} · rozmiar {{ $task->size_label ?: '-' }} · lok. {{ $taskLocation }}</div>
                                    </div>
                                    <strong>{{ $qty($task->quantity_required) }} szt.</strong>
                                </div>
                            @endforeach
                        </div>

                        @if ($notes !== '')
                            <details class="order-notes">
                                <summary>Uwagi z WooCommerce</summary>
                                <div>{{ $notes }}</div>
                            </details>
                        @endif

                        <details class="order-details">
                            <summary>Dane wysyłki i płatności</summary>
                            <div class="order-details-grid">
                                <div><strong>Status Woo:</strong> {{ $order->status ?? '-' }}</div>
                                <div><strong>Wartość:</strong> {{ $money($order->total_gross ?? 0, $order->currency ?? 'PLN') }}</div>
                                <div><strong>Płatność:</strong> {{ $payment }}</div>
                                <div><strong>Kontakt:</strong> {{ $email }} · {{ $phone }}</div>
                                <div><strong>Wysyłka:</strong> {{ $person($shipping) }} · {{ $address($shipping) }}</div>
                                <div><strong>Billing:</strong> {{ $person($billing) }} · {{ $address($billing) }}</div>
                            </div>
                        </details>

                        @if (! $shippingLabel && $labelAutomationStatus === 'failed' && $labelAutomationMessage !== '')
                            <div class="shipment-label-error">
                                Automatyczne generowanie etykiety nie powiodło się. System ponowi próbę; możesz też użyć przycisku poniżej.<br>
                                {{ $labelAutomationMessage }}
                            </div>
                        @endif

                        <div class="order-actions">
                            @if ($shippingLabel)
                                <div class="shipment-label-panel">
                                    <div class="shipment-label-number">
                                        Nr etykiety: {{ $shippingLabel->trackingIdentifier() ?: '#'.$shippingLabel->id }}
                                    </div>
                                    <div class="shipment-label-actions">
                                        <a class="button secondary" href="{{ route('packing.labels.download', $shippingLabel) }}">Pobierz etykietę</a>
                                        @if ($shippingTrackingUrl)
                                            <a class="button secondary" href="{{ $shippingTrackingUrl }}" target="_blank" rel="noopener noreferrer" aria-label="Śledź przesyłkę {{ $shippingLabel->trackingIdentifier() }}">Śledź paczkę</a>
                                        @endif
                                    </div>
                                </div>
                            @else
                                <form class="label-account-form" method="POST" action="{{ route('packing.orders.label', $order) }}">
                                    @csrf
                                    @if ($courierAccounts->isNotEmpty())
                                        <select name="courier_account_id" aria-label="Konto nadawcze InPost">
                                            <option value="">Etykieta ze sklepu</option>
                                            @foreach ($courierAccounts as $courierAccount)
                                                <option value="{{ $courierAccount->id }}" @selected($courierAccount->is_default && $courierAccount->provider === 'inpost')>{{ $courierAccount->provider === 'blpaczka' ? 'BLPaczka' : 'InPost' }}: {{ $courierAccount->name }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <button class="button secondary" type="submit">Ponów etykietę</button>
                                </form>
                            @endif
                            <form class="order-problem-form" method="POST" action="{{ route('packing.orders.problem', $order) }}">
                                @csrf
                                <input name="reason" placeholder="Notatka problemu">
                                <button class="button danger" type="submit">Problem</button>
                            </form>
                            <form method="POST" action="{{ route('packing.orders.pack', $order) }}">
                                @csrf
                                <button class="button" type="submit">Spakuj</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <div class="packing-empty">Brak zamówień gotowych do pakowania. Po kompletacji zamówienia pojawią się tutaj automatycznie.</div>
                @endforelse
            </section>
        </div>
    @endif

    @if ($packingView === 'waiting')
        <div class="pack-workspace">
            <section class="card courier-panel">
                <div class="panel-header">
                    <span>Oczekuje na kuriera</span>
                    <div class="courier-panel-actions">
                        <span>{{ $waitingCourierOrders }} paczek</span>
                        <form method="POST" action="{{ route('packing.couriers.check-pickups') }}">
                            @csrf
                            <button class="button secondary" type="submit">Sprawdź odbiory</button>
                        </form>
                    </div>
                </div>
                <div class="courier-list">
                    @forelse ($waitingCourierGroups as $group)
                        @php
                            $oldestPacked = $group['oldest_packed_at'] ? \Illuminate\Support\Carbon::parse($group['oldest_packed_at']) : null;
                        @endphp
                        <article class="courier-card">
                            <div class="courier-card-header">
                                <div>
                                    <div class="courier-title">{{ $group['courier'] }}</div>
                                    <div class="courier-meta">
                                        {{ $group['orders_count'] }} paczek, {{ $group['tasks_count'] }} pozycji
                                        @if ($oldestPacked)
                                            · najstarsze {{ $oldestPacked->format('Y-m-d H:i') }}
                                        @endif
                                    </div>
                                </div>
                                <form method="POST" action="{{ route('packing.couriers.pickup') }}">
                                    @csrf
                                    <input type="hidden" name="courier" value="{{ $group['courier'] }}">
                                    @foreach ($group['orders'] as $queuedOrder)
                                        <input type="hidden" name="order_ids[]" value="{{ $queuedOrder['id'] }}">
                                    @endforeach
                                    <input type="hidden" name="pickup_token" value="{{ $group['pickup_token'] }}">
                                    <button class="button" type="submit">Odebrano</button>
                                </form>
                            </div>
                            <div class="courier-orders">
                                @foreach ($group['orders'] as $queuedOrder)
                                    @php
                                        $queuedPackedAt = $queuedOrder['packed_at'] ? \Illuminate\Support\Carbon::parse($queuedOrder['packed_at']) : null;
                                    @endphp
                                    <div class="courier-order-row">
                                        <div class="courier-order-main">
                                            <div>
                                                <strong><a href="{{ route('orders.show', $queuedOrder['id']) }}">Zamówienie {{ $queuedOrder['external_number'] }}</a></strong><br>
                                            <span class="muted">
                                                {{ $queuedOrder['customer_name'] }} · {{ $queuedOrder['tasks_count'] }} poz.
                                                @if ($queuedPackedAt)
                                                    · spakowane {{ $queuedPackedAt->format('Y-m-d H:i') }}
                                                @endif
                                            </span>
                                            </div>
                                            <div class="tracking-state">
                                                @if ($queuedOrder['label_number'])
                                                    Etykieta: <strong>{{ $queuedOrder['label_number'] }}</strong>
                                                    @if ($queuedOrder['tracking_status'])
                                                        · status: {{ $queuedOrder['tracking_status'] }}
                                                    @endif
                                                    @if ($queuedOrder['tracking_checked_at'])
                                                        · sprawdzono {{ $queuedOrder['tracking_checked_at']->format('Y-m-d H:i') }}
                                                    @endif
                                                    @if ($queuedOrder['tracking_error'])
                                                        <br>Ostatni błąd: {{ $queuedOrder['tracking_error'] }}
                                                    @endif
                                                @else
                                                    Brak numeru śledzenia — automat ponowi generowanie etykiety.
                                                    @if ($queuedOrder['label_error'])
                                                        <br>{{ $queuedOrder['label_error'] }}
                                                    @endif
                                                @endif
                                            </div>
                                            <div class="courier-order-actions">
                                                @if ($queuedOrder['label_id'])
                                                    <a class="button secondary" href="{{ route('packing.labels.download', $queuedOrder['label_id']) }}">Pobierz etykietę</a>
                                                @endif
                                                @if ($queuedOrder['tracking_url'])
                                                    <a class="button secondary" href="{{ $queuedOrder['tracking_url'] }}" target="_blank" rel="noopener noreferrer">Śledź paczkę</a>
                                                @endif
                                            </div>
                                        </div>
                                        <form class="order-rollback-form" method="POST" action="{{ route('packing.orders.unpack', $queuedOrder['id']) }}">
                                            @csrf
                                            <input name="reason" placeholder="Powód cofnięcia">
                                            <button class="button secondary" type="submit">Cofnij</button>
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        </article>
                    @empty
                        <div class="packing-empty">Nie ma paczek oczekujących na kuriera.</div>
                    @endforelse
                </div>
            </section>
        </div>
    @endif

    @if ($packingView === 'problems')
        <div class="pack-workspace">
                <section class="card problem-panel">
                    <div class="panel-header">
                        <span>Do wyjaśnienia</span>
                        <span>{{ $problemTasks->count() }} pozycji</span>
                    </div>
                    <div class="problem-list">
                        @forelse ($problemTasks as $task)
                            @php
                                $problemReason = data_get($task->metadata, 'packing_problem.reason', 'Do wyjaśnienia');
                                $problemAt = data_get($task->metadata, 'packing_problem.reported_at');
                                $problemLocation = data_get($task->metadata, 'warehouse_location')
                                    ?: data_get($task->product?->attributes, 'master.stock.location')
                                    ?: data_get($task->product?->attributes, 'warehouse_location')
                                    ?: '-';
                            @endphp
                            <article class="problem-card">
                                <div class="problem-card-header">
                                    <div>
                                        <strong>{{ $task->product_name }}</strong><br>
                                        <span class="muted">{{ $task->sku ?: 'brak SKU' }} · lok. {{ $problemLocation }} · zam. {{ $task->order_number }} · {{ $task->courier ?: 'Kurier' }}</span>
                                    </div>
                                    <span class="status red">Problem</span>
                                </div>
                                <div class="problem-reason">{{ $problemReason }}</div>
                                <div class="muted">Zgłoszono: {{ $problemAt ? \Illuminate\Support\Carbon::parse($problemAt)->format('Y-m-d H:i') : $task->updated_at?->format('Y-m-d H:i') }}</div>
                                <form method="POST" action="{{ route('packing.tasks.reopen', $task) }}">
                                    @csrf
                                    <button class="button secondary" type="submit">Przywróć do kolejki</button>
                                </form>
                            </article>
                        @empty
                            <div class="packing-empty">Nie ma pozycji wymagających wyjaśnienia.</div>
                        @endforelse
                    </div>
                </section>
        </div>
    @endif

    @if ($packingView === 'shipped')
        <div class="pack-workspace">
            <section class="queue-list" aria-label="Lista wysłanych zamówień">
                @forelse ($shippedOrders as $shippedOrder)
                    <article class="order-card packing-history-order">
                        <div class="order-card-header">
                            <div>
                                <div class="order-title">Zamówienie {{ $shippedOrder['order_number'] }}</div>
                                <div class="history-order-meta">
                                    {{ $shippedOrder['sales_channel'] }} · {{ $shippedOrder['customer_name'] }} · {{ $shippedOrder['courier'] }} · {{ $shippedOrder['tasks_count'] }} poz.
                                </div>
                            </div>
                            <div class="order-badges">
                                <span class="status green">Wysłane</span>
                                <span class="status">{{ $shippedOrder['pickup_at']?->format('Y-m-d H:i') ?? '-' }}</span>
                            </div>
                        </div>

                        <div class="history-order-meta">
                            Spakowane: {{ $shippedOrder['packed_at']?->format('Y-m-d H:i') ?? '-' }}
                            @if ($shippedOrder['pickup_at'])
                                · odebrane przez kuriera: {{ $shippedOrder['pickup_at']->format('Y-m-d H:i') }}
                            @endif
                        </div>

                        @if ($shippedOrder['label_number'])
                            <div class="shipment-label-panel">
                                <div class="shipment-label-number">Nr przesyłki: {{ $shippedOrder['label_number'] }}</div>
                                <div class="shipment-label-actions">
                                    @if ($shippedOrder['label_id'])
                                        <a class="button secondary" href="{{ route('packing.labels.download', $shippedOrder['label_id']) }}">Pobierz etykietę</a>
                                    @endif
                                    @if ($shippedOrder['tracking_url'])
                                        <a class="button secondary" href="{{ $shippedOrder['tracking_url'] }}" target="_blank" rel="noopener noreferrer">Śledź paczkę</a>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div class="order-items">
                            @foreach ($shippedOrder['items'] as $item)
                                <div class="order-item">
                                    <div class="product-thumb">
                                        @if ($item['image_url'])
                                            <img src="{{ $item['image_url'] }}" alt="{{ $item['name'] }}" loading="lazy" referrerpolicy="no-referrer">
                                        @else
                                            Foto
                                        @endif
                                    </div>
                                    <div>
                                        <div class="order-item-name">{{ $item['name'] }}</div>
                                        <div class="order-item-meta">{{ $item['sku'] ?: 'brak SKU' }} · rozmiar {{ $item['size_label'] ?: '-' }}</div>
                                    </div>
                                    <strong>{{ $qty($item['quantity']) }} szt.</strong>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @empty
                    <div class="packing-empty">Nie ma jeszcze wysłanych zamówień.</div>
                @endforelse
            </section>
        </div>
    @endif

    @if ($packingView === 'history')
        <div class="pack-workspace">
            <form class="history-toolbar" method="GET" action="{{ route('packing.index') }}">
                <input type="hidden" name="view" value="history">
                <label>
                    Data pakowania
                    <input type="date" name="date" value="{{ $packingHistoryDate }}">
                </label>
                <button class="button secondary" type="submit">Pokaż historię</button>
            </form>

            <section class="queue-list" aria-label="Historia pakowania według daty">
                @forelse ($packingHistoryOrders as $historyOrder)
                    <article class="order-card packing-history-order">
                        <div class="order-card-header">
                            <div>
                                <div class="order-title">Zamówienie {{ $historyOrder['order_number'] }}</div>
                                <div class="history-order-meta">
                                    {{ $historyOrder['sales_channel'] }} · {{ $historyOrder['customer_name'] }} · {{ $historyOrder['courier'] }} · {{ $historyOrder['tasks_count'] }} poz.
                                </div>
                            </div>
                            <div class="order-badges">
                                <span @class(['status', 'orange' => $historyOrder['status'] === 'packed', 'green' => $historyOrder['status'] === 'shipped'])>{{ $historyStatusLabels[$historyOrder['status']] ?? $historyOrder['status'] }}</span>
                                <span class="status">{{ $historyOrder['packed_at']?->format('H:i') ?? '-' }}</span>
                            </div>
                        </div>

                        <div class="history-order-meta">
                            Spakowane: {{ $historyOrder['packed_at']?->format('Y-m-d H:i') ?? '-' }}
                            @if ($historyOrder['pickup_at'])
                                · odebrane przez kuriera: {{ $historyOrder['pickup_at']->format('Y-m-d H:i') }}
                            @endif
                        </div>

                        <div class="order-items">
                            @foreach ($historyOrder['items'] as $item)
                                <div class="order-item">
                                    <div class="product-thumb">
                                        @if ($item['image_url'])
                                            <img src="{{ $item['image_url'] }}" alt="{{ $item['name'] }}" loading="lazy" referrerpolicy="no-referrer">
                                        @else
                                            Foto
                                        @endif
                                    </div>
                                    <div>
                                        <div class="order-item-name">{{ $item['name'] }}</div>
                                        <div class="order-item-meta">{{ $item['sku'] ?: 'brak SKU' }} · rozmiar {{ $item['size_label'] ?: '-' }}</div>
                                    </div>
                                    <strong>{{ $qty($item['quantity']) }} szt.</strong>
                                </div>
                            @endforeach
                        </div>

                        @if ($historyOrder['status'] === 'packed' && $historyOrder['order_id'])
                            <div class="history-order-actions">
                                <form class="order-rollback-form" method="POST" action="{{ route('packing.orders.unpack', $historyOrder['order_id']) }}">
                                    @csrf
                                    <input name="reason" placeholder="Powód cofnięcia">
                                    <button class="button secondary" type="submit">Cofnij pakowanie</button>
                                </form>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="packing-empty">Brak historii pakowania dla wybranej daty.</div>
                @endforelse
            </section>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        (() => {
            const overlay = document.querySelector('[data-packing-settings-overlay]');
            const openButton = document.querySelector('[data-packing-settings-open]');
            const closeButtons = document.querySelectorAll('[data-packing-settings-close]');

            if (!overlay || !openButton) {
                return;
            }

            const openDrawer = () => {
                overlay.hidden = false;
                document.body.style.overflow = 'hidden';
                overlay.querySelector('.drawer-close')?.focus();
            };

            const closeDrawer = () => {
                overlay.hidden = true;
                document.body.style.overflow = '';
                openButton.focus();
            };

            openButton.addEventListener('click', openDrawer);
            closeButtons.forEach((button) => button.addEventListener('click', closeDrawer));
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !overlay.hidden) {
                    closeDrawer();
                }
            });
        })();
    </script>
@endpush
