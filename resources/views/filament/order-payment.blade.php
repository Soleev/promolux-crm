@php
    $order ??= $getRecord();
    $total = $order->total_minor;
    $paid = $order->paid_minor;
    $balance = $total - $paid;
    $percent = $total > 0 ? \Brick\Math\BigDecimal::of($paid)->multipliedBy(100)->dividedBy($total, 1, \Brick\Math\RoundingMode::HalfUp) : null;
@endphp
<div class="min-w-0 space-y-1 py-2 text-sm tabular-nums" data-order-payment="{{ $order->id }}">
    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
        @foreach(['Стоимость' => $total, 'Получено от клиента' => $paid, 'Осталось получить' => max(0, $balance)] as $label => $value)
            <dt class="text-gray-600 dark:text-gray-400">{{ $label }}</dt>
            <dd class="text-end font-medium">{{ $order->displayAmount($value) }}</dd>
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
        <span>{{ $order->payment_status }}</span>
        <span>Оплачено: {{ $percent === null ? '—' : str_replace('.', ',', (string) $percent).'%' }}</span>
    </div>
    @if($percent !== null)
        <progress class="block h-2 w-full accent-teal-600" max="{{ $total }}" value="{{ min($total, max(0, $paid)) }}" aria-label="Оплаченная часть заказа"></progress>
    @endif
</div>
