@php
    $order ??= $getRecord();
    $total = $order->total_minor;
    $showRefunds = \App\Services\RefundAccess::canViewAny(auth()->user());
    $paid = $showRefunds ? $order->net_paid_minor : $order->paid_minor;
    $balance = $order->status === 'cancelled' ? 0 : $total - $paid;
    $percent = $total > 0 ? \Brick\Math\BigDecimal::of($paid)->multipliedBy(100)->dividedBy($total, 1, \Brick\Math\RoundingMode::HalfUp) : null;
@endphp
<div class="plx-order-payment" data-order-payment="{{ $order->id }}">
    <div class="plx-order-payment-top">
        <span class="plx-order-payment-label">Сумма</span>
        <span class="plx-order-payment-number">{{ $order->displayAmount($total) }}</span>
    </div>
    <div class="plx-order-payment-bottom">
        <span class="plx-order-payment-secondary">Оплачено: {{ $order->displayAmount($paid) }}</span>
        <span class="plx-order-payment-secondary">{{ $balance < 0 ? 'Переплата' : 'Остаток' }}: {{ $order->displayAmount(abs($balance)) }}</span>
    </div>
    <div class="plx-order-payment-bottom">
        <span class="plx-order-payment-secondary">{{ $order->paymentStatusFor($paid) }}{{ $showRefunds && $order->refund_status ? ' · '.$order->refund_status : '' }}</span>
        <span class="plx-order-payment-secondary">{{ $percent === null ? '—' : str_replace('.', ',', (string) $percent).'%' }}</span>
    </div>
    @if($total > 0)
        <progress max="{{ $total }}" value="{{ min($total, max(0, $paid)) }}" aria-label="Оплаченная часть заказа"></progress>
    @endif
</div>
