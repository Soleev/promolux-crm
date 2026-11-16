<?php

namespace App\Filament;

use App\Models\Order;
use App\Services\ClientDirectory;
use App\Services\CrmAccess;
use App\Services\Money;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

class OrderSelect
{
    public static function make(bool $required = false): Select
    {
        return Select::make('order_id')->label('Заказ')->required($required)->searchable()->wrapOptionLabels()
            ->options(fn () => self::options())
            ->getSearchResultsUsing(fn (string $search) => self::options($search))
            ->getOptionLabelUsing(function ($value): ?string {
                $order = self::query()->find($value);

                return $order ? self::label($order) : null;
            });
    }

    private static function query(): Builder
    {
        return CrmAccess::query(Order::class)->with([
            'client' => fn ($q) => $q->whereIn('id', ClientDirectory::visible()->select('id')),
            'items' => fn ($q) => $q->orderBy('id'),
        ]);
    }

    public static function options(string $search = ''): array
    {
        $query = self::query();
        $term = trim($search);
        if ($term !== '') {
            $query->where(function (Builder $q) use ($term) {
                $like = '%'.$term.'%';
                $q->whereLike('number', $like)
                    ->orWhereHas('client', fn ($client) => $client
                        ->whereIn('id', ClientDirectory::visible()->select('id'))->whereLike('name', $like))
                    ->orWhereHas('items', fn ($items) => $items->whereLike('name', $like));

                if (preg_match('/^№?\s*(\d+)$/u', $term, $matches)) {
                    $q->orWhere('id', $matches[1]);
                }
            });
        }

        return $query->latest('id')->limit(50)->get()
            ->mapWithKeys(fn (Order $order) => [$order->id => self::label($order)])->all();
    }

    public static function label(Order $order): string
    {
        $client = trim($order->client?->name ?? '') ?: 'Клиент не указан';
        $item = $order->items->first();
        $product = 'Без позиций';
        if ($item) {
            $product = trim($item->name) ?: 'Без наименования';
            $extra = $order->items->count() - 1;
            $product .= $extra ? ' + ещё '.$extra.' '.trans_choice('позиция|позиции|позиций', $extra, [], 'ru')
                : ', '.$item->quantity.' шт.';
        }

        return "№ {$order->id} · {$client} · {$product} · Стоимость заказа: ".Money::display($order->total_minor);
    }
}
