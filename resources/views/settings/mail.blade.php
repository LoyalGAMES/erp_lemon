@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@php
    $contextLabel = fn (string $context): string => match ($context) {
        'order' => 'Zamówienia',
        'return' => 'Zwroty',
        default => 'Zamówienia i zwroty',
    };
    $queueTotal = $mailQueue['held'] + $mailQueue['pending'] + $mailQueue['failed'];
    $activeWorkflowCount = collect($mailWorkflow)->where('enabled', true)->count();
    $queuedStatusLabel = fn (string $status): string => match ($status) {
        'held' => 'wstrzymana',
        'pending' => 'oczekuje',
        'failed' => 'błąd',
        default => $status,
    };
    $selectedDeliveryMethod = old('delivery_method', $mailSettings['delivery_method'] ?? 'smtp');
    $deliveryReady = (bool) ($mailSettings['delivery_ready'] ?? $mailSettings['enabled']);
    $mailActive = (bool) $mailSettings['enabled'] && $deliveryReady;
    $googleConnected = (bool) ($mailSettings['google_connected'] ?? false);
    $googleReauthorizationRequired = (bool) ($mailSettings['google_reauthorization_required'] ?? false);
    $googleOauthConfigured = (bool) ($mailSettings['google_oauth_configured'] ?? false);
    $googleClientSecretConfigured = (bool) ($mailSettings['google_client_secret_configured'] ?? $googleOauthConfigured);
    $mailStatusDescription = $mailActive
        ? ($mailSettings['delivery_method'] === 'google_workspace'
            ? 'ERP wysyła komunikację przez połączone konto Google Workspace i Gmail API.'
            : 'ERP wysyła komunikację przez zapisane konto SMTP.')
        : ($mailSettings['enabled']
            ? ($mailSettings['delivery_issue'] ?? 'Uzupełnij konfigurację wybranej metody wysyłki.')
            : 'Nowe wiadomości nie znikają — trafiają do listy niewysłanych i czekają na ręczne ponowienie.');
@endphp

