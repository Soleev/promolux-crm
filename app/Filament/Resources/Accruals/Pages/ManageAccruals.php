<?php

namespace App\Filament\Resources\Accruals\Pages;

use App\Filament\Resources\Accruals\AccrualResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAccruals extends ManageRecords
{
    protected static string $resource = AccrualResource::class;

    protected function getHeaderActions(): array
    {
        return [static::getResource()::createAction()];
    }
}
