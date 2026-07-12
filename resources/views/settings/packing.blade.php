@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@section('content')
    @php
        $segmentLabels = [
            'all' => 'Wszystkie produkty',
            'clothing' => 'Odzież',
            'footwear' => 'Obuwie',
        ];
        $stations = collect($packingSettings['stations'] ?? [])->values();
        $blankRows = $stations->count() < 6
            ? collect(range($stations->count() + 1, 6))->map(fn (int $number): array => [
                'code' => 'station-' . $number,
                'name' => '',
                'printer_name' => '',
                'segment' => 'all',
            ])
            : collect();
        $stationRows = $stations
            ->concat($blankRows)
            ->take(6)
            ->values();
    @endphp

    <div class="page-toolbar">
        <a class="button secondary" href="{{ route('settings.index') }}">Wróć do ustawień</a>
        <a class="button secondary" href="{{ route('packing.index') }}">Przejdź do pakowania</a>
    </div>

    <section class="settings-packing-grid">
        <article class="card settings-panel settings-panel-wide">
            <div class="panel-header">
                <span>Stanowiska i drukarki etykiet</span>
                <span>{{ $stations->count() }} aktywne</span>
            </div>

            <form method="POST" action="{{ route('settings.packing.update') }}" class="packing-settings-form">
                @csrf
                @method('PUT')

                <div class="packing-station-list">
                    @foreach ($stationRows as $index => $station)
                        @php
                            $hasStation = trim((string) ($station['name'] ?? '')) !== '';
                            $rowCode = old("stations.{$index}.code", $station['code']);
                            $rowName = old("stations.{$index}.name", $station['name']);
                            $rowPrinter = old("stations.{$index}.printer_name", $station['printer_name']);
                            $rowSegment = old("stations.{$index}.segment", $station['segment']);
                        @endphp
                        <section class="packing-station-row" data-station-row>
                            <div class="station-row-title">
                                <strong>{{ $hasStation ? $station['name'] : 'Nowe stanowisko' }}</strong>
                                <span>{{ $hasStation ? ($segmentLabels[$station['segment']] ?? $station['segment']) : 'Opcjonalny wiersz' }}</span>
                            </div>

                            <label>Kod stanowiska
                                <input name="stations[{{ $index }}][code]" value="{{ $rowCode }}" maxlength="40" placeholder="station-{{ $index + 1 }}">
                                <span class="muted">Ten sam kod wpisz w instalatorze Windows.</span>
                            </label>
                            <label>Nazwa
                                <input name="stations[{{ $index }}][name]" value="{{ $rowName }}" maxlength="80" placeholder="np. Stanowisko odzież">
                            </label>
                            <label>Drukarka etykiet
                                <input name="stations[{{ $index }}][printer_name]" value="{{ $rowPrinter }}" maxlength="120" placeholder="np. Zebra ZD421">
                                <span class="muted">Wpisz ręcznie dokładną nazwę drukarki z Windows.</span>
                            </label>
                            <label>Asortyment
                                <select name="stations[{{ $index }}][segment]">
                                    @foreach ($segmentLabels as $segmentValue => $segmentLabel)
                                        <option value="{{ $segmentValue }}" @selected($rowSegment === $segmentValue)>{{ $segmentLabel }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </section>
                    @endforeach
                </div>

                <label class="keywords-field">Słowa kluczowe obuwia
                    <textarea name="footwear_keywords" rows="5" placeholder="np. obuwie, buty, sneakersy">{{ old('footwear_keywords', implode(', ', $packingSettings['footwear_keywords'] ?? [])) }}</textarea>
                    <span class="muted">System używa tych słów przy automatycznym podziale kompletacji na odzież i obuwie.</span>
                </label>

                <button class="button" type="submit">Zapisz ustawienia pakowania</button>
            </form>
        </article>

        <article class="card settings-panel settings-panel-listener">
            <div class="panel-header">
                <span>Most wydruku Windows</span>
                <span>Zebra</span>
            </div>
            <div class="packing-help">
                <p>Aplikacja Windows łączy się wychodząco przez HTTPS i pobiera zadania przypisane do kodu stanowiska. ERP nie łączy się do prywatnego adresu komputera w magazynie.</p>
                <p>Wpisz ręcznie dokładną nazwę drukarki z Windows. Podczas instalacji aplikacji podaj widoczny obok kod stanowiska oraz token mostu wydruku.</p>
                <p>Etykiety trafią do kolejki i zostaną pobrane przez właściwy komputer magazynowy.</p>
                <div class="print-bridge-credentials">
                    <strong>Dane do instalatora</strong>
                    <label>Adres ERP
                        <input value="{{ $printBridge['erp_url'] }}" readonly autocomplete="off">
                    </label>
                    <label>Token mostu wydruku
                        <span class="print-bridge-token-row">
                            <input type="password" value="{{ $printBridge['token'] }}" readonly autocomplete="off" spellcheck="false" data-print-bridge-token>
                            <button class="button secondary" type="button" data-print-bridge-token-show>Pokaż</button>
                            <button class="button secondary" type="button" data-print-bridge-token-copy>Kopiuj</button>
                        </span>
                    </label>
                    <small>
                        Token jest dostępny wyłącznie w ustawieniach administratora
                        {{ $printBridge['environment_override'] ? 'i pochodzi z konfiguracji serwera.' : 'oraz jest bezpiecznie wyprowadzony z klucza tej instalacji ERP.' }}
                        Nie wysyłaj go osobom postronnym.
                    </small>
                    <span class="printer-status" data-print-bridge-token-status aria-live="polite"></span>
                </div>
                <div class="windows-listener-download">
                    <div class="listener-release-title">
                        <strong>Aplikacja dla Windows 10 i 11</strong>
                        @if ($printListenerApp['available'])
                            <span class="listener-release-badge {{ $printListenerApp['is_internal'] ? 'is-internal' : 'is-public' }}">
                                {{ $printListenerApp['is_internal'] ? 'Wydanie wewnętrzne' : 'Wydanie publiczne' }}
                            </span>
                        @endif
                    </div>
                    @if ($printListenerApp['available'])
                        <p>ERP udostępnia wyłącznie kompletny pakiet x64, którego podpis Authenticode, znacznik czasu i sumy kontrolne zostały zweryfikowane w procesie wydawniczym.</p>

                        <dl class="listener-release-meta">
                            <div><dt>Wersja</dt><dd>{{ $printListenerApp['version'] }}</dd></div>
                            <div><dt>Kanał</dt><dd>{{ $printListenerApp['release_channel'] === 'internal' ? 'wewnętrzny' : 'publiczny' }}</dd></div>
                            <div class="listener-release-meta-wide"><dt>Podmiot certyfikatu wydawcy</dt><dd><code>{{ $printListenerApp['publisher_subject'] }}</code></dd></div>
                            <div class="listener-release-meta-wide"><dt>SHA-256 instalatora</dt><dd><code>{{ $printListenerApp['installer_sha256'] }}</code></dd></div>
                            @if ($printListenerApp['is_internal'])
                                <div class="listener-release-meta-wide"><dt>Odcisk certyfikatu wydawcy</dt><dd><code>{{ $printListenerApp['publisher_certificate_fingerprint'] }}</code></dd></div>
                                <div class="listener-release-meta-wide"><dt>Odcisk certyfikatu głównego</dt><dd><code>{{ $printListenerApp['root_certificate_fingerprint'] }}</code></dd></div>
                            @endif
                        </dl>

                        @if ($printListenerApp['is_internal'])
                            <section class="listener-install-step">
                                <div class="listener-install-step-heading">
                                    <span class="listener-install-step-number">1</span>
                                    <div>
                                        <strong>Jednorazowo ustanów zaufanie</strong>
                                        <span>Wykonaj na każdym komputerze magazynowym jako administrator.</span>
                                    </div>
                                </div>
                                <div class="listener-certificate-actions">
                                    <a class="button secondary" href="{{ $printListenerApp['publisher_certificate_url'] }}">Pobierz certyfikat wydawcy</a>
                                </div>
                                <ol class="listener-certificate-guide">
                                    <li>
                                        <strong>Certyfikat główny:</strong> przenieś plik <code>SempreERP-Internal-Root.cer</code> z komputera administratora przez pendrive, GPO, Intune albo inny wcześniej zaufany kanał. Nie pobieraj go razem z instalatorem. Otwórz plik, wybierz „Zainstaluj certyfikat”, „Komputer lokalny”, a następnie magazyn „Zaufane główne urzędy certyfikacji” (Trusted Root Certification Authorities).
                                    </li>
                                    <li>
                                        <strong>Certyfikat wydawcy:</strong> otwórz plik <code>SempreERP-Internal-Publisher.cer</code> i w tym samym kreatorze wybierz „Komputer lokalny” oraz magazyn „Zaufani wydawcy” (Trusted Publishers).
                                    </li>
                                    <li>Przed kontynuowaniem porównaj odcisk pliku Root z wartością przekazaną razem z nim niezależnym kanałem. Odciski w ERP służą do dodatkowej kontroli wydania.</li>
                                </ol>
                            </section>

                            <section class="listener-install-step">
                                <div class="listener-install-step-heading">
                                    <span class="listener-install-step-number">2</span>
                                    <div>
                                        <strong>Pobierz i uruchom podpisany instalator</strong>
                                        <span>Okno UAC musi pokazać nazwę wydawcy zgodną z podmiotem certyfikatu powyżej.</span>
                                    </div>
                                </div>
                                <a class="button listener-installer-button" href="{{ $printListenerApp['download_url'] }}">Pobierz {{ $printListenerApp['filename'] }}</a>
                            </section>
                        @else
                            <a class="button listener-installer-button" href="{{ $printListenerApp['download_url'] }}">Pobierz podpisany instalator {{ $printListenerApp['filename'] }}</a>
                        @endif

                        <small>Wersja {{ $printListenerApp['version'] }} · aktualizacja {{ $printListenerApp['updated_at'] }} · {{ $printListenerApp['size_mb'] }}</small>
                        <div class="listener-security-warning" role="note">
                            <strong>Nie wyłączaj Microsoft Defender ani SmartScreen.</strong>
                            <span>Jeżeli Defender poda konkretną nazwę zagrożenia albo UAC pokaże „Nieznany wydawca”, przerwij instalację. SmartScreen lub Smart App Control w Windows 11 może nadal wymagać zgody albo polityki administratora; instalacja certyfikatów nie jest obejściem tych zabezpieczeń.</span>
                        </div>
                    @else
                        <span class="alert error">Podpisany instalator nie został jeszcze opublikowany. Stary surowy plik EXE nie jest udostępniany.</span>
                    @endif
                </div>
            </div>
        </article>
    </section>
@endsection

@push('styles')
    <style>
        .settings-packing-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(340px, 390px); gap: 14px; align-items: start; }
        .settings-panel-wide { min-width: 0; }
        .settings-panel-listener { min-width: 0; }
        .packing-settings-form { display: grid; gap: 14px; padding: 16px; }
        .packing-station-list { display: grid; gap: 10px; }
        .packing-station-row {
            display: grid;
            grid-template-columns: minmax(150px, .85fr) minmax(150px, .7fr) minmax(180px, .9fr) minmax(220px, 1.15fr) minmax(130px, .65fr);
            gap: 10px;
            align-items: end;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fffdfb;
        }
        .station-row-title { align-self: center; display: grid; gap: 3px; }
        .station-row-title span { color: var(--muted); font-size: 12px; font-weight: 720; }
        .keywords-field { display: grid; gap: 6px; }
        .packing-help { padding: 16px; display: grid; gap: 10px; color: var(--muted); line-height: 1.45; }
        .packing-help p { margin: 0; }
        .print-bridge-credentials { display: grid; gap: 9px; padding-top: 12px; border-top: 1px solid var(--border); }
        .print-bridge-credentials strong { color: var(--text); }
        .print-bridge-credentials label { display: grid; gap: 5px; color: var(--text); }
        .print-bridge-token-row { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: 6px; }
        .print-bridge-token-row input { min-width: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .printer-status { color: var(--muted); font-size: 12px; min-height: 18px; }
        .windows-listener-download { display: grid; gap: 8px; padding-top: 12px; border-top: 1px solid var(--border); color: var(--muted); }
        .windows-listener-download strong { color: var(--text); }
        .windows-listener-download p { margin: 0; }
        .windows-listener-download .button { justify-self: start; text-decoration: none; }
        .windows-listener-download small { color: var(--muted); font-size: 12px; }
        .listener-release-title { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .listener-release-badge { flex: none; padding: 4px 7px; border-radius: 999px; font-size: 11px; font-weight: 800; }
        .listener-release-badge.is-internal { color: #6b4700; background: #fff1c2; }
        .listener-release-badge.is-public { color: #176233; background: #dcf7e7; }
        .listener-release-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1px; margin: 4px 0; overflow: hidden; border: 1px solid var(--border); border-radius: 8px; background: var(--border); }
        .listener-release-meta div { min-width: 0; padding: 8px; background: #fff; }
        .listener-release-meta-wide { grid-column: 1 / -1; }
        .listener-release-meta dt { margin: 0 0 3px; color: var(--muted); font-size: 11px; font-weight: 760; }
        .listener-release-meta dd { margin: 0; color: var(--text); font-size: 12px; font-weight: 700; }
        .listener-release-meta code,
        .listener-certificate-guide code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; overflow-wrap: anywhere; word-break: break-word; }
        .listener-install-step { display: grid; gap: 9px; padding: 11px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; }
        .listener-install-step-heading { display: grid; grid-template-columns: 28px minmax(0, 1fr); gap: 8px; align-items: start; }
        .listener-install-step-heading > div { display: grid; gap: 2px; }
        .listener-install-step-heading span:not(.listener-install-step-number) { font-size: 12px; }
        .listener-install-step-number { display: grid; place-items: center; width: 28px; height: 28px; border-radius: 50%; background: var(--text); color: #fff; font-size: 13px; font-weight: 850; }
        .listener-certificate-actions { display: grid; grid-template-columns: 1fr; gap: 6px; }
        .listener-certificate-actions .button { width: 100%; justify-content: center; text-align: center; }
        .listener-certificate-guide { display: grid; gap: 7px; margin: 0; padding-left: 21px; font-size: 12px; }
        .listener-installer-button { text-align: center; }
        .listener-security-warning { display: grid; gap: 4px; padding: 10px; border: 1px solid #e2b24f; border-radius: 8px; background: #fff8df; color: #684c11; font-size: 12px; }
        .listener-security-warning strong { color: #684c11; }
        @media (max-width: 1180px) {
            .settings-packing-grid { grid-template-columns: 1fr; }
            .settings-panel-listener { order: -1; }
            .packing-station-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .station-row-title { grid-column: 1 / -1; }
        }
        @media (max-width: 680px) {
            .packing-help { padding: 12px; }
            .packing-station-row { grid-template-columns: 1fr; }
            .print-bridge-token-row { grid-template-columns: 1fr 1fr; }
            .print-bridge-token-row input { grid-column: 1 / -1; }
            .listener-release-title { align-items: flex-start; flex-direction: column; }
            .listener-release-meta { grid-template-columns: 1fr; }
            .listener-release-meta-wide { grid-column: auto; }
            .listener-install-step { padding: 10px; }
            .listener-installer-button { width: 100%; justify-content: center; }
        }
    </style>
@endpush

@push('scripts')
    <script>
        (() => {
            const tokenField = document.querySelector('[data-print-bridge-token]');
            const showButton = document.querySelector('[data-print-bridge-token-show]');
            const copyButton = document.querySelector('[data-print-bridge-token-copy]');
            const status = document.querySelector('[data-print-bridge-token-status]');

            showButton?.addEventListener('click', () => {
                if (!tokenField) return;
                const reveal = tokenField.type === 'password';
                tokenField.type = reveal ? 'text' : 'password';
                showButton.textContent = reveal ? 'Ukryj' : 'Pokaż';
            });

            copyButton?.addEventListener('click', async () => {
                if (!tokenField) return;

                try {
                    await navigator.clipboard.writeText(tokenField.value);
                    if (status) status.textContent = 'Token skopiowany do schowka.';
                } catch (error) {
                    tokenField.type = 'text';
                    tokenField.select();
                    if (status) status.textContent = 'Zaznaczony token skopiuj skrótem Ctrl+C.';
                }
            });
        })();
    </script>
@endpush
