<?php

namespace App\Filament\Resources\Catalog;

use App\Models\CatalogCategory;

class CategoryResource extends CatalogResource
{
    protected static ?string $model = CatalogCategory::class;

    protected static ?string $modelLabel = 'Категория';

    protected static ?string $pluralModelLabel = 'Категории';

    protected static ?string $slug = 'catalog/categories';

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCategory::route('/')];
    }
}
