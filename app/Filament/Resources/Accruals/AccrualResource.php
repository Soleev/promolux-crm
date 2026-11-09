<?php

namespace App\Filament\Resources\Accruals;

use App\Filament\Resources\CrmResource;
use App\Models\Accrual;

class AccrualResource extends CrmResource
{
    protected static ?string $model = Accrual::class;

    protected static ?string $modelLabel = 'Начисление';

    protected static ?string $pluralModelLabel = 'Начисления и остатки';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    public static function getPages(): array
    {
        return ['index' => Pages\ManageAccruals::route('/')];
    }
}
