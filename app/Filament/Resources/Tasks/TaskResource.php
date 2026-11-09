<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\Resources\CrmResource;
use App\Models\Task;

class TaskResource extends CrmResource
{
    protected static ?string $model = Task::class;

    protected static ?string $modelLabel = 'Задача';

    protected static ?string $pluralModelLabel = 'Задачи';

    protected static string|\UnitEnum|null $navigationGroup = 'Продажи';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-circle';

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTasks::route('/')];
    }
}
