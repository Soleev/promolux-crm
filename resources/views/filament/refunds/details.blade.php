@php
    use App\Filament\Resources\Refunds\RefundResource;

    $date = fn ($value) => $value?->timezone('Asia/Tashkent')->format('d.m.Y H:i');
    $amount = fn (int $minor) => RefundResource::displayAmount($refund->currency, $minor);
    $fields = [
        'Возврат' => $refund->number,
        'Заказ' => $refund->order->number,
        'Клиент' => $refund->client->name,
        'Сумма' => $amount($refund->amount_minor),
        'Способ возврата' => RefundResource::METHODS[$refund->method],
        'Статус' => RefundResource::STATUSES[$refund->status],
        'Дата заявки' => $date($refund->created_at),
        'Дата фактического возврата' => $refund->executed_at?->format('d.m.Y'),
    ];
    if ($cashier || $management) {
        $fields['Источник денег'] = $refund->source;
    }
    if (! $cashier) {
        $fields += [
            'Причина возврата' => $refund->reason,
            'Заявку оформил' => $refund->creator?->name,
            'Согласовал' => $refund->approver?->name,
            'Дата согласования' => $date($refund->approved_at),
            'Подтвердил возврат' => $refund->executor?->name,
            'Дата закрытия' => $date($refund->closed_at),
            'Причина закрытия' => $refund->close_reason,
        ];
    }
@endphp
<div class="space-y-4 text-sm">
    <dl class="grid gap-3 sm:grid-cols-2">
        @foreach($fields as $label => $value)
            @if(filled($value))
                <div>
                    <dt class="text-xs text-gray-500">{{ $label }}</dt>
                    <dd class="whitespace-pre-line break-words">@if($label === 'Статус')<x-filament::badge :color="\App\Filament\CrmTableUi::color($refund->status)">{{ $value }}</x-filament::badge>@else{{ $value }}@endif</dd>
                </div>
            @endif
        @endforeach
    </dl>
    @if(! $cashier && $refund->allocations->isNotEmpty())
        <section class="space-y-2">
            <h3 class="font-semibold">Исходные оплаты</h3>
            <ul>
                @foreach($refund->allocations as $allocation)
                    <li>Оплата № {{ $allocation->payment_id }} · {{ $allocation->payment?->paid_at?->format('d.m.Y') }} · {{ $amount($allocation->amount_minor) }}</li>
                @endforeach
            </ul>
        </section>
    @endif
    @if($management && $refund->document)
        <x-filament::button tag="a" :href="route('crm.document', ['type' => 'refund', 'id' => $refund->id])">Скачать подтверждение</x-filament::button>
    @endif
    @if($management && $refund->executionIssues->isNotEmpty())
        <section class="space-y-2">
            <h3 class="font-semibold">Записи об ошибках проведения</h3>
            <ul class="space-y-1">
                @foreach($refund->executionIssues as $issue)
                    <li class="whitespace-pre-line break-words">{{ $date($issue->created_at) }} · {{ $issue->reason }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
