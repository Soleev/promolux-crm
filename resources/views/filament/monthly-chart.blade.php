@php
    $months = array_values($months);
    $values = [0];
    foreach ($months as $month) {
        foreach (array_keys($series) as $key) {
            $values[] = $month[$key];
        }
    }
    $low = min($values);
    $high = max($values);
    $range = max(1, $high - $low);
    $y = fn ($value) => 150 - 125 * ($value - $low) / $range;
    $zero = $y(0);
    $width = max(500, 70 + 72 * count($months));
    $step = ($width - 70) / count($months);
    $x = fn ($index) => 62 + $step * ($index + 0.5);
    $colors = ['#6366f1', '#0d9488', '#d97706'];
    $amount = fn ($value) => \App\Services\Money::display($value);
    $points = implode(' ', array_map(fn ($index) => $x($index).','.$y($months[$index]['balance']), array_keys($months)));
@endphp
<x-filament::section :heading="$heading">
    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
        @foreach(array_values($series) as $index => $label)
            <span><span aria-hidden="true" style="color:{{ $colors[$index] }}">{{ $index === 2 ? '━' : '■' }}</span> {{ $label }}</span>
        @endforeach
    </div>
    <div class="min-w-0 overflow-x-auto"><svg style="min-width: {{ count($months) > 6 ? $width : 0 }}px" viewBox="0 0 {{ $width }} 183" class="mt-3 w-full" role="img" aria-label="{{ $heading }}, {{ $currency }}. Точные суммы приведены в таблице ниже.">
        @foreach(array_unique([$high, 0, $low]) as $tick)
            <line x1="62" x2="{{ $width - 7 }}" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}" stroke="currentColor" opacity=".2"/>
            <text x="58" y="{{ $y($tick) + 4 }}" text-anchor="end" fill="currentColor" font-size="10">{{ \Illuminate\Support\Number::abbreviate($tick / 100, precision: 1) }}</text>
        @endforeach
        @foreach($months as $index => $month)
            @foreach(array_slice(array_keys($series), 0, 2) as $i => $key)
                <rect x="{{ $x($index) - 17 + 18 * $i }}" y="{{ min($zero, $y($month[$key])) }}" width="15" height="{{ abs($zero - $y($month[$key])) }}" rx="2" fill="{{ $colors[$i] }}">
                    <title>{{ $month['label'] }} · {{ $series[$key] }} · {{ $amount($month[$key]) }}</title>
                </rect>
            @endforeach
            <text x="{{ $x($index) }}" y="175" text-anchor="middle" font-size="11" fill="currentColor">{{ $month['label'] }}</text>
        @endforeach
        @if(isset($series['balance']))
            <polyline points="{{ $points }}" fill="none" stroke="{{ $colors[2] }}" stroke-width="2.5"/>
            @foreach($months as $index => $month)
                <circle cx="{{ $x($index) }}" cy="{{ $y($month['balance']) }}" r="3.5" fill="{{ $colors[2] }}"><title>{{ $month['label'] }} · Остаток · {{ $amount($month['balance']) }}</title></circle>
            @endforeach
        @endif
    </svg></div>
    <details class="mt-3 text-sm">
        <summary class="cursor-pointer">Суммы {{ $chartInterval === 'day' ? 'по дням' : 'по месяцам' }} · {{ $currency }}</summary>
        <div class="overflow-x-auto"><table class="mt-2 w-full text-right text-xs tabular-nums">
            <thead><tr><th class="py-2 text-left">{{ $chartInterval === 'day' ? 'Дата' : 'Месяц' }}</th>@foreach($series as $label)<th class="px-2">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>@foreach($months as $month)<tr><th class="py-1 text-left">{{ $month['label'] }}</th>@foreach(array_keys($series) as $key)<td class="px-2">{{ $amount($month[$key]) }}</td>@endforeach</tr>@endforeach</tbody>
        </table></div>
    </details>
</x-filament::section>
