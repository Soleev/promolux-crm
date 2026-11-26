@if($mode === 'home')
<section class="stats" aria-label="Показатели производства">
@foreach([['В работе',$working,'purple','Статус «Производство»'],['Срочно сегодня',$dueToday,'rose','Срок выполнения сегодня'],['Готово сегодня','Нет данных','green','Дата завершения не учитывается'],['Просрочено',$overdue,'rose','Срок выполнения прошёл']] as [$label,$value,$color,$hint])
<article class="stat {{ $color }}"><span>{{ $label }}</span><strong>{{ $value }}</strong><small>{{ $hint }}</small></article>
@endforeach
</section>
<section class="panel"><div class="section-title"><h2>Очередь производства</h2><span>{{ trans_choice('sfp.order_count', $total, [], 'ru') }}</span></div>
@forelse($queue as $order) @include('sfp.order', ['clientFirst'=>false]) @empty <p class="empty">Активных заказов нет</p> @endforelse
<a class="button wide" href="{{ route('sfp.screen',['view'=>'queue']) }}">Показать всю очередь ({{ $total }}) →</a></section>
<section class="panel"><div class="section-title"><h2>Сейчас в производстве</h2><span>{{ trans_choice('sfp.order_count', $working, [], 'ru') }}</span></div>
@forelse($production as $order) @include('sfp.order', ['clientFirst'=>true]) @empty <p class="empty">Заказов в производстве нет</p> @endforelse
@if($working > 3)<a class="button wide" href="{{ route('sfp.screen',['view'=>'queue']) }}">Открыть полную очередь</a>@endif</section>
<section class="panel urgent"><h2>Срочные позиции</h2>
@forelse($urgent as $order) @include('sfp.order', ['clientFirst'=>true]) @empty <p class="empty">Срочных и просроченных заказов нет</p> @endforelse</section>
@elseif($mode === 'queue')
<a class="button" href="{{ route('sfp.screen') }}">← Главный экран</a>
<section class="panel"><div class="section-title"><h2>Вся очередь производства</h2><span>{{ trans_choice('sfp.order_count', $total, [], 'ru') }}</span></div>
@forelse($queue as $order) @include('sfp.order', ['clientFirst'=>false]) @empty <p class="empty">Активных заказов нет</p> @endforelse
<nav class="pagination" aria-label="Страницы очереди">@if($queue->previousPageUrl())<a class="button" href="{{ $queue->previousPageUrl() }}">← Назад</a>@endif<span>{{ $queue->currentPage() }} / {{ $queue->lastPage() }}</span>@if($queue->nextPageUrl())<a class="button" href="{{ $queue->nextPageUrl() }}">Далее →</a>@endif</nav></section>
@else
<a class="button" href="{{ route('sfp.screen',['view'=>'queue']) }}">← Вся очередь</a> <a class="button" href="{{ route('sfp.screen') }}">Главный экран</a>
<section class="panel"><h2>{{ $detail->client?->name ?? 'Клиент не указан' }}</h2><p class="muted">Заказ {{ $detail->number }}</p><p class="badge">{{ $statuses[$detail->status] }}</p><p>Срок: {{ $detail->due_at?->format('d.m.Y') ?? 'Не указан' }}</p><h3>Продукция и характеристики</h3>
@foreach($detail->items as $item)<div class="item"><strong>{{ $item->name }}</strong>@include('sfp.item')</div>@endforeach
</section>
@endif
