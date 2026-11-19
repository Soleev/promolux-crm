<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\CrmForms;
use App\Filament\Resources\CrmResource;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use App\Models\Task;

class TaskResource extends CrmResource
{
    protected static ?string $model = Task::class;

    protected static ?string $modelLabel = 'Задача';

    protected static ?string $pluralModelLabel = 'Задачи';

    protected static string|\UnitEnum|null $navigationGroup = 'Продажи';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-circle';

    public static function table(Table $table): Table
    {
        $table = parent::table($table);
        $table->getAction('edit')->schema(fn (Task $record) => $record->statusOnlyFor(auth()->user())
            ? [CrmForms::taskStatusField()]
            : CrmForms::fields(Task::class));

        if (in_array(auth()->user()->role, ['owner', 'rop', 'manager'], true) && Task::supportsDelegation()) {
            $table->pushFilters([
                Filter::make('delegated')->label('Поручено мной')
                    ->query(fn ($query) => $query->where('created_by', auth()->id())->where('manager_id', '!=', auth()->id())),
            ]);
        }

        return $table;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTasks::route('/')];
    }
}
