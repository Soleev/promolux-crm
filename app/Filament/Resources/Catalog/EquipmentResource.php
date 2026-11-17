<?php

namespace App\Filament\Resources\Catalog;

use App\Models\CatalogEquipment;

class EquipmentResource extends CatalogResource
{
    protected static ?string $model = CatalogEquipment::class;

    protected static ?string $modelLabel = 'Оборудование';

    protected static ?string $pluralModelLabel = 'Оборудование';

    protected static ?string $slug = 'catalog/equipment';

    public static function getPages(): array
    {
        return ['index' => Pages\ManageEquipment::route('/')];
    }
}
