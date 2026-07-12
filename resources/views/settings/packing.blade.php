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

        <article class="card settings-panel">
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
                    <strong>Podpisany instalator Windows</strong>
                    <span>Udostępniany jest wyłącznie zweryfikowany Setup.exe z podpisanego procesu wydawniczego.</span>
                    @if ($printListenerApp['available'])
                        <a class="button" href="{{ $printListenerApp['download_url'] }}">Pobierz {{ $printListenerApp['filename'] }}</a>
                        <small>Aktualizacja: {{ $printListenerApp['updated_at'] }} · {{ $printListenerApp['size_mb'] }}</small>
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
        .settings-packing-grid { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 14px; align-items: start; }
        .settings-panel-wide { min-width: 0; }
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
        .windows-listener-download .button { justify-self: start; text-decoration: none; }
        .windows-listener-download small { color: var(--muted); font-size: 12px; }
        @media (max-width: 1180px) {
            .settings-packing-grid { grid-template-columns: 1fr; }
            .packing-station-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .station-row-title { grid-column: 1 / -1; }
        }
        @media (max-width: 680px) {
            .packing-station-row { grid-template-columns: 1fr; }
            .print-bridge-token-row { grid-template-columns: 1fr 1fr; }
            .print-bridge-token-row input { grid-column: 1 / -1; }
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
