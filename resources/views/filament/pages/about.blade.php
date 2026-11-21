<x-filament-panels::page>
    @php
        $version = config('app.version');
        $releases = array_filter(config('releases', []), fn ($entry) => version_compare($entry, $version, '<='), ARRAY_FILTER_USE_KEY);
        uksort($releases, fn ($a, $b) => version_compare($b, $a));
        $current = $releases[$version] ?? null;
    @endphp
    <x-filament::section heading="Promolux CRM">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Версия программы</dt><dd class="text-lg font-semibold">v{{ $version }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Дата последнего обновления версии</dt><dd class="text-lg font-semibold">{{ ($current['date'] ?? null) ? \Carbon\CarbonImmutable::parse($current['date'])->format('d.m.Y') : 'Не указана' }}</dd></div>
        </dl>
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">CRM, заказы, задачи и учёт расчётов Promolux. Время приложения — Ташкент (UTC+5).</p>
    </x-filament::section>
    <x-filament::section heading="История изменений">
        <ol class="plx-release-timeline" aria-label="История версий">
            @foreach($releases as $releaseVersion => $release)
                @if(version_compare($releaseVersion, $version, '<='))
                    <li class="plx-release">
                        <div class="plx-release-meta">
                            <h2 class="plx-release-version">v{{ $releaseVersion }}</h2>
                            @if($release['date'])<time datetime="{{ $release['date'] }}">{{ \Carbon\CarbonImmutable::parse($release['date'])->format('d.m.Y') }}</time>@endif
                        </div>
                        <h3 class="plx-release-title">{{ $release['title'] ?? 'Обновление Promolux CRM' }}</h3>
                        <ul class="plx-release-changes">
                            @foreach($release['changes'] as $change)<li>{{ $change }}</li>@endforeach
                        </ul>
                    </li>
                @endif
            @endforeach
            <li class="plx-release plx-release-origin">
                <div class="plx-release-meta"><h2 class="plx-release-version">Подготовительный этап</h2><span>04–06.11.2026</span></div>
                <p>Создание первой рабочей версии Promolux CRM.</p>
                <ul class="plx-release-changes">
                    <li>Создана архитектура Laravel 13 + Filament 5 + Livewire 4; настроена локальная разработка Laravel Herd.</li>
                    <li>Разработаны модули клиентов, сделок, заказов, задач, оплат, сотрудников и журнала действий.</li>
                    <li>Внедрены роли владельца, РОП, менеджера, дизайнера и кассира.</li>
                    <li>Реализованы учёт оплат клиентов, передачи наличных, начислений, выплат, расходов и сторнирований.</li>
                    <li>Добавлена сверка финансовых операций и остатков.</li>
                    <li>Созданы обзор бизнеса, финансовые отчёты, реестры, CSV-экспорт и аудит действий.</li>
                </ul>
            </li>
        </ol>
    </x-filament::section>
</x-filament-panels::page>
