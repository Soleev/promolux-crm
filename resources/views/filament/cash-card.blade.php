@php($cash = $getRecord())
<div class="plx-cash-card">
    <div class="plx-cash-card-heading"><strong>№ {{ $cash->order->id }} · {{ $cash->order->client?->name ?? 'Клиент не указан' }}</strong>
    @if($cash->status === 'pending')<span class="plx-cash-age">{{ max(0, (int) $cash->collected_at->diffInDays(today())) }} дн.</span>@endif</div>
    <p>{{ $cash->order->items->pluck('name')->take(2)->join(', ') }}</p>
    <strong class="plx-cash-amount">{{ \App\Services\Money::display($cash->amount_minor) }}</strong>
    <p>{{ $cash->collector->name }} · {{ $cash->collected_at->format('d.m.Y') }}</p>
    @if($cash->status !== 'pending')<p>{{ \App\Filament\Resources\CashReceipts\CashReceiptResource::STATUSES[$cash->status] ?? $cash->status }}</p>@endif
</div>
