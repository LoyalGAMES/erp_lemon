@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@section('content')
    @if (! empty($summaryCards))
        <section class="metrics" aria-label="Stan kolejek">
            @foreach ($summaryCards as $card)
                <article class="card metric">
                    <div class="metric-label">{{ $card['label'] }}</div>
                    <div @class(['metric-value', 'metric-value-blue' => $card['tone'] === 'blue', 'metric-value-red' => $card['tone'] === 'red'])>{{ $card['value'] }}</div>
                    <div class="metric-caption">{{ $card['caption'] }}</div>
                </article>
            @endforeach
        </section>
    @endif

    @if (($module ?? null) === 'sync')
        <article class="card" style="margin-bottom: 18px;">
            <div class="panel-header">
                <span>Pełna synchronizacja stanów</span>
                <span>ERP → WooCommerce</span>
            </div>
            <form method="POST" action="{{ route('sync.rebuild') }}" class="grid-form" style="padding: 16px;">
                @csrf
                <label>Kanał sprzedaży
                    <select name="sales_channel_id">
                        <option value="">Wszystkie kanały z eksportem stanów</option>
                        @foreach (($syncChannels ?? collect()) as $channel)
                            <option value="{{ $channel->id }}">{{ $channel->code }} - {{ $channel->name }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="form-actions">
                    <button class="button" type="submit">Przelicz i dodaj do kolejki</button>
                </div>
            </form>
        </article>
    @endif

    <article class="card">
        <div class="panel-header">
            <span>{{ $title }}</span>
            <span>{{ count($rows) }} rekordów</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            @foreach ($row as $index => $cell)
                                <td>
                                    @if (($html_last_column ?? false) && $loop->last)
                                        {!! $cell !!}
                                    @else
                                        {{ $cell }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) }}">Brak danych w module.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
@endsection
