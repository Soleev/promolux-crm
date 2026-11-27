<?php

namespace App\Filament\Resources\Users;

use App\Filament\FinancialAgreementDisplay;
use App\Filament\FinancialTermsActions;
use App\Filament\Resources\CrmResource;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends CrmResource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'Сотрудник';

    protected static ?string $pluralModelLabel = 'Команда';

    protected static string|\UnitEnum|null $navigationGroup = 'Управление';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    public static function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table->columns([
            ...$table->getColumns(),
            TextColumn::make('agreement_rate')->label('Действующая ставка')
                ->visible(FinancialAgreementDisplay::isOwner(...))
                ->state(function (User $record): string {
                    $terms = FinancialAgreementDisplay::forUser($record);

                    if (! ($terms['available'] ?? false)) {
                        return 'Не определена';
                    }

                    return FinancialAgreementDisplay::percent(match ($record->role) {
                        'owner' => $terms['owner_sfp_bps'],
                        'rop' => $terms['rop_sfp_bps'],
                        default => $terms['owner_funding_bps'] + $terms['rop_funding_bps'],
                    });
                })->description(fn (User $record): ?string => FinancialAgreementDisplay::forUser($record)['source'] ?? null),
            TextColumn::make('agreement_funding')->label('Источники финансирования')
                ->visible(FinancialAgreementDisplay::isOwner(...))->wrap()
                ->state(function (User $record): string {
                    $terms = FinancialAgreementDisplay::forUser($record);
                    if (! ($terms['available'] ?? false)) {
                        return 'Не определены';
                    }
                    if (in_array($record->role, ['owner', 'rop'], true)) {
                        return 'SFP · до внутреннего распределения';
                    }
                    if ($terms['owner_funding_bps'] + $terms['rop_funding_bps'] === 0) {
                        return 'Без внутренней комиссии';
                    }

                    return 'Доля владельца · '.$terms['owner_name'].' — '.FinancialAgreementDisplay::percent($terms['owner_funding_bps'])
                        .'; доля РОП · '.$terms['rop_name'].' — '.FinancialAgreementDisplay::percent($terms['rop_funding_bps']);
                }),
            TextColumn::make('agreement_effective')->label('Действует с')
                ->visible(FinancialAgreementDisplay::isOwner(...))
                ->state(fn (User $record): string => (FinancialAgreementDisplay::forUser($record)['effective_from'] ?? null)?->format('d.m.Y H:i') ?? 'Дата не зафиксирована'),
        ])->pushRecordActions([FinancialTermsActions::individualAgreement()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageUsers::route('/')];
    }
}
