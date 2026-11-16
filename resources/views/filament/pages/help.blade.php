<x-filament-panels::page>
    <p>Добро пожаловать в справку Promolux. Выберите раздел или найдите инструкцию по названию операции.</p>
    <form action="{{ \App\Filament\Pages\Help::getUrl() }}" method="get" class="day-actions">
        <label for="help-search">Поиск по справке</label>
        <x-filament::input.wrapper>
            <x-filament::input id="help-search" type="search" name="q" :value="$query" maxlength="160" />
        </x-filament::input.wrapper>
        <x-filament::button type="submit">Найти</x-filament::button>
        @if($query !== '') <x-filament::link :href="\App\Filament\Pages\Help::getUrl()">Все инструкции</x-filament::link> @endif
    </form>
    <div class="day-sections">
        @forelse($articles as $key => $article)
            <section id="help-{{ $key }}" class="help-article">
                <x-filament::section :heading="$article['title']">
                    <ol>@foreach($article['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                    <x-filament::link :href="$article['url']">Открыть раздел «{{ $article['title'] }}»</x-filament::link>
                </x-filament::section>
            </section>
        @empty <p>Инструкций по запросу «{{ $query }}» не найдено.</p> @endforelse
    </div>
</x-filament-panels::page>
