@extends('layouts.app', [
    'title' => 'Audyt operacji',
    'subtitle' => 'Chronologiczny ślad operacji magazynowych, księgowań i błędów. Dane służą do kontroli zmian bez cichej edycji historii.',
    'module' => 'audit',
])

@section('content')
    <article class="card">
        <div class="panel-header">
            <span>Ostatnie zdarzenia</span>
            <span>{{ $logs->count() }} rekordów</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Akcja</th>
                        <th>Obiekt</th>
                        <th>Przed</th>
                        <th>Po</th>
                        <th>Metadane</th>
                        <th>Źródło</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td>{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td><span class="status blue">{{ $log->action }}</span></td>
                            <td>
                                {{ class_basename((string) $log->auditable_type) ?: '-' }}<br>
                                <span class="muted">ID: {{ $log->auditable_id ?? '-' }}</span>
                            </td>
                            <td class="muted">{{ json_encode($log->before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-' }}</td>
                            <td class="muted">{{ json_encode($log->after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-' }}</td>
                            <td class="muted">{{ json_encode($log->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-' }}</td>
                            <td class="muted">{{ $log->ip_address ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">Brak zdarzeń audytowych.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
@endsection
