<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Sempre ERP' }}</title>
    <script>
        (() => {
            try {
                if (window.matchMedia('(min-width: 900px)').matches
                    && localStorage.getItem('sempre-erp-sidebar-open') === '1') {
                    document.documentElement.classList.add('sidebar-open-initial');
                }
            } catch (error) {}
        })();
    </script>
    <style>
        :root {
            --bg: #f5f2f0;
            --surface: #fffefd;
            --surface-soft: rgba(134, 115, 100, .08);
            --border: #d8cec6;
            --text: #12110f;
            --muted: #6d645d;
            --brand: #867364;
            --brand-dark: #5f5045;
            --brand-soft: rgba(134, 115, 100, .14);
            --green: var(--brand);
            --green-dark: var(--brand-dark);
            --green-soft: var(--brand-soft);
            --orange: #a97955;
            --blue: var(--brand);
            --red: #d83434;
            --shadow: 0 12px 30px rgba(134, 115, 100, .09);
            --sidebar-width: 306px;
        }
        html { overflow-y: auto; scrollbar-gutter: stable; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 14px; line-height: 1.5; }
        .app { min-height: 100vh; display: grid; grid-template-columns: minmax(0, 1fr); }
        .sidebar { position: fixed; inset: 0 auto 0 0; z-index: 70; width: min(var(--sidebar-width), 88vw); height: 100vh; max-height: 100dvh; overflow: hidden; border-right: 1px solid var(--border); background: var(--surface); display: flex; flex-direction: column; padding: 18px 10px 14px; box-shadow: 24px 0 48px rgba(134, 115, 100, .16); transform: translateX(-100%); transition: transform .18s ease; }
        body.sidebar-open .sidebar,
        html.sidebar-open-initial .sidebar { transform: translateX(0); }
        .sidebar-backdrop { display: none; position: fixed; inset: 0; z-index: 60; background: rgba(37, 31, 26, .38); }
        body.sidebar-open .sidebar-backdrop { display: block; }
        .sidebar-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex: 0 0 auto; padding: 0 12px 28px 20px; }
        .brand { display: flex; align-items: center; width: 130px; min-height: 42px; padding: 0; color: var(--text); text-decoration: none; }
        .sidebar-head .brand { padding-bottom: 0; }
        .brand-logo { display: block; width: 130px; height: auto; }
        .nav { display: grid; gap: 4px; flex: 1 1 auto; min-height: 0; align-content: start; overflow-x: hidden; overflow-y: auto; padding-right: 4px; scrollbar-gutter: stable; }
        .nav a { display: flex; align-items: center; gap: 13px; flex: 0 0 auto; color: var(--text); text-decoration: none; border-radius: 8px; padding: 12px 16px; font-size: 15px; font-weight: 520; }
        .nav a.active { color: var(--green-dark); background: var(--green-soft); font-weight: 750; }
        .nav-submenu { display: grid; gap: 2px; margin: -1px 0 6px 18px; padding-left: 12px; border-left: 1px solid var(--border); }
        .nav-submenu a { min-height: 34px; padding: 8px 12px; font-size: 13px; border-radius: 7px; color: var(--muted); }
        .nav-submenu a.active { color: var(--green-dark); background: rgba(134, 115, 100, .10); }
        .icon { width: 21px; height: 21px; display: inline-grid; place-items: center; color: currentColor; }
        .user-menu { margin-top: 10px; position: relative; flex: 0 0 auto; }
        .user-menu summary { list-style: none; cursor: pointer; }
        .user-menu summary::-webkit-details-marker { display: none; }
        .user-card { border: 1px solid var(--border); border-radius: 8px; padding: 14px; display: flex; align-items: center; gap: 12px; background: var(--surface); }
        .user-menu-panel { margin-top: 8px; border: 1px solid var(--border); border-radius: 8px; padding: 8px; display: grid; gap: 2px; background: var(--surface); box-shadow: var(--shadow); }
        .user-menu-panel a { color: var(--text); text-decoration: none; border-radius: 7px; padding: 9px 10px; font-weight: 680; }
        .user-menu-panel a:hover, .user-menu-panel a.active { background: var(--green-soft); color: var(--green-dark); }
        .avatar { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; background: var(--green); color: #fff; font-weight: 800; font-size: 13px; }
        .main { min-width: 0; padding: 0 26px 26px; transition: margin-left .18s ease; }
        .topbar { min-height: 73px; display: flex; align-items: center; justify-content: space-between; gap: 18px; border-bottom: 1px solid var(--border); margin: 0 -26px 22px; padding: 16px 26px; background: rgba(255, 255, 255, .88); backdrop-filter: blur(12px); }
        .topbar.compact { min-height: 62px; margin-bottom: 10px; }
        .top-title { display: flex; align-items: flex-start; gap: 14px; min-width: 260px; }
        .menu-button, .sidebar-close { width: 42px; height: 42px; flex: 0 0 auto; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); cursor: pointer; display: inline-grid; place-items: center; box-shadow: 0 5px 18px rgba(134, 115, 100, .08); }
        .menu-button span, .sidebar-close span { display: block; width: 18px; height: 2px; border-radius: 2px; background: currentColor; box-shadow: 0 6px 0 currentColor, 0 -6px 0 currentColor; }
        .sidebar-close span { transform: rotate(45deg); box-shadow: none; }
        .sidebar-close span::after { content: ""; display: block; width: 18px; height: 2px; border-radius: 2px; background: currentColor; transform: rotate(90deg); }
        .back-button { width: 42px; height: 42px; flex: 0 0 auto; display: inline-grid; place-items: center; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); text-decoration: none; font-size: 25px; line-height: 1; box-shadow: 0 5px 18px rgba(134, 115, 100, .08); }
        h1 { margin: 0; font-size: 26px; line-height: 1.1; letter-spacing: 0; }
        .subtitle { margin: 7px 0 0; color: var(--muted); max-width: 820px; }
        .top-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .status-chip { min-height: 42px; display: inline-flex; align-items: center; gap: 10px; border: 1px solid var(--border); border-radius: 8px; padding: 8px 13px; background: var(--surface); font-weight: 650; white-space: nowrap; box-shadow: 0 5px 18px rgba(134, 115, 100, .08); }
        .top-shortcut { color: var(--text); text-decoration: none; }
        .top-shortcut.active { color: var(--green-dark); background: var(--green-soft); }
        .dot { width: 8px; height: 8px; flex: 0 0 auto; border-radius: 50%; background: var(--green); }
        .dot.green { background: var(--green); }
        .dot.orange { background: var(--orange); }
        .dot.red { background: var(--red); }
        .metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 18px; margin-bottom: 20px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; box-shadow: var(--shadow); }
        .metric { min-height: 118px; padding: 22px; }
        .metric-label { color: var(--muted); font-weight: 650; margin-bottom: 4px; }
        .metric-value { font-size: 31px; line-height: 1; letter-spacing: 0; font-weight: 820; margin-bottom: 12px; overflow-wrap: anywhere; }
        .metric-value-blue { color: var(--blue); }
        .metric-value-red { color: var(--red); }
        .metric-caption { color: var(--muted); }
        .grid-two { display: grid; grid-template-columns: minmax(420px, 1fr) minmax(560px, 1.45fr); gap: 18px; margin-bottom: 20px; }
        .bottom-grid { display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(360px, .95fr); gap: 18px; }
        .panel-header { min-height: 49px; display: flex; align-items: center; justify-content: space-between; padding: 0 16px; border-bottom: 1px solid var(--border); font-weight: 780; font-size: 15px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 16px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: middle; white-space: nowrap; }
        th { font-size: 12px; color: #4b423b; font-weight: 780; }
        td { font-size: 13px; }
        tr.total td { background: var(--surface-soft); font-weight: 800; }
        .status { display: inline-flex; align-items: center; min-height: 25px; border-radius: 7px; padding: 2px 8px; font-weight: 760; font-size: 12px; background: var(--green-soft); color: var(--green-dark); }
        .status.blue { background: rgba(134, 115, 100, .11); color: var(--brand-dark); }
        .status.red { background: #ffecec; color: var(--red); }
        .status.orange { background: #fff0e8; color: var(--orange); }
        .panel-link, .button-link { display: inline-flex; align-items: center; gap: 7px; color: var(--green-dark); text-decoration: none; font-weight: 760; padding: 14px 16px; }
        .button { border: 0; border-radius: 7px; padding: 7px 10px; background: var(--green); color: white; font-weight: 760; cursor: pointer; }
        a.button { text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .button.secondary { background: rgba(134, 115, 100, .08); color: var(--green-dark); border: 1px solid var(--border); }
        .alert { margin: 0 0 16px; border-radius: 8px; padding: 12px 14px; border: 1px solid var(--border); background: var(--surface); }
        .alert.ok { border-color: rgba(134, 115, 100, .32); background: rgba(134, 115, 100, .09); color: var(--green-dark); }
        .alert.error { border-color: #f0c3c3; background: #fff0f0; color: var(--red); }
        label { display: grid; gap: 6px; color: var(--muted); font-weight: 650; }
        input, select, textarea { width: 100%; border: 1px solid var(--border); border-radius: 7px; padding: 10px 11px; font: inherit; color: var(--text); background: #fff; }
        input[type="checkbox"] { width: auto; }
        .form-grid { padding: 16px; display: grid; gap: 12px; }
        .inline-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .inline-actions .button { min-height: 38px; display: inline-flex; align-items: center; justify-content: center; }
        .page-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .toolbar-note { color: var(--muted); font-size: 13px; }
        .table-scroll { overflow-x: auto; }
        .table-scroll table { min-width: 980px; }
        .dense-table th, .dense-table td { padding: 9px 12px; }
        .numeric { text-align: right; font-variant-numeric: tabular-nums; }
        .muted { color: var(--muted); }
        .stock-stack { display: grid; gap: 5px; min-width: 220px; }
        .stock-line { display: flex; justify-content: space-between; gap: 12px; color: var(--muted); font-size: 12px; }
        .stock-line strong { color: var(--text); }
        .drawer-toggle { position: fixed; opacity: 0; pointer-events: none; }
        .drawer-backdrop { display: none; position: fixed; inset: 0; z-index: 40; background: rgba(37, 31, 26, .36); }
        .drawer-panel { position: fixed; top: 0; right: 0; z-index: 50; width: min(460px, 94vw); height: 100vh; overflow-y: auto; background: var(--surface); border-left: 1px solid var(--border); box-shadow: -24px 0 48px rgba(134, 115, 100, .18); transform: translateX(100%); transition: transform .18s ease; }
        .drawer-toggle:checked ~ .drawer-backdrop { display: block; }
        .drawer-toggle:checked ~ .drawer-panel { transform: translateX(0); }
        .drawer-header { min-height: 64px; padding: 18px 18px 12px; display: flex; justify-content: space-between; align-items: center; gap: 12px; border-bottom: 1px solid var(--border); }
        .drawer-title { font-size: 18px; font-weight: 850; }
        .drawer-close { cursor: pointer; color: var(--muted); font-size: 26px; line-height: 1; padding: 2px 8px; }
        details.compact-editor summary { cursor: pointer; color: var(--green-dark); font-weight: 760; }
        details.compact-editor form { margin-top: 8px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .template-editor textarea { min-height: 520px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size: 12px; line-height: 1.45; }
        .route-cards { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; padding: 16px; }
        .route-card { border: 1px solid var(--border); border-radius: 8px; padding: 14px; }
        .route-title { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; font-weight: 800; margin-bottom: 18px; }
        .route-flow { font-size: 18px; font-weight: 850; text-align: center; margin-bottom: 12px; }
        .route-flow span { color: var(--green-dark); }
        .ksef-list { padding: 7px 16px 16px; }
        .ksef-row { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border); min-height: 39px; gap: 12px; }
        .counter { min-width: 42px; text-align: center; border-radius: 7px; padding: 2px 8px; font-weight: 820; background: #fff0e8; color: var(--orange); }
        .counter.blue { background: rgba(134, 115, 100, .11); color: var(--brand-dark); }
        .counter.green { background: var(--green-soft); color: var(--green-dark); }
        .counter.red { background: #ffecec; color: var(--red); }
        footer { color: var(--muted); text-align: center; font-size: 12px; padding: 23px 0 0; }
        @media (min-width: 900px) {
            .sidebar { width: var(--sidebar-width); box-shadow: none; }
            body.sidebar-open .main,
            html.sidebar-open-initial .main { margin-left: var(--sidebar-width); }
            body.sidebar-open .sidebar-backdrop { display: none; }
        }
        @media (max-width: 1180px) {
            .app { grid-template-columns: 1fr; }
            .metrics, .grid-two, .bottom-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 760px) {
            .main { padding: 0 14px 18px; }
            .topbar { height: auto; align-items: flex-start; flex-direction: column; margin: 0 -14px 18px; padding: 14px; }
            .top-title { width: 100%; }
            .top-actions { width: 100%; gap: 8px; }
            .top-actions .status-chip { min-height: 38px; }
            .metrics, .route-cards { grid-template-columns: 1fr; }
            .card { overflow-x: auto; }
            .drawer-panel { width: 96vw; }
        }
    </style>
    @stack('styles')
</head>
<body>
    @php
        $erpUser = request()->attributes->get('erp_user') ?: auth()->user();
        $operatorName = is_object($erpUser) && isset($erpUser->name) ? (string) $erpUser->name : 'admin';
        $operatorRole = is_object($erpUser) && method_exists($erpUser, 'roleLabel') ? $erpUser->roleLabel() : 'Administrator';
        $operatorInitials = collect(preg_split('/\s+/', trim($operatorName)) ?: [])
            ->filter()
            ->map(fn (string $part): string => mb_substr($part, 0, 1))
            ->take(2)
            ->implode('');
        $operatorInitials = $operatorInitials !== '' ? mb_strtoupper($operatorInitials) : 'AM';
        $nav = [
            ['dashboard', route('dashboard'), 'Dashboard'],
            ['orders', route('modules.show', 'orders'), 'Zamówienia'],
            ['products', route('products.index'), 'Produkty'],
            ['invoices', route('invoices.index'), 'Faktury'],
            ['documents', route('documents.index'), 'Dokumenty magazynowe'],
            ['returns', route('returns.index'), 'Zwroty'],
            ['warehouses', route('warehouses.index'), 'Magazyny'],
        ];
        $settingsNav = [
            ['settings', route('settings.index'), 'Ustawienia'],
            ['users', route('settings.users'), 'Użytkownicy'],
            ['integrations', route('integrations.index'), 'Integracje'],
            ['ksef', route('ksef.index'), 'KSeF'],
            ['sync', route('modules.show', 'sync'), 'Kolejka sync'],
            ['ledger', route('ledger.index'), 'Ledger'],
            ['audit', route('audit.index'), 'Audyt'],
        ];
        $active = $module ?? 'dashboard';
        $canAccessArea = function (string $area) use ($erpUser): bool {
            return ! is_object($erpUser)
                || ! method_exists($erpUser, 'canAccessArea')
                || $erpUser->canAccessArea($area);
        };
        $nav = array_values(array_filter($nav, fn (array $item): bool => $canAccessArea($item[0])));
        $settingsNav = array_values(array_filter($settingsNav, fn (array $item): bool => $canAccessArea($item[0])));
        $productSubnav = $canAccessArea('products') ? [
            ['products', route('products.index'), 'Lista produktów'],
            ['product-categories', route('products.categories.index'), 'Kategorie'],
            ['product-parameters', route('products.parameters.index'), 'Parametry'],
        ] : [];
        $productActiveKeys = array_column($productSubnav, 0);
        $topStatus = ($hideTopActions ?? false)
            ? []
            : app(\App\Support\OperationalStatus::class)->navigation();
        $packingTopCount = (int) data_get($topStatus, 'packing_orders', 0);
        $returnsTopCount = (int) data_get($topStatus, 'return_cases', 0);
        $woocommerceTopStatus = data_get($topStatus, 'woocommerce', ['tone' => 'red', 'label' => 'Brak statusu']);
        $ksefTopStatus = data_get($topStatus, 'ksef', ['tone' => 'red', 'label' => 'Brak statusu']);
    @endphp
    <div class="sidebar-backdrop" data-sidebar-close></div>
    <div class="app">
        <aside class="sidebar" id="main-menu" aria-label="Menu główne">
            <div class="sidebar-head">
                <a class="brand" href="{{ route('dashboard') }}">
                    <img class="brand-logo" src="{{ asset('assets/sempre-logotyp.svg') }}" alt="SEMPRE">
                </a>
                <button class="sidebar-close" type="button" data-sidebar-close aria-label="Zamknij menu"><span></span></button>
            </div>
            <nav class="nav" aria-label="Główne">
                @foreach ($nav as [$key, $url, $label])
                    @php $isProductGroup = $key === 'products' && in_array($active, $productActiveKeys, true); @endphp
                    <a href="{{ $url }}" @class(['active' => $active === $key || $isProductGroup])>{{ $label }}</a>
                    @if ($key === 'products' && $productSubnav !== [])
                        <div class="nav-submenu" aria-label="Produkty">
                            @foreach ($productSubnav as [$subKey, $subUrl, $subLabel])
                                <a href="{{ $subUrl }}" @class(['active' => $active === $subKey])>{{ $subLabel }}</a>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </nav>
            <details class="user-menu" @if(in_array($active, array_column($settingsNav, 0), true)) open @endif>
                <summary class="user-card">
                    <div class="avatar">{{ $operatorInitials }}</div>
                    <div><strong>{{ $operatorName }}</strong><br><span style="color: var(--muted); font-size: 12px;">{{ $operatorRole }}</span></div>
                </summary>
                <div class="user-menu-panel">
                    @foreach ($settingsNav as [$key, $url, $label])
                        <a href="{{ $url }}" @class(['active' => $active === $key])>{{ $label }}</a>
                    @endforeach
                </div>
            </details>
        </aside>
        <main class="main">
            <header @class(['topbar', 'compact' => ($compactHeader ?? false)])>
                <div class="top-title">
                    <button class="menu-button" type="button" data-sidebar-open aria-controls="main-menu" aria-label="Otwórz menu"><span></span></button>
                    @isset($headerBackUrl)
                        <a class="back-button" href="{{ $headerBackUrl }}" aria-label="Wstecz">&larr;</a>
                    @endisset
                    <div>
                        <h1>{{ $title ?? 'Panel operacyjny' }}</h1>
                        @isset($subtitle)
                            <p class="subtitle">{{ $subtitle }}</p>
                        @endisset
                    </div>
                </div>
                @unless($hideTopActions ?? false)
                    <div class="top-actions">
                        @if ($canAccessArea('packing'))
                            <a href="{{ route('packing.index') }}" @class(['status-chip', 'top-shortcut', 'active' => $active === 'packing'])>
                                <strong>Pakowanie@if($packingTopCount > 0) ({{ $packingTopCount }}) @endif</strong>
                            </a>
                        @endif
                        @if ($canAccessArea('returns'))
                            <a href="{{ route('returns.index') }}" @class(['status-chip', 'top-shortcut', 'active' => $active === 'returns'])>
                                <strong>Moduł zwrotów@if($returnsTopCount > 0) ({{ $returnsTopCount }}) @endif</strong>
                            </a>
                        @endif
                        @if ($canAccessArea('integrations'))
                            <a href="{{ route('integrations.index') }}" @class(['status-chip', 'top-shortcut', 'active' => $active === 'integrations'])>
                                <strong>WooCommerce</strong>
                                <span @class(['dot', $woocommerceTopStatus['tone'] ?? 'red']) title="{{ $woocommerceTopStatus['label'] ?? 'Brak statusu' }}"></span>
                                {{ $woocommerceTopStatus['label'] ?? 'Brak statusu' }}
                            </a>
                        @endif
                        @if ($canAccessArea('ksef'))
                            <a href="{{ $canAccessArea('integrations') ? route('integrations.index') . '#ksef' : route('ksef.index') }}" @class(['status-chip', 'top-shortcut', 'active' => $active === 'ksef'])>
                                <strong>KSeF</strong>
                                <span @class(['dot', $ksefTopStatus['tone'] ?? 'red']) title="{{ $ksefTopStatus['label'] ?? 'Brak statusu' }}"></span>
                                {{ $ksefTopStatus['label'] ?? 'Brak statusu' }}
                            </a>
                        @endif
                    </div>
                @endunless
            </header>

            @if (session('status'))
                <div class="alert ok">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="alert error">{{ session('error') }}</div>
            @endif

            @yield('content')
            <footer>Sempre ERP © 2026 | wersja robocza 0.2</footer>
        </main>
    </div>
    <script>
        const sidebarPersistenceKey = 'sempre-erp-sidebar-open';
        const desktopSidebar = () => window.matchMedia('(min-width: 900px)').matches;
        if (desktopSidebar() && localStorage.getItem(sidebarPersistenceKey) === '1') {
            document.body.classList.add('sidebar-open');
        }
        document.documentElement.classList.remove('sidebar-open-initial');
        document.querySelectorAll('[data-sidebar-open]').forEach((button) => {
            button.addEventListener('click', () => {
                document.body.classList.add('sidebar-open');
                if (desktopSidebar()) {
                    localStorage.setItem(sidebarPersistenceKey, '1');
                }
            });
        });
        document.querySelectorAll('[data-sidebar-close]').forEach((button) => {
            button.addEventListener('click', () => {
                document.body.classList.remove('sidebar-open');
                if (desktopSidebar()) {
                    localStorage.setItem(sidebarPersistenceKey, '0');
                }
            });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                document.body.classList.remove('sidebar-open');
                if (desktopSidebar()) {
                    localStorage.setItem(sidebarPersistenceKey, '0');
                }
            }
        });
    </script>
    @stack('scripts')
</body>
</html>
