<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\CrmResource;
use App\Models\Client;

class ClientResource extends CrmResource
{
    protected static ?string $model = Client::class;

    protected static ?string $modelLabel = 'Клиент';

    protected static ?string $pluralModelLabel = 'Клиенты';

    protected static string|\UnitEnum|null $navigationGroup = 'Продажи';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public static function getPages(): array
    {
        return ['index' => Pages\ManageClients::route('/')];
    }
}
