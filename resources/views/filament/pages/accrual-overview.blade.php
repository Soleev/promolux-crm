<x-filament-panels::page>
    @if(in_array(auth()->user()->role, ['owner', 'rop'], true))
        <x-filament::section description="За всё время · Обязательства и начисленные вознаграждения" compact class="w-full" style="max-width: 100%; box-shadow: none;">
            <x-slot name="heading">
                <span class="text-xl font-semibold">Финансы Promolux</span>
            </x-slot>
            <div class="grid gap-6">
                @foreach($this->overallSummary as $summary)
                    <div class="grid gap-3" wire:key="funding-summary-{{ $summary['currency'] }}">
                        <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <h3 class="text-base font-semibold">SFP должен выплатить</h3>
                            <p class="mt-2 font-semibold tabular-nums break-words" style="font-size: 1.5rem; line-height: 1.4;">{{ $this->formatAmount($summary['sfp_remaining'], $summary['currency']) }}</p>
                            <dl class="mt-4 grid gap-3 border-t border-gray-200 pt-4 text-sm dark:border-gray-700">
                                @foreach(['owner_gross' => 'Маркетолог', 'rop_gross' => 'РОП'] as $category => $label)
                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                                        <dd class="text-right font-medium tabular-nums">{{ $this->formatAmount($summary['obligations'][$category]['remaining'], $summary['currency']) }}</dd>
                                    </div>
                                @endforeach
                                @if($summary['unassigned_sfp_settled'])
                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="text-gray-600 dark:text-gray-400">Погашение долга без распределения по ролям</dt>
                                        <dd class="text-right font-medium tabular-nums">{{ $this->formatAmount($summary['unassigned_sfp_settled'], $summary['currency']) }}</dd>
                                    </div>
                                @endif
                            </dl>
                            @if($summary['unassigned_sfp_settled'])
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Сумма погашения исторического счёта не распределена между ролями, но учтена в итоге SFP.</p>
                            @endif
                        </section>
                        <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <h3 class="text-base font-semibold">Обязательства перед менеджерами</h3>
                            <p class="mt-2 font-semibold tabular-nums break-words" style="font-size: 1.5rem; line-height: 1.4;">{{ $this->formatAmount($summary['obligations']['internal']['remaining'], $summary['currency']) }}</p>
                            <dl class="mt-4 grid gap-3 border-t border-gray-200 pt-4 text-sm dark:border-gray-700">
                                @forelse($summary['employees'] as $employee)
                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="min-w-0 break-words text-gray-600 dark:text-gray-400">{{ $employee['name'] }}</dt>
                                        <dd class="text-right font-medium tabular-nums">{{ $this->formatAmount($employee['remaining'], $summary['currency']) }}</dd>
                                    </div>
                                @empty
                                    <div><dt class="text-sm text-gray-500 dark:text-gray-400">Начислений сотрудникам пока нет.</dt><dd></dd></div>
                                @endforelse
                            </dl>
                        </section>
                        <section class="mt-2 border-t border-gray-200 dark:border-gray-700" style="padding-top: 1.5rem;">
                            <h3 class="text-base font-semibold">Вознаграждение после обязательств перед менеджерами</h3>
                            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                                @foreach(['owner' => 'Маркетолог', 'rop' => 'РОП'] as $role => $label)
                                    <div class="grid min-w-0 gap-2">
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                        <dd class="font-medium tabular-nums break-words" style="color: var(--success-600); font-size: 1.25rem; line-height: 1.4;">{{ $this->formatAmount($summary['remuneration'][$role], $summary['currency']) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">До прочих расходов. Начисленные вознаграждения не означают фактически полученные деньги.</p>
                        </section>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @else
        <x-filament::section heading="Мои начисления · за всё время">
            @include('filament.accrual-totals', ['totals' => $this->personalSummary])
        </x-filament::section>
    @endif
    <p class="text-sm text-gray-500 dark:text-gray-400">Начисления и выплаты учитывают сторно и возвраты. Отрицательный остаток — встречный долг. Его погашения и списания учтены в сводке, но не привязаны к заказам. Поиск меняет только список заказов.</p>
    <label class="text-sm">Поиск по номеру заказа
        <x-filament::input.wrapper>
            <x-filament::input wire:model.live.debounce.400ms="orderSearch" placeholder="Номер заказа" />
        </x-filament::input.wrapper>
    </label>
    @forelse($this->orderAccruals as $row)
        <x-filament::section :heading="'Заказ '.$row->number" wire:key="accrual-order-{{ $row->order_id }}-{{ $row->beneficiary_id }}-{{ $row->currency }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ $row->beneficiary }} · {{ $row->currency }}</p>
                <x-filament::button size="sm" color="gray" wire:click="showPayments({{ $row->order_id }}, {{ $row->beneficiary_id }}, @js($row->currency))">По оплатам</x-filament::button>
            </div>
            @include('filament.accrual-totals', ['totals' => [(array) $row]])
            @if($detailOrder === (int) $row->order_id && $detailBeneficiary === (int) $row->beneficiary_id && $detailCurrency === $row->currency)
                <div class="mt-4 space-y-4">
                    @foreach($this->paymentDetails as $payment)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                            <p class="text-sm">Оплата №{{ $payment->payment_id }} · {{ \Carbon\CarbonImmutable::parse($payment->paid_at)->format('d.m.Y') }} · {{ $this->formatAmount((int) $payment->payment_amount, $payment->currency) }}</p>
                            @include('filament.accrual-totals', ['totals' => [(array) $payment]])
                        </div>
                    @endforeach
                    <x-filament::button size="sm" color="gray" wire:click="closeDetails">Скрыть детализацию</x-filament::button>
                </div>
            @endif
        </x-filament::section>
    @empty
        <p>Начислений по заказам не найдено.</p>
    @endforelse
    @if($this->orderAccruals->hasPages())
        <x-filament::pagination :paginator="$this->orderAccruals" wire:key="orders-pagination" />
    @endif
    @if(auth()->user()->role === 'owner')
        <x-filament::section heading="Отдельные проводки" collapsible collapsed>
            {{ $this->table }}
        </x-filament::section>
    @endif
</x-filament-panels::page>
