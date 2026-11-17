<?php

namespace App\Filament;

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Services\BannerPrice;
use App\Services\Money;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CatalogItemForm
{
    public static function make(): Repeater
    {
        if (! Schema::hasTable('catalog_products')) {
            return Repeater::make('items')->label('Позиции')->schema([
                TextInput::make('name')->label('Наименование')->required()->maxLength(255),
                TextInput::make('quantity')->label('Количество')->integer()->minValue(1)->maxValue(1000000)->default(1)->required(),
                CrmForms::money('price', 'Цена за единицу'),
            ])->columns(3)->minItems(1)->defaultItems(1)->columnSpanFull();
        }
        $catalog = in_array(auth()->user()?->role, ['owner', 'rop', 'manager'], true);
        return Repeater::make('items')->label('Позиции')->schema([
            Hidden::make('line_key'),
            Hidden::make('saved_supplier_id')->dehydrated(false),
            Hidden::make('saved_supplier_name')->dehydrated(false),
            Hidden::make('fields')->dehydrated(false),
            Hidden::make('area_pricing')->dehydrated(false),
            Hidden::make('unit')->dehydrated(false),
            Select::make('category_id')->label('Категория')->placeholder('Все категории')->visible($catalog)
                ->options(fn () => CatalogCategory::where('active', true)->orderBy('sort')->pluck('name', 'id'))
                ->disabled(fn (Get $get) => (bool) $get('line_key'))->live()->dehydrated(false)
                ->afterStateUpdated(fn (Set $set) => self::select($set, null)),
            Select::make('product_id')->label('Продукция и услуги')->placeholder('Нестандартная позиция')
                ->visible(fn (Get $get) => $catalog || $get('line_key'))
                ->disabled(fn (Get $get) => (bool) $get('line_key'))->dehydrated()->searchable()->live()
                ->getSearchResultsUsing(fn (string $search, Get $get) => self::options($get, $search))
                ->options(fn (Get $get) => self::options($get, ''))
                ->getOptionLabelUsing(fn ($value, Get $get) => $get('line_key') ? $get('name') : CatalogProduct::available()->whereKey($value)->value('name'))
                ->afterStateUpdated(fn ($state, Set $set) => self::select($set, $state)),
            TextInput::make('name')->label('Наименование')->required()->maxLength(255)
                ->readOnly(fn (Get $get) => (bool) $get('product_id')),
            Select::make('supplier_id')->label('Исполнитель')->visible(fn (Get $get) => (bool) $get('product_id'))->required()
                ->options(function (Get $get) {
                    $product = CatalogProduct::available()->find($get('product_id'));
                    $options = $product?->suppliers()->pluck('name', 'id')->all() ?? [];
                    if ($get('line_key')) {
                        $options[(int) $get('saved_supplier_id')] = $get('saved_supplier_name');
                    }
                    return $options;
                })
                ->getOptionLabelUsing(fn ($value) => \App\Models\CatalogSupplier::whereKey($value)->value('name')),
            Group::make()->schema(function (Get $get) {
                $fields = [];
                foreach ((array) $get('fields') as $index => $label) {
                    $fields[] = TextInput::make('characteristics.'.$index)->label($label)->maxLength(2000);
                }
                return $fields;
            })->columns(2)->columnSpanFull(),
            ...array_map(fn ($field, $label) => TextInput::make($field)->label($label)->inputMode('decimal')
                ->visible(fn (Get $get) => (bool) $get('area_pricing'))->required()->live(onBlur: true),
                ['width', 'height'], ['Ширина, м', 'Высота, м']),
            TextInput::make('quantity')->label(fn (Get $get) => $get('area_pricing') ? 'Количество экземпляров' : 'Количество'.($get('unit') ? ', '.$get('unit') : ''))
                ->integer()->minValue(1)->maxValue(1000000)->default(1)->required()->live(onBlur: true),
            CrmForms::money('price', 'Цена')->label(fn (Get $get) => $get('area_pricing') ? 'Цена за м²' : 'Цена за единицу')->live(onBlur: true),
            Text::make(fn (Get $get) => self::total($get))->columnSpanFull(),
        ])->columns(2)->minItems(1)->defaultItems(1)->columnSpanFull();
    }

    private static function options(Get $get, string $search): array
    {
        return CatalogProduct::available()->when($get('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->where('search_name', 'like', '%'.mb_strtolower($search).'%')->orderBy('sort')->limit(50)->pluck('name', 'id')->all();
    }

    private static function select(Set $set, mixed $id): void
    {
        $product = $id ? CatalogProduct::available()->find($id) : null;
        foreach (['product_id' => $product?->id, 'name' => $product?->name, 'fields' => $product?->fields,
            'supplier_id' => $product?->supplier_id, 'area_pricing' => $product?->area_pricing, 'unit' => $product?->unit,
            'price' => null, 'quantity' => 1, 'width' => null, 'height' => null, 'characteristics' => []] as $field => $value) {
            $set($field, $value);
        }
    }

    private static function total(Get $get): string
    {
        if (! is_numeric($get('quantity')) || $get('quantity') < 1 || $get('quantity') > 1000000 || blank($get('price'))) {
            return '';
        }
        try {
            $rate = Money::parse((string) $get('price'));
            if ($get('area_pricing')) {
                $area = BannerPrice::calculate((string) $get('width'), (string) $get('height'), (int) $get('quantity'), $rate);
                return $area['area'].' м² · '.Money::display($area['total']);
            }
            if ($rate > 100000000000) {
                return '';
            }
            return Money::display($rate * (int) $get('quantity'));
        } catch (ValidationException) {
            return '';
        }
    }
}
