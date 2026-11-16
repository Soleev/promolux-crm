<?php

namespace App\Filament\Pages;

use App\Filament\CrmForms;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CrmAccess;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientDebts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Долги клиентов';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.client-debts';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->active && in_array($user->role, ['owner', 'rop', 'manager'], true);
    }

    private function debts(): Builder
    {
        abort_unless(static::canAccess(), 403);
        $totals = CrmAccess::query(Order::class)->withPaymentTotals()->addSelect([
            'client_name' => CrmAccess::query(Client::class)->select('name')->whereColumn('clients.id', 'orders.client_id'),
            'product' => OrderItem::select('name')->whereColumn('order_id', 'orders.id')->orderBy('id')->limit(1),
        ]);
        // Cast before subtraction: MariaDB may return unsigned amounts and an overpayment is valid.
        $balances = Order::query()->fromSub($totals, 'orders')->select('orders.*')
            ->selectRaw('CAST(COALESCE(ordered_minor, 0) AS DECIMAL(32,0)) - COALESCE(confirmed_minor, 0) - COALESCE(pending_minor, 0) AS debt_minor');

        return Order::query()->fromSub($balances, 'orders')->where('debt_minor', '>', 0);
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => $this->debts())->columns([
            Split::make([
                Stack::make([
                    TextColumn::make('client_name')->label('Клиент')->weight('bold')->placeholder('Клиент не указан')->wrap()->searchable()->sortable(),
                    TextColumn::make('number')->label('Заказ')->formatStateUsing(fn (Order $record) => '№ '.$record->id)
                        ->tooltip(fn (Order $record) => $record->number)
                        ->searchable(query: function (Builder $query, string $search): Builder {
                            return $query->where(function (Builder $match) use ($search) {
                                $match->whereLike('number', '%'.$search.'%');
                                if (preg_match('/^№?\s*(\d+)$/u', trim($search), $id)) {
                                    $match->orWhere('id', $id[1]);
                                }
                            });
                        }),
                    TextColumn::make('product')->state(fn (Order $record) => $record->product ?: ($record->description ?: 'Без позиций'))->wrap()->limit(90),
                    TextColumn::make('manager.name')->prefix('Ответственный: ')->placeholder('Без ответственного')->wrap(),
                    TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => CrmForms::orderStatuses()[$state] ?? $state),
                ])->space(1),
                ViewColumn::make('debt_minor')->label('Остаток долга')->sortable()->view('filament.order-payment', ['emphasizeDebt' => true]),
            ])->from('md'),
        ])->filters([
            SelectFilter::make('manager_id')->label('Ответственный')->options(fn () => User::whereIn('id', CrmAccess::query(Order::class)->select('manager_id'))->orderBy('name')->pluck('name', 'id')),
            SelectFilter::make('status')->label('Статус заказа')->options(CrmForms::orderStatuses()),
            SelectFilter::make('amount')->label('Долг в валюте заказа')->options([
                'small' => 'До 1 млн', 'medium' => 'От 1 до 10 млн', 'large' => 'От 10 млн',
            ])->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                'small' => $query->where('debt_minor', '<', 100000000),
                'medium' => $query->whereBetween('debt_minor', [100000000, 999999999]),
                'large' => $query->where('debt_minor', '>=', 1000000000),
                default => $query,
            }),
        ])->splitSearchTerms(false)->searchPlaceholder('Клиент или номер заказа')
            ->defaultSort('debt_minor', 'desc')->paginationPageOptions([10, 25, 50])
            ->emptyStateHeading('Нет задолженностей')
            ->emptyStateDescription('По выбранным условиям нет заказов с положительным остатком.')
            ->recordActions([Action::make('order')->label('Заказ и оплаты')->url(fn (Order $record) => OrderResource::getUrl('index', [
                'tableAction' => 'details', 'tableActionRecord' => $record->id,
            ]))]);
    }

    protected function getViewData(): array
    {
        $query = $this->getFilteredTableQuery()->toBase();
        $totals = (clone $query)->selectRaw('currency, SUM(debt_minor) AS amount, COUNT(*) AS orders_count')
            ->groupBy('currency')->orderBy('currency')->get();

        return [
            'totals' => $totals,
            'orderCount' => $totals->sum('orders_count'),
            'clientCount' => $query->distinct()->count('client_id'),
        ];
    }
}
