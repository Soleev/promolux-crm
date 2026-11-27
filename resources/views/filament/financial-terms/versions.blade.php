<div class="space-y-4">
<h3 class="font-semibold">История версий соглашения</h3>
@forelse($rows as $row)
    <x-filament::section :heading="'Версия ' . $row['version'] . ' · с ' . $row['effective']">
        <p>{{ $owner ? 'Доля сотрудника' : 'Моя доля' }} {{ $row['seller'] }}</p>
        @if($row['scheduled'])<p>Запланированная версия</p>@endif
        @if($owner)
            <p>Владелец · {{ $row['owner'] }}</p>
            <p>РОП · {{ $row['rop'] }}</p>
        @endif
    </x-filament::section>
@empty
    <p>Опубликованных условий пока нет.</p>
@endforelse
</div>
