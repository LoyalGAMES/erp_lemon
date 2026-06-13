@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@section('content')
    <div class="page-toolbar">
        <a class="button secondary" href="{{ route('settings.index') }}">Wróć do ustawień</a>
    </div>

    <section class="settings-detail-grid">
        <article class="card settings-panel">
            <div class="panel-header">
                <span>Numeracja dokumentów</span>
                <span>{{ $documentNumberExample }}</span>
            </div>
            <form method="POST" action="{{ route('settings.documents.update') }}" class="form-grid settings-form">
                @csrf
                @method('PUT')
                <label>Format
                    <input name="pattern" value="{{ old('pattern', $documentNumbering['pattern']) }}" required>
                </label>
                <label>Długość numeru
                    <input name="padding" value="{{ old('padding', $documentNumbering['padding']) }}" type="number" min="3" max="9" required>
                </label>
                <p class="muted">Tokeny: {TYPE}, {YYYY}, {YY}, {MM}, {SEQ}. Przykład: {{ $documentNumberExample }}</p>
                <button class="button" type="submit">Zapisz numerację</button>
            </form>
        </article>

        <article class="card settings-panel">
            <div class="panel-header">
                <span>Lokalizacje magazynowe</span>
                <span>{{ count($warehouseLocations) }} pozycji</span>
            </div>
            <form method="POST" action="{{ route('settings.locations.update') }}" class="form-grid settings-form">
                @csrf
                @method('PUT')
                <label>Lokalizacje
                    <textarea name="locations_text" rows="10" placeholder="np. A-01-01&#10;A-01-02&#10;Regał B">{{ old('locations_text', implode("\n", $warehouseLocations)) }}</textarea>
                </label>
                <p class="muted">Jedna lokalizacja w linii. Możesz też wkleić wartości oddzielone przecinkami lub średnikami.</p>
                <button class="button" type="submit">Zapisz lokalizacje</button>
            </form>
        </article>

        <article class="card settings-panel settings-panel-wide">
            <div class="panel-header">
                <span>Automatyczny obieg dokumentów</span>
                <span>Zwroty, zamówienia i pakowanie</span>
            </div>
            <form method="POST" action="{{ route('settings.document_automation.update') }}" class="settings-form automation-form">
                @csrf
                @method('PUT')

                @foreach ($documentAutomationRules as $rule)
                    <div class="automation-rule">
                        <div>
                            <h3>{{ $rule['label'] }}</h3>
                            <p>{{ $rule['description'] }}</p>
                        </div>
                        <div class="automation-actions">
                            @foreach ($rule['actions'] as $action)
                                <label class="toggle-row">
                                    <input
                                        type="checkbox"
                                        name="automation[{{ $rule['event'] }}][{{ $action['action'] }}]"
                                        value="1"
                                        @checked($action['enabled'])
                                    >
                                    <span>
                                        <strong>{{ $action['label'] }}</strong>
                                        <small>{{ $action['description'] }}</small>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <button class="button" type="submit">Zapisz obieg dokumentów</button>
            </form>
        </article>
    </section>
@endsection

@push('styles')
    <style>
        .settings-detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .settings-panel { align-self: start; }
        .settings-panel-wide { grid-column: 1 / -1; }
        .settings-form { padding: 16px; }
        .settings-form .button { width: fit-content; }
        .automation-form { display: grid; gap: 14px; }
        .automation-rule {
            display: grid;
            grid-template-columns: minmax(220px, 320px) minmax(0, 1fr);
            gap: 14px;
            padding: 14px;
            border: 1px solid rgba(134, 115, 100, 0.18);
            border-radius: 8px;
            background: rgba(134, 115, 100, 0.035);
        }
        .automation-rule h3 { margin: 0 0 6px; font-size: 18px; }
        .automation-rule p { margin: 0; color: var(--muted); line-height: 1.4; }
        .automation-actions { display: grid; gap: 10px; }
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
        @media (max-width: 900px) {
            .settings-detail-grid { grid-template-columns: 1fr; }
            .automation-rule { grid-template-columns: 1fr; }
        }
    </style>
@endpush
