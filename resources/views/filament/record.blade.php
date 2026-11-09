<div class="space-y-5">
    @php
    $labels=['id'=>'Номер записи','name'=>'Имя / название','title'=>'Название','number'=>'Номер заказа','manager_id'=>'Ответственный (№)','client_id'=>'Клиент (№)','deal_id'=>'Сделка (№)','order_id'=>'Заказ (№)','phone'=>'Телефон','email'=>'Email','source'=>'Источник','notes'=>'Примечания','description'=>'Описание','contacts'=>'Контактные лица','status'=>'Статус','currency'=>'Валюта','due_at'=>'Срок','paid_at'=>'Дата оплаты','amount_minor'=>'Сумма','recipient'=>'Получатель','recipient_id'=>'Получатель (№)','beneficiary_id'=>'Получатель начисления (№)','payer'=>'Плательщик','kind'=>'Вид начисления','rate_bps'=>'Ставка в базисных пунктах','payment_id'=>'Оплата (№)','method'=>'Способ оплаты','calculation_status'=>'Расчёт','reversal_of'=>'Сторно операции №','category'=>'Категория','role'=>'Роль','active'=>'Доступ','lost_reason'=>'Причина проигрыша','created_at'=>'Создано','updated_at'=>'Изменено','total'=>'Стоимость заказа','payment_status'=>'Статус оплаты','subject_type'=>'Тип записи','subject_id'=>'Номер записи','event'=>'Действие','changes'=>'Изменения','actor_id'=>'Автор (№)','items'=>'Позиции','email_verified_at'=>'Email подтверждён'];
    @endphp
    <dl class="grid gap-3 sm:grid-cols-2">
    @foreach($details['fields'] as $key=>$value)
        @if($value !== null && $value !== '' && $key!=='items')
        <div><dt class="text-xs text-gray-500">{{ $labels[$key] ?? $key }}</dt><dd class="break-words text-sm">{{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value }}</dd></div>
        @endif
    @endforeach
    </dl>
    @if($record instanceof \App\Models\Order && $record->attachment)
        <x-filament::button tag="a" :href="route('crm.document',['type'=>'order','id'=>$record->id])">Скачать документ заказа</x-filament::button>
    @elseif($record instanceof \App\Models\Payment && $record->document)
        <x-filament::button tag="a" :href="route('crm.document',['type'=>'payment','id'=>$record->id])">Скачать подтверждение оплаты</x-filament::button>
    @endif
    @if(count($details['items']))
        <h3 class="font-semibold">Позиции заказа</h3>
        @foreach($details['items'] as $item)<p class="text-sm">{{ $item['name'] }} · {{ $item['quantity'] }} × {{ $item['price'] }} = {{ $item['total'] }}</p>@endforeach
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
        @foreach($details['history'] as $event)
        <details class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <summary class="text-sm">{{ $event->created_at->format('d.m.Y H:i') }} · {{ $event->actor?->name ?? 'Система' }} · {{ ['created'=>'Создано','updated'=>'Изменено','communication'=>'Общение','items_replaced'=>'Позиции заказа','converted'=>'Создан заказ','account_saved'=>'Учётная запись'][$event->event] ?? $event->event }}</summary>
            <pre class="mt-2 whitespace-pre-wrap break-words text-xs">{{ json_encode($event->changes,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre>
        </details>
        @endforeach
    @endif
</div>
