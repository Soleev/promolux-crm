<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\CrmForms;
use App\Filament\CrmTableUi;
use App\Filament\Resources\Deals\DealResource;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Livewire\Attributes\Locked;

class ManageDeals extends ManageRecords
{
    protected static string $resource = DealResource::class;

    #[Locked]
    public string $viewMode = 'table';

    public function getTabs(): array
    {
        $counts = DealResource::getEloquentQuery()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $tabs = ['all' => Tab::make('Все')->badge($counts->sum())->extraAttributes(['class' => 'plx-deal-tab plx-deal-tab-primary'])];
        $statuses = CrmForms::dealStatuses();
        $lost = $statuses['lost'] ?? null;
        unset($statuses['lost']);
        if ($lost !== null) {
            $statuses['lost'] = $lost;
        }
        foreach ($statuses as $status => $label) {
            $color = CrmTableUi::color($status);
            $tabs[$status] = Tab::make($label)->badge($counts->get($status, 0))->badgeColor($color)
                ->extraAttributes(['class' => 'plx-deal-tab plx-deal-tab-'.$color])
                ->modifyQueryUsing(fn ($query) => $query->where('status', $status));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [
            static::getResource()::createAction(),
        ];
    }
}
