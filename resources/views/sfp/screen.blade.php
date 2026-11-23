<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SFP — Promolux</title>
@vite(['resources/css/sfp.css', 'resources/js/sfp.js'])
</head><body>
<header class="top"><a href="{{ url('/crm') }}">Promolux</a><div><time id="clock" data-epoch="{{ now()->getTimestampMs() }}">{{ now('Asia/Tashkent')->format('d.m.Y H:i:s') }}</time><small>v{{ config('app.version') }}</small></div></header>
<main><div class="heading"><div><span class="eyebrow">SFP</span><h1>Информационный экран производства</h1></div><button id="fullscreen" type="button">На весь экран</button></div>
<p id="connection" role="status" aria-live="polite">Данные обновлены · {{ now('Asia/Tashkent')->format('H:i:s') }}</p>
<div id="sfp-content">@include('sfp.content')</div>
</main></body></html>
