<?php

namespace App\Filament\Resources\Payouts;

use App\Filament\Resources\CrmResource;
use App\Models\Payout;

class PayoutResource extends CrmResource
{
    protected static ?string $model = Payout::class;

    protected static ?string $modelLabel = 'Выплата';

    protected static ?string $pluralModelLabel = 'Выплаты';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePayouts::route('/')];
    }
}
