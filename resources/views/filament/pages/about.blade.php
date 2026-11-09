<x-filament-panels::page>
    @php
        $version = config('app.version');
        $releases = config('releases', []);
        $current = $releases[$version] ?? null;
    @endphp
    <x-filament::section heading="Promolux CRM">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Версия программы</dt><dd class="text-lg font-semibold">v{{ $version }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Дата последнего обновления версии</dt><dd class="text-lg font-semibold">{{ $current ? \Carbon\CarbonImmutable::parse($current['date'])->format('d.m.Y') : 'Не указана' }}</dd></div>
        </dl>
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">CRM, заказы, задачи и учёт расчётов Promolux. Время приложения — Ташкент (UTC+5).</p>
    </x-filament::section>
    <x-filament::section heading="История изменений">
        <div class="space-y-6">
            @foreach($releases as $releaseVersion => $release)
                @if(version_compare($releaseVersion, $version, '<='))
                    <article>
                        <h2 class="font-semibold">v{{ $releaseVersion }} · {{ \Carbon\CarbonImmutable::parse($release['date'])->format('d.m.Y') }}</h2>
                        <ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-gray-600 dark:text-gray-300">
                            @foreach($release['changes'] as $change)<li>{{ $change }}</li>@endforeach
                        </ul>
                    </article>
                @endif
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
