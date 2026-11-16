@if($matches)
    <aside class="space-y-2">
        <strong>В базе уже существует похожий клиент. Проверьте его перед созданием новой карточки.</strong>
        @foreach($matches as $id => $name)
            <p><x-filament::link target="_blank" rel="noopener" :href="\App\Filament\Resources\Clients\ClientResource::getUrl('index',['tableAction'=>'details','tableActionRecord'=>$id])">{{ $name }}</x-filament::link></p>
        @endforeach
    </aside>
@endif
