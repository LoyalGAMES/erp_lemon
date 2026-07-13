@extends('customer-account-claims.layout')

@section('title', 'Nie udało się przypisać zamówienia')

@section('content')
    <div class="card-header">
        <h1>Nie możemy użyć tego linku</h1>
    </div>
    <div class="card-body">
        <p>{{ $message }}</p>
        @if ($storeUrl)
            <a class="secondary-link" href="{{ $storeUrl }}">Wróć do sklepu</a>
        @endif
    </div>
@endsection
