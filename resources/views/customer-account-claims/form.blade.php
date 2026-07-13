@extends('customer-account-claims.layout')

@section('title', 'Załóż konto')

@section('content')
    <div class="card-header">
        <h1>Załóż konto i zachowaj zamówienie</h1>
        <p class="subtitle">
            Po utworzeniu konta zamówienie automatycznie pojawi się w jego historii. Zyskasz też dostęp do programu lojalnościowego.
        </p>
    </div>
    <div class="card-body">
        <div class="order-box">
            <span>Zamówienie</span>
            <strong>{{ $orderNumber }}</strong>
            <span>Adres konta: {{ $maskedEmail }}</span>
        </div>

        @if (session('claim_error'))
            <div class="alert">{{ session('claim_error') }}</div>
        @endif

        <form method="POST" action="{{ request()->fullUrl() }}">
            @csrf
            <label>
                Hasło do nowego konta
                <input
                    name="password"
                    type="password"
                    minlength="10"
                    maxlength="255"
                    autocomplete="new-password"
                    autofocus
                >
                <span class="hint">
                    Co najmniej 10 znaków. Jeśli konto z tym adresem e-mail już istnieje, pozostaw pola puste — nie zmienimy jego hasła.
                </span>
                @error('password')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </label>

            <label>
                Powtórz hasło
                <input
                    name="password_confirmation"
                    type="password"
                    minlength="10"
                    maxlength="255"
                    autocomplete="new-password"
                >
            </label>

            <button class="button" type="submit">Załóż konto i przypisz zamówienie</button>
        </form>
    </div>
@endsection
