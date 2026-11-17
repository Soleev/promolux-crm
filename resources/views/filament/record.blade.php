<div class="space-y-5">
    @php
    $labels=['id'=>'Номер записи','name'=>'Имя / название','title'=>'Название','number'=>'Номер заказа','manager_id'=>'Ответственный (№)','client_id'=>'Клиент (№)','deal_id'=>'Сделка (№)','order_id'=>'Заказ (№)','phone'=>'Телефон','email'=>'Email','source'=>'Источник','notes'=>'Примечания','description'=>'Описание','contacts'=>'Контактные лица','status'=>'Статус','currency'=>'Валюта','due_at'=>'Срок','paid_at'=>'Дата оплаты','amount_minor'=>'Сумма','recipient'=>'Получатель','recipient_id'=>'Получатель (№)','beneficiary_id'=>'Получатель начисления (№)','payer'=>'Плательщик','kind'=>'Вид начисления','rate_bps'=>'Ставка в базисных пунктах','payment_id'=>'Оплата (№)','method'=>'Способ оплаты','calculation_status'=>'Расчёт','reversal_of'=>'Сторно операции №','category'=>'Категория','role'=>'Роль','active'=>'Доступ','lost_reason'=>'Причина проигрыша','created_at'=>'Создано','updated_at'=>'Изменено','total'=>'Стоимость заказа','payment_status'=>'Статус оплаты','subject_type'=>'Тип записи','subject_id'=>'Номер записи','event'=>'Действие','changes'=>'Изменения','actor_id'=>'Автор (№)','items'=>'Позиции','email_verified_at'=>'Email подтверждён'];
    @endphp
    @if(isset($details['balanceOrder']))
        <section class="space-y-3">
            <h3 class="font-semibold">Оплата заказа</h3>
            @include('filament.order-payment', ['order' => $details['balanceOrder'], 'showBreakdown' => true])
            <a class="text-sm underline" href="#order-payments-{{ $record->id }}">История оплат заказа</a>
        </section>
    @endif
    <dl class="grid gap-3 sm:grid-cols-2">
    @foreach($details['fields'] as $key=>$value)
        @if($value !== null && $value !== '' && $key!=='items')
        <div><dt class="text-xs text-gray-500">{{ $labels[$key] ?? $key }}</dt><dd class="whitespace-pre-line break-words text-sm">{{ is_array($value) ? 'Подробности недоступны' : $value }}</dd></div>
        @endif
    @endforeach
    </dl>
    @if($record instanceof \App\Models\Order && $record->attachment)
        <x-filament::button tag="a" :href="route('crm.document',['type'=>'order','id'=>$record->id])">Скачать документ заказа</x-filament::button>
    @elseif($record instanceof \App\Models\Payment && $record->document)
        <x-filament::button tag="a" :href="route('crm.document',['type'=>'payment','id'=>$record->id])">Скачать подтверждение оплаты</x-filament::button>
    @endif
    @if(count($details['items']))
        <h3 class="font-semibold">Позиции</h3>
        @foreach($details['items'] as $item)
            <div class="text-sm">
                <p>{{ $item['name'] }} · {{ $item['quantity'] }} {{ $item['unit'] }} × {{ $item['price'] }} = {{ $item['total'] }}</p>
                @if($item['supplier'])<p>Исполнитель · {{ $item['supplier'] }}</p>@endif
                @if(isset($item['snapshot']['area']))<p>{{ $item['snapshot']['width'] }} × {{ $item['snapshot']['height'] }} м · {{ $item['snapshot']['copies'] }} экз.</p>@endif
                @foreach($item['snapshot']['characteristics'] ?? [] as $index => $value)
                    @if(filled($value))<p>{{ $item['snapshot']['fields'][$index] }} · {{ $value }}</p>@endif
                @endforeach
            </div>
        @endforeach
    @endif
    @if(isset($details['balanceOrder']))
        <section id="order-payments-{{ $record->id }}">
                <h3 class="font-semibold">История оплат заказа</h3>
                <ul class="space-y-1 py-2 text-sm">
                    @foreach($details['pendingCash'] as $cash)
                        <li>{{ $cash->collected_at->format('d.m.Y') }} · Наличные у сотрудника · {{ $record->displayAmount($cash->amount_minor) }}</li>
                    @endforeach
                    @foreach($details['payments'] as $payment)
                        <li>{{ $payment->paid_at->format('d.m.Y') }} · {{ $payment->reversal_of ? 'Сторно оплаты № '.$payment->reversal_of : $payment->method }} · {{ $record->displayAmount($payment->amount_minor) }}</li>
                    @endforeach
                    @if($details['payments']->isEmpty() && $details['pendingCash']->isEmpty())
                        <li>Доступных записей об оплате нет.</li>
                    @endif
                </ul>
        </section>
    @endif
    @if(count($details['related']))
        <h3 class="font-semibold">Связанные записи</h3>
        @foreach($details['related'] as $line)<p class="text-sm">{{ $line }}</p>@endforeach
    @endif
    @foreach($details['accruals'] as $a)
        <p class="text-sm">#{{ $a->id }} · {{ $a->beneficiary->name }} · {{ \App\Filament\CrmForms::kindLabels()[$a->kind] }} · {{ \App\Services\Money::display($a->amount_minor) }}</p>
    @endforeach
    @foreach($details['allocations'] as $a)
        <p class="text-sm">Начисление #{{ $a->accrual_id }} · {{ $a->accrual->beneficiary->name }} · {{ \App\Services\Money::display($a->amount_minor) }}</p>
    @endforeach
    @if(count($details['history']))
        <h3 class="font-semibold">История изменений и общения</h3>
        @include('filament.activity-list', ['events' => $details['history']])
    @endif
</div>
