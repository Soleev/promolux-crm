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
            <article>
                <h2 class="font-semibold">Подготовительный этап · 04–06.11.2026</h2>
                <p>Создание первой рабочей версии Promolux CRM.</p>
                <ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-gray-600 dark:text-gray-300">
                    <li>Создана архитектура Laravel 13 + Filament 5 + Livewire 4; настроена локальная разработка Laravel Herd.</li>
                    <li>Разработаны модули клиентов, сделок, заказов, задач, оплат, сотрудников и журнала действий.</li>
                    <li>Внедрены роли владельца, РОП, менеджера, дизайнера и кассира.</li>
                    <li>Реализованы учёт оплат клиентов, передачи наличных, начислений, выплат, расходов и сторнирований.</li>
                    <li>Добавлена сверка финансовых операций и остатков.</li>
                    <li>Созданы обзор бизнеса, финансовые отчёты, реестры, CSV-экспорт и аудит действий.</li>
                </ul>
            </article>
        </div>
    </x-filament::section>
</x-filament-panels::page>
