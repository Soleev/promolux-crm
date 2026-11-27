@foreach($totals as $total)
    <dl @class(['mt-3 grid gap-2' => $compact ?? false, 'mt-4 grid gap-4 sm:grid-cols-3' => ! ($compact ?? false)])>
        @foreach(['accrued' => 'Начислено', 'paid' => 'Выплачено', 'remaining' => $remainingLabel ?? 'Осталось получить'] as $key => $label)
            <div @class(['flex flex-wrap items-baseline justify-between gap-2' => $compact ?? false])>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                <dd @class(['font-semibold', 'text-sm' => $compact ?? false, 'text-lg' => ! ($compact ?? false)])>{{ $this->formatAmount((int) $total[$key], $total['currency']) }}</dd>
            </div>
        @endforeach
    </dl>
    @if($total['settled'] ?? 0)
        <p class="mt-2 text-sm">Погашено или списано встречного долга: {{ $this->formatAmount($total['settled'], $total['currency']) }}</p>
    @endif
@endforeach
