<?php

namespace App\Filament\Resources\CashReceipts\Pages;

use App\Filament\Resources\CashReceipts\CashReceiptResource;
use Filament\Resources\Pages\ListRecords;

class ManageCashReceipts extends ListRecords
{
    protected static string $resource = CashReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [CashReceiptResource::collectionAction()];
    }
}
