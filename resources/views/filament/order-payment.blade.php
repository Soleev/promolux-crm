@php
    $order ??= $getRecord();
    $total = $order->total_minor;
    $showRefunds = \App\Services\RefundAccess::canViewAny(auth()->user());
    $paid = $showRefunds ? $order->net_paid_minor : $order->paid_minor;
    $balance = $order->status === 'cancelled' ? 0 : $total - $paid;
    $summary = ['Стоимость' => $total, 'Получено от клиента' => $order->paid_minor];
    if ($showRefunds) $summary['Возвращено клиенту'] = $order->returned_minor;
    $summary['Осталось получить'] = max(0, $balance);
    $percent = $total > 0 ? \Brick\Math\BigDecimal::of($paid)->multipliedBy(100)->dividedBy($total, 1, \Brick\Math\RoundingMode::HalfUp) : null;
@endphp
<div class="min-w-0 space-y-1 py-2 text-sm tabular-nums" data-order-payment="{{ $order->id }}">
    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
        @foreach($summary as $label => $value)
            <dt class="text-gray-600 dark:text-gray-400">{{ $label }}</dt>
            <dd @class(['text-end', 'font-bold' => ($emphasizeDebt ?? false) && $label === 'Осталось получить', 'font-medium' => !($emphasizeDebt ?? false) || $label !== 'Осталось получить'])>{{ $order->displayAmount($value) }}</dd>
        @endforeach
        @if($showBreakdown ?? false)
            <dt class="text-gray-600 dark:text-gray-400">Подтверждено в SFP</dt>
            <dd class="text-end">{{ $order->displayAmount((int) $order->confirmed_minor) }}</dd>
            @if($order->pending_minor > 0)
                <dt class="text-gray-600 dark:text-gray-400">Наличные у сотрудника</dt>
                <dd class="text-end">{{ $order->displayAmount((int) $order->pending_minor) }}</dd>
            @endif
        @endif
        @if($balance < 0)
            <dt>Переплата</dt><dd class="text-end font-semibold">{{ $order->displayAmount(-$balance) }}</dd>
        @endif
    </dl>
    <div class="flex flex-wrap justify-between gap-x-3">
        <x-filament::badge :color="\App\Filament\CrmTableUi::color($order->paymentStatusFor($paid))">{{ $order->paymentStatusFor($paid) }}</x-filament::badge>
        @if($showRefunds && $order->refund_status)<x-filament::badge :color="\App\Filament\CrmTableUi::color($order->refund_status)">{{ $order->refund_status }}</x-filament::badge>@endif
        <span>Оплачено: {{ $percent === null ? '—' : str_replace('.', ',', (string) $percent).'%' }}</span>
    </div>
    @if($percent !== null)
        <progress class="block h-2 w-full accent-teal-600" max="{{ $total }}" value="{{ min($total, max(0, $paid)) }}" aria-label="Оплаченная часть заказа"></progress>
    @endif
</div>
