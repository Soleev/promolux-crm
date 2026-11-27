<x-filament::section heading="Предварительное распределение">
    <p class="text-sm text-gray-500 dark:text-gray-400">Доли от фактически полученной оплаты. Начисления здесь не создаются.</p>
    @if($standard)
        <p class="text-sm text-gray-500 dark:text-gray-400">Показано распределение для заказа менеджера. Для личных заказов владельца и РОП, а также дизайнера без индивидуального соглашения внутренняя комиссия не предусмотрена.</p>
    @endif
    <dl class="mt-3 space-y-2">
        @forelse($shares as $label => $rate)
            <div class="flex flex-wrap justify-between gap-2"><dt>{{ $label }}</dt><dd class="font-semibold">{{ $rate }}</dd></div>
        @empty
            <p>Введите корректные проценты для предварительного распределения.</p>
        @endforelse
    </dl>
</x-filament::section>
