<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\CrmResource;
use App\Models\Payment;

class PaymentResource extends CrmResource
{
    protected static ?string $model = Payment::class;

    protected static ?string $modelLabel = 'Оплата';

    protected static ?string $pluralModelLabel = 'Оплаты клиентов';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePayments::route('/')];
    }
}
