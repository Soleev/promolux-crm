<x-filament-panels::page>
    <x-filament::section heading="Laravel Pulse" description="Мониторинг производительности и использования Promolux CRM.">
        @if ($pulseReady)
            <p class="text-sm text-gray-600 dark:text-gray-300">Панель использует твою текущую авторизацию владельца. Отдельный вход не нужен.</p>
            <a href="{{ route('pulse') }}" target="_blank" rel="noopener noreferrer"
               class="mt-4 inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-500">
                Открыть Laravel Pulse
            </a>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Подключение Laravel Pulse ещё не завершено. Для включения необходимы пакет Composer,
                опубликованные миграции и проверка на тестовой базе. Мониторинг пока не активен.
            </p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Версии программного обеспечения">
        <dl class="grid gap-4 sm:grid-cols-3">
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Promolux CRM</dt><dd class="font-semibold">v{{ $appVersion }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Laravel</dt><dd class="font-semibold">{{ $laravelVersion }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">PHP</dt><dd class="font-semibold">{{ $phpVersion }}</dd></div>
        </dl>
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            Доступ к диагностике имеет только активный владелец CRM. Пароли, ключи и параметры подключения
            здесь не отображаются.
        </p>
    </x-filament::section>
</x-filament-panels::page>
