@php
    $values = $this->report['currencies']['UZS'];
    $role = auth()->user()->role;
    $cards = ['order_total' => 'Заказы за период', 'client_payments' => 'Оплачено клиентами в SFP'];
    $cards += match ($role) {
        'owner' => ['sfp_remaining' => 'К получению от SFP', 'manager_remaining' => 'К выплате менеджерам'],
        'rop' => ['sfp_remaining' => 'Мне к получению от SFP', 'rop_net' => 'Моя доля за период'],
        default => ['manager_accrued' => 'Мне начислено за период', 'manager_remaining' => 'Мне осталось получить'],
    };
    $groups = [];
    if ($role === 'owner') {
        $groups['Владелец и РОП'] = ['owner_gross'=>'Начислено владельцу от SFP', 'owner_net'=>'Доля владельца после менеджера', 'rop_gross'=>'Начислено РОП от SFP', 'rop_net'=>'Доля РОП после менеджера'];
    } elseif ($role === 'rop') {
        $groups['Моя доля'] = ['rop_gross'=>'Начислено от SFP', 'rop_net'=>'После вознаграждения менеджеру'];
    }
    $groups['Выплаты'] = match ($role) {
        'owner' => ['sfp_paid'=>'Перечислено SFP', 'manager_accrued'=>'Начислено менеджерам', 'manager_paid'=>'Выплачено менеджерам'],
        'rop' => ['sfp_paid'=>'Погашено по моим начислениям'],
        default => ['manager_paid'=>'Мне выплачено'],
    };
    if ($role === 'owner') {
        $groups['Расходы'] = ['sfp_expenses'=>'За счёт SFP', 'promolux_expenses'=>'За счёт Promolux'];
    }
@endphp
<section aria-label="Финансовые показатели" class="space-y-3">
    <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach($cards as $key => $label)
            <div class="min-w-0 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                <dd class="mt-2 text-lg leading-tight font-semibold tabular-nums sm:text-xl">{{ \App\Services\Money::display($values[$key]) }}</dd>
                <p class="mt-1 text-xs text-gray-500">{{ in_array($key, ['sfp_remaining','manager_remaining']) ? 'На выбранную дату' : 'За выбранный период' }}</p>
            </div>
        @endforeach
    </dl>
    @if(!$this->report['period_payment_count'])
        <p class="text-sm text-gray-600 dark:text-gray-400">Оплат в SFP за период пока нет. Вознаграждение начисляется после поступления денег в SFP.</p>
    @endif
    <details class="group rounded-xl bg-white px-4 shadow-sm dark:bg-gray-900">
        <summary class="cursor-pointer py-3 text-sm font-semibold text-indigo-700 dark:text-indigo-300">
            <span class="group-open:hidden">Показать детализацию</span><span class="hidden group-open:inline">Скрыть детализацию</span>
        </summary>
        <div class="grid gap-5 border-t border-gray-200 py-4 md:grid-cols-3 dark:border-gray-700">
            @foreach($groups as $heading => $metrics)
                <div>
                    <h3 class="mb-2 font-semibold">{{ $heading }}</h3>
                    <dl class="space-y-2 text-sm">
                        @foreach($metrics as $key => $label)
                            <div class="flex flex-wrap justify-between gap-x-2"><dt>{{ $label }}</dt><dd class="font-medium tabular-nums">{{ \App\Services\Money::display($values[$key]) }}</dd></div>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </div>
    </details>
</section>
