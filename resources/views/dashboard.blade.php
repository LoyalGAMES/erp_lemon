@extends('layouts.app', ['title' => 'Panel operacyjny', 'module' => 'dashboard'])

@section('content')
    @php
        $erpUser = request()->attributes->get('erp_user') ?: auth()->user();
        $canAccessArea = function (string $area) use ($erpUser): bool {
            return ! is_object($erpUser)
                || ! method_exists($erpUser, 'canAccessArea')
                || $erpUser->canAccessArea($area);
        };
        $metricAreas = [
            'Produkty' => 'products',
            'Magazyny' => 'warehouses',
            'Synchronizacje' => 'sync',
            'Faktury' => 'invoices',
        ];
        $visibleMetrics = collect($metrics)
            ->filter(fn (array $metric): bool => $canAccessArea($metricAreas[$metric[0]] ?? 'dashboard'));
        $dashboardPanels = [
            'warehouses' => $canAccessArea('warehouses'),
            'documents' => $canAccessArea('documents'),
            'integrations' => $canAccessArea('integrations'),
            'ksef' => $canAccessArea('ksef'),
        ];
        $hasDashboardPanels = in_array(true, $dashboardPanels, true);
    @endphp

    @if ($visibleMetrics->isNotEmpty())
        <section class="metrics" aria-label="Metryki">
            @foreach ($visibleMetrics as [$label, $value, $caption])
                <article class="card metric">
                    <div class="metric-label">{{ $label }}</div>
                    <div class="metric-value">{{ $value }}</div>
                    <div class="metric-caption">{{ $caption }}</div>
                </article>
            @endforeach
        </section>
    @endif

    @if ($dashboardPanels['warehouses'] || $dashboardPanels['documents'])
        <section class="grid-two">
            @if ($dashboardPanels['warehouses'])
                <article class="card">
                    <div class="panel-header">Stany magazynowe według magazynu</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Magazyn</th>
                                <th>Routing</th>
                                <th>Produkty</th>
                                <th>Ilość</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($warehouseBalances as $row)
                                <tr>
                                    <td>{{ $row['warehouse']->code }} - {{ $row['warehouse']->name }}</td>
                                    <td>{{ $row['routes']->isEmpty() ? 'Wewnętrzny' : $row['routes']->pluck('salesChannel.code')->implode(', ') }}</td>
                                    <td>{{ $row['products'] }}</td>
                                    <td>{{ number_format($row['quantity'], 0, ',', ' ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <a class="panel-link" href="{{ route('warehouses.index') }}">Zobacz magazyny -></a>
                </article>
            @endif

            @if ($dashboardPanels['documents'])
                <article class="card">
                    <div class="panel-header">Ostatnie dokumenty magazynowe</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Numer</th>
                                <th>Typ</th>
                                <th>Status</th>
                                <th>Źródło</th>
                                <th>Cel</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($documents as $document)
                                <tr>
                                    <td>{{ $document->number }}</td>
                                    <td>{{ $document->type }}</td>
                                    <td><span class="status {{ $document->status === 'draft' ? 'blue' : '' }}">{{ $document->status }}</span></td>
                                    <td>{{ $document->sourceWarehouse?->code ?? '-' }}</td>
                                    <td>{{ $document->destinationWarehouse?->code ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <a class="panel-link" href="{{ route('documents.index') }}">Zobacz dokumenty -></a>
                </article>
            @endif
        </section>
    @endif

    @if ($dashboardPanels['integrations'] || $dashboardPanels['ksef'])
        <section class="bottom-grid">
            @if ($dashboardPanels['integrations'])
                <article class="card">
                    <div class="panel-header">Routing integracji</div>
                    <div class="route-cards">
                        @foreach ($warehouseBalances as $row)
                            <div class="route-card">
                                <div class="route-title"><span>{{ $row['warehouse']->code }}</span><span>{{ $row['routes']->isEmpty() ? 'Wewnętrzny' : 'Aktywny' }}</span></div>
                                <div class="route-flow">{{ $row['warehouse']->code }} <span>-></span> {{ $row['routes']->isEmpty() ? 'ERP' : $row['routes']->pluck('salesChannel.code')->implode(' + ') }}</div>
                                <div class="metric-caption">{{ $row['routes']->isEmpty() ? 'Bez wysyłki stanów' : 'Synchronizacja przez kolejkę' }}</div>
                            </div>
                        @endforeach
                    </div>
                    <a class="panel-link" href="{{ route('integrations.index') }}">Zarządzaj integracjami -></a>
                </article>
            @endif

            @if ($dashboardPanels['ksef'])
                <article class="card">
                    <div class="panel-header">KSeF - kolejka</div>
                    <div class="ksef-list">
                        @foreach ($ksef as [$label, $count, $tone])
                            <div class="ksef-row">
                                <span>{{ $label }}</span>
                                <span @class(['counter', $tone])>{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                    <a class="panel-link" href="{{ route('ksef.index') }}">Przejdź do KSeF -></a>
                </article>
            @endif
        </section>
    @endif

    @unless ($visibleMetrics->isNotEmpty() || $hasDashboardPanels)
        <article class="card">
            <div class="panel-header">Panel operacyjny</div>
            <p class="subtitle" style="padding: 16px; margin: 0;">Dostępne akcje znajdziesz w górnych skrótach albo w menu konta.</p>
        </article>
    @endunless
@endsection
