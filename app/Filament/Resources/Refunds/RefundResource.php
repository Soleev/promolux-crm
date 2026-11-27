<?php

namespace App\Filament\Resources\Refunds;

use App\Filament\CrmTableUi;
use App\Filament\Resources\Refunds\Pages\ManageRefunds;
use App\Models\Refund;
use App\Services\Money;
use App\Services\RefundAccess;
use Filament\Actions\ActionGroup;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static ?string $modelLabel = 'Возврат';

    protected static ?string $pluralModelLabel = 'Возвраты';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 25;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static bool $isGloballySearchable = false;

    public const METHODS = ['cash' => 'Наличные', 'bank_transfer' => 'Безналичный возврат'];

    public const STATUSES = [
        Refund::CREATED => 'Создан',
        Refund::APPROVED => 'Согласован',
        Refund::COMPLETED => 'Выполнен',
        Refund::REJECTED => 'Отклонён',
        Refund::CANCELLED => 'Отменён',
    ];

    public static function canViewAny(): bool
    {
        return RefundAccess::canViewAny(auth()->user());
    }

    public static function getEloquentQuery(): Builder
    {
        return RefundAccess::query()->with(['order:id,number,currency', 'client:id,name']);
    }

    public static function displayAmount(string $currency, int $minor): string
    {
        return $currency === 'UZS' ? Money::display($minor) : Money::format($minor).' '.$currency;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('number')->label('Номер возврата')->searchable(),
            TextColumn::make('order.number')->label('Заказ')->searchable(),
            TextColumn::make('client.name')->label('Клиент')->searchable()->wrap(),
            TextColumn::make('amount_minor')->label('Сумма')->alignEnd()
                ->formatStateUsing(fn (Refund $record): string => self::displayAmount($record->currency, $record->amount_minor)),
            TextColumn::make('method')->label('Способ')->formatStateUsing(fn ($state) => self::METHODS[$state]),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn ($state) => self::STATUSES[$state])
                ->color(fn ($state) => CrmTableUi::color($state)),
            TextColumn::make('created_at')->label('Дата заявки')->dateTime('d.m.Y H:i', 'Asia/Tashkent')->sortable(),
            TextColumn::make('executed_at')->label('Дата возврата')->date('d.m.Y')->sortable()->placeholder('—'),
        ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(self::STATUSES),
                SelectFilter::make('method')->label('Способ')->options(self::METHODS),
                CrmTableUi::period('created_at', 'Дата заявки'),
            ])
            ->recordActions([
                ActionGroup::make([
                    RefundActions::details(),
                    RefundActions::approve(),
                    RefundActions::execute(),
                    RefundActions::close(reject: true),
                    RefundActions::close(reject: false),
                    RefundActions::flagIssue(),
                ])->label('Действия')->icon('heroicon-m-ellipsis-vertical'),
            ])
            ->defaultSort('id', 'desc')->stackedOnMobile();
    }

    public static function getPages(): array
    {
        return ['index' => ManageRefunds::route('/')];
    }
}
