<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="referrer" content="same-origin">
    <title>@yield('title') | Sempre</title>
    <style>
        :root {
            --bg: #f5f2f0;
            --surface: #fffefd;
            --border: #d8cec6;
            --text: #12110f;
            --muted: #6d645d;
            --brand: #867364;
            --brand-dark: #5f5045;
            --brand-soft: rgba(134, 115, 100, .11);
            --red: #b52525;
            --shadow: 0 20px 58px rgba(95, 80, 69, .14);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            padding: 28px 16px;
            display: grid;
            place-items: center;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .shell { width: min(100%, 560px); display: grid; gap: 18px; }
        .brand { display: flex; justify-content: center; }
        .brand img { display: block; width: 156px; height: auto; }
        .card {
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            box-shadow: var(--shadow);
        }
        .card-header { padding: 28px 28px 22px; border-bottom: 1px solid var(--border); }
        .card-body { padding: 24px 28px 28px; }
        h1 { margin: 0; font-size: clamp(24px, 5vw, 31px); line-height: 1.15; }
        h2 { margin: 0 0 8px; font-size: 16px; }
        p { margin: 0; line-height: 1.6; }
        .subtitle { margin-top: 10px; color: var(--muted); font-size: 15px; }
        .order-box {
            display: grid;
            gap: 4px;
            margin-bottom: 22px;
            padding: 15px 16px;
            border: 1px solid rgba(134, 115, 100, .25);
            border-radius: 8px;
            background: var(--brand-soft);
        }
        .order-box span { color: var(--muted); font-size: 13px; }
        .order-box strong { font-size: 17px; }
        form { display: grid; gap: 16px; }
        label { display: grid; gap: 7px; color: var(--muted); font-size: 13px; font-weight: 700; }
        input {
            width: 100%;
            min-height: 44px;
            border: 1px solid var(--border);
            border-radius: 7px;
            padding: 10px 12px;
            background: #fff;
            color: var(--text);
            font: inherit;
        }
        input:focus { outline: 3px solid rgba(134, 115, 100, .16); border-color: var(--brand); }
        .hint { color: var(--muted); font-size: 12px; font-weight: 500; line-height: 1.45; }
        .field-error { color: var(--red); font-size: 12px; font-weight: 700; }
        .alert {
            margin-bottom: 18px;
            padding: 12px 14px;
            border: 1px solid #edbcbc;
            border-radius: 8px;
            background: #fff1f1;
            color: var(--red);
            font-size: 13px;
            line-height: 1.5;
        }
        .button {
            display: inline-flex;
            min-height: 44px;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 7px;
            padding: 10px 16px;
            background: var(--brand);
            color: #fff;
            font: inherit;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
        }
        .button:hover { background: var(--brand-dark); }
        .secondary-link {
            display: inline-block;
            margin-top: 16px;
            color: var(--brand-dark);
            font-size: 14px;
            font-weight: 700;
        }
        .success-mark {
            width: 52px;
            height: 52px;
            display: grid;
            place-items: center;
            margin-bottom: 18px;
            border-radius: 50%;
            background: var(--brand-soft);
            color: var(--brand-dark);
            font-size: 28px;
            font-weight: 900;
        }
        .footer { color: var(--muted); text-align: center; font-size: 12px; }
        @media (max-width: 520px) {
            body { padding: 18px 12px; }
            .card-header, .card-body { padding-left: 20px; padding-right: 20px; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <div class="brand">
            <img src="{{ asset('assets/sempre-logotyp.svg') }}" alt="SEMPRE">
        </div>
        <section class="card">
            @yield('content')
        </section>
        <div class="footer">Bezpieczne przypisanie zamówienia do konta klienta.</div>
    </main>
</body>
</html>
