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
                'listener_url' => '',
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
                            $rowListener = old("stations.{$index}.listener_url", $station['listener_url']);
                            $rowSegment = old("stations.{$index}.segment", $station['segment']);
                        @endphp
                        <section class="packing-station-row" data-station-row>
                            <div class="station-row-title">
                                <strong>{{ $hasStation ? $station['name'] : 'Nowe stanowisko' }}</strong>
                                <span>{{ $hasStation ? ($segmentLabels[$station['segment']] ?? $station['segment']) : 'Opcjonalny wiersz' }}</span>
                            </div>

                            <label>Kod
                                <input name="stations[{{ $index }}][code]" value="{{ $rowCode }}" maxlength="40" placeholder="station-{{ $index + 1 }}">
                            </label>
                            <label>Nazwa
                                <input name="stations[{{ $index }}][name]" value="{{ $rowName }}" maxlength="80" placeholder="np. Stanowisko odzież">
                            </label>
                            <label>Aplikacja Windows
                                <input name="stations[{{ $index }}][listener_url]" value="{{ $rowListener }}" maxlength="180" placeholder="http://192.168.1.25:17777" data-listener-url>
                            </label>
                            <label>Drukarka etykiet
                                <span class="printer-field" data-printer-picker>
                                    <input name="stations[{{ $index }}][printer_name]" value="{{ $rowPrinter }}" maxlength="120" placeholder="np. Zebra ZD421" data-printer-name>
                                    <select data-printer-select hidden>
                                        <option value="">Wybierz drukarkę</option>
                                    </select>
                                    <button class="button secondary" type="button" data-load-printers>Pobierz</button>
                                    <span class="printer-status" data-printer-status></span>
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

        <article class="card settings-panel">
            <div class="panel-header">
                <span>Most wydruku Windows</span>
                <span>Zebra</span>
            </div>
            <div class="packing-help">
                <p>Adres aplikacji Windows jest zapisywany per stanowisko. Po wpisaniu adresu użyj przycisku pobierania, żeby wybrać drukarkę z listy zwróconej przez aplikację nasłuchującą.</p>
                <p>Etykiety wygenerowane podczas pakowania trafią do kolejki wydruku wybranego stanowiska i drukarki.</p>
                <div class="windows-listener-download">
                    <strong>Aplikacja na Windows</strong>
                    <span>Pobierz aktualną wersję mostu wydruku z tego serwera ERP.</span>
                    @if ($printListenerApp['available'])
                        <a class="button" href="{{ $printListenerApp['download_url'] }}">Pobierz {{ $printListenerApp['filename'] }}</a>
                        <small>Aktualizacja: {{ $printListenerApp['updated_at'] }} · {{ $printListenerApp['size_mb'] }}</small>
                    @else
                        <span class="alert error">Brak pliku aplikacji na serwerze ERP.</span>
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
            grid-template-columns: minmax(150px, .85fr) minmax(110px, .55fr) minmax(170px, .9fr) minmax(190px, 1fr) minmax(180px, 1fr) minmax(130px, .65fr);
            gap: 10px;
            align-items: end;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fffdfb;
        }
        .station-row-title { align-self: center; display: grid; gap: 3px; }
        .station-row-title span { color: var(--muted); font-size: 12px; font-weight: 720; }
        .printer-field { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 6px; align-items: end; }
        .printer-field input, .printer-field select { grid-column: 1 / -1; }
        .printer-field button { min-height: 38px; align-self: stretch; }
        .printer-status { grid-column: 1 / -1; color: var(--muted); font-size: 12px; min-height: 18px; }
        .keywords-field { display: grid; gap: 6px; }
        .packing-help { padding: 16px; display: grid; gap: 10px; color: var(--muted); line-height: 1.45; }
        .packing-help p { margin: 0; }
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
        }
    </style>
@endpush

@push('scripts')
    <script>
        (() => {
            const endpoint = @json(route('settings.packing.listener.printers'));
            const token = @json(csrf_token());

            document.querySelectorAll('[data-load-printers]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const row = button.closest('[data-station-row]');
                    const listenerInput = row?.querySelector('[data-listener-url]');
                    const printerInput = row?.querySelector('[data-printer-name]');
                    const printerSelect = row?.querySelector('[data-printer-select]');
                    const status = row?.querySelector('[data-printer-status]');
                    const listenerUrl = String(listenerInput?.value || '').trim();

                    if (!listenerUrl) {
                        if (status) status.textContent = 'Najpierw wpisz adres aplikacji Windows.';
                        listenerInput?.focus();
                        return;
                    }

                    button.disabled = true;
                    if (status) status.textContent = 'Pobieranie listy drukarek...';

                    try {
                        const response = await fetch(endpoint, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': token,
                            },
                            body: JSON.stringify({ listener_url: listenerUrl }),
                        });
                        const payload = await response.json();

                        if (!response.ok || !payload.success) {
                            throw new Error(payload.message || 'Nie udało się pobrać drukarek.');
                        }

                        const printers = Array.isArray(payload.printers) ? payload.printers : [];
                        if (printerSelect) {
                            printerSelect.innerHTML = '<option value="">Wybierz drukarkę</option>';

                            printers.forEach((printer) => {
                                const option = document.createElement('option');
                                option.value = printer.name;
                                option.textContent = `${printer.default ? 'Domyślna · ' : ''}${printer.name}${printer.driver ? ' · ' + printer.driver : ''}`;
                                printerSelect.append(option);
                            });

                            printerSelect.hidden = printers.length === 0;
                            printerSelect.onchange = () => {
                                if (printerInput) printerInput.value = printerSelect.value;
                            };
                        }

                        if (printers.length === 1 && printerInput) {
                            printerInput.value = printers[0].name;
                        }

                        if (status) status.textContent = printers.length > 0 ? `Znaleziono drukarki: ${printers.length}.` : 'Aplikacja Windows nie zwróciła drukarek.';
                    } catch (error) {
                        if (status) status.textContent = error.message || 'Nie udało się pobrać drukarek.';
                    } finally {
                        button.disabled = false;
                    }
                });
            });
        })();
    </script>
@endpush
