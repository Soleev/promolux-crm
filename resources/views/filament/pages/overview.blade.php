<x-filament-panels::page>
    <div class="rounded-2xl bg-indigo-950 p-6 text-white">
        <p class="text-sm text-indigo-200">PROMOLUX · УПРАВЛЕНИЕ БИЗНЕСОМ</p>
        <h2 class="mt-2 text-2xl font-semibold">Продажи и расчёты в одном месте</h2>
        <p class="mt-2 text-sm text-indigo-100">Оплаты клиентов поступают SFP. Начисления и фактические выплаты учитываются отдельно.</p>
    </div>
    <div class="grid gap-4 rounded-xl bg-white p-4 shadow-sm md:grid-cols-4 dark:bg-gray-900">
        <div class="md:col-span-3">{{ $this->filtersForm }}</div>
        <div class="flex items-end"><x-filament::button tag="a" :href="route('crm.export', ['from'=>$from,'until'=>$until,'manager'=>$manager])" icon="heroicon-o-arrow-down-tray">Скачать реестр CSV</x-filament::button></div>
    </div>
    @error('until')<p class="text-red-600">{{ $message }}</p>@enderror
    @include('filament.financial-summary')
    <div class="grid gap-4 md:grid-cols-3">
        @foreach(['orders'=>'Всего заказов','active_orders'=>'Активные заказы','overdue_tasks'=>'Просроченные задачи'] as $key=>$label)
        <x-filament::section><p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p><p class="mt-2 text-3xl font-semibold">{{ $this->report[$key] }}</p></x-filament::section>
        @endforeach
    </div>
    @if($this->report['unresolved'])
    <x-filament::section><p class="text-amber-600">Оплат без согласованного распределения: {{ $this->report['unresolved'] }}. Начисления по ним не созданы; требуется уточнение условий.</p></x-filament::section>
    @endif
    @if(!$this->report['orders'])
    <x-filament::section heading="Начните с первого клиента">
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">Система пока пуста. Добавьте клиента, создайте сделку или заказ. Финансовые показатели появятся после фактической оплаты.</p>
        <x-filament::button tag="a" :href="\App\Filament\Resources\Clients\ClientResource::getUrl()">Открыть клиентов</x-filament::button>
    </x-filament::section>
    @endif
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold">Динамика за выбранный период</h2>
        <label class="text-sm">Группировка
            <select wire:model.live="chartInterval" class="rounded-lg border border-gray-300 p-2 dark:border-gray-600 dark:bg-gray-800">
                <option value="day">По дням</option><option value="month">По месяцам</option>
            </select>
        </label>
    </div>
    @if($this->chartMonths)
    <div class="grid gap-6 xl:grid-cols-2">
        @include('filament.monthly-chart', ['heading'=>'Заказы и оплаты клиентов', 'months'=>$this->chartMonths, 'currency'=>'сум', 'series'=>['orders'=>'Стоимость заказов', 'payments'=>'Клиенты оплатили в SFP']])
        @include('filament.monthly-chart', ['heading'=>match(auth()->user()->role){'manager'=>'Моё вознаграждение', 'rop'=>'Мои расчёты с SFP', default=>'Расчёты с SFP'}, 'months'=>$this->chartMonths, 'currency'=>'сум', 'series'=>['earned'=>'Начислено', 'paid'=>'Погашено', 'balance'=>'Остаток на конец периода']])
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">Заказы показаны по дате оформления, оплаты и выплаты — по дате поступления. Остаток учитывает долг до начала периода.</p>
    @else
    <p>Выберите обе даты для построения графиков.</p>
    @endif
    <x-filament::section heading="Реестр начислений и сверка">
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">Начисления по оплатам выбранного периода. Выплаты и остатки — по выбранную конечную дату. Полный реестр доступен в CSV.</p>
        <div class="overflow-x-auto">
        <table class="w-full text-left text-sm"><thead><tr class="border-b border-gray-200 dark:border-gray-700">
            @foreach(['Месяц','Заказ / оплата','Получатель','Начисление','Валюта','Начислено','Погашено','Остаток'] as $title)<th class="px-3 py-3 font-medium">{{ $title }}</th>@endforeach
        </tr></thead><tbody>
        @forelse($this->register->take(50) as $row)
            <tr class="border-b border-gray-100 dark:border-gray-800">
                <td class="px-3 py-3">{{ $row['month'] }}</td><td class="px-3 py-3">{{ $row['order'] }} / #{{ $row['payment_id'] }}</td><td class="px-3 py-3">{{ $row['beneficiary'] }}</td><td class="px-3 py-3">{{ $row['kind'] }}</td><td class="px-3 py-3">{{ $row['currency'] }}</td>
                @foreach(['accrued','paid','remaining'] as $key)<td class="whitespace-nowrap px-3 py-3 tabular-nums">{{ \App\Services\Money::display($row[$key]) }}</td>@endforeach
            </tr>
        @empty
            <tr><td colspan="8" class="py-8 text-center text-gray-500">Начислений за этот период нет.</td></tr>
        @endforelse
        </tbody></table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
