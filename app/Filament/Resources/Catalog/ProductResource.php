<?php

namespace App\Filament\Resources\Catalog;

use App\Models\CatalogProduct;

class ProductResource extends CatalogResource
{
    protected static ?string $model = CatalogProduct::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $modelLabel = 'Продукция или услуга';

    protected static ?string $pluralModelLabel = 'Продукция и услуги';

    protected static ?string $slug = 'catalog/products';

    public static function getPages(): array
    {
        return ['index' => Pages\ManageProduct::route('/')];
    }
}
