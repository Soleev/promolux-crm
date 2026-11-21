<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Livewire\Attributes\Url;

class ManageDeals extends ManageRecords
{
    protected static string $resource = DealResource::class;

    #[Url(as: 'view')]
    public string $viewMode = 'table';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tableView')->label('Таблица')->icon('heroicon-o-table-cells')
                ->color(fn () => $this->viewMode === 'kanban' ? 'gray' : 'primary')

                ->action(fn () => $this->viewMode = 'table'),
            Action::make('kanbanView')->label('Kanban')->icon('heroicon-o-view-columns')
                ->color(fn () => $this->viewMode === 'kanban' ? 'primary' : 'gray')

                ->action(fn () => $this->viewMode = 'kanban'),
            static::getResource()::createAction(),
        ];
    }
}
