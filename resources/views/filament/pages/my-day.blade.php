<x-filament-panels::page>
    @php
        $details = fn ($resource, $id) => $resource::getUrl('index', ['tableAction' => 'details', 'tableActionRecord' => $id]);
    @endphp
    <nav class="plx-day-toolbar" aria-label="Быстрые действия">
        <div class="plx-day-primary">
            @if(isset($resources['заказ']) && $resources['заказ']::canCreate())
                <x-filament::button tag="a" :href="$resources['заказ']::getUrl('index', ['action' => 'create'])" icon="heroicon-m-plus">Создать заказ</x-filament::button>
            @endif
            @if(isset($resources['сделку']) && $resources['сделку']::canCreate())
                <x-filament::button tag="a" :href="$resources['сделку']::getUrl('index', ['action' => 'create'])" color="gray" icon="heroicon-o-plus">Создать сделку</x-filament::button>
            @endif
            @if(isset($resources['задачу']) && $resources['задачу']::canCreate())
                <x-filament::button tag="a" :href="$resources['задачу']::getUrl('index', ['action' => 'create'])" color="gray" icon="heroicon-o-plus">Создать задачу</x-filament::button>
            @endif
        </div>
        <div class="plx-day-secondary">
            @foreach($resources as $noun => $resource)
                @if($noun !== 'задачу')
                    <x-filament::link color="gray" :href="$resource::getUrl()">Открыть {{ $noun }}</x-filament::link>
                @endif
                @if($noun === 'клиента' && $resource::canCreate())
                    <x-filament::link color="gray" :href="$resource::getUrl('index', ['action' => 'create'])">Создать клиента</x-filament::link>
                @endif
            @endforeach
        </div>
    </nav>
    {{-- Each list is intentionally limited to eight visible records by MyDay::getViewData. --}}
    <div class="plx-day-summary" aria-label="Краткий обзор">
        <a href="{{ $resources['задачу']::getUrl() }}" class="plx-day-stat">
            <span class="plx-day-stat-label">Задачи на сегодня</span>
            <strong class="plx-day-stat-number">{{ $today->count() }}{{ $today->count() === 8 ? '+' : '' }}</strong>
            <span class="plx-day-stat-help">В текущем обзоре</span>
        </a>
        <a href="{{ $resources['задачу']::getUrl() }}" class="plx-day-stat">
            <span class="plx-day-stat-label">Просроченные</span>
            <strong class="plx-day-stat-number">{{ $late->count() }}{{ $late->count() === 8 ? '+' : '' }}</strong>
            <span class="plx-day-stat-help">В текущем обзоре</span>
        </a>
        @if(isset($resources['заказ']))
            <a href="{{ $resources['заказ']::getUrl() }}" class="plx-day-stat">
                <span class="plx-day-stat-label">Заказы в работе</span>
                <strong class="plx-day-stat-number">{{ $orders->count() }}{{ $orders->count() === 8 ? '+' : '' }}</strong>
                <span class="plx-day-stat-help">В текущем обзоре</span>
            </a>
        @endif
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
                            <x-filament::button size="sm" color="gray" wire:click="complete({{ $task->id }})" wire:loading.attr="disabled">Завершить</x-filament::button>
                        </li>
                    @empty <li class="plx-empty">{{ $empty }}</li> @endforelse
                </ul>
                <x-filament::link :href="$resources['задачу']::getUrl()">Все задачи</x-filament::link>
            </x-filament::section>
        @endforeach
        <x-filament::section heading="Заказы в работе">
            <ul class="day-list">
                @forelse($orders as $order)
                    <li>
                        <x-filament::link :href="$details($resources['заказ'],$order->id)">№ {{ $order->id }} · {{ $order->client_name ?? 'Клиент не указан' }} · {{ $order->product ?? 'Без позиций' }}</x-filament::link>
                        <div class="plx-day-order-meta">
                            <x-filament::badge :color="match ($order->status) { 'ready' => 'success', 'production' => 'warning', 'design' => 'info', default => 'gray' }">{{ \App\Filament\CrmForms::orderStatuses()[$order->status] }}</x-filament::badge>
                            <span>Срок производства: {{ $order->due_at?->format('d.m.Y') ?? 'не указан' }}</span>
                        </div>
                        <p>Ответственный: {{ auth()->user()->name }}</p>
                    </li>
                @empty <li class="plx-empty">У вас нет активных заказов</li> @endforelse
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
                    @empty <li class="plx-empty">Нет сделок с открытыми задачами</li> @endforelse
                </ul>
                <x-filament::link :href="$resources['сделку']::getUrl()">Все сделки</x-filament::link>
            </x-filament::section>
        @endif
        @if($upcoming?->isNotEmpty())
            <x-filament::section heading="Предстоящие задачи">
                <ul class="day-list">
                    @foreach($upcoming as $task)
                        <li><x-filament::link :href="$details($resources['задачу'], $task->id)">{{ $task->title }}</x-filament::link>
                            <p>{{ $task->due_at->format('d.m.Y H:i') }} · {{ \App\Filament\CrmForms::taskStatuses()[$task->status] }}</p></li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
        @if($delegated !== null)
            <x-filament::section heading="Поручено мной">
                <ul class="day-list">
                    @forelse($delegated as $task)
                        <li><x-filament::link :href="$details($resources['задачу'], $task->id)">{{ $task->title }}</x-filament::link>
                            <p>{{ $task->manager->name }} · {{ \App\Filament\CrmForms::taskStatuses()[$task->status] }} · {{ $task->due_at->format('d.m.Y H:i') }}</p></li>
                    @empty <li class="plx-empty">Поручений дизайнерам пока нет</li> @endforelse
                </ul>
                <x-filament::link :href="$resources['задачу']::getUrl('index', ['filters' => ['delegated' => ['isActive' => true]]])">Все мои поручения</x-filament::link>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
