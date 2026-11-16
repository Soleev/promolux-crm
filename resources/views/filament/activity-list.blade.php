<div class="space-y-2">
    @foreach($events as $event)
        <details wire:key="history-{{ $event['id'] }}" class="min-w-0 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <summary class="cursor-pointer text-sm">
                <span class="font-semibold">{{ $event['title'] }}</span>
                <span class="ms-1"><x-filament::badge :color="match ($event['category']) { 'Создание' => 'success', 'Изменение' => 'info', 'Общение' => 'warning', default => 'gray' }" class="inline-flex">{{ $event['category'] }}</x-filament::badge></span>
                <span class="mt-1 block break-words text-gray-600 dark:text-gray-400">{{ $event['subject'] }} · {{ $event['actor'] }} · {{ $event['date'] }}</span>
            </summary>
            <dl class="mt-3 grid gap-2 text-sm">
                @forelse($event['fields'] as $field)
                    <div class="min-w-0"><dt class="font-medium">{{ $field['label'] }}</dt>
                        @if(array_key_exists('before', $field))
                            <dd class="whitespace-pre-line break-words">Было: {{ $field['before'] }}</dd>
                        @endif
                        @if(array_key_exists('after', $field))
                            <dd class="whitespace-pre-line break-words">@if(array_key_exists('before', $field))Стало: @endif{{ $field['after'] }}</dd>
                        @endif
                    </div>
                @empty
                    <div>Подробности недоступны.</div>
                @endforelse
            </dl>
            @if($event['url'])
                <a class="mt-2 inline-block text-sm underline" href="{{ $event['url'] }}">{{ $event['linkLabel'] }}</a>
            @endif
        </details>
    @endforeach
</div>
