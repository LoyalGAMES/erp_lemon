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
        $bridgeStations = collect($printBridgeStations ?? []);
        $connectedBridgeStations = $bridgeStations
            ->filter(fn (array $station): bool => (bool) ($station['connected'] ?? false))
            ->count();
        $readyBridgeStations = $stations->filter(function (array $station) use ($bridgeStations): bool {
            $stationCode = trim((string) ($station['code'] ?? ''));
            $printerName = trim((string) ($station['printer_name'] ?? ''));
            $bridgeStation = (array) $bridgeStations->get($stationCode, []);
            $reportedNames = collect($bridgeStation['printers'] ?? [])
                ->map(fn (mixed $printer): string => is_array($printer) ? trim((string) ($printer['name'] ?? '')) : '')
                ->filter();
            $printerIsReported = $reportedNames->contains(
                fn (string $reportedName): bool => mb_strtolower($reportedName) === mb_strtolower($printerName),
            );

            return (bool) ($bridgeStation['connected'] ?? false)
                && trim((string) ($bridgeStation['printer_error'] ?? '')) === ''
                && $printerName !== ''
                && $printerIsReported;
        })->count();
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
                            $rowCode = old("stations.{$index}.code", $station['code']);
                            $rowName = old("stations.{$index}.name", $station['name']);
                            $rowPrinter = old("stations.{$index}.printer_name", $station['printer_name']);
                            $rowSegment = old("stations.{$index}.segment", $station['segment']);
                            $hasStation = trim((string) $rowName) !== '';
                            $bridgeStation = (array) $bridgeStations->get($rowCode, []);
                            $bridgeStatus = (string) ($bridgeStation['status'] ?? 'never');
                            $bridgeWorker = trim((string) ($bridgeStation['worker'] ?? ''));
                            $bridgeVersion = trim((string) ($bridgeStation['version'] ?? ''));
                            $bridgeLastSeen = trim((string) ($bridgeStation['last_seen_at'] ?? ''));
                            $bridgePrinterError = trim((string) ($bridgeStation['printer_error'] ?? ''));
                            $reportedPrinters = collect($bridgeStation['printers'] ?? [])
                                ->filter(fn (mixed $printer): bool => is_array($printer) && trim((string) ($printer['name'] ?? '')) !== '')
                                ->unique(fn (array $printer): string => mb_strtolower(trim((string) $printer['name'])))
                                ->values();
                            $reportedPrinterNames = $reportedPrinters
                                ->map(fn (array $printer): string => trim((string) $printer['name']));
                            $matchedPrinterName = $reportedPrinterNames->first(
                                fn (string $reportedName): bool => mb_strtolower($reportedName) === mb_strtolower($rowPrinter),
                            );
                            $savedPrinterMissing = $rowPrinter !== '' && $matchedPrinterName === null;
                            $printerReady = $bridgeStatus === 'online'
                                && $bridgePrinterError === ''
                                && $rowPrinter !== ''
                                && $matchedPrinterName !== null;
                            $bridgeStatusLabel = ! $hasStation
                                ? 'Nieaktywne'
                                : match ($bridgeStatus) {
                                    'online' => 'Połączono',
                                    'offline' => 'Offline',
                                    default => 'Nie połączono',
                                };
                            $readinessTone = ! $hasStation
                                ? 'is-neutral'
                                : ($printerReady
                                    ? 'is-ready'
                                    : ($bridgeStatus === 'online' && $rowPrinter === '' && $bridgePrinterError === '' ? 'is-warning' : 'is-error'));
                        @endphp
                        <section class="packing-station-row" data-station-row data-bridge-status="{{ $bridgeStatus }}" data-printer-error="{{ $bridgePrinterError }}">
                            <div class="station-row-title">
                                <strong>{{ $hasStation ? $station['name'] : 'Nowe stanowisko' }}</strong>
                                <span>{{ $hasStation ? ($segmentLabels[$station['segment']] ?? $station['segment']) : 'Opcjonalny wiersz' }}</span>
                                <span class="station-bridge-badge is-{{ $bridgeStatus }}" data-station-bridge-badge>
                                    <i aria-hidden="true"></i>{{ $bridgeStatusLabel }}
                                </span>
                                <span class="station-bridge-meta" data-station-bridge-meta>
                                    @if (! $hasStation)
                                        Uzupełnij nazwę, aby dodać stanowisko.
                                    @elseif ($bridgeStatus === 'online')
                                        {{ $bridgeWorker !== '' ? $bridgeWorker : 'Most Windows' }}{{ $bridgeVersion !== '' ? ' · v'.$bridgeVersion : '' }}
                                    @elseif ($bridgeStatus === 'offline')
                                        Ostatni sygnał: {{ $bridgeLastSeen !== '' ? $bridgeLastSeen : 'brak daty' }}
                                    @else
                                        Uruchom aplikację Windows dla kodu {{ $rowCode }}.
                                    @endif
                                </span>
                            </div>

                            <label>Kod stanowiska
                                <input name="stations[{{ $index }}][code]" value="{{ $rowCode }}" maxlength="40" placeholder="station-{{ $index + 1 }}">
                                <span class="muted">Ten sam kod wpisz w instalatorze Windows.</span>
                            </label>
                            <label>Nazwa
                                <input name="stations[{{ $index }}][name]" value="{{ $rowName }}" maxlength="80" placeholder="np. Stanowisko odzież">
                            </label>
                            <label>Drukarka etykiet
                                <select name="stations[{{ $index }}][printer_name]" data-station-printer data-saved-printer="{{ $rowPrinter }}">
                                    <option value="">Wybierz drukarkę z Windows</option>
                                    @if ($savedPrinterMissing)
                                        <option value="{{ $rowPrinter }}" selected>{{ $rowPrinter }} — zapisana, teraz niewidoczna</option>
                                    @endif
                                    @foreach ($reportedPrinters as $printer)
                                        @php
                                            $printerName = trim((string) $printer['name']);
                                            $printerDriver = trim((string) ($printer['driver'] ?? ''));
                                            $printerPort = trim((string) ($printer['port'] ?? ''));
                                            $printerDetails = collect([
                                                ($printer['default'] ?? false) ? 'domyślna' : '',
                                                $printerDriver,
                                                $printerPort,
                                            ])->filter()->implode(' · ');
                                        @endphp
                                        <option value="{{ $printerName }}" data-reported-printer @selected($matchedPrinterName === $printerName)>
                                            {{ $printerName }}{{ $printerDetails !== '' ? ' — '.$printerDetails : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="muted" data-station-printer-hint>
                                    @if ($bridgeStatus === 'online' && $bridgePrinterError !== '')
                                        Połączono, ale odczyt drukarek nie powiódł się: {{ $bridgePrinterError }}
                                    @elseif ($bridgeStatus === 'online' && $reportedPrinters->isNotEmpty())
                                        Lista pochodzi z komputera {{ $bridgeWorker !== '' ? $bridgeWorker : 'Windows' }}.
                                    @elseif ($bridgeStatus === 'online')
                                        Połączono, ale Windows nie zgłosił żadnej drukarki.
                                    @elseif ($bridgeStatus === 'offline' && $reportedPrinters->isNotEmpty())
                                        Pokazujemy ostatnią zgłoszoną listę. Sprawdź połączenie przed zapisem.
                                    @else
                                        Lista pojawi się po połączeniu aplikacji Windows z tym kodem stanowiska.
                                    @endif
                                </span>
                                <span class="station-print-readiness {{ $readinessTone }}" data-station-print-readiness role="status">
                                    @if (! $hasStation)
                                        Opcjonalne stanowisko — uzupełnij nazwę, gdy chcesz je uruchomić.
                                    @elseif ($printerReady)
                                        Gotowe do automatycznego wydruku na {{ $rowPrinter }}.
                                    @elseif ($bridgeStatus !== 'online')
                                        Automatyczny wydruk nie jest gotowy: brak aktywnego połączenia z aplikacją Windows.
                                    @elseif ($bridgePrinterError !== '')
                                        Automatyczny wydruk nie jest gotowy: nie można potwierdzić drukarek z Windows.
                                    @elseif ($rowPrinter === '')
                                        Wybierz drukarkę — samo połączenie nie uruchamia automatycznego wydruku.
                                    @else
                                        Drukarka „{{ $rowPrinter }}” nie jest dostępna na aktualnej liście Windows. Wybierz inną.
                                    @endif
                                </span>
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
                <span>Windows 10/11</span>
            </div>
            <div class="packing-help">
                <p>Aplikacja Windows łączy się wychodząco przez HTTPS i pobiera zadania przypisane do kodu stanowiska. ERP nie łączy się do prywatnego adresu komputera w magazynie.</p>
                <p>Po połączeniu aplikacja automatycznie prześle listę drukarek zainstalowanych w Windows. Wybierz drukarkę przy właściwym stanowisku — nie trzeba przepisywać jej nazwy.</p>
                <p>Etykiety trafią do kolejki i zostaną pobrane przez właściwy komputer magazynowy.</p>
                <section class="print-bridge-verification" data-print-bridge-verification data-status-url="{{ route('settings.packing.print-bridge.status') }}">
                    <div class="print-bridge-verification-heading">
                        <span>
                            <strong>Stan połączenia</strong>
                            <small data-print-bridge-summary>
                                {{ $connectedBridgeStations }} z {{ $stations->count() }} online · {{ $readyBridgeStations }} gotowych do druku
                            </small>
                        </span>
                        <span class="station-bridge-badge {{ $stations->isNotEmpty() && $readyBridgeStations === $stations->count() ? 'is-online' : ($connectedBridgeStations > 0 ? 'is-offline' : 'is-never') }}" data-print-bridge-overall-badge>
                            <i aria-hidden="true"></i>{{ $stations->isNotEmpty() && $readyBridgeStations === $stations->count() ? 'Gotowe' : ($connectedBridgeStations > 0 ? 'Wymaga konfiguracji' : 'Brak połączenia') }}
                        </span>
                    </div>
                    <p>Status jest potwierdzany sygnałem wysyłanym przez usługę Windows. Stan „Połączono” oznacza sygnał odebrany w ciągu ostatnich 90 sekund.</p>
                    <button class="button secondary" type="button" data-print-bridge-check>Sprawdź połączenie</button>
                    <span class="printer-status" data-print-bridge-check-status aria-live="polite"></span>
                </section>
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

                        @if ($printListenerApp['self_trusts'])
                            <section class="listener-install-step">
                                <div class="listener-install-step-heading">
                                    <span class="listener-install-step-number">1</span>
                                    <div>
                                        <strong>Pobierz instalator i uruchom go jako administrator</strong>
                                        <span>Instalator jednorazowo doda zaufanie Sempre ERP na tym komputerze, zainstaluje usługę i przygotuje kolejne aktualizacje do zwykłego uruchamiania.</span>
                                    </div>
                                </div>
                                <a class="button listener-installer-button" href="{{ $printListenerApp['download_url'] }}">Pobierz instalator {{ $printListenerApp['filename'] }}</a>
                            </section>
                        @elseif ($printListenerApp['is_internal'])
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

                        <div class="listener-settings-guide">
                            <strong>Gdzie znaleźć ustawienia po instalacji?</strong>
                            <span>Otwórz menu Start → Sempre ERP Print Listener → Ustawienia połączenia. Możesz tam ponownie podać adres ERP, token i kod stanowiska.</span>
                            <span>Skrót „Sprawdź połączenie” w tym samym folderze potwierdzi pracę usługi i połączenie z ERP.</span>
                        </div>

                        <details class="listener-release-details">
                            <summary>Szczegóły techniczne i sumy kontrolne</summary>
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
                        </details>

                        <small>Wersja {{ $printListenerApp['version'] }} · aktualizacja {{ $printListenerApp['updated_at'] }} · {{ $printListenerApp['size_mb'] }}</small>
                        <div class="listener-security-warning" role="note">
                            <strong>Nie wyłączaj Microsoft Defender ani SmartScreen.</strong>
                            @if ($printListenerApp['self_trusts'])
                                <span>Przy pierwszym uruchomieniu Windows może pokazać „Nieznany wydawca”, ponieważ zaufanie jest dodawane dopiero po uruchomieniu tego pliku jako administrator. Jest to oczekiwane wyłącznie dla instalatora pobranego z tego panelu i zgodnego z SHA-256 w szczegółach. Jeżeli Defender poda konkretną nazwę zagrożenia, przerwij instalację.</span>
                            @else
                                <span>Jeżeli Defender poda konkretną nazwę zagrożenia albo UAC pokaże „Nieznany wydawca”, przerwij instalację. SmartScreen lub Smart App Control w Windows 11 może nadal wymagać zgody albo polityki administratora.</span>
                            @endif
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
        .station-bridge-badge { justify-self: start; display: inline-flex; align-items: center; gap: 6px; min-height: 24px; padding: 3px 8px; border-radius: 999px; background: #f0ece8; color: var(--muted) !important; font-size: 11px !important; font-weight: 820 !important; }
        .station-bridge-badge i { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
        .station-bridge-badge.is-online { background: #dcf7e7; color: #176233 !important; }
        .station-bridge-badge.is-offline { background: #fff0e8; color: var(--orange) !important; }
        .station-bridge-badge.is-never { background: #f0ece8; color: var(--muted) !important; }
        .station-bridge-meta { overflow-wrap: anywhere; font-weight: 600 !important; }
        .station-print-readiness { display: block; padding: 7px 8px; border-radius: 7px; font-size: 12px; font-weight: 720; line-height: 1.35; }
        .station-print-readiness.is-ready { background: #dcf7e7; color: #176233; }
        .station-print-readiness.is-warning { background: #fff8df; color: #684c11; }
        .station-print-readiness.is-error { background: #fff0f0; color: var(--red); }
        .station-print-readiness.is-neutral { background: #f5f2ef; color: var(--muted); }
        .keywords-field { display: grid; gap: 6px; }
        .packing-help { padding: 16px; display: grid; gap: 10px; color: var(--muted); line-height: 1.45; }
        .packing-help p { margin: 0; }
        .print-bridge-verification { display: grid; gap: 9px; padding: 11px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; }
        .print-bridge-verification-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .print-bridge-verification-heading > span:first-child { display: grid; gap: 2px; }
        .print-bridge-verification-heading strong { color: var(--text); }
        .print-bridge-verification-heading small { color: var(--muted); }
        .print-bridge-verification .button { justify-self: start; }
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
        .listener-release-details { border: 1px solid var(--border); border-radius: 8px; background: #fff; }
        .listener-release-details summary { padding: 9px 10px; cursor: pointer; font-size: 12px; font-weight: 800; }
        .listener-release-details[open] summary { border-bottom: 1px solid var(--border); }
        .listener-release-details .listener-release-meta { margin: 10px; }
        .listener-install-step { display: grid; gap: 9px; padding: 11px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; }
        .listener-install-step-heading { display: grid; grid-template-columns: 28px minmax(0, 1fr); gap: 8px; align-items: start; }
        .listener-install-step-heading > div { display: grid; gap: 2px; }
        .listener-install-step-heading span:not(.listener-install-step-number) { font-size: 12px; }
        .listener-install-step-number { display: grid; place-items: center; width: 28px; height: 28px; border-radius: 50%; background: var(--text); color: #fff; font-size: 13px; font-weight: 850; }
        .listener-certificate-actions { display: grid; grid-template-columns: 1fr; gap: 6px; }
        .listener-certificate-actions .button { width: 100%; justify-content: center; text-align: center; }
        .listener-certificate-guide { display: grid; gap: 7px; margin: 0; padding-left: 21px; font-size: 12px; }
        .listener-installer-button { text-align: center; }
        .listener-settings-guide { display: grid; gap: 4px; padding: 10px; border: 1px solid rgba(134, 115, 100, .32); border-radius: 8px; background: rgba(134, 115, 100, .09); color: var(--green-dark); font-size: 12px; }
        .listener-settings-guide strong { color: var(--green-dark); }
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
            .print-bridge-verification-heading { align-items: flex-start; flex-direction: column; }
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
            const verification = document.querySelector('[data-print-bridge-verification]');
            const checkButton = document.querySelector('[data-print-bridge-check]');
            const checkStatus = document.querySelector('[data-print-bridge-check-status]');
            const summary = document.querySelector('[data-print-bridge-summary]');
            const overallBadge = document.querySelector('[data-print-bridge-overall-badge]');

            const statusLabel = (bridgeStatus) => ({
                online: 'Połączono',
                offline: 'Offline',
                never: 'Nie połączono',
            })[bridgeStatus] || 'Nie połączono';

            const formattedDate = (value) => {
                if (!value) return 'brak daty';
                const date = new Date(value);
                if (Number.isNaN(date.getTime())) return value;

                return new Intl.DateTimeFormat('pl-PL', {
                    dateStyle: 'short',
                    timeStyle: 'medium',
                }).format(date);
            };

            const normalizedPrinterName = (value) => String(value || '').trim().toLocaleLowerCase('pl-PL');

            const updatePrintReadiness = (row, bridgeStatus, printerName, reportedNames, printerError = '') => {
                const readiness = row.querySelector('[data-station-print-readiness]');
                const active = (row.querySelector('input[name$="[name]"]')?.value || '').trim() !== '';
                if (!active) {
                    if (readiness) {
                        readiness.classList.remove('is-ready', 'is-warning', 'is-error');
                        readiness.classList.add('is-neutral');
                        readiness.textContent = 'Opcjonalne stanowisko — uzupełnij nazwę, gdy chcesz je uruchomić.';
                    }
                    return false;
                }
                const available = bridgeStatus === 'online'
                    && !printerError
                    && printerName !== ''
                    && reportedNames.some((reportedName) => normalizedPrinterName(reportedName) === normalizedPrinterName(printerName));
                const savedPrinter = row.querySelector('[data-station-printer]')?.dataset.savedPrinter || '';
                const ready = available && normalizedPrinterName(printerName) === normalizedPrinterName(savedPrinter);
                if (!readiness) return ready;

                readiness.classList.remove('is-ready', 'is-warning', 'is-error', 'is-neutral');
                if (ready) {
                    readiness.classList.add('is-ready');
                    readiness.textContent = `Gotowe do automatycznego wydruku na ${printerName}.`;
                } else if (bridgeStatus !== 'online') {
                    readiness.classList.add('is-error');
                    readiness.textContent = 'Automatyczny wydruk nie jest gotowy: brak aktywnego połączenia z aplikacją Windows.';
                } else if (printerError) {
                    readiness.classList.add('is-error');
                    readiness.textContent = 'Automatyczny wydruk nie jest gotowy: nie można potwierdzić drukarek z Windows.';
                } else if (!printerName) {
                    readiness.classList.add('is-warning');
                    readiness.textContent = 'Wybierz drukarkę — samo połączenie nie uruchamia automatycznego wydruku.';
                } else if (available) {
                    readiness.classList.add('is-warning');
                    readiness.textContent = `Drukarka ${printerName} jest dostępna. Zapisz ustawienia, aby uruchomić na niej automatyczny wydruk.`;
                } else {
                    readiness.classList.add('is-error');
                    readiness.textContent = `Drukarka „${printerName}” nie jest dostępna na aktualnej liście Windows. Wybierz inną.`;
                }

                return ready;
            };

            const updateStationStatus = (row, stationCode, station) => {
                const bridgeStatus = ['online', 'offline'].includes(station?.status) ? station.status : 'never';
                const badge = row.querySelector('[data-station-bridge-badge]');
                const meta = row.querySelector('[data-station-bridge-meta]');
                const hint = row.querySelector('[data-station-printer-hint]');
                const select = row.querySelector('[data-station-printer]');
                const active = (row.querySelector('input[name$="[name]"]')?.value || '').trim() !== '';
                const currentPrinter = select?.value || '';
                let selectedPrinter = currentPrinter;
                const printers = Array.isArray(station?.printers)
                    ? station.printers.filter((printer) => printer && typeof printer.name === 'string' && printer.name.trim() !== '')
                    : [];
                const uniquePrinters = printers.filter((printer, index, all) => all.findIndex(
                    (candidate) => normalizedPrinterName(candidate.name) === normalizedPrinterName(printer.name),
                ) === index);
                const printerError = typeof station?.printer_error === 'string' ? station.printer_error.trim() : '';
                row.dataset.bridgeStatus = bridgeStatus;
                row.dataset.printerError = printerError;

                if (badge) {
                    badge.classList.remove('is-online', 'is-offline', 'is-never');
                    badge.classList.add(`is-${bridgeStatus}`);
                    badge.innerHTML = '<i aria-hidden="true"></i>' + (active ? statusLabel(bridgeStatus) : 'Nieaktywne');
                }
                if (meta) {
                    if (!active) {
                        meta.textContent = 'Uzupełnij nazwę, aby dodać stanowisko.';
                    } else if (bridgeStatus === 'online') {
                        meta.textContent = `${station.worker || 'Most Windows'}${station.version ? ` · v${station.version}` : ''}`;
                    } else if (bridgeStatus === 'offline') {
                        meta.textContent = `Ostatni sygnał: ${formattedDate(station.last_seen_at)}`;
                    } else {
                        meta.textContent = `Uruchom aplikację Windows dla kodu ${stationCode || 'tego stanowiska'}.`;
                    }
                }

                if (select) {
                    select.replaceChildren();
                    select.add(new Option('Wybierz drukarkę z Windows', ''));
                    const reportedNames = uniquePrinters.map((printer) => printer.name.trim());
                    const canonicalPrinter = reportedNames.find(
                        (reportedName) => normalizedPrinterName(reportedName) === normalizedPrinterName(currentPrinter),
                    );
                    if (currentPrinter && !canonicalPrinter) {
                        select.add(new Option(`${currentPrinter} — zapisana, teraz niewidoczna`, currentPrinter));
                    }
                    uniquePrinters.forEach((printer) => {
                        const name = printer.name.trim();
                        const details = [
                            printer.default ? 'domyślna' : '',
                            typeof printer.driver === 'string' ? printer.driver.trim() : '',
                            typeof printer.port === 'string' ? printer.port.trim() : '',
                        ].filter(Boolean);
                        const option = new Option(`${name}${details.length ? ` — ${details.join(' · ')}` : ''}`, name);
                        option.dataset.reportedPrinter = '';
                        select.add(option);
                    });
                    select.value = canonicalPrinter || currentPrinter;
                    selectedPrinter = select.value;
                }

                if (hint) {
                    if (bridgeStatus === 'online' && printerError) {
                        hint.textContent = `Połączono, ale odczyt drukarek nie powiódł się: ${printerError}`;
                    } else if (bridgeStatus === 'online' && uniquePrinters.length > 0) {
                        hint.textContent = `Lista pochodzi z komputera ${station.worker || 'Windows'}.`;
                    } else if (bridgeStatus === 'online') {
                        hint.textContent = 'Połączono, ale Windows nie zgłosił żadnej drukarki.';
                    } else if (bridgeStatus === 'offline' && uniquePrinters.length > 0) {
                        hint.textContent = 'Pokazujemy ostatnią zgłoszoną listę. Sprawdź połączenie przed zapisem.';
                    } else {
                        hint.textContent = 'Lista pojawi się po połączeniu aplikacji Windows z tym kodem stanowiska.';
                    }
                }

                return {
                    online: bridgeStatus === 'online',
                    ready: updatePrintReadiness(
                        row,
                        bridgeStatus,
                        selectedPrinter,
                        uniquePrinters.map((printer) => printer.name.trim()),
                        printerError,
                    ),
                };
            };

            const refreshOverallStatus = () => {
                let activeStations = 0;
                let onlineStations = 0;
                let readyStations = 0;
                document.querySelectorAll('[data-station-row]').forEach((row) => {
                    const name = row.querySelector('input[name$="[name]"]')?.value.trim() || '';
                    if (!name) return;
                    activeStations += 1;
                    if (row.dataset.bridgeStatus === 'online') onlineStations += 1;
                    if (row.querySelector('[data-station-print-readiness]')?.classList.contains('is-ready')) readyStations += 1;
                });

                if (summary) summary.textContent = `${onlineStations} z ${activeStations} online · ${readyStations} gotowych do druku`;
                if (overallBadge) {
                    overallBadge.classList.remove('is-online', 'is-offline', 'is-never');
                    const allReady = activeStations > 0 && readyStations === activeStations;
                    overallBadge.classList.add(allReady ? 'is-online' : (onlineStations > 0 ? 'is-offline' : 'is-never'));
                    overallBadge.innerHTML = `<i aria-hidden="true"></i>${allReady ? 'Gotowe' : (onlineStations > 0 ? 'Wymaga konfiguracji' : 'Brak połączenia')}`;
                }

                return { activeStations, onlineStations, readyStations };
            };

            const refreshRowReadinessFromControls = (row) => {
                const select = row.querySelector('[data-station-printer]');
                if (!select) return;
                const reportedNames = Array.from(select.options)
                    .filter((option) => option.hasAttribute('data-reported-printer'))
                    .map((option) => option.value);
                updatePrintReadiness(
                    row,
                    row.dataset.bridgeStatus || 'never',
                    select.value,
                    reportedNames,
                    row.dataset.printerError || '',
                );
                refreshOverallStatus();
            };

            document.querySelectorAll('[data-station-row]').forEach((row) => {
                row.querySelector('[data-station-printer]')?.addEventListener('change', () => refreshRowReadinessFromControls(row));
                row.querySelector('input[name$="[name]"]')?.addEventListener('input', () => refreshRowReadinessFromControls(row));
            });

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

            checkButton?.addEventListener('click', async () => {
                if (!verification?.dataset.statusUrl) return;

                checkButton.disabled = true;
                checkButton.textContent = 'Sprawdzam…';
                if (checkStatus) checkStatus.textContent = 'Pobieranie aktualnego stanu mostów wydruku…';

                try {
                    const response = await fetch(verification.dataset.statusUrl, {
                        method: 'GET',
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    const payload = await response.json();
                    if (!payload?.success || !payload.stations || typeof payload.stations !== 'object') {
                        throw new Error('Nieprawidłowa odpowiedź ERP');
                    }

                    document.querySelectorAll('[data-station-row]').forEach((row) => {
                        const code = row.querySelector('input[name$="[code]"]')?.value.trim() || '';
                        const station = payload.stations[code] || {
                            station: code,
                            status: 'never',
                            connected: false,
                            printers: [],
                        };
                        updateStationStatus(row, code, station);
                    });

                    const aggregate = refreshOverallStatus();
                    if (checkStatus) checkStatus.textContent = `Sprawdzono: ${formattedDate(payload.checked_at)}. Gotowe do automatycznego druku: ${aggregate.readyStations}/${aggregate.activeStations}.`;
                } catch (error) {
                    if (checkStatus) checkStatus.textContent = 'Nie udało się sprawdzić połączenia. Odśwież stronę i spróbuj ponownie.';
                } finally {
                    checkButton.disabled = false;
                    checkButton.textContent = 'Sprawdź połączenie';
                }
            });
        })();
    </script>
@endpush
