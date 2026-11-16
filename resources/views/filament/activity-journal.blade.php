<div class="p-3 sm:p-5">
    @include('filament.activity-list', ['events' => \App\Services\ActivityHistory::journal($records->getCollection())])
</div>
