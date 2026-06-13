@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@php
    $statusLabel = [
        'queued' => 'W kolejce',
        'running' => 'Przetwarzanie',
        'missing_configuration' => 'Brak konfiguracji',
        'requires_configuration' => 'Wymaga konfiguracji',
        'submitted' => 'Wysłana',
        'accepted' => 'Przyjęta',
        'rejected' => 'Odrzucona',
        'failed' => 'Błąd',
    ];
    $statusTone = [
        'queued' => 'blue',
        'running' => 'blue',
        'missing_configuration' => 'orange',
        'requires_configuration' => 'orange',
        'submitted' => 'blue',
        'accepted' => '',
        'rejected' => 'red',
        'failed' => 'red',
    ];
    $retryableKsefStatuses = ['failed', 'missing_configuration', 'requires_configuration'];
    $refreshableKsefStatuses = ['running', 'submitted'];
@endphp

@push('styles')
    <style>
        .ksef-validation-cell { min-width: 300px; white-space: normal; }
        .ksef-validation-details { margin-top: 7px; }
        .ksef-validation-details summary { cursor: pointer; color: var(--green-dark); font-size: 12px; font-weight: 760; }
        .ksef-validation-list { margin: 7px 0 0; padding-left: 18px; color: var(--muted); font-size: 12px; line-height: 1.35; }
        .ksef-validation-list li + li { margin-top: 4px; }
        .ksef-validation-list .error { color: var(--red); }
        .ksef-validation-list .warning { color: var(--orange); }
    </style>
@endpush

@section('content')
    <section class="page-toolbar">
        <div class="toolbar-note">
            KSeF API {{ $configuration['api_version'] }} | środowisko: {{ $configuration['environment'] }} | {{ $configuration['base_url'] }}
        </div>
        <a class="button secondary" href="{{ route('integrations.index') }}#ksef">Konfiguracja w Integracjach</a>
    </section>

    @if (! $configuration['has_access_token'])
        <div class="alert">
            Brakuje tokena KSeF. Skonfiguruj KSeF w zakładce Integracje. System przygotuje XML FA(3), ale realna wysyłka zostanie zatrzymana ze statusem „Brak konfiguracji”.
        </div>
    @elseif (! $configuration['has_gateway_url'])
        <div class="alert">
            Token jest skonfigurowany, ale nie ma bramki/szyfrowanej sesji KSeF. Uzupełnij konfigurację w zakładce Integracje.
        </div>
    @endif

    <article class="card" style="margin-bottom: 18px;">
        <div class="panel-header">
            <span>Faktury do KSeF</span>
            <span>{{ $invoices->count() }} rekordów</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Numer</th>
                        <th>Status faktury</th>
                        <th>Walidacja</th>
                        <th>Brutto</th>
                        <th>WooCommerce</th>
                        <th>KSeF</th>
                        <th>Akcja</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        @php
                            $latest = $invoice->ksefSubmissions->sortByDesc('id')->first();
                            $status = $latest?->status;
                            $validationState = $validation->get($invoice->id, ['errors' => [], 'warnings' => [], 'is_blocking' => false]);
                        @endphp
                        <tr>
                            <td>{{ $invoice->number }}</td>
                            <td>{{ $invoice->status }}</td>
                            <td class="ksef-validation-cell">
                                @if ($validationState['is_blocking'])
                                    <span class="status red" title="{{ implode(' ', $validationState['errors']) }}">Do poprawy</span>
                                @elseif ($validationState['warnings'])
                                    <span class="status orange" title="{{ implode(' ', $validationState['warnings']) }}">Ostrzeżenia</span>
                                @else
                                    <span class="status">OK</span>
                                @endif

                                @if ($validationState['errors'] || $validationState['warnings'])
                                    <details class="ksef-validation-details">
                                        <summary>Komunikaty ({{ count($validationState['errors']) + count($validationState['warnings']) }})</summary>
                                        <ul class="ksef-validation-list">
                                            @foreach ($validationState['errors'] as $message)
                                                <li class="error">{{ $message }}</li>
                                            @endforeach
                                            @foreach ($validationState['warnings'] as $message)
                                                <li class="warning">{{ $message }}</li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </td>
                            <td>{{ number_format((float) $invoice->gross_total, 2, ',', ' ') }} {{ $invoice->currency }}</td>
                            <td>{{ data_get($invoice->metadata, 'woocommerce_upload.status') === 'success' ? 'Wysłana' : '-' }}</td>
                            <td>
                                @if ($status)
                                    <span @class(['status', $statusTone[$status] ?? 'blue'])>{{ $statusLabel[$status] ?? $status }}</span>
                                @else
                                    <span class="muted">Nieprzygotowana</span>
                                @endif
                            </td>
                            <td>
                                <div class="inline-actions">
                                    @if ($status !== 'accepted')
                                        @if ($validationState['is_blocking'])
                                            <a class="button secondary" href="{{ route('invoices.edit', $invoice) }}">Popraw fakturę</a>
                                            <form method="POST" action="{{ route('invoices.regenerate', $invoice) }}">
                                                @csrf
                                                <button class="button secondary" type="submit">Regeneruj PDF/XML</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('ksef.invoices.submit', $invoice) }}">
                                                @csrf
                                                <button class="button" type="submit">Wyślij do KSeF</button>
                                            </form>
                                        @endif
                                    @endif
                                    @if ($latest?->xml_payload)
                                        <a class="button secondary" href="{{ route('ksef.submissions.xml', $latest) }}">XML</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">Brak faktur. Najpierw wystaw fakturę z zamówienia.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>

    <article class="card">
        <div class="panel-header">
            <span>Historia zgłoszeń KSeF</span>
            <span>{{ $submissions->count() }} rekordów</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Faktura</th>
                        <th>Środowisko</th>
                        <th>API</th>
                        <th>Status</th>
                        <th>Numer ref.</th>
                        <th>Nr KSeF</th>
                        <th>Ponowień</th>
                        <th>Błąd</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($submissions as $submission)
                        <tr>
                            <td>{{ $submission->invoice?->number ?? $submission->invoice_id }}</td>
                            <td>{{ $submission->environment }}</td>
                            <td>{{ $submission->api_version ?? '-' }}</td>
                            <td><span @class(['status', $statusTone[$submission->status] ?? 'blue'])>{{ $statusLabel[$submission->status] ?? $submission->status }}</span></td>
                            <td>{{ $submission->reference_number ?? '-' }}</td>
                            <td>{{ $submission->ksef_number ?? '-' }}</td>
                            <td>{{ (int) data_get($submission->request_metadata, 'retry_count', 0) }}</td>
                            <td>{{ $submission->last_error ? str($submission->last_error)->limit(120) : '-' }}</td>
                            <td>
                                <div class="inline-actions">
                                    @if ($submission->xml_payload)
                                        <a class="button secondary" href="{{ route('ksef.submissions.xml', $submission) }}">XML</a>
                                    @endif
                                    @if (in_array($submission->status, $retryableKsefStatuses, true))
                                        <form method="POST" action="{{ route('ksef.submissions.retry', $submission) }}">
                                            @csrf
                                            <button class="button" type="submit">Ponów</button>
                                        </form>
                                    @endif
                                    @if (in_array($submission->status, $refreshableKsefStatuses, true) && $submission->reference_number)
                                        <form method="POST" action="{{ route('ksef.submissions.refresh', $submission) }}">
                                            @csrf
                                            <button class="button secondary" type="submit">Sprawdź status</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">Brak zgłoszeń. Użyj akcji „Wyślij do KSeF” przy fakturze.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
@endsection