@section('content')
    <div class="page-toolbar">
        <a class="button secondary" href="{{ route('settings.index') }}">Wróć do ustawień</a>
    </div>

    <section @class(['mail-status-banner', 'inactive' => ! $mailActive]) aria-label="Stan komunikacji mailowej">
        <div class="mail-status-indicator" aria-hidden="true"></div>
        <div class="mail-status-copy">
            <strong>{{ $mailActive ? 'Wysyłka maili jest aktywna' : ($mailSettings['enabled'] ? 'Wysyłka wymaga dokończenia konfiguracji' : 'Wysyłka maili jest wstrzymana') }}</strong>
            <span>{{ $mailStatusDescription }}</span>
        </div>
        <div class="mail-status-facts">
            <span>{{ $activeWorkflowCount }} z {{ count($mailWorkflow) }} scenariuszy aktywnych</span>
            <span>{{ $queueTotal === 0 ? 'Brak niewysłanych maili' : $queueTotal.' niewysłanych maili' }}</span>
        </div>
    </section>

    <section class="mail-settings-grid">
        <article class="card settings-panel" id="mail-delivery">
            <div class="panel-header">
                <span>Wysyłka e-mail</span>
                <span @class(['mail-panel-state', 'inactive' => ! $mailActive])>{{ $mailActive ? 'Aktywna' : ($mailSettings['enabled'] ? 'Wymaga konfiguracji' : 'Wyłączona') }}</span>
            </div>
            <form method="POST" action="{{ route('settings.mail.update') }}" class="form-grid settings-form" data-mail-settings-form>
                @csrf
                @method('PUT')

                <label class="toggle-row">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $mailSettings['enabled']))>
                    <span>
                        <strong>Włącz wysyłkę wiadomości z ERP</strong>
                        <small>Gdy opcja jest wyłączona, automatyczne i ręczne maile są bezpiecznie wstrzymywane. Samo ponowne włączenie nie wysyła zaległości.</small>
                    </span>
                </label>

                <fieldset class="mail-delivery-methods">
                    <legend>Metoda wysyłki</legend>
                    <label class="mail-delivery-choice" data-mail-delivery-choice="smtp">
                        <input type="radio" name="delivery_method" value="smtp" @checked($selectedDeliveryMethod === 'smtp')>
                        <span><strong>Serwer SMTP</strong><small>Klasyczne połączenie z serwerem pocztowym.</small></span>
                    </label>
                    <label class="mail-delivery-choice" data-mail-delivery-choice="google_workspace">
                        <input type="radio" name="delivery_method" value="google_workspace" @checked($selectedDeliveryMethod === 'google_workspace')>
                        <span><strong>Google Workspace / Gmail API</strong><small>Autoryzacja OAuth 2.0 bez zapisywania hasła do skrzynki.</small></span>
                    </label>
                </fieldset>

                <section class="mail-delivery-panel" data-mail-delivery-panel="smtp" @if ($selectedDeliveryMethod !== 'smtp') hidden @endif>
                    <div class="mail-delivery-panel-heading">
                        <strong>Konfiguracja SMTP</strong>
                        <span class="muted">Ustawienia pozostaną zapisane również po przełączeniu na Gmail API.</span>
                    </div>
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
                        <label>Domena EHLO
                            <input name="ehlo_domain" value="{{ old('ehlo_domain', $mailSettings['ehlo_domain']) }}" maxlength="255" placeholder="opcjonalnie">
                        </label>
                        <label class="inline-flag mail-clear-password">
                            <input type="checkbox" name="clear_password" value="1">
                            Usuń zapisane hasło SMTP
                        </label>
                    </div>
                </section>

                <section class="mail-delivery-panel google-mail-panel" data-mail-delivery-panel="google_workspace" @if ($selectedDeliveryMethod !== 'google_workspace') hidden @endif>
                    <div class="google-connection-status">
                        <div>
                            <strong>Połączenie z Google</strong>
                            @if ($googleConnected)
                                <span>Połączono konto <b>{{ $mailSettings['google_account_email'] ?: 'Google Workspace' }}</b>.</span>
                            @elseif ($googleReauthorizationRequired)
                                <span>Google wymaga ponownej autoryzacji konta.</span>
                            @elseif ($googleOauthConfigured)
                                <span>Dane aplikacji są zapisane. Połącz konto, z którego ERP ma wysyłać wiadomości.</span>
                            @else
                                <span>Wpisz dane aplikacji OAuth, a następnie użyj przycisku łączenia poniżej.</span>
                            @endif
                        </div>
                        <span @class(['google-status-badge', 'connected' => $googleConnected, 'warning' => $googleReauthorizationRequired])>
                            {{ $googleConnected ? 'Połączono' : ($googleReauthorizationRequired ? 'Połącz ponownie' : 'Niepołączono') }}
                        </span>
                    </div>

                    <div class="mail-settings-fields">
                        <label>Identyfikator klienta
                            <input name="google_client_id" value="{{ old('google_client_id', $mailSettings['google_client_id'] ?? '') }}" maxlength="500" autocomplete="off" placeholder="000000000000-xxxxxxxx.apps.googleusercontent.com">
                        </label>
                        <label>Klucz prywatny klienta
                            <input name="google_client_secret" type="password" maxlength="2000" autocomplete="new-password" placeholder="{{ $googleClientSecretConfigured ? 'Klucz zapisany - wpisz nowy, aby zmienić' : 'Wklej client secret z Google Cloud' }}">
                        </label>
                        <label class="google-redirect-field">Autoryzowany identyfikator URI przekierowania
                            <span class="google-redirect-control">
                                <input value="{{ $mailSettings['google_redirect_uri'] ?? route('settings.mail.google.callback') }}" readonly aria-readonly="true" data-google-redirect-uri>
                                <button class="button secondary compact" type="button" data-copy-google-redirect>Kopiuj</button>
                            </span>
                            <small class="form-hint">Skopiuj ten adres dokładnie do pola „Authorized redirect URIs” w aplikacji internetowej Google.</small>
                        </label>
                        <label class="inline-flag mail-clear-password">
                            <input type="checkbox" name="clear_google_client_secret" value="1">
                            Usuń zapisany klucz prywatny klienta
                        </label>
                    </div>

                    <div class="google-mail-actions">
                        @if ($googleConnected)
                            <button class="button secondary" type="submit" form="google-mail-disconnect-form">Rozłącz konto Google</button>
                        @else
                            <button class="button secondary" type="submit" name="connect_google" value="1">Zapisz i połącz z Google</button>
                        @endif
                        <span class="form-hint">Przycisk zapisze ustawienia i od razu otworzy autoryzację Google.</span>
                    </div>

                    <details class="google-setup-guide">
                        <summary>Jak przygotować integrację w Google Cloud</summary>
                        <ol>
                            <li>Utwórz lub wybierz projekt i włącz <strong>Gmail API</strong>.</li>
                            <li>Skonfiguruj ekran zgody OAuth; dla jednej organizacji Google Workspace wybierz typ <strong>Internal</strong>.</li>
                            <li>Utwórz dane logowania OAuth typu <strong>Web application</strong>.</li>
                            <li>Dodaj powyższy adres jako autoryzowany URI przekierowania i wklej tutaj Client ID oraz Client Secret.</li>
                            <li>Kliknij „Zapisz i połącz z Google” i zaakceptuj minimalny zakres <code>gmail.send</code>.</li>
                            <li>Jeżeli organizacja blokuje aplikację, administrator Workspace musi dopuścić ten Client ID w <strong>Security → API controls → Manage App Access</strong>.</li>
                        </ol>
                    </details>
                </section>

                <div class="settings-subsection">
                    <div>
                        <strong>Nadawca i odpowiedzi</strong>
                        <span class="muted">Te dane obowiązują zarówno dla SMTP, jak i Gmail API.</span>
                    </div>
                    <div class="mail-settings-fields">
                        <label>Adres nadawcy
                            <input name="from_address" type="email" value="{{ old('from_address', $mailSettings['from_address']) }}" maxlength="255" placeholder="sklep@example.com">
                        </label>
                        <label>Nazwa nadawcy
                            <input name="from_name" value="{{ old('from_name', $mailSettings['from_name']) }}" maxlength="120" placeholder="Sempre">
                        </label>
                        <label>Odpowiedz do (Reply-To)
                            <input name="reply_to_address" type="email" value="{{ old('reply_to_address', $mailSettings['reply_to_address']) }}" maxlength="255" placeholder="obsluga@example.com">
                            <small class="form-hint">Na ten adres trafi odpowiedź klienta. Puste pole oznacza adres nadawcy.</small>
                        </label>
                    </div>
                </div>

                <div class="settings-subsection">
                    <div>
                        <strong>Wygląd i dane kontaktowe</strong>
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
                    <button class="button secondary" type="button" data-layout-preview>Podejrzyj mail z tym wyglądem</button>
                </div>

                <details class="technical-details">
                    <summary>Informacje techniczne i bezpieczeństwo</summary>
                    <p>Aktywny mechanizm wysyłki przed zastosowaniem ustawień: <strong>{{ $runtimeMailer }}</strong>. Hasła SMTP, klucze OAuth i tokeny Google są przechowywane w bazie w formie szyfrowanej.</p>
                </details>
                <button class="button" type="submit">Zapisz ustawienia wysyłki</button>
            </form>
            <form id="google-mail-disconnect-form" method="POST" action="{{ route('settings.mail.google.disconnect') }}" hidden>
                @csrf
                @method('DELETE')
            </form>
        </article>

        <article class="card settings-panel">
            <div class="panel-header">
                <span>Test wysyłki</span>
                <span>{{ $mailSettings['delivery_method'] === 'google_workspace' ? ($googleConnected ? 'Gmail API połączone' : 'Gmail API niepołączone') : ($mailSettings['password_configured'] ? 'Hasło SMTP zapisane' : 'Brak hasła SMTP') }}</span>
            </div>
            <form method="POST" action="{{ route('settings.mail.test') }}" class="form-grid settings-form">
                @csrf
                <label>Adres testowy
                    <input name="recipient" type="email" value="{{ old('recipient', $mailSettings['from_address']) }}" required maxlength="255">
                </label>
                <button class="button secondary" type="submit" @disabled(! $deliveryReady)>Wyślij wiadomość testową</button>
                @if (! $deliveryReady)
                    <p class="form-hint warning">{{ $mailSettings['delivery_issue'] ?? 'Najpierw włącz i zapisz poprawną konfigurację wysyłki.' }}</p>
                @endif
            </form>
            <div class="deliverability-box">
                <strong>Dostarczalność</strong>
                <div class="deliverability-meta">
                    <span>Nadawca: {{ $mailDeliverability['from_domain'] ?? '-' }}</span>
                    @if ($mailSettings['delivery_method'] === 'google_workspace')
                        <span>Konto Google: {{ $mailSettings['google_account_email'] ?: '-' }}</span>
                        <span>Transport: Gmail API (OAuth 2.0)</span>
                    @else
                        <span>Login SMTP: {{ $mailDeliverability['username_domain'] ?? '-' }}</span>
                        <span>EHLO: {{ $mailDeliverability['ehlo_domain'] ?: '-' }}</span>
                    @endif
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
                        <span>Wstrzymane: {{ $mailQueue['held'] }} · oczekujące: {{ $mailQueue['pending'] }} · błędy: {{ $mailQueue['failed'] }}</span>
                    </div>
                    <form method="POST" action="{{ route('settings.mail.retry-unsent') }}">
                        @csrf
                        <input type="hidden" name="limit" value="100">
                        <button class="button secondary" type="submit" @disabled(($mailQueue['held'] + $mailQueue['pending'] + $mailQueue['failed']) === 0)>Ponów niewysłane</button>
                    </form>
                </div>
                <p class="muted">Włączenie wysyłki nie uruchamia automatycznie historii. Dopiero ta akcja ponawia maksymalnie 100 oczekujących lub błędnych wiadomości; pominięte etapy workflow pozostają pominięte.</p>
                @if ($mailQueue['recent']->isNotEmpty())
                    <div class="mail-queue-list">
                        @foreach ($mailQueue['recent'] as $queuedMessage)
                            <div>
                                <span class="status {{ $queuedMessage->status === 'failed' ? 'orange' : 'blue' }}">{{ $queuedStatusLabel($queuedMessage->status) }}</span>
                                <strong>{{ \Illuminate\Support\Str::limit($queuedMessage->subject, 55) }}</strong>
                                <small>{{ $queuedMessage->recipient_email ?: 'brak odbiorcy' }} · {{ $queuedMessage->created_at?->format('d.m H:i') }}</small>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </article>
    </section>

    <section class="card settings-panel mail-workflow-panel" id="mail-workflow">
        <div class="panel-header">
            <span>Automatyczna komunikacja z klientem</span>
            <span>{{ $activeWorkflowCount }} z {{ count($mailWorkflow) }} aktywnych</span>
        </div>
        <form method="POST" action="{{ route('settings.mail.workflow.update') }}" class="mail-workflow-body">
            @csrf
            @method('PUT')

            <div class="workflow-intro">
                <div>
                    <strong>Scenariusze na całej ścieżce zamówienia</strong>
                    <span class="muted">ERP odpowiada za całą komunikację, niezależnie od wyłączonych maili WooCommerce. Każdy scenariusz możesz włączyć, opisać i zobaczyć dokładnie tak, jak klient.</span>
                </div>
                <button class="button" type="submit">Zapisz zmiany</button>
            </div>

            <div class="workflow-tools">
                <label class="workflow-search">
                    <span class="sr-only">Szukaj scenariusza</span>
                    <input type="search" data-workflow-search placeholder="Szukaj po nazwie lub momencie wysyłki…" autocomplete="off" aria-controls="workflow-mail-list">
                </label>
                <div class="workflow-filters" role="group" aria-label="Filtruj scenariusze">
                    <button type="button" class="active" data-workflow-filter="all" aria-pressed="true">Wszystkie</button>
                    <button type="button" data-workflow-filter="order" aria-pressed="false">Zamówienia</button>
                    <button type="button" data-workflow-filter="customer" aria-pressed="false">Konta klientów</button>
                    <button type="button" data-workflow-filter="return" aria-pressed="false">Zwroty i wymiany</button>
                    <button type="button" data-workflow-filter="enabled" aria-pressed="false">Aktywne</button>
                    <button type="button" data-workflow-filter="disabled" aria-pressed="false">Wyłączone</button>
                </div>
                <span class="workflow-results" data-workflow-results aria-live="polite"></span>
            </div>

            <div class="workflow-mail-list" id="workflow-mail-list">
                @foreach ($mailWorkflow as $workflowMail)
                    @php
                        $workflowCode = $workflowMail['code'];
                        $workflowEnabled = (bool) old('workflow.'.$workflowCode.'.enabled', $workflowMail['enabled']);
                    @endphp
                    <details @class(['workflow-mail-card', 'disabled' => ! $workflowEnabled]) data-mail-workflow-card data-trigger="{{ $workflowCode }}" data-scenario="{{ $workflowMail['scenario'] }}" data-context="{{ $workflowMail['context'] }}" data-enabled="{{ $workflowEnabled ? '1' : '0' }}" data-search="{{ Illuminate\Support\Str::lower($workflowMail['name'].' '.$workflowMail['stage'].' '.$workflowMail['description']) }}" @if($loop->first) open @endif>
                        <summary class="workflow-mail-summary">
                            <div>
                                <strong>{{ $workflowMail['name'] }}</strong>
                                <span><b>{{ $workflowMail['context_label'] }}</b> · <span data-workflow-stage-summary>{{ $workflowMail['stage'] }}</span></span>
                            </div>
                            <span class="workflow-mail-state"><span data-workflow-state>{{ $workflowEnabled ? 'Aktywny' : 'Wyłączony' }}</span> <b aria-hidden="true">⌄</b></span>
                        </summary>
                        <div class="workflow-mail-editor">
                            <div class="workflow-mail-actions">
                                <button class="button secondary compact" type="button" data-mail-preview>Podgląd</button>
                                <label class="inline-flag workflow-toggle">
                                    <input type="hidden" name="workflow[{{ $workflowCode }}][enabled]" value="0">
                                    <input type="checkbox" name="workflow[{{ $workflowCode }}][enabled]" value="1" @checked($workflowEnabled)>
                                    Wysyłaj automatycznie
                                </label>
                            </div>

                            <p class="muted">{{ $workflowMail['description'] }}</p>
                            <p class="workflow-code">Kod zdarzenia: <code>{{ $workflowCode }}</code></p>

                            <div class="mail-settings-fields">
                                <label>Moment wysyłki
                                    <input name="workflow[{{ $workflowCode }}][stage]" value="{{ old('workflow.'.$workflowCode.'.stage', $workflowMail['stage']) }}" maxlength="160">
                                </label>
                                <label>Temat
                                    <input name="workflow[{{ $workflowCode }}][subject]" value="{{ old('workflow.'.$workflowCode.'.subject', $workflowMail['subject']) }}" maxlength="160">
                                </label>
                            </div>

                            @if ($workflowCode === 'order_on_hold')
                                <div class="mail-settings-fields">
                                    <label>Opóźnienie dla płatności online (minuty)
                                        <input name="workflow[{{ $workflowCode }}][reminder_delay_minutes]" value="{{ old('workflow.'.$workflowCode.'.reminder_delay_minutes', $workflowMail['reminder_delay_minutes']) }}" type="number" min="5" max="10080" step="5">
                                        <small class="form-hint">Domyślnie 30 minut. Zakres: od 5 minut do 7 dni.</small>
                                    </label>
                                    <label>Opóźnienie dla przelewu tradycyjnego (minuty)
                                        <input name="workflow[{{ $workflowCode }}][bank_transfer_delay_minutes]" value="{{ old('workflow.'.$workflowCode.'.bank_transfer_delay_minutes', $workflowMail['bank_transfer_delay_minutes']) }}" type="number" min="5" max="10080" step="5">
                                        <small class="form-hint">Domyślnie 1440 minut (24 godziny). Zakres: do 7 dni.</small>
                                    </label>
                                </div>
                                <p class="muted">Pobranie jest wykluczone z przypomnień. Przed wysyłką ERP ponownie sprawdza status WooCommerce i lokalnie zaksięgowane wpłaty.</p>
                            @endif

                            <label>Treść automatycznego maila
                                <textarea name="workflow[{{ $workflowCode }}][body]" rows="4" maxlength="5000">{{ old('workflow.'.$workflowCode.'.body', $workflowMail['body']) }}</textarea>
                            </label>
                        </div>
                    </details>
                @endforeach
                <div class="workflow-empty" data-workflow-empty hidden>Nie znaleziono scenariuszy pasujących do wybranego filtra.</div>
            </div>
            <div class="workflow-save-footer">
                <span>Zapis obejmie wszystkie scenariusze, także te zwinięte.</span>
                <button class="button" type="submit">Zapisz wszystkie zmiany</button>
            </div>
        </form>
    </section>

    <section class="card settings-panel template-settings-panel">
        <div class="panel-header">
            <span>Szablony e-mail</span>
            <span>{{ $emailTemplates->count() }} szablonów</span>
        </div>
        <div class="template-settings-body">
            <details class="template-variable-help">
                <summary>
                    <strong>Dostępne zmienne</strong>
                    <span>{{ count($templateVariables) }} pól do personalizacji</span>
                </summary>
                <div class="template-variable-content">
                    <div class="template-variable-list">
                        @foreach ($templateVariables as $variable => $description)
                            @php $placeholder = sprintf('{{%s}}', $variable); @endphp
                            <span title="{{ $description }}">{{ $placeholder }}</span>
                        @endforeach
                    </div>
                    <p class="muted">Zmienne są renderowane po stronie systemu, więc zadziałają również wtedy, gdy wpiszesz je ręcznie w temacie lub treści.</p>
                </div>
            </details>
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

    <div class="mail-preview-overlay" data-mail-preview-overlay aria-hidden="true" hidden>
        <section class="mail-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="mail-preview-title" tabindex="-1">
            <header>
                <div>
                    <span>Podgląd wiadomości</span>
                    <strong id="mail-preview-title" data-mail-preview-title aria-live="polite">Ładowanie…</strong>
                </div>
                <button type="button" class="mail-preview-close" data-mail-preview-close aria-label="Zamknij podgląd">×</button>
            </header>
            <div class="mail-preview-toolbar">
                <div class="mail-preview-tabs" role="tablist">
                    <button type="button" class="active" role="tab" aria-selected="true" data-preview-mode="html">Wiadomość</button>
                    <button type="button" role="tab" aria-selected="false" data-preview-mode="text">Wersja tekstowa</button>
                </div>
                <div class="mail-preview-tabs" role="group" aria-label="Szerokość podglądu">
                    <button type="button" class="active" aria-pressed="true" data-preview-width="680">Komputer</button>
                    <button type="button" aria-pressed="false" data-preview-width="390">Telefon</button>
                </div>
            </div>
            <div class="mail-preview-diagnostics" role="alert" data-mail-preview-diagnostics hidden></div>
            <div class="mail-preview-stage" data-mail-preview-stage aria-busy="false">
                <iframe title="Podgląd wiadomości e-mail" sandbox referrerpolicy="no-referrer" data-mail-preview-frame></iframe>
                <pre data-mail-preview-text hidden></pre>
            </div>
        </section>
    </div>
