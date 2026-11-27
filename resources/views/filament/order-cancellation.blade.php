@php
    $pending = (int) $order->pending_minor;
    $fields = [
        'Номер заказа' => $order->number,
        'Клиент' => $order->client?->name ?? 'Не указан',
        'Стоимость' => $order->displayAmount($order->total_minor),
        'Получено от клиента' => $order->displayAmount($order->paid_minor),
        'Осталось получить' => $order->displayAmount(max(0, $order->total_minor - $order->paid_minor)),
        'Статус оплаты' => $order->payment_status,
        'Производственный статус' => \App\Filament\CrmForms::orderStatuses()[$order->status],
        'Наличные у сотрудника' => $pending > 0 ? $order->displayAmount($pending) : 'Нет',
    ];
@endphp
<div class="space-y-3 text-sm">
    <dl class="grid gap-3 sm:grid-cols-2">
        @foreach($fields as $label => $value)
            <div>
                <dt class="text-xs text-gray-500">{{ $label }}</dt>
                <dd>{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
    @if($order->status === 'production')
        <p class="font-semibold">Заказ уже находится в производстве.</p>
    @elseif($order->status === 'ready')
        <p class="font-semibold">Производство завершено. Заказ ещё не передан клиенту.</p>
    @endif
    @if($pending > 0)
        <p class="font-semibold">Урегулируйте наличные у сотрудника перед отменой заказа.</p>
    @endif
    <p>Незавершённые задачи заказа будут отменены. Оплаты сохранятся в истории.</p>
</div>
