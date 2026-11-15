<x-filament-panels::page>
    <div class="grid gap-3 sm:grid-cols-3" aria-label="Итоги по выбранным условиям">
        <x-filament::section>
            <p class="text-sm text-gray-600 dark:text-gray-400">Общая задолженность</p>
            @forelse($totals as $total)
                <p class="text-xl font-semibold tabular-nums">{{ $total->currency === 'UZS' ? \App\Services\Money::display((int) $total->amount) : \App\Services\Money::format((int) $total->amount).' '.$total->currency }}</p>
            @empty
                <p class="text-xl font-semibold">0 сум</p>
            @endforelse
        </x-filament::section>
        @foreach(['Заказов с долгом' => $orderCount, 'Клиентов с долгом' => $clientCount] as $label => $count)
            <x-filament::section>
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</p>
                <p class="text-xl font-semibold tabular-nums">{{ $count }}</p>
            </x-filament::section>
        @endforeach
    </div>
    {{ $this->table }}
</x-filament-panels::page>
