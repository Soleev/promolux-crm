<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\CrmForms;
use App\Filament\CrmTableUi;
use App\Filament\Resources\Orders\OrderOverview;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Widgets\OrderStats;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ManageOrders extends ManageRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        $counts = OrderOverview::counts();
        $tabs = ['all' => Tab::make('Все')->badge(array_sum($counts))];
        foreach (CrmForms::orderStatuses() as $status => $label) {
            $tabs[$status] = Tab::make($label)
                ->badge($counts[$status] ?? 0)
                ->badgeColor(CrmTableUi::color($status))
                ->modifyQueryUsing(fn ($query) => $query->where('status', $status));
        }

        return $tabs;
    }

    protected function getHeaderWidgets(): array
    {
        return [OrderStats::class];
    }

    protected function getHeaderActions(): array
    {
        return [static::getResource()::createAction()];
    }
}
