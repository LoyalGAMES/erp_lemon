@extends('customer-account-claims.layout')

@section('title', 'Zamówienie przypisane')

@section('content')
    <div class="card-header">
        <h1>Gotowe</h1>
        <p class="subtitle">Zamówienie jest już powiązane z Twoim kontem.</p>
    </div>
    <div class="card-body">
        <div class="success-mark" aria-hidden="true">✓</div>
        <h2>{{ $createdAccount ? 'Konto zostało utworzone' : 'Zamówienie zostało przypisane' }}</h2>
        <p>
            {{ $createdAccount
                ? 'Możesz zalogować się podanym adresem e-mail i hasłem ustawionym przed chwilą.'
                : 'Konto z tym adresem e-mail już istniało, dlatego nie zmieniliśmy jego hasła. Zaloguj się dotychczasowymi danymi.' }}
        </p>
        <a class="button" style="margin-top: 22px;" href="{{ $loginUrl }}">Przejdź do mojego konta</a>
    </div>
@endsection
