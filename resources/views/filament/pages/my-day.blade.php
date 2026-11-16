<x-filament-panels::page>
    @php
        $details = fn ($resource, $id) => $resource::getUrl('index', ['tableAction' => 'details', 'tableActionRecord' => $id]);
    @endphp
    <div class="day-actions">
        @foreach($resources as $noun => $resource)
            @if($noun !== 'задачу')
                <x-filament::link :href="$resource::getUrl()">Открыть {{ $noun }}</x-filament::link>
            @endif
            @if(!session()->has('crm_preview') && $resource::canCreate())
                <x-filament::link :href="$resource::getUrl('index', ['action' => 'create'])">Создать {{ $noun }}</x-filament::link>
            @endif
        @endforeach
    </div>
    <div class="day-sections">
        @foreach([['Задачи на сегодня', $today, 'На сегодня задач нет'], ['Просроченные задачи', $late, 'Просроченных задач нет']] as [$heading, $tasks, $empty])
            <x-filament::section :heading="$heading">
                <ul class="day-list">
                    @forelse($tasks as $task)
                        <li wire:key="task-{{ $loop->parent->index }}-{{ $task->id }}">
                            <x-filament::link :href="$details($resources['задачу'], $task->id)">{{ $task->title }}</x-filament::link>
                            <p @class(['day-late' => $loop->parent->index === 1])>{{ $task->due_at->format('d.m.Y H:i') }} · {{ \App\Filament\CrmForms::taskStatuses()[$task->status] }}</p>
                            @foreach([['клиента',$task->client_name,$task->client_id], ['сделку',$task->deal_title,$task->deal_id], ['заказ',$task->visible_order ? 'Заказ № '.$task->visible_order : null,$task->visible_order]] as [$type,$name,$id])
                                @if($name)<x-filament::link color="gray" :href="$details($resources[$type],$id)">{{ $name }}</x-filament::link>@endif
                            @endforeach
                            @if(!session()->has('crm_preview') && (!$task->client_id || $task->client_name) && (!$task->deal_id || $task->deal_title) && (!$task->order_id || $task->visible_order))
                                <x-filament::button size="sm" color="gray" wire:click="complete({{ $task->id }})" wire:loading.attr="disabled">Завершить</x-filament::button>
                            @endif
                        </li>
                    @empty <li>{{ $empty }}</li> @endforelse
                </ul>
                <x-filament::link :href="$resources['задачу']::getUrl()">Все задачи</x-filament::link>
            </x-filament::section>
        @endforeach
        <x-filament::section heading="Заказы в работе">
            <ul class="day-list">
                @forelse($orders as $order)
                    <li>
                        <x-filament::link :href="$details($resources['заказ'],$order->id)">№ {{ $order->id }} · {{ $order->client_name ?? 'Клиент не указан' }} · {{ $order->product ?? 'Без позиций' }}</x-filament::link>
                        <p>{{ \App\Filament\CrmForms::orderStatuses()[$order->status] }} · Срок производства: {{ $order->due_at?->format('d.m.Y') ?? 'не указан' }}</p>
                        <p>Ответственный: {{ auth()->user()->name }}</p>
                    </li>
                @empty <li>У вас нет активных заказов</li> @endforelse
            </ul>
            <x-filament::link :href="$resources['заказ']::getUrl()">Все заказы</x-filament::link>
        </x-filament::section>
        @if($deals !== null)
            <x-filament::section heading="Сделки и контакты">
                <ul class="day-list">
                    @forelse($deals as $deal)
                        <li>
                            <x-filament::link :href="$details($resources['сделку'],$deal->id)">{{ $deal->title }}</x-filament::link>
                            <p>{{ $deal->client_name ?? 'Клиент не указан' }} · {{ \App\Filament\CrmForms::dealStatuses()[$deal->status] }}</p>
                            <p>{{ $deal->action_title }}</p>
                        </li>
                    @empty <li>Нет сделок с открытыми задачами</li> @endforelse
                </ul>
                <x-filament::link :href="$resources['сделку']::getUrl()">Все сделки</x-filament::link>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
