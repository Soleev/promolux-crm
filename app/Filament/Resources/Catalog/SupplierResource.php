<?php

namespace App\Filament\Resources\Catalog;

use App\Models\CatalogSupplier;

class SupplierResource extends CatalogResource
{
    protected static ?string $model = CatalogSupplier::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $modelLabel = 'Исполнитель';

    protected static ?string $pluralModelLabel = 'Исполнители';

    protected static ?string $slug = 'catalog/suppliers';

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSupplier::route('/')];
    }
}
