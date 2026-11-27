<?php

namespace App\Filament\Resources\Refunds\Pages;

use App\Filament\Resources\Refunds\RefundActions;
use App\Filament\Resources\Refunds\RefundResource;
use Filament\Resources\Pages\ListRecords;

class ManageRefunds extends ListRecords
{
    protected static string $resource = RefundResource::class;

    protected function getHeaderActions(): array
    {
        return [RefundActions::request()];
    }
}
