<div class="plx-cash-confirm">
    <div><span>Заказ и клиент</span><strong>{{ $cash->order->number }} · {{ $cash->order->client?->name }}</strong><p>{{ $cash->order->items->pluck('name')->take(2)->join(', ') }}</p></div>
    <div><span>Сотрудник</span><strong>{{ $cash->collector->name }}</strong><p>Получено от клиента {{ $cash->collected_at->format('d.m.Y') }}</p></div>
    <div class="plx-cash-confirm-total"><span>Сумма к приёму</span><strong>{{ \App\Services\Money::display($cash->amount_minor) }}</strong></div>
    <p>После подтверждения сумма будет учтена в SFP. Повторное поступление не создаётся.</p>
</div>
