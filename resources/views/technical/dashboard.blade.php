<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Promolux Technical Admin</title>
    <style>
        :root{color-scheme:dark;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;background:#0a1020;color:#eff5ff}
        .wrap{max-width:1180px;margin:auto;padding:35px 26px 70px}
        header{display:flex;gap:24px;justify-content:space-between;align-items:center;margin-bottom:75px}
        .identity{display:flex;gap:12px;align-items:center;font-weight:740;letter-spacing:.02em}
        .mark{background:#2e63f1;padding:11px 13px;border-radius:13px;box-shadow:0 6px 30px #12317966}
        .tag{font-size:11px;color:#a6b7d1;text-transform:uppercase;letter-spacing:.14em}
        .exit{border:1px solid #34425f;color:#e4eaf7;background:transparent;padding:9px 16px;border-radius:11px;cursor:pointer}
        .exit:hover{background:#172442}
        .eyebrow{color:#7ea9ff;font-size:12px;font-weight:800;letter-spacing:.15em;text-transform:uppercase}
        h1{font-size:clamp(31px,4vw,49px);letter-spacing:-.04em;line-height:1.13;margin:13px 0 15px}
        .intro{max-width:690px;color:#adbad2;font-size:16px;line-height:1.7;margin:0 0 38px}
        .cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
        .card{display:flex;flex-direction:column;min-height:278px;gap:18px;border-radius:20px;padding:26px;background:linear-gradient(155deg,#17233c,#111b30);border:1px solid #30425f;text-decoration:none;color:inherit}
        .card.live:hover{transform:translateY(-3px);border-color:#6593fb;box-shadow:0 12px 45px #07112788}
        .card.live{transition:transform .14s,border .14s,box-shadow .14s;cursor:pointer}
        .card.pending{opacity:.69}
        .top{display:flex;justify-content:space-between;align-items:center;gap:10px}
        .symbol{display:grid;place-items:center;width:50px;height:50px;background:#254b8c;border-radius:15px;font-weight:800;font-size:24px}
        .status{font-size:11px;font-weight:750;border-radius:30px;padding:7px 11px;color:#bfcbdd;background:#2b364a}
        .status.ready{color:#93efc9;background:#174939}
        h2{font-size:25px;letter-spacing:-.02em;margin:0}
        .desc{color:#a4b3cd;font-size:14px;line-height:1.6;margin:-10px 0 0;flex:1}
        .action{font-weight:700;font-size:13px;color:#91b5ff}
        .pending .action{color:#8d9cb5}
        .foot{border-top:1px solid #283956;padding-top:24px;margin-top:52px;color:#96a9c4;font-size:13px;line-height:1.6}
        .foot strong{color:#dce9ff}
        @media(max-width:780px){header{margin-bottom:48px}.cards{grid-template-columns:1fr}.card{min-height:235px}.wrap{padding:23px 18px 45px}}
    </style>
</head>
<body>
<main class="wrap">
    <header>
        <div class="identity"><span class="mark">P</span><div><div>Promolux</div><div class="tag">Technical Admin</div></div></div>
        <form action="{{ route('filament.tech.auth.logout') }}" method="post">
            @csrf
            <button class="exit" type="submit">Выйти</button>
        </form>
    </header>

    <div class="eyebrow">Центр технического контроля</div>
    <h1>Мониторинг и диагностика</h1>
    <p class="intro">Инструменты наблюдения за работой CRM. Здесь нет продаж, расчётов и управления бизнесом — только техническая диагностика приложения.</p>

    <section class="cards" aria-label="Инструменты мониторинга">
        @if($pulseReady)
            <a class="card live" href="{{ route('pulse') }}" target="_blank" rel="noopener noreferrer" aria-label="Открыть Laravel Pulse в новой вкладке">
        @else
            <div class="card pending">
        @endif
                <div class="top"><span class="symbol">P</span><span class="status {{ $pulseReady ? 'ready' : '' }}">{{ $pulseReady ? 'Подключён' : 'Ожидает установки' }}</span></div>
                <h2>Laravel Pulse</h2>
                <p class="desc">Скорость запросов, медленный SQL, ошибки, активность и фоновые задания. Срок хранения данных — 14 дней.</p>
                <span class="action">{{ $pulseReady ? 'Открыть в новой вкладке ↗' : 'Требуется установка пакета' }}</span>
        @if($pulseReady)
            </a>
        @else
            </div>
        @endif

        <div class="card pending">
            <div class="top"><span class="symbol">T</span><span class="status">Позже</span></div>
            <h2>Laravel Telescope</h2>
            <p class="desc">Подробная диагностика отдельных запросов, исключений, событий и SQL.</p>
            <span class="action">Пока не подключён</span>
        </div>
        <div class="card pending">
            <div class="top"><span class="symbol">N</span><span class="status">Позже</span></div>
            <h2>Laravel Nightwatch</h2>
            <p class="desc">Облачный мониторинг, трассировка операций и оповещения о сбоях.</p>
            <span class="action">Пока не подключён</span>
        </div>
    </section>

    <div class="foot"><strong>Ограниченный доступ.</strong> Доступно только активному техническому администратору и владельцу. Метрики Pulse появятся после завершения установки и накопления реальных событий.</div>
</main>
</body>
</html>
