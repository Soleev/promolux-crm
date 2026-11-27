<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SFP — Promolux</title>
@vite(['resources/css/sfp.css', 'resources/js/sfp.js'])
<style>
@foreach(\Filament\Support\Facades\FilamentColor::getColors() as $name => $shades)
[data-status-color="{{ $name }}"] { --status-bg: {{ $shades[50] }}; --status-text: {{ $shades[700] }}; --status-dark-bg: {{ $shades[950] }}; --status-dark-text: {{ $shades[200] }}; }
@endforeach
</style>
</head><body>
<header class="top">@if(auth()->user()?->role === 'sfp_screen')<a href="{{ route('sfp.screen') }}">Promolux · SFP</a>@else<a href="{{ url('/crm') }}">Promolux</a>@endif<div><time id="clock" data-epoch="{{ now()->getTimestampMs() }}">{{ now('Asia/Tashkent')->format('d.m.Y H:i:s') }}</time><small>v{{ config('app.version') }}</small>@if(auth()->user()?->role === 'sfp_screen')<form method="POST" action="{{ route('sfp.logout') }}">@csrf<button class="button" type="submit">Выйти</button></form>@endif</div></header>
<main><div class="heading"><div><span class="eyebrow">SFP</span><h1>Информационный экран производства</h1></div><button id="fullscreen" type="button">На весь экран</button></div>
<p id="connection" role="status" aria-live="polite">Данные обновлены · {{ now('Asia/Tashkent')->format('H:i:s') }}</p>
@if(auth()->user()?->role === 'sfp_screen')<p><a href="{{ route('sfp.login') }}">Повторный вход SFP</a></p>@endif
<div id="sfp-content">@include('sfp.content')</div>
</main></body></html>
