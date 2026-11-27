<dl class="grid gap-3 text-sm sm:grid-cols-2">
    @foreach([
        'Возврат' => $refund->number,
        'Заказ' => $refund->order->number,
        'Клиент' => $refund->client->name,
        'Сумма' => \App\Filament\Resources\Refunds\RefundResource::displayAmount($refund->currency, $refund->amount_minor),
        'Источник денег' => $refund->source,
        'Способ возврата' => \App\Filament\Resources\Refunds\RefundResource::METHODS[$refund->method],
    ] as $label => $value)
        <div><dt class="text-xs text-gray-500">{{ $label }}</dt><dd>{{ $value }}</dd></div>
    @endforeach
</dl>
