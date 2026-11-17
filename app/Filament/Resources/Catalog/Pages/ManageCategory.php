<?php

namespace App\Filament\Resources\Catalog\Pages;

use App\Filament\Resources\Catalog\CategoryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCategory extends ManageRecords
{
    protected static string $resource = CategoryResource::class;
}
