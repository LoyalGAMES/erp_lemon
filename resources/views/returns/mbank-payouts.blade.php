@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@php
    $money = fn ($value): string => number_format((float) $value, 2, ',', ' ') . ' PLN';
@endphp

@section('content')
    <div class="page-toolbar">
        <a class="button secondary" href="{{ route('returns.index') }}">Wróć do zwrotów</a>
        @if ($returns->isNotEmpty())
            <a class="button" href="{{ route('returns.payouts.mbank.download') }}">Pobierz plik mBank</a>
        @endif
    </div>

    <article class="card">
        <div class="panel-header">
            <span>Zwroty pobraniowe do wypłaty</span>
            <span>{{ $returns->count() }} pozycji · {{ $money($totalAmount) }}</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Zwrot</th>
                        <th>Zamówienie</th>
                        <th>Odbiorca</th>
                        <th>Rachunek</th>
                        <th>Kwota</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($returns as $returnCase)
                        <tr>
                            <td>{{ $returnCase->number }}</td>
                            <td>{{ $returnCase->externalOrder?->external_number ?? '-' }}</td>
                            <td>{{ $mbankBasket->recipientName($returnCase) }}</td>
                            <td>{{ $mbankBasket->recipientAccount($returnCase) ?? '-' }}</td>
                            <td>{{ $money($mbankBasket->amount($returnCase)) }}</td>
                            <td><span class="status">{{ $returnCase->status }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">Brak zatwierdzonych zwrotów pobraniowych z poprawnym numerem rachunku klienta.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
@endsection
