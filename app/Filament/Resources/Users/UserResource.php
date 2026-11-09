<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\CrmResource;
use App\Models\User;

class UserResource extends CrmResource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'Сотрудник';

    protected static ?string $pluralModelLabel = 'Команда';

    protected static string|\UnitEnum|null $navigationGroup = 'Управление';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    public static function getPages(): array
    {
        return ['index' => Pages\ManageUsers::route('/')];
    }
}
