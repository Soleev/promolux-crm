<?php

namespace App\Filament\Resources\CashReceipts\Pages;

use App\Filament\Resources\CashReceipts\CashOverview;
use App\Filament\Resources\CashReceipts\CashReceiptResource;
use App\Filament\Resources\CashReceipts\Widgets\CashStats;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ManageCashReceipts extends ListRecords
{
    protected static string $resource = CashReceiptResource::class;

    public function getTitle(): string
    {
        return auth()->user()->role === 'cashier' ? 'Приём наличных в кассу SFP' : 'Передача наличных';
    }

    public function getSubheading(): ?string
    {
        return auth()->user()->role !== 'cashier' ? 'Контроль передачи наличных от сотрудников в SFP.' : 'Подтвердите получение наличных от сотрудников. После приёма сумма будет учтена в SFP.';
    }

    public function getTabs(): array
    {
        $query = CashReceiptResource::getEloquentQuery();

        return [
            'pending' => Tab::make('К приёму')->badge((clone $query)->where('status', 'pending')->count())->modifyQueryUsing(fn ($query) => $query->where('status', 'pending')),
            'today' => Tab::make('Принятые сегодня')->modifyQueryUsing(fn ($query) => CashOverview::acceptedToday($query)),
            'history' => Tab::make('История'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [CashStats::class];
    }

    protected function getHeaderActions(): array
    {
        return [CashReceiptResource::collectionAction()];
    }
}
