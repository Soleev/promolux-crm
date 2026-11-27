@if($order && $summary)
    @php
        $fields = [
            'Подтверждённая оплата' => $summary['confirmed_minor'],
            'Фактически возвращено' => $summary['completed_minor'],
            'Согласовано к возврату' => $summary['approved_minor'],
            'Доступно для возврата' => $summary['available_minor'],
        ];
        if ($retainedAfterMinor !== null) {
            $fields['Останется после этой выдачи'] = $retainedAfterMinor;
        }
    @endphp
    <dl class="grid gap-3 text-sm sm:grid-cols-2">
        @foreach($fields as $label => $value)
            <div>
                <dt class="text-xs text-gray-500">{{ $label }}</dt>
                <dd>{{ $order->displayAmount($value) }}</dd>
            </div>
        @endforeach
    </dl>
@endif
