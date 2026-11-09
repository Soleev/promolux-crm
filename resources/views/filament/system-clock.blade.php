@php($serverTime = now()->timezone('Asia/Tashkent'))
<div
    data-crm-clock
    x-data="{
        epoch: {{ $serverTime->getTimestampMs() }},
        started: 0,
        timer: null,
        date: '',
        time: '',
        init() {
            this.started = performance.now();
            this.tick();
            this.timer = setInterval(() => this.tick(), 1000);
        },
        tick() {
            const instant = new Date(this.epoch + performance.now() - this.started);
            const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
                timeZone: 'Asia/Tashkent', hourCycle: 'h23',
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            }).formatToParts(instant).map(part => [part.type, part.value]));
            this.date = `${parts.day}.${parts.month}.${parts.year}`;
            this.time = `${parts.hour}:${parts.minute}:${parts.second}`;
        },
        destroy() { clearInterval(this.timer); }
    }"
    class="shrink-0 text-right text-xs text-gray-600 tabular-nums dark:text-gray-300 sm:text-sm"
    title="Время Ташкента · UTC+5"
>
    <div class="flex flex-col sm:flex-row sm:gap-1" aria-label="Текущее время в Ташкенте">
        <span data-clock-date x-text="date">{{ $serverTime->format('d.m.Y') }}</span>
        <span data-clock-time x-text="time">{{ $serverTime->format('H:i:s') }}</span>
    </div>
    <a href="{{ \App\Filament\Pages\About::getUrl() }}" class="text-primary-600 hover:underline dark:text-primary-400" aria-label="О Promolux CRM, версия {{ config('app.version') }}">v{{ config('app.version') }}</a>
</div>
