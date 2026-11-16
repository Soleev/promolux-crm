<div class="space-y-4 break-words">
    <h3 class="font-semibold">Общая информация</h3>
    <p>{{ $client->name }}</p>
    @if($client->other_deal || $client->other_order)<p>С этим клиентом уже работает другой менеджер.</p>@endif
    <h3 class="font-semibold">Контакты</h3>
    <p>{{ $client->phone ?: 'Телефон не указан' }} · {{ $client->email ?: 'Email не указан' }}</p>
    @foreach($contacts as $contact)<p>{{ $contact }}</p>@endforeach
    @foreach($groups as $heading => $rows)
        <h3 class="font-semibold">{{ $heading }}</h3>
        @forelse($rows as $row)<p>{{ $heading === 'Мои заказы' ? 'Заказ № '.$row : $row }}</p>@empty<p>Нет доступных записей</p>@endforelse
    @endforeach
    @isset($private)
        <details><summary>Подробности моей карточки и история</summary>@include('filament.record',['details'=>$private])</details>
    @endisset
</div>
