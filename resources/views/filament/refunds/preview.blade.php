<div class="space-y-3 text-sm">
    <p>{{ $refund->number }} · {{ $refund->order->number }} · {{ $refund->client->name }}</p>
    <p>Заявлено к возврату · {{ \App\Filament\Resources\Refunds\RefundResource::displayAmount($refund->currency, $refund->amount_minor) }}</p>
    @if($previewError)
        <p class="font-semibold">{{ $previewError }}</p>
    @elseif($summary)
        @include('filament.refunds.summary', ['order' => $refund->order, 'retainedAfterMinor' => $summary['retained_minor']])
        @php
            $kinds = [
                'owner_gross' => 'Доля владельца от SFP',
                'rop_gross' => 'Доля РОП от SFP',
                'manager_owner' => 'Комиссия сотрудника из доли владельца',
                'manager_rop' => 'Комиссия сотрудника из доли РОП',
            ];
        @endphp
        <h3 class="font-semibold">Ожидаемое сторно после выдачи денег</h3>
        <ul class="space-y-2">
            @forelse($summary['commission_reversals'] as $row)
                <li>
                    {{ $kinds[$row['kind']] ?? $row['kind'] }} · {{ $row['payer'] }}
                    · {{ $beneficiaries[$row['beneficiary_id']] ?? 'Получатель № '.$row['beneficiary_id'] }}
                    · {{ \App\Filament\Resources\Refunds\RefundResource::displayAmount($row['currency'], -$row['amount_minor']) }}
                </li>
            @empty
                <li>По проведённым начислениям сумма сторно составляет 0.</li>
            @endforelse
        </ul>
    @endif
</div>