@endsection

@push('styles')
    <style>
        .sr-only { position: absolute !important; width: 1px !important; height: 1px !important; padding: 0 !important; margin: -1px !important; overflow: hidden !important; clip: rect(0, 0, 0, 0) !important; white-space: nowrap !important; border: 0 !important; }
        .mail-status-banner { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 13px; align-items: center; margin-bottom: 14px; padding: 15px 18px; border: 1px solid rgba(47, 111, 79, .22); border-radius: 10px; background: rgba(47, 111, 79, .075); }
        .mail-status-banner.inactive { border-color: rgba(176, 112, 38, .28); background: rgba(226, 167, 84, .1); }
        .mail-status-indicator { width: 11px; height: 11px; border-radius: 50%; background: var(--green-dark); box-shadow: 0 0 0 5px rgba(47, 111, 79, .12); }
        .mail-status-banner.inactive .mail-status-indicator { background: #b06f26; box-shadow: 0 0 0 5px rgba(176, 112, 38, .12); }
        .mail-status-copy { display: grid; gap: 3px; }
        .mail-status-copy strong { font-size: 15px; }
        .mail-status-copy span { color: var(--muted); font-size: 12px; line-height: 1.45; }
        .mail-status-facts { display: flex; gap: 7px; flex-wrap: wrap; justify-content: flex-end; }
        .mail-status-facts span { display: inline-flex; min-height: 29px; align-items: center; border: 1px solid var(--border); border-radius: 999px; padding: 4px 10px; background: var(--surface); color: var(--muted); font-size: 11px; font-weight: 760; }
        .mail-settings-grid { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(320px, .65fr); gap: 14px; }
        .settings-panel { align-self: start; }
        .mail-panel-state { color: var(--green-dark) !important; }
        .mail-panel-state.inactive { color: #9a5d19 !important; }
        .settings-form,
        .template-settings-body { padding: 16px; }
        .settings-form .button,
        .template-form .button { width: fit-content; }
        .mail-settings-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .mail-delivery-methods { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 0; padding: 0; border: 0; }
        .mail-delivery-methods legend { grid-column: 1 / -1; margin-bottom: 1px; color: var(--text); font-size: 13px; font-weight: 760; }
        .mail-delivery-choice { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 10px; align-items: start; min-height: 75px; border: 1px solid var(--border); border-radius: 9px; padding: 12px; background: var(--surface); cursor: pointer; transition: border-color .15s ease, background .15s ease, box-shadow .15s ease; }
        .mail-delivery-choice:hover { background: rgba(47, 111, 79, .035); }
        .mail-delivery-choice.active { border-color: var(--green-dark); background: rgba(47, 111, 79, .065); box-shadow: inset 0 0 0 1px rgba(47, 111, 79, .12); }
        .mail-delivery-choice > input { width: 18px; height: 18px; margin-top: 2px; }
        .mail-delivery-choice > span { display: grid; gap: 3px; }
        .mail-delivery-choice small { color: var(--muted); font-size: 12px; font-weight: 520; line-height: 1.4; }
        .mail-delivery-panel { display: grid; gap: 13px; border: 1px solid var(--border); border-radius: 9px; padding: 14px; background: rgba(134, 115, 100, .025); }
        .mail-delivery-panel[hidden] { display: none; }
        .mail-delivery-panel-heading { display: grid; gap: 3px; }
        .mail-delivery-panel-heading > span { font-size: 12px; line-height: 1.45; }
        .google-mail-panel { background: rgba(66, 133, 244, .035); }
        .google-connection-status { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; border-bottom: 1px solid var(--border); padding-bottom: 12px; }
        .google-connection-status > div { display: grid; gap: 3px; }
        .google-connection-status > div > span { color: var(--muted); font-size: 12px; line-height: 1.45; }
        .google-status-badge { flex: 0 0 auto; display: inline-flex; min-height: 27px; align-items: center; border: 1px solid var(--border); border-radius: 999px; padding: 3px 9px; background: var(--surface); color: var(--muted); font-size: 11px; font-weight: 760; }
        .google-status-badge.connected { border-color: rgba(47, 111, 79, .3); background: rgba(47, 111, 79, .09); color: var(--green-dark); }
        .google-status-badge.warning { border-color: rgba(176, 112, 38, .32); background: rgba(226, 167, 84, .12); color: #8a5818; }
        .google-redirect-field { grid-column: 1 / -1; }
        .google-redirect-control { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: center; }
        .google-redirect-control input[readonly] { color: var(--text); background: #f7f6f4; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 12px; }
        .google-redirect-control .button { min-height: 42px; }
        .google-mail-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .google-mail-actions .form-hint { flex: 1 1 280px; }
        .google-setup-guide { border-top: 1px solid var(--border); padding-top: 11px; color: var(--muted); font-size: 12px; }
        .google-setup-guide summary { color: var(--text); font-weight: 740; cursor: pointer; }
        .google-setup-guide ol { display: grid; gap: 6px; margin: 10px 0 0; padding-left: 22px; line-height: 1.45; }
        .settings-subsection { display: grid; gap: 12px; border-top: 1px solid var(--border); padding-top: 16px; margin-top: 4px; }
        .settings-subsection > div:first-child { display: grid; gap: 4px; }
        .settings-subsection strong { font-size: 15px; }
        .mail-clear-password { align-self: end; min-height: 42px; }
        .technical-details { border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; background: rgba(134, 115, 100, .025); color: var(--muted); font-size: 12px; }
        .technical-details summary { color: var(--text); font-weight: 740; cursor: pointer; }
        .technical-details p { margin: 9px 0 0; line-height: 1.5; }
        .form-hint { margin: -2px 0 0; color: var(--muted); font-size: 12px; line-height: 1.45; }
        .form-hint.warning { color: #8a5818; }
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
        .workflow-tools { display: grid; grid-template-columns: minmax(230px, .8fr) minmax(0, 1.2fr) auto; gap: 10px; align-items: center; padding: 12px; border: 1px solid var(--border); border-radius: 10px; background: #faf9f7; }
        .workflow-search input { min-height: 40px; background: var(--surface); }
        .workflow-filters { display: flex; gap: 5px; flex-wrap: wrap; }
        .workflow-filters button { min-height: 34px; border: 1px solid var(--border); border-radius: 999px; padding: 5px 11px; background: var(--surface); color: var(--muted); font: inherit; font-size: 11px; font-weight: 760; cursor: pointer; }
        .workflow-filters button.active { border-color: var(--brand-dark); background: var(--brand-dark); color: #fff; }
        .workflow-results { color: var(--muted); font-size: 11px; font-weight: 720; white-space: nowrap; }
        .workflow-mail-list { display: grid; gap: 10px; }
        .workflow-mail-card { border: 1px solid var(--border); border-radius: 8px; background: var(--surface); overflow: hidden; }
        .workflow-mail-card[hidden] { display: none; }
        .workflow-mail-card.disabled { background: rgba(134, 115, 100, 0.045); }
        .workflow-mail-summary { display: flex; justify-content: space-between; gap: 12px; align-items: center; min-height: 62px; padding: 12px 14px; cursor: pointer; list-style: none; }
        .workflow-mail-summary:hover { background: rgba(134, 115, 100, .035); }
        .workflow-mail-summary:focus-visible { outline: 3px solid rgba(47, 111, 79, .24); outline-offset: -3px; }
        .workflow-mail-summary::-webkit-details-marker { display: none; }
        .workflow-mail-summary > div { display: grid; gap: 2px; }
        .workflow-mail-summary > div > span { color: var(--muted); font-size: 12px; font-weight: 600; line-height: 1.4; }
        .workflow-mail-summary > div > span b { color: var(--text); font-weight: 740; }
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
        .workflow-code { margin: -3px 0 1px; color: var(--muted); font-size: 11px; }
        .workflow-code code { color: var(--brand-dark); }
        .workflow-empty { border: 1px dashed var(--border); border-radius: 8px; padding: 28px 18px; color: var(--muted); text-align: center; }
        .workflow-empty[hidden] { display: none; }
        .workflow-save-footer { display: flex; justify-content: space-between; gap: 14px; align-items: center; padding: 14px; border: 1px solid var(--border); border-radius: 10px; background: #faf9f7; }
        .workflow-save-footer span { color: var(--muted); font-size: 12px; }
        .template-settings-panel { margin-top: 14px; }
        .template-settings-body { display: grid; gap: 18px; }
        .template-variable-help { border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; overflow: hidden; }
        .template-variable-help summary { display: flex; justify-content: space-between; gap: 12px; align-items: center; min-height: 48px; padding: 10px 12px; cursor: pointer; }
        .template-variable-help summary span { color: var(--muted); font-size: 12px; }
        .template-variable-content { display: grid; gap: 9px; padding: 0 12px 12px; border-top: 1px solid var(--border); }
        .template-variable-content .template-variable-list { padding-top: 12px; }
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
        .mail-preview-close:hover { background: #f3f1ee; }
        .mail-preview-close:focus-visible,
        .mail-preview-tabs button:focus-visible,
        .workflow-filters button:focus-visible { outline: 3px solid rgba(47, 111, 79, .28); outline-offset: 2px; }
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
            .mail-status-banner { grid-template-columns: auto minmax(0, 1fr); }
            .mail-status-facts { grid-column: 2; justify-content: flex-start; }
            .mail-settings-grid,
            .mail-settings-fields,
            .mail-delivery-methods,
            .template-list { grid-template-columns: 1fr; }
            .workflow-intro { display: grid; }
            .workflow-tools { grid-template-columns: 1fr; }
            .workflow-results { white-space: normal; }
            .mail-queue-header { display: grid; }
            .mail-preview-overlay { padding: 0; }
            .mail-preview-dialog { width: 100vw; height: 100vh; border-radius: 0; }
            .mail-preview-stage { padding: 12px; }
        }
        @media (max-width: 560px) {
            .mail-status-banner { padding: 13px; }
            .mail-status-facts { grid-column: 1 / -1; }
            .workflow-intro .button,
            .workflow-save-footer .button { width: 100%; }
            .workflow-save-footer { display: grid; }
            .workflow-mail-summary { align-items: flex-start; }
            .workflow-mail-state { flex: 0 0 auto; }
            .google-connection-status { display: grid; }
            .google-redirect-control { grid-template-columns: 1fr; }
            .google-redirect-control .button { width: 100%; }
            .mail-preview-toolbar { display: grid; }
            .mail-preview-tabs { overflow-x: auto; }
        }
    </style>
@endpush

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('[data-mail-settings-form]');
            if (!form) return;

            const methodInputs = [...form.querySelectorAll('input[name="delivery_method"]')];
            const panels = [...form.querySelectorAll('[data-mail-delivery-panel]')];
            const choices = [...form.querySelectorAll('[data-mail-delivery-choice]')];

            const refreshDeliveryMethod = () => {
                const selected = methodInputs.find((input) => input.checked)?.value ?? 'smtp';
                panels.forEach((panel) => { panel.hidden = panel.dataset.mailDeliveryPanel !== selected; });
                choices.forEach((choice) => choice.classList.toggle('active', choice.dataset.mailDeliveryChoice === selected));
            };

            methodInputs.forEach((input) => input.addEventListener('change', refreshDeliveryMethod));
            refreshDeliveryMethod();

            const copyButton = form.querySelector('[data-copy-google-redirect]');
            const redirectInput = form.querySelector('[data-google-redirect-uri]');
            copyButton?.addEventListener('click', async () => {
                if (!redirectInput) return;
                try {
                    await navigator.clipboard.writeText(redirectInput.value);
                } catch (_) {
                    redirectInput.select();
                    document.execCommand('copy');
                }
                const originalLabel = copyButton.textContent;
                copyButton.textContent = 'Skopiowano';
                window.setTimeout(() => { copyButton.textContent = originalLabel; }, 1600);
            });
        })();

        (() => {
            const overlay = document.querySelector('[data-mail-preview-overlay]');
            if (!overlay) return;

            const frame = overlay.querySelector('[data-mail-preview-frame]');
            const textPreview = overlay.querySelector('[data-mail-preview-text]');
            const title = overlay.querySelector('[data-mail-preview-title]');
            const diagnostics = overlay.querySelector('[data-mail-preview-diagnostics]');
            const dialog = overlay.querySelector('.mail-preview-dialog');
            const stage = overlay.querySelector('[data-mail-preview-stage]');
            const layoutForm = document.querySelector('form[action="{{ route('settings.mail.update') }}"]');
            let lastFocused = null;

            const fieldValue = (scope, selector) => scope?.querySelector(selector)?.value ?? '';
            const layout = () => Object.fromEntries([
                'brand_name', 'logo_url', 'accent_color', 'header_text', 'signature',
                'footer_text', 'support_email', 'support_phone',
            ].map((name) => [name, fieldValue(layoutForm, `[name="${name}"]`)]));

            const workflowCards = [...document.querySelectorAll('[data-mail-workflow-card]')];
            const workflowSearch = document.querySelector('[data-workflow-search]');
            const workflowResults = document.querySelector('[data-workflow-results]');
            const workflowEmpty = document.querySelector('[data-workflow-empty]');
            let workflowFilter = 'all';

            const refreshWorkflow = () => {
                const query = (workflowSearch?.value ?? '').trim().toLocaleLowerCase('pl');
                let visible = 0;

                workflowCards.forEach((card) => {
                    const enabled = card.dataset.enabled === '1';
                    const stageValue = fieldValue(card, 'input[name$="[stage]"]');
                    const subjectValue = fieldValue(card, 'input[name$="[subject]"]');
                    const searchable = `${card.dataset.search ?? ''} ${stageValue} ${subjectValue}`.toLocaleLowerCase('pl');
                    const matchesQuery = query === '' || searchable.includes(query);
                    const matchesFilter = workflowFilter === 'all'
                        || workflowFilter === card.dataset.context
                        || (workflowFilter === 'enabled' && enabled)
                        || (workflowFilter === 'disabled' && !enabled);
                    const show = matchesQuery && matchesFilter;
                    card.hidden = !show;
                    if (show) visible += 1;
                });

                if (workflowResults) workflowResults.textContent = `Widoczne: ${visible} z ${workflowCards.length}`;
                if (workflowEmpty) workflowEmpty.hidden = visible !== 0;
            };

            document.querySelectorAll('[data-workflow-filter]').forEach((button) => {
                button.addEventListener('click', () => {
                    workflowFilter = button.dataset.workflowFilter;
                    document.querySelectorAll('[data-workflow-filter]').forEach((item) => {
                        const active = item === button;
                        item.classList.toggle('active', active);
                        item.setAttribute('aria-pressed', active ? 'true' : 'false');
                    });
                    refreshWorkflow();
                });
            });
            workflowSearch?.addEventListener('input', refreshWorkflow);
            workflowCards.forEach((card) => {
                const toggle = card.querySelector('input[type="checkbox"][name$="[enabled]"]');
                const stageInput = card.querySelector('input[name$="[stage]"]');
                const stageSummary = card.querySelector('[data-workflow-stage-summary]');
                toggle?.addEventListener('change', () => {
                    card.dataset.enabled = toggle.checked ? '1' : '0';
                    card.classList.toggle('disabled', !toggle.checked);
                    const state = card.querySelector('[data-workflow-state]');
                    if (state) state.textContent = toggle.checked ? 'Aktywny' : 'Wyłączony';
                    refreshWorkflow();
                });
                stageInput?.addEventListener('input', () => {
                    if (stageSummary) stageSummary.textContent = stageInput.value || 'Nie określono momentu wysyłki';
                    refreshWorkflow();
                });
            });
            refreshWorkflow();

            const setPreviewMode = (mode) => {
                overlay.querySelectorAll('[data-preview-mode]').forEach((item) => {
                    const active = item.dataset.previewMode === mode;
                    item.classList.toggle('active', active);
                    item.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                const textMode = mode === 'text';
                frame.hidden = textMode;
                textPreview.hidden = !textMode;
            };

            const openPreview = async ({trigger, scenario, subject, body}) => {
                lastFocused = document.activeElement;
                overlay.hidden = false;
                overlay.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                stage.setAttribute('aria-busy', 'true');
                title.textContent = 'Generowanie podglądu…';
                frame.srcdoc = '<!doctype html><html><body style="font-family:Arial;padding:32px;color:#666">Przygotowujemy wiadomość…</body></html>';
                textPreview.textContent = '';
                diagnostics.hidden = true;
                setPreviewMode('html');
                dialog.focus();

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
                } finally {
                    stage.setAttribute('aria-busy', 'false');
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
                overlay.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                frame.srcdoc = '';
                textPreview.textContent = '';
                if (lastFocused instanceof HTMLElement && lastFocused.isConnected) lastFocused.focus();
                lastFocused = null;
            };
            overlay.querySelector('[data-mail-preview-close]').addEventListener('click', close);
            overlay.addEventListener('click', (event) => { if (event.target === overlay) close(); });
            document.addEventListener('keydown', (event) => {
                if (overlay.hidden) return;
                if (event.key === 'Escape') {
                    event.preventDefault();
                    close();
                    return;
                }
                if (event.key !== 'Tab') return;
                const focusable = [...dialog.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
                    .filter((element) => element instanceof HTMLElement && element.offsetParent !== null);
                if (focusable.length === 0) {
                    event.preventDefault();
                    dialog.focus();
                    return;
                }
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });

            overlay.querySelectorAll('[data-preview-mode]').forEach((button) => {
                button.addEventListener('click', () => setPreviewMode(button.dataset.previewMode));
            });
            overlay.querySelectorAll('[data-preview-width]').forEach((button) => {
                button.addEventListener('click', () => {
                    overlay.querySelectorAll('[data-preview-width]').forEach((item) => {
                        const active = item === button;
                        item.classList.toggle('active', active);
                        item.setAttribute('aria-pressed', active ? 'true' : 'false');
                    });
                    const width = `${button.dataset.previewWidth}px`;
                    frame.style.width = width;
                    textPreview.style.width = width;
                });
            });
        })();
    </script>
@endpush
