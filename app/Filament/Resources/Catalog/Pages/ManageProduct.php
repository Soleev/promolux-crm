<?php

namespace App\Filament\Resources\Catalog\Pages;

use App\Filament\Resources\Catalog\ProductResource;
use Filament\Resources\Pages\ManageRecords;

class ManageProduct extends ManageRecords
{
    protected static string $resource = ProductResource::class;
}
