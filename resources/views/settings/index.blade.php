@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@section('content')
    <section class="settings-grid" aria-label="Sekcje ustawień">
        <a class="settings-card" href="{{ route('settings.documents') }}">
            <strong>Dokumenty magazynowe</strong>
            <span>Numeracja, lokalizacje magazynowe i automatyczny obieg RX/WZ/faktur.</span>
        </a>
        <a class="settings-card" href="{{ route('settings.returns') }}">
            <strong>Zwroty</strong>
            <span>Numeracja zwrotów, domyślny magazyn przyjęcia i domyślne wartości pozycji.</span>
        </a>
        <a class="settings-card" href="{{ route('settings.packing') }}">
            <strong>Pakowanie i drukarki</strong>
            <span>Połączenie aplikacji Windows, wybór drukarek z wykrytej listy, test połączenia i podział asortymentu.</span>
        </a>
        <a class="settings-card" href="{{ route('integrations.index') }}">
            <strong>Integracje</strong>
            <span>WooCommerce, KSeF, dane WordPress REST i etykiety kurierskie.</span>
        </a>
        <a class="settings-card" href="{{ route('invoices.index') }}">
            <strong>Faktury</strong>
            <span>Dane sprzedawcy, numeracja i szablony wydruku.</span>
        </a>
        <a class="settings-card" href="{{ route('modules.show', 'sync') }}">
            <strong>Kolejka sync</strong>
            <span>Nieudane i oczekujące eksporty stanów do kanałów sprzedaży.</span>
        </a>
        <a class="settings-card" href="{{ route('ledger.index') }}">
            <strong>Ledger</strong>
            <span>Historia księgowań dokumentów magazynowych.</span>
        </a>
        <a class="settings-card" href="{{ route('audit.index') }}">
            <strong>Audyt</strong>
            <span>Historia operacji wykonanych w aplikacji.</span>
        </a>
        <a class="settings-card" href="{{ route('settings.products') }}">
            <strong>Edycja produktów</strong>
            <span>Widoczność pojedynczych pól formularza produktu, np. tagów, dostawców i opisów.</span>
        </a>
        <a class="settings-card" href="{{ route('settings.shipping') }}">
            <strong>Wysyłki i konta kurierskie</strong>
            <span>Konta InPost (ShipX) do generowania etykiet z wyborem konta nadawczego.</span>
        </a>
        <a class="settings-card" href="{{ route('settings.mail') }}">
            <strong>Maile i Gmail API</strong>
            <span>SMTP lub Google Workspace, nadawca, Reply-To i test wysyłki dla komunikacji z klientami.</span>
        </a>
        <a class="settings-card" href="{{ route('settings.payments') }}">
            <strong>Płatności i zwroty</strong>
            <span>Refundy PayU oraz konto źródłowe do koszyka przelewów mBank.</span>
        </a>
        <a class="settings-card" href="{{ route('settings.users') }}">
            <strong>Użytkownicy</strong>
            <span>Konta ERP, role robocze, aktywność i hasła dostępu do aplikacji.</span>
        </a>
    </section>
@endsection

@push('styles')
    <style>
        .settings-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
        .settings-card { min-height: 132px; border: 1px solid var(--border); border-radius: 8px; padding: 18px; background: var(--surface); color: var(--text); text-decoration: none; box-shadow: var(--shadow); display: grid; align-content: start; gap: 8px; }
        .settings-card strong { font-size: 18px; line-height: 1.2; }
        .settings-card span { color: var(--muted); }
        .settings-card:not(.muted-card):hover { border-color: rgba(134, 115, 100, .34); background: #fffdfb; }
        .muted-card { opacity: .78; }
    </style>
@endpush
