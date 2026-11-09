<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\CrmResource;
use App\Models\Expense;

class ExpenseResource extends CrmResource
{
    protected static ?string $model = Expense::class;

    protected static ?string $modelLabel = 'Расход';

    protected static ?string $pluralModelLabel = 'Расходы';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    public static function getPages(): array
    {
        return ['index' => Pages\ManageExpenses::route('/')];
    }
}
