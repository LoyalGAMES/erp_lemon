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
                        <span class="muted">Wspólna oprawa wszystkich automatycznych i ręcznych wiadomości. Podgląd uwzględnia także niezapisane zmiany.</span>
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
                    <button class="button secondary" type="button" data-layout-preview>Podgląd wyglądu</button>
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
            <div class="mail-queue-box">
                <div class="mail-queue-header">
                    <div>
                        <strong>Niewysłane wiadomości</strong>
                        <span>SMTP wyłączone: {{ $mailQueue['held'] }} · w toku: {{ $mailQueue['pending'] }} · błędy: {{ $mailQueue['failed'] }}</span>
                    </div>
                    <form method="POST" action="{{ route('settings.mail.retry-unsent') }}">
                        @csrf
                        <input type="hidden" name="limit" value="100">
                        <button class="button secondary" type="submit" @disabled(($mailQueue['held'] + $mailQueue['pending'] + $mailQueue['failed']) === 0)>Ponów niewysłane</button>
                    </form>
                </div>
                <p class="muted">Włączenie SMTP nie wysyła automatycznie historii. Dopiero ta akcja ponawia maksymalnie 100 oczekujących lub błędnych wiadomości; pominięte etapy workflow pozostają pominięte.</p>
                @if ($mailQueue['recent']->isNotEmpty())
                    <div class="mail-queue-list">
                        @foreach ($mailQueue['recent'] as $queuedMessage)
                            <div>
                                <span class="status {{ $queuedMessage->status === 'failed' ? 'orange' : 'blue' }}">{{ $queuedMessage->status }}</span>
                                <strong>{{ \Illuminate\Support\Str::limit($queuedMessage->subject, 55) }}</strong>
                                <small>{{ $queuedMessage->recipient_email ?: 'brak odbiorcy' }} · {{ $queuedMessage->created_at?->format('d.m H:i') }}</small>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </article>
    </section>

    <section class="card settings-panel mail-workflow-panel">
        <div class="panel-header">
            <span>Workflow maili do klientów</span>
            <span>{{ count($mailWorkflow) }} sytuacji klienta</span>
        </div>
        <form method="POST" action="{{ route('settings.mail.workflow.update') }}" class="mail-workflow-body">
            @csrf
            @method('PUT')

            <div class="workflow-intro">
                <div>
                    <strong>Automatyczne wiadomości statusowe</strong>
                    <span class="muted">ERP odpowiada za całą komunikację, niezależnie od wyłączonych maili WooCommerce. Każdą sytuację możesz włączyć, opisać i zobaczyć dokładnie tak, jak klient.</span>
                </div>
                <button class="button" type="submit">Zapisz workflow maili</button>
            </div>

            <div class="workflow-mail-list">
                @foreach ($mailWorkflow as $workflowMail)
                    @php
                        $workflowCode = $workflowMail['code'];
                        $workflowEnabled = (bool) old('workflow.'.$workflowCode.'.enabled', $workflowMail['enabled']);
                    @endphp
                    <details @class(['workflow-mail-card', 'disabled' => ! $workflowEnabled]) data-mail-workflow-card data-trigger="{{ $workflowCode }}" data-scenario="{{ $workflowMail['scenario'] }}" @if($loop->first) open @endif>
                        <summary class="workflow-mail-summary">
                            <div>
                                <strong>{{ $workflowMail['name'] }}</strong>
                                <span>{{ $workflowMail['context_label'] }} · {{ $workflowCode }}</span>
                            </div>
                            <span class="workflow-mail-state">{{ $workflowEnabled ? 'Aktywny' : 'Wyłączony' }} <b>⌄</b></span>
                        </summary>
                        <div class="workflow-mail-editor">
                            <div class="workflow-mail-actions">
                                <button class="button secondary compact" type="button" data-mail-preview>Podgląd</button>
                                <label class="inline-flag workflow-toggle">
                                    <input type="hidden" name="workflow[{{ $workflowCode }}][enabled]" value="0">
                                    <input type="checkbox" name="workflow[{{ $workflowCode }}][enabled]" value="1" @checked($workflowEnabled)>
                                    Wysyłaj
                                </label>
                            </div>

                            <p class="muted">{{ $workflowMail['description'] }}</p>

                            <div class="mail-settings-fields">
                                <label>Moment wysyłki
                                    <input name="workflow[{{ $workflowCode }}][stage]" value="{{ old('workflow.'.$workflowCode.'.stage', $workflowMail['stage']) }}" maxlength="160">
                                </label>
                                <label>Temat
                                    <input name="workflow[{{ $workflowCode }}][subject]" value="{{ old('workflow.'.$workflowCode.'.subject', $workflowMail['subject']) }}" maxlength="160">
                                </label>
                            </div>

                            <label>Treść automatycznego maila
                                <textarea name="workflow[{{ $workflowCode }}][body]" rows="4" maxlength="5000">{{ old('workflow.'.$workflowCode.'.body', $workflowMail['body']) }}</textarea>
                            </label>
                        </div>
                    </details>
                @endforeach
            </div>
        </form>
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
                <div class="template-actions">
                    <button class="button secondary" type="button" data-template-preview>Podgląd</button>
                    <button class="button" type="submit">Dodaj szablon</button>
                </div>
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
                                <button class="button secondary" type="button" data-template-preview>Podgląd</button>
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

    <div class="mail-preview-overlay" data-mail-preview-overlay hidden>
        <section class="mail-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="mail-preview-title">
            <header>
                <div>
                    <span>Podgląd wiadomości</span>
                    <strong id="mail-preview-title" data-mail-preview-title>Ładowanie…</strong>
                </div>
                <button type="button" class="mail-preview-close" data-mail-preview-close aria-label="Zamknij">×</button>
            </header>
            <div class="mail-preview-toolbar">
                <div class="mail-preview-tabs" role="tablist">
                    <button type="button" class="active" data-preview-mode="html">HTML</button>
                    <button type="button" data-preview-mode="text">Tekst</button>
                </div>
                <div class="mail-preview-tabs" role="tablist">
                    <button type="button" class="active" data-preview-width="680">Komputer</button>
                    <button type="button" data-preview-width="390">Telefon</button>
                </div>
            </div>
            <div class="mail-preview-diagnostics" data-mail-preview-diagnostics hidden></div>
            <div class="mail-preview-stage" data-mail-preview-stage>
                <iframe title="Podgląd wiadomości e-mail" sandbox referrerpolicy="no-referrer" data-mail-preview-frame></iframe>
                <pre data-mail-preview-text hidden></pre>
            </div>
        </section>
    </div>
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
        .mail-queue-box { display: grid; gap: 12px; padding: 16px; border-top: 1px solid var(--border); background: rgba(134, 115, 100, .025); }
        .mail-queue-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
        .mail-queue-header > div { display: grid; gap: 3px; }
        .mail-queue-header span { color: var(--muted); font-size: 12px; }
        .mail-queue-list { display: grid; gap: 8px; }
        .mail-queue-list > div { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 3px 8px; align-items: center; padding: 8px 0; border-top: 1px solid var(--border); }
        .mail-queue-list small { grid-column: 2; color: var(--muted); }
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
        .mail-workflow-panel { margin-top: 14px; }
        .mail-workflow-body { padding: 16px; display: grid; gap: 14px; }
        .workflow-intro { display: flex; justify-content: space-between; gap: 14px; align-items: flex-start; }
        .workflow-intro > div { display: grid; gap: 4px; max-width: 760px; }
        .workflow-intro .button { white-space: nowrap; }
        .workflow-mail-list { display: grid; gap: 12px; }
        .workflow-mail-card { border: 1px solid var(--border); border-radius: 8px; background: var(--surface); overflow: hidden; }
        .workflow-mail-card.disabled { background: rgba(134, 115, 100, 0.045); }
        .workflow-mail-summary { display: flex; justify-content: space-between; gap: 12px; align-items: center; min-height: 62px; padding: 12px 14px; cursor: pointer; list-style: none; }
        .workflow-mail-summary::-webkit-details-marker { display: none; }
        .workflow-mail-summary > div { display: grid; gap: 2px; }
        .workflow-mail-summary > div span { color: var(--muted); font-size: 12px; font-weight: 720; }
        .workflow-mail-state { display: inline-flex; align-items: center; gap: 9px; color: var(--green-dark); font-size: 12px; font-weight: 780; }
        .workflow-mail-card.disabled .workflow-mail-state { color: var(--muted); }
        .workflow-mail-state b { font-size: 18px; line-height: 1; transition: transform .16s ease; }
        .workflow-mail-card[open] .workflow-mail-state b { transform: rotate(180deg); }
        .workflow-mail-editor { display: grid; gap: 10px; padding: 12px 14px 14px; border-top: 1px solid var(--border); }
        .workflow-mail-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; flex-wrap: wrap; }
        .workflow-mail-header div { display: grid; gap: 2px; }
        .workflow-mail-header span { color: var(--muted); font-size: 12px; font-weight: 720; }
        .workflow-mail-actions { display: flex; justify-content: flex-end; gap: 8px; align-items: center; flex-wrap: wrap; }
        .button.compact { min-height: 34px; padding: 6px 11px; }
        .workflow-toggle { min-height: 34px; border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; background: #fff; }
        .workflow-external-note { border: 1px dashed var(--border); border-radius: 8px; padding: 10px 12px; background: #fffdfb; color: var(--muted); font-size: 13px; line-height: 1.45; }
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
        .mail-preview-overlay[hidden] { display: none; }
        .mail-preview-overlay { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 20px; background: rgba(25, 25, 25, .62); backdrop-filter: blur(4px); }
        .mail-preview-dialog { width: min(1100px, 96vw); height: min(900px, 94vh); display: grid; grid-template-rows: auto auto auto minmax(0, 1fr); overflow: hidden; border-radius: 14px; background: var(--surface); box-shadow: 0 24px 80px rgba(0, 0, 0, .28); }
        .mail-preview-dialog > header { display: flex; justify-content: space-between; gap: 16px; align-items: center; padding: 16px 18px; border-bottom: 1px solid var(--border); }
        .mail-preview-dialog > header > div { display: grid; gap: 3px; }
        .mail-preview-dialog > header span { color: var(--muted); font-size: 12px; font-weight: 760; text-transform: uppercase; letter-spacing: .5px; }
        .mail-preview-dialog > header strong { font-size: 16px; }
        .mail-preview-close { width: 38px; height: 38px; border: 1px solid var(--border); border-radius: 50%; background: var(--surface); color: var(--text); font-size: 25px; line-height: 1; cursor: pointer; }
        .mail-preview-toolbar { display: flex; justify-content: space-between; gap: 12px; padding: 10px 18px; border-bottom: 1px solid var(--border); background: #faf9f7; }
        .mail-preview-tabs { display: flex; gap: 3px; padding: 3px; border: 1px solid var(--border); border-radius: 9px; background: #fff; }
        .mail-preview-tabs button { border: 0; border-radius: 6px; padding: 7px 11px; background: transparent; color: var(--muted); font: inherit; font-size: 12px; font-weight: 760; cursor: pointer; }
        .mail-preview-tabs button.active { background: var(--brand-dark); color: #fff; }
        .mail-preview-diagnostics { padding: 9px 18px; border-bottom: 1px solid #f1d39b; background: #fff7e7; color: #7a4d0b; font-size: 12px; line-height: 1.5; }
        .mail-preview-stage { min-height: 0; overflow: auto; padding: 22px; background: #d9d9d6; text-align: center; }
        .mail-preview-stage iframe { display: block; width: min(680px, 100%); height: 100%; min-height: 680px; margin: 0 auto; border: 0; background: #fff; box-shadow: 0 8px 28px rgba(0, 0, 0, .12); transition: width .2s ease; }
        .mail-preview-stage pre { width: min(680px, 100%); min-height: 100%; margin: 0 auto; padding: 26px; overflow: auto; background: #fff; color: #222; text-align: left; white-space: pre-wrap; font: 13px/1.6 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; box-shadow: 0 8px 28px rgba(0, 0, 0, .12); }
        .mail-preview-stage iframe[hidden],
        .mail-preview-stage pre[hidden] { display: none; }
        @media (max-width: 900px) {
            .mail-settings-grid,
            .mail-settings-fields,
            .template-list { grid-template-columns: 1fr; }
            .workflow-intro { display: grid; }
            .mail-queue-header { display: grid; }
            .mail-preview-overlay { padding: 0; }
            .mail-preview-dialog { width: 100vw; height: 100vh; border-radius: 0; }
            .mail-preview-stage { padding: 12px; }
        }
    </style>
@endpush

@push('scripts')
    <script>
        (() => {
            const overlay = document.querySelector('[data-mail-preview-overlay]');
            if (!overlay) return;

            const frame = overlay.querySelector('[data-mail-preview-frame]');
            const textPreview = overlay.querySelector('[data-mail-preview-text]');
            const title = overlay.querySelector('[data-mail-preview-title]');
            const diagnostics = overlay.querySelector('[data-mail-preview-diagnostics]');
            const layoutForm = document.querySelector('form[action="{{ route('settings.mail.update') }}"]');
            let previewData = null;

            const fieldValue = (scope, selector) => scope?.querySelector(selector)?.value ?? '';
            const layout = () => Object.fromEntries([
                'brand_name', 'logo_url', 'accent_color', 'header_text', 'signature',
                'footer_text', 'support_email', 'support_phone',
            ].map((name) => [name, fieldValue(layoutForm, `[name="${name}"]`)]));

            const openPreview = async ({trigger, scenario, subject, body}) => {
                overlay.hidden = false;
                document.body.style.overflow = 'hidden';
                title.textContent = 'Generowanie podglądu…';
                frame.srcdoc = '<!doctype html><html><body style="font-family:Arial;padding:32px;color:#666">Przygotowujemy wiadomość…</body></html>';
                textPreview.textContent = '';
                diagnostics.hidden = true;

                try {
                    const response = await fetch(@json(route('settings.mail.preview')), {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': @json(csrf_token()),
                        },
                        body: JSON.stringify({trigger, scenario, subject, body, layout: layout()}),
                    });
                    const data = await response.json();
                    if (!response.ok) {
                        const errors = Object.values(data.errors ?? {}).flat();
                        throw new Error(errors[0] ?? data.message ?? 'Nie udało się przygotować podglądu.');
                    }

                    previewData = data;
                    title.textContent = data.subject;
                    frame.srcdoc = data.html;
                    textPreview.textContent = data.text;
                    const warnings = [];
                    const placeholder = (item) => `\u007b\u007b${item}\u007d\u007d`;
                    if (data.diagnostics?.unknown_variables?.length) warnings.push(`Nieznane zmienne: ${data.diagnostics.unknown_variables.map(placeholder).join(', ')}`);
                    if (data.diagnostics?.missing_variables?.length) warnings.push(`Brak danych w tym scenariuszu: ${data.diagnostics.missing_variables.map(placeholder).join(', ')}`);
                    diagnostics.textContent = warnings.join(' · ');
                    diagnostics.hidden = warnings.length === 0;
                } catch (error) {
                    title.textContent = 'Błąd podglądu';
                    frame.srcdoc = `<!doctype html><html><body style="font-family:Arial;padding:32px;color:#8b1e1e">${String(error.message ?? error).replace(/[<>&"]/g, '')}</body></html>`;
                }
            };

            document.querySelectorAll('[data-mail-preview]').forEach((button) => {
                button.addEventListener('click', () => {
                    const card = button.closest('[data-mail-workflow-card]');
                    openPreview({
                        trigger: card.dataset.trigger,
                        scenario: card.dataset.scenario,
                        subject: fieldValue(card, 'input[name$="[subject]"]'),
                        body: fieldValue(card, 'textarea[name$="[body]"]'),
                    });
                });
            });

            document.querySelectorAll('[data-template-preview]').forEach((button) => {
                button.addEventListener('click', () => {
                    const form = button.closest('.template-form');
                    const context = fieldValue(form, 'select[name="context"]');
                    const isReturn = context === 'return';
                    openPreview({
                        trigger: isReturn ? 'return_waiting_for_package' : 'order_received',
                        scenario: isReturn ? 'return' : 'order',
                        subject: fieldValue(form, 'input[name="subject"]'),
                        body: fieldValue(form, 'textarea[name="body"]'),
                    });
                });
            });

            document.querySelector('[data-layout-preview]')?.addEventListener('click', () => {
                const card = document.querySelector('[data-mail-workflow-card][data-trigger="order_received"]');
                openPreview({
                    trigger: 'order_received',
                    scenario: 'order',
                    subject: fieldValue(card, 'input[name$="[subject]"]'),
                    body: fieldValue(card, 'textarea[name$="[body]"]'),
                });
            });

            const close = () => {
                overlay.hidden = true;
                document.body.style.overflow = '';
                frame.srcdoc = '';
                previewData = null;
            };
            overlay.querySelector('[data-mail-preview-close]').addEventListener('click', close);
            overlay.addEventListener('click', (event) => { if (event.target === overlay) close(); });
            document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !overlay.hidden) close(); });

            overlay.querySelectorAll('[data-preview-mode]').forEach((button) => {
                button.addEventListener('click', () => {
                    overlay.querySelectorAll('[data-preview-mode]').forEach((item) => item.classList.toggle('active', item === button));
                    const textMode = button.dataset.previewMode === 'text';
                    frame.hidden = textMode;
                    textPreview.hidden = !textMode;
                });
            });
            overlay.querySelectorAll('[data-preview-width]').forEach((button) => {
                button.addEventListener('click', () => {
                    overlay.querySelectorAll('[data-preview-width]').forEach((item) => item.classList.toggle('active', item === button));
                    const width = `${button.dataset.previewWidth}px`;
                    frame.style.width = width;
                    textPreview.style.width = width;
                });
            });
        })();
    </script>
@endpush
