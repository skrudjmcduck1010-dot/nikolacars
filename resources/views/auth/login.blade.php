<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">
    <title>Вход</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: radial-gradient(circle at top, #edf7f6, #dbe4e6 58%, #b9c4c8); font-family: "Segoe UI", Tahoma, sans-serif; }
        .card { width: min(420px, calc(100vw - 32px)); background: rgba(255,255,255,.94); border-radius: 22px; padding: 28px; box-shadow: 0 18px 45px rgba(28, 39, 36, .16); }
        .logo-wrap { display: inline-flex; align-items: center; justify-content: center; max-width: 100%; margin-bottom: 20px; padding: 14px 18px; border-radius: 16px; background: linear-gradient(135deg, #103c39, #0f766e); box-shadow: inset 0 1px 0 rgba(255,255,255,.14), 0 12px 26px rgba(15, 118, 110, .20); }
        .logo { display: block; width: 190px; max-width: 100%; height: auto; }
        h1 { margin: 0 0 8px; }
        p { color: #5f676c; margin-bottom: 20px; }
        label { display:block; font-weight:600; margin: 12px 0 6px; }
        input { width:100%; padding:12px; border-radius:12px; border:1px solid #d0d4d5; }
        button { width:100%; margin-top:18px; padding:12px; border:none; border-radius:999px; background:#0f766e; color:#fff; font-weight:700; cursor:pointer; }
        .error { color:#9f2d2d; margin-bottom:12px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo-wrap">
            <img class="logo" src="{{ asset('nikolacars-logo.png') }}" alt="НиколаКарз">
        </div>
        <h1>Вход в НиколаКарз</h1>
        <p>Административный доступ к складу запчастей.</p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <label for="email">E-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required>

            <label for="password">Пароль</label>
            <input id="password" type="password" name="password" required>

            <label style="display:flex;align-items:center;gap:8px;margin-top:14px;font-weight:500;">
                <input type="checkbox" name="remember" value="1" style="width:auto;">
                Запомнить меня
            </label>

            <button type="submit">Войти</button>
        </form>
    </div>
</body>
</html>
