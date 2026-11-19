<?php

namespace App\Filament\Resources\Orders\Widgets;

use App\Filament\Resources\Orders\OrderOverview;
use App\Services\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrderStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $counts = OrderOverview::counts();
        $foreign = OrderOverview::foreignOpenCount();

        return [
            Stat::make('Всего заказов', array_sum($counts)),
            Stat::make('Открытые заказы', array_sum(array_intersect_key($counts, array_flip(OrderOverview::OPEN)))),
            Stat::make('Общая стоимость открытых заказов', Money::display(OrderOverview::openValue()))
                ->description($foreign ? "Только UZS. Не включены заказы в другой валюте: {$foreign}" : 'Полная стоимость позиций · UZS'),
        ];
    }
}
