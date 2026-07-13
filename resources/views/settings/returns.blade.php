@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@section('content')
    @php
        $settingReasons = old('return_reasons', $returnSettings['return_reasons'] ?? []);
        $settingConditions = old('conditions', $returnSettings['conditions'] ?? []);
        $settingDispositions = old('dispositions', $returnSettings['dispositions'] ?? []);
        $defaultCondition = old('default_condition', $returnSettings['default_condition'] ?? 'unchecked');
        $defaultDisposition = old('default_disposition', $returnSettings['default_disposition'] ?? 'restock');
        $storeApiConfigured = (bool) ($returnSettings['store_api_token_configured'] ?? false);
        $storeWebhookConfigured = (bool) ($returnSettings['store_webhook_secret_configured'] ?? false);
    @endphp

    <div class="page-toolbar">
        <a class="button secondary" href="{{ route('settings.index') }}">Wróć do ustawień</a>
        <a class="button secondary" href="{{ route('returns.index') }}">Przejdź do zwrotów</a>
    </div>

    <article class="card settings-panel">
        <div class="panel-header">
            <span>Konfiguracja zwrotów</span>
            <span>Przykład: {{ $returnNumberExample }}</span>
        </div>
        <form method="POST" action="{{ route('settings.returns.update') }}" class="form-grid settings-form">
            @csrf
            @method('PUT')
            <section class="settings-section">
                <h2>Numeracja</h2>
                <div class="settings-fields">
                    <label>Prefiks
                        <input name="numbering_prefix" value="{{ old('numbering_prefix', $returnSettings['numbering_prefix']) }}" required maxlength="32">
                    </label>
                    <label>Format
                        <input name="numbering_pattern" value="{{ old('numbering_pattern', $returnSettings['numbering_pattern']) }}" required maxlength="120">
                    </label>
                    <label>Długość numeru
                        <input name="numbering_padding" value="{{ old('numbering_padding', $returnSettings['numbering_padding']) }}" type="number" min="3" max="9" required>
                    </label>
                </div>
                <p class="muted">Tokeny: {PREFIX}, {YYYY}, {YY}, {MM}, {SEQ}. Obecny przykład: {{ $returnNumberExample }}</p>
            </section>

            <section class="settings-section">
                <h2>Domyślne przyjęcie</h2>
                <div class="settings-fields">
                    <label>Domyślny magazyn zwrotów
                        <select name="default_target_warehouse_id">
                            <option value="">Brak domyślnego magazynu</option>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((string) old('default_target_warehouse_id', $returnSettings['default_target_warehouse_id']) === (string) $warehouse->id)>
                                    {{ $warehouse->code }} - {{ $warehouse->name }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <label>Domyślny stan towaru
                        <select name="default_condition" required>
                            @foreach ($settingConditions as $condition)
                                @continue(empty($condition['code']) || empty($condition['label']))
                                <option value="{{ $condition['code'] }}" @selected($defaultCondition === $condition['code'])>{{ $condition['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Domyślna dyspozycja
                        <select name="default_disposition" required>
                            @foreach ($settingDispositions as $disposition)
                                @continue(empty($disposition['code']) || empty($disposition['label']))
                                <option value="{{ $disposition['code'] }}" @selected($defaultDisposition === $disposition['code'])>{{ $disposition['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </section>

            <section class="settings-section">
                <h2>Powody zwrotów</h2>
                <p class="muted">Te wartości pojawiają się jako lista wyboru podczas tworzenia i edycji zwrotu.</p>
                <div class="settings-repeat" data-repeat-list data-repeat-type="reason" data-next-index="{{ count($settingReasons) }}">
                    @foreach ($settingReasons as $index => $reason)
                        <label>Powód
                            <input name="return_reasons[{{ $index }}]" value="{{ $reason }}" maxlength="120">
                        </label>
                    @endforeach
                </div>
                <button class="button secondary" type="button" data-repeat-add="reason">Dodaj powód</button>
            </section>

            <section class="settings-section">
                <h2>Stany towaru</h2>
                <p class="muted">Kod jest techniczny i stabilny, etykieta jest widoczna dla użytkownika.</p>
                <div class="settings-option-list" data-repeat-list data-repeat-type="condition" data-next-index="{{ count($settingConditions) }}">
                    @foreach ($settingConditions as $index => $condition)
                        <div class="settings-option-row">
                            <label>Kod
                                <input name="conditions[{{ $index }}][code]" value="{{ $condition['code'] ?? '' }}" maxlength="40">
                            </label>
                            <label>Etykieta
                                <input name="conditions[{{ $index }}][label]" value="{{ $condition['label'] ?? '' }}" maxlength="80">
                            </label>
                        </div>
                    @endforeach
                </div>
                <button class="button secondary" type="button" data-repeat-add="condition">Dodaj stan</button>
            </section>

            <section class="settings-section">
                <h2>Dyspozycje i magazyny domyślne</h2>
                <p class="muted">Magazyn wybrany przy konkretnym zwrocie ma pierwszeństwo. Tu ustawiasz domyślne podpowiedzi dla dyspozycji, gdy magazyn nie został wskazany ręcznie.</p>
                <div class="settings-option-list" data-repeat-list data-repeat-type="disposition" data-next-index="{{ count($settingDispositions) }}">
                    @foreach ($settingDispositions as $index => $disposition)
                        <div class="settings-option-row disposition-row">
                            <label>Kod
                                <input name="dispositions[{{ $index }}][code]" value="{{ $disposition['code'] ?? '' }}" maxlength="40">
                            </label>
                            <label>Etykieta
                                <input name="dispositions[{{ $index }}][label]" value="{{ $disposition['label'] ?? '' }}" maxlength="80">
                            </label>
                            <label>Magazyn docelowy
                                <select name="dispositions[{{ $index }}][warehouse_id]">
                                    <option value="">Użyj magazynu domyślnego</option>
                                    @foreach ($warehouses as $warehouse)
                                        <option value="{{ $warehouse->id }}" @selected((string) ($disposition['warehouse_id'] ?? '') === (string) $warehouse->id)>
                                            {{ $warehouse->code }} - {{ $warehouse->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    @endforeach
                </div>
                <button class="button secondary" type="button" data-repeat-add="disposition">Dodaj dyspozycję</button>
            </section>

            <section class="settings-section">
                <h2>Formularz zwrotów w sklepie (wtyczka lemon-woo-returns)</h2>
                <p class="muted">Token API uwierzytelnia zgłoszenia przychodzące ze sklepu. Sekret webhooka pozwala ERP natychmiast powiadomić sklep o zatwierdzeniu zwrotu — wpisz te same wartości w ustawieniach wtyczki (WooCommerce → Ustawienia zwrotów).</p>
                <div @class(['integration-status', 'ready' => $storeApiConfigured, 'warning' => ! $storeApiConfigured])>
                    <strong>{{ $storeApiConfigured ? 'API formularza zwrotów aktywne' : 'API formularza zwrotów nieaktywne' }}</strong>
                    <span>
                        @if ($storeApiConfigured)
                            ERP przyjmie zgłoszenia ze sklepu, jeśli dokładnie ten sam token jest zapisany we wtyczce WooCommerce.
                        @else
                            Brakuje tokena API. Zgłoszenia z formularza zwrotów w sklepie będą odrzucane statusem HTTP 403 i nie trafią do ERP.
                        @endif
                    </span>
                    @unless ($storeWebhookConfigured)
                        <span>Brakuje też sekretu webhooka, więc ERP nie wyśle automatycznie statusu zwrotu z powrotem do sklepu.</span>
                    @endunless
                </div>
                <div class="settings-fields store-integration-fields">
                    <label>Token API (Bearer / X-API-Key)
                        <span class="token-field">
                            <input name="store_api_token" type="password" value="" maxlength="120" autocomplete="new-password" spellcheck="false" placeholder="{{ $storeApiConfigured ? 'Zostaw puste, aby zachować zapisany token' : 'Wygeneruj lub wpisz token API' }}">
                            <span class="token-actions">
                                <button class="button secondary" type="button" data-token-generate="store_api_token">Generuj token API</button>
                                <button class="button secondary" type="button" data-token-copy="store_api_token" disabled>Kopiuj</button>
                            </span>
                        </span>
                        <small data-token-copy-status="store_api_token" aria-live="polite">Wygeneruj lub wpisz nowy token, skopiuj go, a dopiero potem zapisz ustawienia.</small>
                        @if ($storeApiConfigured)
                            <small>Zapisany token: {{ $returnSettings['store_api_token_mask'] }}. Pole pozostaw puste, aby go nie zmieniać.</small>
                            <span class="check-row">
                                <input name="clear_store_api_token" type="checkbox" value="1" @checked(old('clear_store_api_token'))>
                                Usuń zapisany token API
                            </span>
                        @endif
                    </label>
                    <label>Sekret webhooka (X-Lemon-Returns-Token)
                        <span class="token-field">
                            <input name="store_webhook_secret" type="password" value="" maxlength="120" autocomplete="new-password" spellcheck="false" placeholder="{{ $storeWebhookConfigured ? 'Zostaw puste, aby zachować zapisany sekret' : 'Wygeneruj lub wpisz sekret webhooka' }}">
                            <span class="token-actions">
                                <button class="button secondary" type="button" data-token-generate="store_webhook_secret">Generuj sekret</button>
                                <button class="button secondary" type="button" data-token-copy="store_webhook_secret" disabled>Kopiuj</button>
                            </span>
                        </span>
                        <small data-token-copy-status="store_webhook_secret" aria-live="polite">Wygeneruj lub wpisz nowy sekret, skopiuj go, a dopiero potem zapisz ustawienia.</small>
                        @if ($storeWebhookConfigured)
                            <small>Zapisany sekret: {{ $returnSettings['store_webhook_secret_mask'] }}. Pole pozostaw puste, aby go nie zmieniać.</small>
                            <span class="check-row">
                                <input name="clear_store_webhook_secret" type="checkbox" value="1" @checked(old('clear_store_webhook_secret'))>
                                Usuń zapisany sekret webhooka
                            </span>
                        @endif
                    </label>
                </div>
                <p class="muted">
                    Adresy endpointów do wpisania we wtyczce:<br>
                    wyszukiwanie zamówienia: <code>{{ url('/api/store-returns/lookup-order') }}</code><br>
                    tworzenie zwrotu: <code>{{ url('/api/store-returns') }}</code><br>
                    status zwrotu: <code>{{ url('/api/store-returns/status') }}</code>
                </p>
            </section>

            <button class="button" type="submit">Zapisz ustawienia zwrotów</button>
        </form>
    </article>

    <template id="return-setting-reason-template">
        <label>Powód
            <input name="return_reasons[__INDEX__]" maxlength="120">
        </label>
    </template>

    <template id="return-setting-condition-template">
        <div class="settings-option-row">
            <label>Kod
                <input name="conditions[__INDEX__][code]" maxlength="40" placeholder="np. repaired">
            </label>
            <label>Etykieta
                <input name="conditions[__INDEX__][label]" maxlength="80" placeholder="np. Naprawiony">
            </label>
        </div>
    </template>

    <template id="return-setting-disposition-template">
        <div class="settings-option-row disposition-row">
            <label>Kod
                <input name="dispositions[__INDEX__][code]" maxlength="40" placeholder="np. laundry">
            </label>
            <label>Etykieta
                <input name="dispositions[__INDEX__][label]" maxlength="80" placeholder="np. Do prania">
            </label>
            <label>Magazyn docelowy
                <select name="dispositions[__INDEX__][warehouse_id]">
                    <option value="">Użyj magazynu domyślnego</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </template>
@endsection

@push('styles')
    <style>
        .settings-panel { max-width: 980px; }
        .settings-form { padding: 16px; }
        .settings-section { display: grid; gap: 10px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
        .settings-section h2 { margin: 0; font-size: 18px; }
        .settings-fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .settings-fields.store-integration-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .settings-repeat { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .settings-option-list { display: grid; gap: 10px; }
        .settings-option-row { display: grid; grid-template-columns: minmax(160px, .7fr) minmax(220px, 1fr); gap: 12px; align-items: end; }
        .settings-option-row.disposition-row { grid-template-columns: minmax(140px, .55fr) minmax(200px, .85fr) minmax(260px, 1fr); }
        .integration-status { display: grid; gap: 4px; border: 1px solid rgba(134, 115, 100, .28); border-radius: 8px; padding: 12px; background: #fffdfb; color: var(--muted); line-height: 1.45; }
        .integration-status strong { color: var(--text); font-size: 15px; }
        .integration-status.warning { border-color: rgba(216, 52, 52, .28); background: rgba(216, 52, 52, .07); color: #8f2525; }
        .integration-status.warning strong { color: var(--red); }
        .integration-status.ready { border-color: rgba(95, 80, 69, .30); background: var(--green-soft); color: var(--green-dark); }
        .integration-status.ready strong { color: var(--green-dark); }
        .token-field { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: center; }
        .token-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .token-field .button { min-height: 42px; white-space: nowrap; }
        .settings-form .button { width: fit-content; }
        @media (max-width: 900px) {
            .settings-fields,
            .settings-fields.store-integration-fields,
            .settings-repeat,
            .settings-option-row,
            .settings-option-row.disposition-row,
            .token-field { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@push('scripts')
    <script>
        function generateReturnToken(length = 48) {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789_-';

            if (!window.crypto?.getRandomValues) {
                return null;
            }

            const values = new Uint32Array(length);
            window.crypto.getRandomValues(values);

            return Array.from(values, (value) => chars[value % chars.length]).join('');
        }

        function legacyCopyReturnToken(value) {
            const helper = document.createElement('textarea');
            helper.value = value;
            helper.setAttribute('readonly', '');
            helper.style.position = 'fixed';
            helper.style.opacity = '0';
            document.body.appendChild(helper);
            helper.select();

            const copied = document.execCommand('copy');
            helper.remove();

            if (!copied) {
                throw new Error('Clipboard copy failed');
            }
        }

        async function copyReturnToken(target, button) {
            const value = target.value;

            if (!value) {
                window.alert('Najpierw wygeneruj lub wpisz nową wartość. Zapisanej, zamaskowanej wartości nie można skopiować z tego ekranu.');
                target.focus();
                return;
            }

            try {
                if (navigator.clipboard?.writeText) {
                    try {
                        await navigator.clipboard.writeText(value);
                    } catch (error) {
                        legacyCopyReturnToken(value);
                    }
                } else {
                    legacyCopyReturnToken(value);
                }

                const status = document.querySelector(`[data-token-copy-status="${target.name}"]`);
                if (status) status.textContent = 'Skopiowano do schowka. Teraz zapisz ustawienia i wklej tę samą wartość we wtyczce WooCommerce.';

                const originalLabel = button.textContent;
                button.textContent = 'Skopiowano';
                window.setTimeout(() => {
                    button.textContent = originalLabel;
                }, 2000);
            } catch (error) {
                window.alert('Nie udało się skopiować wartości automatycznie. Zaznacz ją i skopiuj ręcznie przed zapisaniem ustawień.');
                target.focus();
                target.select();
            }
        }

        document.addEventListener('click', (event) => {
            const copyButton = event.target.closest('[data-token-copy]');

            if (copyButton) {
                const target = document.querySelector(`[name="${copyButton.dataset.tokenCopy}"]`);
                if (target) copyReturnToken(target, copyButton);
                return;
            }

            const tokenButton = event.target.closest('[data-token-generate]');

            if (tokenButton) {
                const target = document.querySelector(`[name="${tokenButton.dataset.tokenGenerate}"]`);

                if (target) {
                    const generated = generateReturnToken();

                    if (!generated) {
                        window.alert('Ta przeglądarka nie udostępnia bezpiecznego generatora kryptograficznego. Wpisz token wygenerowany przez menedżer haseł.');
                        return;
                    }

                    target.value = generated;
                    const clearField = document.querySelector(`[name="clear_${target.name}"]`);
                    if (clearField) clearField.checked = false;
                    const copyButton = document.querySelector(`[data-token-copy="${target.name}"]`);
                    if (copyButton) copyButton.disabled = false;
                    target.dispatchEvent(new Event('input', { bubbles: true }));
                    target.focus();
                }

                return;
            }

            const button = event.target.closest('[data-repeat-add]');

            if (!button) {
                return;
            }

            const type = button.dataset.repeatAdd;
            const list = document.querySelector(`[data-repeat-list][data-repeat-type="${type}"]`);
            const template = document.getElementById(`return-setting-${type}-template`);

            if (!list || !template) {
                return;
            }

            const index = Number(list.dataset.nextIndex || 0);
            list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index)));
            list.dataset.nextIndex = String(index + 1);
        });

        document.addEventListener('input', (event) => {
            if (!['store_api_token', 'store_webhook_secret'].includes(event.target?.name)) {
                return;
            }

            const copyButton = document.querySelector(`[data-token-copy="${event.target.name}"]`);
            if (copyButton) copyButton.disabled = !event.target.value;

            const status = document.querySelector(`[data-token-copy-status="${event.target.name}"]`);
            if (status) {
                status.textContent = event.target.value
                    ? 'Nowa wartość jest gotowa. Kliknij „Kopiuj” przed zapisaniem ustawień.'
                    : 'Wygeneruj lub wpisz nową wartość, skopiuj ją, a dopiero potem zapisz ustawienia.';
            }

            if (!event.target.value) return;

            const clearField = document.querySelector(`[name="clear_${event.target.name}"]`);
            if (clearField) clearField.checked = false;
        });
    </script>
@endpush
