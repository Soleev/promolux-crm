<?php

namespace App\Filament\Resources\Activities;

use App\Filament\CrmTableUi;
use App\Filament\Resources\CrmResource;
use App\Models\Activity;
use App\Models\User;
use App\Services\ActivityHistory;
use App\Services\CrmAccess;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ActivityResource extends CrmResource
{
    protected static ?string $model = Activity::class;

    protected static ?string $modelLabel = 'Действие';

    protected static ?string $pluralModelLabel = 'История действий';

    protected static string|\UnitEnum|null $navigationGroup = 'Управление';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(function (Builder $query): Builder {
            abort_unless(static::canViewAny(), 403);

            return $query->with('actor:id,name');
        })
            ->content(fn () => view('filament.activity-journal'))
            ->filters([
                CrmTableUi::period('created_at', 'Дата события'),
                SelectFilter::make('actor_id')->label('Сотрудник')->options(fn () => CrmAccess::query(User::class)
                    ->whereIn('id', CrmAccess::query(Activity::class)->select('actor_id'))->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('subject_type')->label('Тип объекта')->options(ActivityHistory::TYPES),
                SelectFilter::make('event')->label('Действие')->options(ActivityHistory::EVENTS),
            ])->defaultSort(fn (Builder $query) => $query->latest('created_at')->latest('id'))
            ->paginated([10, 25, 50])->enforcePaginationPageOptions()->emptyStateHeading('Нет событий')->emptyStateDescription('Здесь появляются сохранённые действия сотрудников.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageActivities::route('/')];
    }
}
