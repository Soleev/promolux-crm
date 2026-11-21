<?php

namespace App\Filament\Resources\Catalog;

use App\Filament\CrmForms;
use App\Models\CatalogCategory;
use App\Models\CatalogEquipment;
use App\Models\CatalogProduct;
use App\Models\CatalogSupplier;
use App\Models\CatalogTechnology;
use App\Services\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as DatabaseSchema;

abstract class CatalogResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static bool $hasTitleCaseModelLabel = false;

    private const UNITS = ['шт.', 'тираж', 'м²', 'пог. м', 'лист', 'страница', 'комплект', 'набор', 'услуга', 'проект'];

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->active && (in_array($user->role, ['owner', 'rop'], true) ||
            ($user->role === 'manager' && in_array(static::$model, [CatalogCategory::class, CatalogProduct::class], true)))
            && DatabaseSchema::hasTable('catalog_products');
    }

    private static function writable(): bool
    {
        $user = auth()->user();

        return $user?->active &&
            ($user->role === 'owner' || ($user->role === 'rop' && in_array(static::$model, [CatalogCategory::class, CatalogProduct::class, CatalogSupplier::class], true)));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        if (! static::canViewAny()) {
            return $query->whereRaw('1 = 0');
        }
        if (auth()->user()->role === 'manager') {
            return static::$model === CatalogProduct::class ? $query->available() : $query->where('active', true);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        $fields = [
            TextInput::make('name')->label('Название')->required()->maxLength(255),
            TextInput::make('code')->label('Системный код')->required()->alphaDash()->unique(ignoreRecord: true)
                ->disabledOn('edit')->maxLength(100),
        ];
        if (static::$model === CatalogProduct::class) {
            $fields = [...$fields,
                self::dictionary('category_id', 'Категория', CatalogCategory::class)->required(),
                Select::make('type')->label('Тип')->options(['product' => 'Товар', 'work' => 'Работа', 'service' => 'Услуга'])->required(),
                Textarea::make('description')->label('Описание')->maxLength(5000),
                Select::make('unit')->label('Единица измерения')->options(array_combine(self::UNITS, self::UNITS))->live()->required(),
                self::dictionary('supplier_id', 'Основной исполнитель', CatalogSupplier::class)->required(fn (Get $get) => (bool) $get('active')),
                self::dictionary('alternative_ids', 'Допустимые альтернативные исполнители', CatalogSupplier::class)->multiple(),
                self::dictionary('technology_id', 'Технология', CatalogTechnology::class),
                self::dictionary('equipment_ids', 'Оборудование', CatalogEquipment::class)->multiple(),
                CrmForms::money('reference_minor', 'Справочная цена')->required(false)
                    ->formatStateUsing(fn ($state) => $state === null ? null : Money::format((int) $state))
                    ->dehydrateStateUsing(fn ($state) => blank($state) ? null : Money::parse((string) $state)),
                Toggle::make('negotiable')->label('Договорная цена')->default(true),
                Toggle::make('area_pricing')->label('Расчёт по ширине и высоте')->disabled(fn (Get $get) => $get('unit') !== 'м²')
                    ->dehydrated()->dehydrateStateUsing(fn ($state, Get $get) => $get('unit') === 'м²' && $state),
                TagsInput::make('fields')->label('Характеристики позиции')->rules(['array', 'max:40'])->nestedRecursiveRules(['string', 'max:100']),
            ];
        }
        if (static::$model === CatalogCategory::class) {
            $fields[] = self::dictionary('parent_id', 'Родительская категория', CatalogCategory::class)
                ->rules([fn (?Model $record) => function ($attribute, $value, $fail) use ($record) {
                    $seen = [$record?->id];
                    while ($value) {
                        if (in_array((int) $value, $seen, true)) {
                            $fail('Категории не могут образовывать цикл.');

                            return;
                        }
                        $seen[] = (int) $value;
                        $value = CatalogCategory::whereKey($value)->value('parent_id');
                    }
                }]);
        }
        if (in_array(static::$model, [CatalogCategory::class, CatalogProduct::class], true)) {
            $fields[] = Toggle::make('active')->label('Активно')->live()->default(static::$model === CatalogCategory::class);
            $fields[] = TextInput::make('sort')->label('Сортировка')->integer()->minValue(0)->maxValue(1000000)->default(0)->required();
        }

        return $schema->components($fields)->columns(2);
    }

    private static function dictionary(string $field, string $label, string $model): Select
    {
        return Select::make($field)->label($label)->searchable()->options(fn () => $model::orderBy('name')->pluck('name', 'id'));
    }

    public static function table(Table $table): Table
    {
        $product = static::$model === CatalogProduct::class;
        $columns = [
            TextColumn::make('name')->label('Название')->wrap()->searchable(query: $product
                ? fn (Builder $query, string $search) => $query->where('search_name', 'like', '%'.mb_strtolower($search).'%')
                : null)->sortable(),
            TextColumn::make('code')->label('Код')->searchable(),
        ];
        $filters = [];
        if ($product) {
            $columns[] = TextColumn::make('category.name')->label('Категория');
            $columns[] = TextColumn::make('supplier.name')->label('Исполнитель');
            $filters[] = SelectFilter::make('category_id')->label('Категория')->options(fn () => CatalogCategory::orderBy('sort')->pluck('name', 'id'));
        }
        if ($product || static::$model === CatalogCategory::class) {
            $columns[] = TextColumn::make('active')->label('Статус')->badge()->formatStateUsing(fn ($state) => $state ? 'Активно' : 'Черновик');
            $filters[] = SelectFilter::make('active')->label('Статус')->options([1 => 'Активно', 0 => 'Черновик']);
            $table->defaultSort('sort');
        }

        return $table->columns($columns)->filters($filters)->stackedOnMobile()
            ->headerActions([CreateAction::make()->authorize(fn () => static::writable())])
            ->recordActions([ViewAction::make()->authorize(fn () => static::canViewAny()),
                EditAction::make()->authorize(fn () => static::writable())]);
    }
}
