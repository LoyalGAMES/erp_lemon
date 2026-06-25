@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@section('content')
    @php
        $settingReasons = old('return_reasons', $returnSettings['return_reasons'] ?? []);
        $settingConditions = old('conditions', $returnSettings['conditions'] ?? []);
        $settingDispositions = old('dispositions', $returnSettings['dispositions'] ?? []);
        $defaultCondition = old('default_condition', $returnSettings['default_condition'] ?? 'unchecked');
        $defaultDisposition = old('default_disposition', $returnSettings['default_disposition'] ?? 'restock');
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
        .settings-repeat { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .settings-option-list { display: grid; gap: 10px; }
        .settings-option-row { display: grid; grid-template-columns: minmax(160px, .7fr) minmax(220px, 1fr); gap: 12px; align-items: end; }
        .settings-option-row.disposition-row { grid-template-columns: minmax(140px, .55fr) minmax(200px, .85fr) minmax(260px, 1fr); }
        .settings-form .button { width: fit-content; }
        @media (max-width: 900px) {
            .settings-fields,
            .settings-repeat,
            .settings-option-row,
            .settings-option-row.disposition-row { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@push('scripts')
    <script>
        document.addEventListener('click', (event) => {
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
    </script>
@endpush
