<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">Сохранённые условия этого заказа. Доли указаны от фактически полученной оплаты.</p>
    <dl class="grid gap-3 sm:grid-cols-2">
        @foreach($shares as $label => $rate)
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                <dd class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $rate }}</dd>
            </div>
        @endforeach
    </dl>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Зафиксированы: {{ $confirmedAt?->format('d.m.Y H:i') ?? 'Дата не указана' }}.
        @if($version) Версия соглашения: {{ $version }}. @endif
        Новые соглашения не изменяют эти условия.
    </p>
</div>
