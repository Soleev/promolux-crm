@php
    $values = $this->report['currencies']['UZS'];
    $role = auth()->user()->role;
    $cards = ['order_total' => 'Действующие и выданные заказы', 'cancelled_order_total' => 'Отменённые заказы', 'client_payments' => 'Оплачено клиентами в SFP',
        'client_refunds' => 'Возвращено клиентам', 'client_net_payments' => 'Чистые оплаты клиентов', 'client_refund_due' => 'К возврату клиентам'];
    $cards += match ($role) {
        'owner' => ['sfp_remaining' => 'Баланс с SFP', 'manager_remaining' => 'Баланс с сотрудниками'],
        'rop' => ['rop_net' => 'Моё вознаграждение за период'],
        default => ['manager_accrued' => 'Мне начислено за период', 'manager_storno' => 'Сторнировано за период', 'manager_remaining' => 'Баланс вознаграждений', 'employee_debt' => 'К возврату плательщикам'],
    };
    $groups = [];
    if ($role === 'owner') {
        $groups['Владелец и РОП'] = ['owner_gross'=>'Начислено владельцу от SFP', 'owner_net'=>'Доля владельца после менеджера', 'rop_gross'=>'Начислено РОП от SFP', 'rop_net'=>'Доля РОП после менеджера'];
        $groups['Сторно и встречные долги'] = ['sfp_storno' => 'Сторно начислений SFP', 'manager_storno' => 'Сторно комиссий сотрудников', 'sfp_debt' => 'Встречный долг перед SFP', 'employee_debt' => 'Задолженность сотрудников'];
    }
    $groups['Выплаты'] = match ($role) {
        'owner' => ['sfp_opening'=>'SFP: остаток на начало', 'sfp_paid'=>'Перечислено SFP', 'sfp_repaid'=>'SFP: погашено встречного долга', 'sfp_written_off'=>'SFP: списано долга', 'manager_opening'=>'Сотрудники: остаток на начало', 'manager_accrued'=>'Начислено менеджерам', 'manager_paid'=>'Выплачено менеджерам', 'manager_repaid'=>'Сотрудники: погашено долга', 'manager_written_off'=>'Сотрудники: списано долга'],
        'rop' => [],
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
                <p class="mt-1 text-xs text-gray-500">{{ in_array($key, ['sfp_remaining','manager_remaining','client_refund_due','employee_debt']) ? 'На выбранную дату' : 'За выбранный период' }}</p>
            </div>
        @endforeach
    </dl>
    @if(!$this->report['period_payment_count'])
        <p class="text-sm text-gray-600 dark:text-gray-400">Оплат в SFP за период пока нет. Вознаграждение начисляется после поступления денег в SFP.</p>
    @endif
    @if($role !== 'rop')
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
    @endif
</section>
