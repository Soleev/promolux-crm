<?php

namespace App\Filament\Resources\Catalog;

use App\Models\CatalogTechnology;

class TechnologyResource extends CatalogResource
{
    protected static ?string $model = CatalogTechnology::class;

    protected static ?string $modelLabel = 'Технология';

    protected static ?string $pluralModelLabel = 'Технологии';

    protected static ?string $slug = 'catalog/technologies';

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTechnology::route('/')];
    }
}
