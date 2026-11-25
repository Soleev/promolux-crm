<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход SFP — Promolux</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f4f5fa;color:#202133;font:16px system-ui,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}main{width:min(100%,420px);background:white;border:1px solid #e2e3ec;border-radius:16px;padding:32px}h1{margin:8px 0 12px;font-size:26px}p{line-height:1.5;color:#55596c}label{display:block;font-weight:600;margin:18px 0 6px}input,button{width:100%;padding:12px;border-radius:8px;font:inherit}input{border:1px solid #a8aabc}button{margin-top:24px;border:0;background:#5b35e5;color:#fff;font-weight:600;cursor:pointer}a{color:#5130c7}.error{color:#a11b32}small{color:#55596c}
    </style>
</head>
<body>
<main>
    <small>Promolux · SFP</small>
    <h1>Вход на экран производства</h1>
    <p>Доступ только к производственной очереди.</p>
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('sfp.login.submit') }}">
        @csrf
        <label for="email">Электронная почта</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required>
        <label for="password">Пароль</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Войти</button>
    </form>
    <p><a href="{{ route('filament.admin.auth.login') }}">Вход для сотрудников CRM</a></p>
</main>
</body>
</html>
