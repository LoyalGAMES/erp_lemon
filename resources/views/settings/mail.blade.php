@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@php
    $contextLabel = fn (string $context): string => match ($context) {
        'order' => 'Zamówienia',
        'return' => 'Zwroty',
        default => 'Zamówienia i zwroty',
    };
@endphp

@section('content')
    <div class="page-toolbar">
        <a class="button secondary" href="{{ route('settings.index') }}">Wróć do ustawień</a>
    </div>

    <section class="mail-settings-grid">
        <article class="card settings-panel">
            <div class="panel-header">
                <span>SMTP</span>
                <span>{{ $mailSettings['enabled'] ? 'Włączone' : 'Wyłączone' }}</span>
            </div>
            <form method="POST" action="{{ route('settings.mail.update') }}" class="form-grid settings-form">
                @csrf
                @method('PUT')

                <label class="toggle-row">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $mailSettings['enabled']))>
                    <span>
                        <strong>Używaj konfiguracji SMTP z panelu</strong>
                        <small>Po włączeniu automatyczne i ręczne maile do klientów będą wysyłane przez poniższe konto SMTP.</small>
                    </span>
                </label>

                <div class="mail-settings-fields">
                    <label>Host SMTP
                        <input name="host" value="{{ old('host', $mailSettings['host']) }}" maxlength="255" placeholder="smtp.example.com">
                    </label>
                    <label>Port
                        <input name="port" type="number" min="1" max="65535" value="{{ old('port', $mailSettings['port']) }}">
                    </label>
                    <label>Szyfrowanie
                        <select name="encryption">
                            <option value="tls" @selected(old('encryption', $mailSettings['encryption']) === 'tls')>STARTTLS / TLS</option>
                            <option value="ssl" @selected(old('encryption', $mailSettings['encryption']) === 'ssl')>SSL / SMTPS</option>
                            <option value="none" @selected(old('encryption', $mailSettings['encryption']) === 'none')>Brak</option>
                        </select>
                    </label>
                    <label>Timeout [s]
                        <input name="timeout" type="number" min="3" max="120" value="{{ old('timeout', $mailSettings['timeout']) }}">
                    </label>
                    <label>Login SMTP
                        <input name="username" value="{{ old('username', $mailSettings['username']) }}" maxlength="255" autocomplete="username">
                    </label>
                    <label>Hasło SMTP
                        <input name="password" type="password" maxlength="2000" autocomplete="new-password" placeholder="{{ $mailSettings['password_configured'] ? 'Hasło zapisane - wpisz nowe, aby zmienić' : '' }}">
                    </label>
                    <label>Adres nadawcy
                        <input name="from_address" type="email" value="{{ old('from_address', $mailSettings['from_address']) }}" maxlength="255" placeholder="sklep@example.com">
                    </label>
                    <label>Nazwa nadawcy
                        <input name="from_name" value="{{ old('from_name', $mailSettings['from_name']) }}" maxlength="120" placeholder="Sempre">
                    </label>
                    <label>Domena EHLO
                        <input name="ehlo_domain" value="{{ old('ehlo_domain', $mailSettings['ehlo_domain']) }}" maxlength="255" placeholder="opcjonalnie">
                    </label>
                    <label class="inline-flag mail-clear-password">
                        <input type="checkbox" name="clear_password" value="1">
                        Usuń zapisane hasło SMTP
                    </label>
                </div>

                <div class="settings-subsection">
                    <div>
                        <strong>Wygląd maili do klientów</strong>
                        <span class="muted">Wspólna oprawa wszystkich automatycznych i ręcznych wiadomości.</span>
                    </div>
                    <div class="mail-settings-fields">
                        <label>Nazwa marki
                            <input name="brand_name" value="{{ old('brand_name', $mailSettings['brand_name']) }}" maxlength="120" placeholder="Sempre">
                        </label>
                        <label>Adres logo
                            <input name="logo_url" type="url" value="{{ old('logo_url', $mailSettings['logo_url']) }}" maxlength="1000" placeholder="https://sklep.pl/logo.png">
                        </label>
                        <label>Kolor akcentu
                            <input name="accent_color" type="color" value="{{ old('accent_color', $mailSettings['accent_color']) }}">
                        </label>
                        <label>Nagłówek
                            <input name="header_text" value="{{ old('header_text', $mailSettings['header_text']) }}" maxlength="160" placeholder="Informacja o zamówieniu">
                        </label>
                        <label>E-mail kontaktowy
                            <input name="support_email" type="email" value="{{ old('support_email', $mailSettings['support_email']) }}" maxlength="255">
                        </label>
                        <label>Telefon kontaktowy
                            <input name="support_phone" value="{{ old('support_phone', $mailSettings['support_phone']) }}" maxlength="40">
                        </label>
                    </div>
                    <label>Podpis
                        <textarea name="signature" rows="3" maxlength="1000">{{ old('signature', $mailSettings['signature']) }}</textarea>
                    </label>
                    <label>Stopka
                        <textarea name="footer_text" rows="3" maxlength="1000">{{ old('footer_text', $mailSettings['footer_text']) }}</textarea>
                    </label>
                </div>

                <p class="muted">Aktywny mailer runtime przed zastosowaniem ustawień: {{ $runtimeMailer }}. Hasło SMTP jest zapisywane w bazie w formie szyfrowanej.</p>
                <button class="button" type="submit">Zapisz SMTP i wygląd maili</button>
            </form>
        </article>

        <article class="card settings-panel">
            <div class="panel-header">
                <span>Test wysyłki</span>
                <span>{{ $mailSettings['password_configured'] ? 'Hasło SMTP zapisane' : 'Brak hasła SMTP' }}</span>
            </div>
            <form method="POST" action="{{ route('settings.mail.test') }}" class="form-grid settings-form">
                @csrf
                <label>Adres testowy
                    <input name="recipient" type="email" value="{{ old('recipient', $mailSettings['from_address']) }}" required maxlength="255">
                </label>
                <button class="button secondary" type="submit">Wyślij mail testowy</button>
            </form>
            <div class="deliverability-box">
                <strong>Dostarczalność</strong>
                <div class="deliverability-meta">
                    <span>Nadawca: {{ $mailDeliverability['from_domain'] ?? '-' }}</span>
                    <span>Login SMTP: {{ $mailDeliverability['username_domain'] ?? '-' }}</span>
                    <span>EHLO: {{ $mailDeliverability['ehlo_domain'] ?: '-' }}</span>
                </div>
                <div class="deliverability-list">
                    @foreach ($mailDeliverability['checks'] as $check)
                        @php $checkLabel = ['ok' => 'OK', 'info' => 'info', 'warn' => 'uwaga'][$check['status']] ?? $check['status']; @endphp
                        <div class="deliverability-item">
                            <span @class(['status', 'blue' => $check['status'] === 'info', 'orange' => $check['status'] === 'warn'])>{{ $checkLabel }}</span>
                            <div>
                                <strong>{{ $check['title'] }}</strong>
                                <p>{{ $check['description'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </article>
    </section>

    <section class="card settings-panel template-settings-panel">
        <div class="panel-header">
            <span>Szablony e-mail</span>
            <span>{{ $emailTemplates->count() }} szablonów</span>
        </div>
        <div class="template-settings-body">
            <div class="template-variable-help">
                <strong>Dostępne zmienne</strong>
                <div class="template-variable-list">
                    @foreach ($templateVariables as $variable => $description)
                        @php $placeholder = sprintf('{{%s}}', $variable); @endphp
                        <span title="{{ $description }}">{{ $placeholder }}</span>
                    @endforeach
                </div>
                <p class="muted">Zmienne są teraz renderowane również po stronie backendu, więc zadziałają w mailu nawet wtedy, gdy ktoś wpisze je ręcznie w temacie lub treści.</p>
            </div>
            <form method="POST" action="{{ route('settings.mail.templates.store') }}" class="template-form">
                @csrf
                <div class="mail-settings-fields">
                    <label>Nazwa
                        <input name="name" value="{{ old('name') }}" maxlength="120" placeholder="Np. brak towaru" required>
                    </label>
                    <label>Kontekst
                        <select name="context" required>
                            <option value="order" @selected(old('context') === 'order')>Zamówienia</option>
                            <option value="return" @selected(old('context') === 'return')>Zwroty</option>
                            <option value="both" @selected(old('context', 'both') === 'both')>Zamówienia i zwroty</option>
                        </select>
                    </label>
                </div>
                <label>Temat
                    <input name="subject" value="{{ old('subject') }}" maxlength="160" required>
                </label>
                <label>Treść
                    <textarea name="body" rows="5" maxlength="5000" required>{{ old('body') }}</textarea>
                </label>
                <label class="inline-flag">
                    <input type="checkbox" name="is_active" value="1" checked>
                    Aktywny
                </label>
                <button class="button" type="submit">Dodaj szablon</button>
            </form>

            <div class="template-list">
                @forelse ($emailTemplates as $template)
                    <article class="template-card">
                        <form method="POST" action="{{ route('settings.mail.templates.update', $template) }}" class="template-form">
                            @csrf
                            @method('PUT')
                            <div class="template-card-header">
                                <strong>{{ $template->name }}</strong>
                                <span>{{ $contextLabel($template->context) }} · {{ $template->is_active ? 'aktywny' : 'wyłączony' }}</span>
                            </div>
                            <div class="mail-settings-fields">
                                <label>Nazwa
                                    <input name="name" value="{{ old('templates.'.$template->id.'.name', $template->name) }}" maxlength="120" required>
                                </label>
                                <label>Kontekst
                                    <select name="context" required>
                                        <option value="order" @selected($template->context === 'order')>Zamówienia</option>
                                        <option value="return" @selected($template->context === 'return')>Zwroty</option>
                                        <option value="both" @selected($template->context === 'both')>Zamówienia i zwroty</option>
                                    </select>
                                </label>
                            </div>
                            <label>Temat
                                <input name="subject" value="{{ old('templates.'.$template->id.'.subject', $template->subject) }}" maxlength="160" required>
                            </label>
                            <label>Treść
                                <textarea name="body" rows="5" maxlength="5000" required>{{ old('templates.'.$template->id.'.body', $template->body) }}</textarea>
                            </label>
                            <label class="inline-flag">
                                <input type="checkbox" name="is_active" value="1" @checked($template->is_active)>
                                Aktywny
                            </label>
                            <div class="template-actions">
                                <button class="button secondary" type="submit">Zapisz</button>
                            </div>
                        </form>
                        <form method="POST" action="{{ route('settings.mail.templates.destroy', $template) }}" onsubmit="return confirm('Usunąć szablon {{ $template->name }}?');">
                            @csrf
                            @method('DELETE')
                            <button class="button danger" type="submit">Usuń</button>
                        </form>
                    </article>
                @empty
                    <div class="empty-state">Brak szablonów maili.</div>
                @endforelse
            </div>
        </div>
    </section>
@endsection

@push('styles')
    <style>
        .mail-settings-grid { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(320px, .65fr); gap: 14px; }
        .settings-panel { align-self: start; }
        .settings-form,
        .template-settings-body { padding: 16px; }
        .settings-form .button,
        .template-form .button { width: fit-content; }
        .mail-settings-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .settings-subsection { display: grid; gap: 12px; border-top: 1px solid var(--border); padding-top: 16px; margin-top: 4px; }
        .settings-subsection > div:first-child { display: grid; gap: 4px; }
        .settings-subsection strong { font-size: 15px; }
        .mail-clear-password { align-self: end; min-height: 42px; }
        .deliverability-box { display: grid; gap: 12px; padding: 16px; border-top: 1px solid var(--border); }
        .deliverability-meta { display: grid; gap: 5px; color: var(--muted); font-size: 12px; }
        .deliverability-list { display: grid; gap: 10px; }
        .deliverability-item { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 10px; align-items: start; }
        .deliverability-item p { margin: 2px 0 0; color: var(--muted); font-size: 12px; line-height: 1.4; }
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
        .template-settings-panel { margin-top: 14px; }
        .template-settings-body { display: grid; gap: 18px; }
        .template-variable-help { display: grid; gap: 9px; border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: #fffdfb; }
        .template-variable-list { display: flex; flex-wrap: wrap; gap: 7px; }
        .template-variable-list span { display: inline-flex; min-height: 27px; align-items: center; border: 1px solid var(--border); border-radius: 999px; padding: 3px 9px; background: var(--surface); color: var(--brand-dark); font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size: 12px; font-weight: 760; }
        .template-form { display: grid; gap: 10px; }
        .template-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .template-card { display: grid; gap: 10px; border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: var(--surface); }
        .template-card-header { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; color: var(--muted); }
        .template-card-header strong { color: var(--text); }
        .template-actions { display: flex; gap: 8px; justify-content: flex-end; }
        @media (max-width: 900px) {
            .mail-settings-grid,
            .mail-settings-fields,
            .template-list { grid-template-columns: 1fr; }
        }
    </style>
@endpush
