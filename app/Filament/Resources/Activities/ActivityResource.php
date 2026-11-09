<?php

namespace App\Filament\Resources\Activities;

use App\Filament\Resources\CrmResource;
use App\Models\Activity;

class ActivityResource extends CrmResource
{
    protected static ?string $model = Activity::class;

    protected static ?string $modelLabel = 'Действие';

    protected static ?string $pluralModelLabel = 'Журнал действий';

    protected static string|\UnitEnum|null $navigationGroup = 'Управление';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    public static function getPages(): array
    {
        return ['index' => Pages\ManageActivities::route('/')];
    }
}
