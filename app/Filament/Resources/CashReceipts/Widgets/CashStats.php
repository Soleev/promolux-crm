<?php

namespace App\Filament\Resources\CashReceipts\Widgets;

use App\Filament\Resources\CashReceipts\CashOverview;
use App\Filament\Resources\CashReceipts\CashReceiptResource;
use App\Services\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CashStats extends StatsOverviewWidget
{
    protected int|array|null $columns = ['default' => 2, '@xl' => 4];

    protected function getStats(): array
    {
        $q = CashReceiptResource::getEloquentQuery();

        return [
            Stat::make('К приёму', (clone $q)->where('status', 'pending')->count())->icon('heroicon-o-wallet')->extraAttributes(['class' => 'plx-stat-violet']),
            Stat::make('Сумма к приёму', Money::display((int) (clone $q)->where('status', 'pending')->sum('amount_minor')))->extraAttributes(['class' => 'plx-stat-blue']),
            Stat::make('Принято сегодня', Money::display((int) CashOverview::acceptedToday(clone $q)->sum('amount_minor')))->extraAttributes(['class' => 'plx-stat-green']),
            Stat::make('Ожидают более 3 дней', (clone $q)->where('status', 'pending')->whereDate('collected_at', '<', today()->subDays(3))->count())->extraAttributes(['class' => 'plx-stat-amber']),
        ];
    }
}
