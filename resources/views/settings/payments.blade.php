@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@section('content')
    <div class="page-toolbar">
        <a class="button secondary" href="{{ route('settings.index') }}">Wróć do ustawień</a>
    </div>

    <form method="POST" action="{{ route('settings.payments.update') }}" class="payments-settings-grid">
        @csrf
        @method('PUT')

        <article class="card settings-panel">
            <div class="panel-header">
                <span>PayU refundy</span>
                <span>{{ $payuSettings['enabled'] ? 'Włączone' : 'Wyłączone' }}</span>
            </div>
            <div class="settings-form">
                <label class="toggle-row">
                    <input type="checkbox" name="payu_enabled" value="1" @checked(old('payu_enabled', $payuSettings['enabled']))>
                    <span>
                        <strong>Włącz refundy PayU</strong>
                        <small>Zwroty dla zamówień opłaconych przez PayU będą mogły być wypłacane z poziomu zwrotu.</small>
                    </span>
                </label>
                <label class="toggle-row">
                    <input type="checkbox" name="payu_auto_refund_enabled" value="1" @checked(old('payu_auto_refund_enabled', $payuSettings['auto_refund_enabled']))>
                    <span>
                        <strong>Automatycznie po korekcie zwrotu</strong>
                        <small>Po wystawieniu korekty system wyśle refund PayU, jeśli w zamówieniu jest identyfikator PayU.</small>
                    </span>
                </label>

                <div class="settings-fields">
                    <label>Środowisko
                        <select name="payu_environment">
                            <option value="sandbox" @selected(old('payu_environment', $payuSettings['environment']) === 'sandbox')>Sandbox</option>
                            <option value="production" @selected(old('payu_environment', $payuSettings['environment']) === 'production')>Produkcja</option>
                        </select>
                    </label>
                    <label>Client ID
                        <input name="payu_client_id" value="{{ old('payu_client_id', $payuSettings['client_id']) }}" maxlength="120">
                    </label>
                    <label>POS ID
                        <input name="payu_pos_id" value="{{ old('payu_pos_id', $payuSettings['pos_id']) }}" maxlength="120">
                    </label>
                    <label>Client secret
                        <input name="payu_client_secret" type="password" maxlength="2000" autocomplete="new-password" placeholder="{{ $payuSettings['client_secret_configured'] ? 'Sekret zapisany - wpisz nowy, aby zmienić' : '' }}">
                    </label>
                    <label>Typ refundu
                        <select name="payu_refund_type">
                            <option value="REFUND_PAYMENT_STANDARD" @selected(old('payu_refund_type', $payuSettings['refund_type']) === 'REFUND_PAYMENT_STANDARD')>Standardowy</option>
                            <option value="FAST" @selected(old('payu_refund_type', $payuSettings['refund_type']) === 'FAST')>FAST</option>
                        </select>
                    </label>
                    <label class="inline-flag clear-secret">
                        <input type="checkbox" name="payu_clear_client_secret" value="1">
                        Usuń zapisany sekret PayU
                    </label>
                </div>
            </div>
        </article>

        <article class="card settings-panel">
            <div class="panel-header">
                <span>mBank koszyk przelewów</span>
                <span>Elixir-0</span>
            </div>
            <div class="settings-form">
                <div class="settings-fields one-column">
                    <label>Rachunek źródłowy
                        <input name="mbank_source_account" value="{{ old('mbank_source_account', $mbankSettings['source_account']) }}" maxlength="34" placeholder="26 cyfr albo PL...">
                    </label>
                    <label>Numer rozliczeniowy banku
                        <input name="mbank_source_bank_code" value="{{ old('mbank_source_bank_code', $mbankSettings['source_bank_code']) }}" maxlength="8" required>
                    </label>
                    <label>Nazwa zleceniodawcy
                        <input name="mbank_source_name" value="{{ old('mbank_source_name', $mbankSettings['source_name']) }}" maxlength="143" required>
                    </label>
                    <label>Kodowanie pliku
                        <select name="mbank_encoding">
                            <option value="Windows-1250" @selected(old('mbank_encoding', $mbankSettings['encoding']) === 'Windows-1250')>Windows-1250</option>
                            <option value="UTF-8" @selected(old('mbank_encoding', $mbankSettings['encoding']) === 'UTF-8')>UTF-8</option>
                            <option value="CP852" @selected(old('mbank_encoding', $mbankSettings['encoding']) === 'CP852')>CP852</option>
                        </select>
                    </label>
                </div>
            </div>
        </article>

        <div class="settings-actions">
            <button class="button" type="submit">Zapisz ustawienia płatności</button>
        </div>
    </form>
@endsection

@push('styles')
    <style>
        .payments-settings-grid { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(320px, .85fr); gap: 14px; align-items: start; }
        .settings-panel { align-self: start; }
        .settings-form { display: grid; gap: 12px; padding: 16px; }
        .settings-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .settings-fields.one-column { grid-template-columns: 1fr; }
        .toggle-row {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 12px;
            align-items: start;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: rgba(134, 115, 100, 0.04);
        }
        .toggle-row input { margin-top: 3px; width: 18px; height: 18px; }
        .toggle-row span { display: grid; gap: 4px; }
        .toggle-row small { color: var(--muted); line-height: 1.4; }
        .inline-flag { display: inline-flex; align-items: center; gap: 7px; font-weight: 720; }
        .inline-flag input { width: 17px; height: 17px; }
        .clear-secret { align-self: end; min-height: 42px; }
        .settings-actions { grid-column: 1 / -1; }
        @media (max-width: 900px) {
            .payments-settings-grid,
            .settings-fields { grid-template-columns: 1fr; }
        }
    </style>
@endpush
