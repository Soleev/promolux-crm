<?php

namespace App\Filament\Resources\Clients;

use App\Filament\CrmTableUi;
use App\Filament\Resources\CrmResource;
use App\Models\Client;
use App\Services\ClientDirectory;
use App\Services\CrmAccess;
use App\Services\CrmWriter;
use App\Services\RecordDetails;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ClientResource extends CrmResource
{
    protected static ?string $model = Client::class;

    protected static ?string $modelLabel = 'Клиент';

    protected static ?string $pluralModelLabel = 'Клиенты';

    protected static string|\UnitEnum|null $navigationGroup = 'Продажи';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public static function getEloquentQuery(): Builder
    {
        return auth()->user()?->role === 'manager' ? ClientDirectory::listings() : parent::getEloquentQuery();
    }

    public static function table(Table $table): Table
    {
        $table = parent::table($table);
        if (auth()->user()->role !== 'manager') {
            return $table;
        }
        $own = fn (Client $record) => auth()->user()->active && $record->manager_id == auth()->id();
        $table->getAction('details')->modalContent(function (Client $record) use ($own) {
            $data = ClientDirectory::card($record);
            if ($own($record)) {
                $record = CrmAccess::query(Client::class)->findOrFail($record->id);
                $data['private'] = RecordDetails::get($record);
            }

            return view('filament.shared-client', $data + ['record' => $record]);
        });
        $table->getAction('edit')->authorize($own)->visible($own)
            ->beforeFormFilled(fn (Client $record) => Gate::authorize('update', $record))
            ->mutateRecordDataUsing(fn (Client $record) => CrmAccess::query(Client::class)->findOrFail($record->id)->toArray())
            ->using(fn (Client $record, array $data) => app(CrmWriter::class)->save(Client::class, $data, CrmAccess::query(Client::class)->findOrFail($record->id)));
        $table->getAction('communication')->visible($own);

        return $table->filters([CrmTableUi::period('created_at', 'Дата создания')])->description('Общая база клиентов Promolux. Найдите компанию по названию, телефону или email.')
            ->columns([Stack::make([
                TextColumn::make('name')->label('Клиент')->weight('bold')->wrap()->sortable()->searchable(),
                TextColumn::make('phone')->label('Телефон')->wrap()->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->wrap()->searchable()->sortable(),
                TextColumn::make('other_work')->state(fn (Client $record) => $record->other_order || $record->other_deal ? 'С этим клиентом уже работает другой менеджер.' : null)->wrap(),
            ])]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageClients::route('/')];
    }
}
