@php
    $stages = \App\Filament\CrmForms::dealStatuses();
    $groups = collect($records->items())->groupBy('status');
@endphp
<div class="p-4" x-data="{ dragged: null, over: null }" data-deal-kanban>
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        На доске — сделки текущей страницы с учётом поиска и фильтров. Числа в колонках относятся к этой странице.
        Перетащите карточку или нажмите «Изменить этап». Изменение подтверждается в окне.
    </p>
    <div class="flex gap-4 overflow-x-auto pb-4 snap-x snap-proximity" tabindex="0" aria-label="Этапы сделок — прокрутка по горизонтали">
        @foreach ($stages as $stage => $label)
            <section class="w-72 shrink-0 snap-start rounded-xl bg-gray-50 p-3 dark:bg-gray-900 ring-1 ring-gray-200 dark:ring-gray-700"
                wire:key="stage-{{ $stage }}" data-stage="{{ $stage }}"
                x-bind:class="over === '{{ $stage }}' ? 'outline-2 outline-primary-500' : ''"
                x-on:dragover.prevent="if (dragged?.targets.includes('{{ $stage }}')) over = '{{ $stage }}'"
                x-on:dragleave.self="over = null"
                x-on:drop.prevent="if (dragged?.targets.includes('{{ $stage }}')) { $wire.mountAction('changeStage', {target: '{{ $stage }}', expected: dragged.status}, {table: true, recordKey: dragged.id}); } dragged = null; over = null">
                <h3 class="mb-3 flex items-center justify-between gap-2 font-semibold text-gray-950 dark:text-white">
                    {{ $label }} <span class="text-sm text-gray-500">{{ $groups->get($stage, collect())->count() }}</span>
                </h3>
                <div class="flex flex-col gap-3">
                    @forelse ($groups->get($stage, collect()) as $deal)
                        @php
                            $targets = \App\Services\DealStages::targets($deal);
                            $editable = ! request()->attributes->get('preview_applied') && \Illuminate\Support\Facades\Gate::allows('update', $deal);
                            $next = $deal->tasks->first();
                            $total = array_sum(array_map(fn ($item) => (int) $item['quantity'] * (int) $item['price_minor'], $deal->items ?? []));
                        @endphp
                        <article wire:key="deal-card-{{ $deal->id }}" data-deal-card="{{ $deal->id }}"
                            draggable="{{ $editable && count($targets) ? 'true' : 'false' }}"
                            x-on:dragstart.stop="dragged = {id: '{{ $deal->id }}', status: '{{ $deal->status }}', targets: @js($targets)}; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', '{{ $deal->id }}')"
                            x-on:dragend="dragged = null; over = null"
                            class="flex flex-col gap-3 rounded-lg bg-white p-3 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <h4 class="font-semibold break-words text-gray-950 dark:text-white">{{ $deal->title }}</h4>
                            <p class="text-sm break-words text-gray-600 dark:text-gray-300">{{ $deal->client?->name ?? 'Клиент не указан' }}</p>
                            <p class="font-semibold text-gray-950 dark:text-white">{{ \App\Services\Money::display($total) }}</p>
                            <dl class="text-sm text-gray-600 dark:text-gray-300">
                                <dt>Менеджер</dt><dd class="break-words text-gray-950 dark:text-white">{{ $deal->manager?->name ?? 'Не назначен' }}</dd>
                                <dt class="mt-2">Этап</dt><dd>{{ $label }}</dd>
                                <dt class="mt-2">Производство заказа</dt>
                                <dd>{{ \App\Filament\Resources\Deals\DealResource::productionLabel($deal) }}</dd>
                            </dl>
                            <div class="rounded-md bg-gray-50 p-2 text-sm dark:bg-gray-900">
                                <p class="text-gray-500 dark:text-gray-400">Ближайшее действие</p>
                                @if ($next)
                                    <p class="break-words text-gray-950 dark:text-white">{{ $next->title }}</p>
                                    <time datetime="{{ $next->due_at->toIso8601String() }}" @class(['text-danger-600 dark:text-danger-400' => $next->due_at->isPast()])>{{ $next->due_at->timezone('Asia/Tashkent')->format('d.m.Y H:i') }}</time>
                                @else
                                    <p class="text-gray-500">Нет открытых задач</p>
                                @endif
                            </div>
                            @if ($deal->order_exists)
                                <p class="text-xs text-gray-500">Заказ уже создан. Этап зафиксирован.</p>
                            @endif
                            <div class="flex flex-wrap gap-3">
                                @foreach (['details', 'edit', 'changeStage', 'convert', 'openOrder'] as $name)
                                    @php $action = (clone $this->getTable()->getAction($name))->record($deal); @endphp
                                    @if ($action->isVisible() && $action->isAuthorized())
                                        {{ $action }}
                                    @endif
                                @endforeach
                            </div>
                        </article>
                    @empty
                        <p class="py-6 text-center text-sm text-gray-500">На этой странице сделок нет</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</div>
