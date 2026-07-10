<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logowanie | Sempre ERP</title>
    <style>
        :root {
            --bg: #f5f2f0;
            --surface: #fffefd;
            --border: #d8cec6;
            --text: #12110f;
            --muted: #6d645d;
            --brand: #867364;
            --brand-dark: #5f5045;
            --brand-soft: rgba(134, 115, 100, .14);
            --red: #d83434;
            --shadow: 0 18px 54px rgba(134, 115, 100, .14);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            display: grid;
            place-items: center;
            padding: 28px 16px;
        }
        .login-shell {
            width: min(100%, 440px);
            display: grid;
            gap: 16px;
        }
        .brand {
            display: flex;
            justify-content: center;
            margin-bottom: 8px;
        }
        .brand img {
            width: 156px;
            height: auto;
            display: block;
        }
        .login-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .login-header {
            padding: 24px 24px 18px;
            border-bottom: 1px solid var(--border);
        }
        h1 {
            margin: 0;
            font-size: 25px;
            line-height: 1.15;
            letter-spacing: 0;
        }
        .subtitle {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 14px;
        }
        form {
            display: grid;
            gap: 14px;
            padding: 22px 24px 24px;
        }
        label {
            display: grid;
            gap: 6px;
            color: var(--muted);
            font-weight: 680;
            font-size: 13px;
        }
        input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 7px;
            padding: 11px 12px;
            color: var(--text);
            background: #fff;
            font: inherit;
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text);
        }
        .checkbox-label input {
            width: auto;
        }
        .button {
            border: 0;
            border-radius: 7px;
            min-height: 42px;
            padding: 9px 13px;
            background: var(--brand);
            color: white;
            font-weight: 800;
            cursor: pointer;
            font: inherit;
        }
        .alert {
            border-radius: 8px;
            padding: 12px 14px;
            border: 1px solid var(--border);
            background: var(--surface);
            font-size: 13px;
        }
        .alert.ok {
            border-color: rgba(134, 115, 100, .32);
            background: rgba(134, 115, 100, .09);
            color: var(--brand-dark);
        }
        .alert.error {
            border-color: #f0c3c3;
            background: #fff0f0;
            color: var(--red);
        }
        .field-error {
            color: var(--red);
            font-size: 12px;
            font-weight: 650;
        }
        .setup-note {
            padding: 14px 24px 0;
            color: var(--muted);
            font-size: 13px;
        }
    </style>
</head>
<body>
    <main class="login-shell">
        <div class="brand">
            <img src="{{ asset('assets/sempre-logotyp.svg') }}" alt="SEMPRE">
        </div>

        @if (session('status'))
            <div class="alert ok">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="alert error">{{ session('error') }}</div>
        @endif

        <section class="login-card">
            <div class="login-header">
                <h1>{{ $hasUsers ? 'Logowanie do ERP' : 'Utwórz administratora' }}</h1>
                <p class="subtitle">
                    {{ $hasUsers ? 'Zaloguj się kontem użytkownika ERP.' : 'W bazie nie ma jeszcze użytkowników. Pierwsze konto zostanie administratorem.' }}
                </p>
            </div>

            @if ($hasUsers)
                <form method="POST" action="{{ route('login.attempt') }}">
                    @csrf
                    <label>Email / login
                        <input name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                        @error('email')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>
                    <label>Hasło
                        <input name="password" type="password" required autocomplete="current-password">
                        @error('password')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>
                    <label class="checkbox-label">
                        <input name="remember" type="checkbox" value="1" @checked(old('remember'))>
                        Zapamiętaj logowanie
                    </label>
                    <button class="button" type="submit">Zaloguj</button>
                </form>
            @else
                <div class="setup-note">
                    Po utworzeniu konta dostęp do ERP będzie oparty o sesję i role użytkowników.
                </div>
                <form method="POST" action="{{ route('login.setup') }}">
                    @csrf
                    <label>Imię lub nazwa użytkownika
                        <input name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
                        @error('name')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>
                    <label>Email / login
                        <input name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
                        @error('email')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>
                    <label>Hasło
                        <input name="password" type="password" required minlength="10" autocomplete="new-password">
                        @error('password')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </label>
                    <label>Powtórz hasło
                        <input name="password_confirmation" type="password" required minlength="10" autocomplete="new-password">
                    </label>
                    <button class="button" type="submit">Utwórz administratora</button>
                </form>
            @endif
        </section>
    </main>
</body>
</html>
