<?php

namespace App\Services;

use App\Models\CatalogProduct;
use App\Models\Deal;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogLines
{
    public static function normalize(array $lines, ?Model $record): array
    {
        $existing = match (true) {
            $record instanceof Deal => $record->items ?? [],
            $record instanceof Order => $record->items()->get()->toArray(),
            default => [],
        };
        $history = [];
        foreach ($existing as $line) {
            if ($snapshot = $line['catalog_snapshot'] ?? null) {
                $history[$snapshot['key']] = $line;
            }
        }
        $result = [];
        $sum = 0;
        foreach ($lines as $line) {
            $key = $line['line_key'] ?? null;
            $saved = null;
            if ($key) {
                $saved = $history[$key] ?? self::invalid('Позиция изменилась. Откройте продажу повторно.');
                unset($history[$key]);
                if ((int) $saved['product_id'] !== (int) ($line['product_id'] ?? 0)) {
                    self::invalid('Для замены продукции добавьте новую строку.');
                }
            }
            $price = Money::parse($line['price']);
            if ($price > 100000000000) {
                self::invalid('Цена позиции слишком велика.');
            }
            $row = empty($line['product_id'])
                ? ['name' => $line['name'], 'quantity' => (int) $line['quantity'], 'price_minor' => $price]
                : self::product($line, $price, $saved);
            $sum += $row['quantity'] * $row['price_minor'];
            if ($sum > 99999999999999) {
                self::invalid('Сумма заказа слишком велика.');
            }
            $result[] = $row;
        }

        return $result;
    }

    private static function product(array $line, int $price, ?array $saved): array
    {
        $prior = $saved['catalog_snapshot'] ?? null;
        abort_unless(in_array(auth()->user()->role, ['owner', 'rop', 'manager'], true) || ($prior && auth()->user()->role === 'designer'), 403);
        $product = CatalogProduct::with('category')->lockForUpdate()->find($line['product_id']);
        if (! $product || (! $prior && (! $product->active || ! $product->category->active))) {
            self::invalid('Выберите активную продукцию.');
        }
        $snapshot = $prior ?? [
            'key' => (string) Str::uuid(), 'code' => $product->code, 'unit' => $product->unit,
            'category_id' => $product->category_id, 'category' => $product->category->name,
            'fields' => $product->fields ?? [], 'area_pricing' => $product->area_pricing,
        ];
        $supplier = (int) ($line['supplier_id'] ?? $product->supplier_id);
        if (! $prior || $supplier !== (int) $prior['supplier_id']) {
            $allowed = $product->suppliers()->find($supplier);
            if (! $allowed || ! $product->active || ! $product->category->active) {
                self::invalid('Выберите допустимого исполнителя.');
            }
            $snapshot['supplier_id'] = $allowed->id;
            $snapshot['supplier'] = $allowed->name;
        }
        $attributes = $line['characteristics'] ?? [];
        if (array_diff_key($attributes, $snapshot['fields'])) {
            self::invalid('Недопустимая характеристика продукции.');
        }
        $snapshot['characteristics'] = array_filter($attributes, fn ($value) => filled($value));
        $snapshot['rate_minor'] = $price;
        $quantity = (int) $line['quantity'];
        if ($snapshot['area_pricing']) {
            $area = BannerPrice::calculate($line['width'] ?? '', $line['height'] ?? '', $quantity, $price);
            $snapshot = array_merge($snapshot, ['width' => $line['width'], 'height' => $line['height'], 'copies' => $quantity, 'area' => $area['area']]);
            $quantity = 1;
            $price = $area['total'];
        }

        return ['name' => $saved['name'] ?? $product->name, 'quantity' => $quantity, 'price_minor' => $price,
            'product_id' => $product->id, 'catalog_snapshot' => $snapshot];
    }

    public static function edit(array $row): array
    {
        $s = $row['catalog_snapshot'] ?? [];
        return ['name' => $row['name'], 'quantity' => $s['copies'] ?? $row['quantity'], 'price' => Money::format($s['rate_minor'] ?? $row['price_minor'])]
            + ($s ? ['product_id' => $row['product_id'], 'category_id' => $s['category_id'], 'supplier_id' => $s['supplier_id'],
                'line_key' => $s['key'], 'saved_supplier_id' => $s['supplier_id'], 'saved_supplier_name' => $s['supplier'], 'fields' => $s['fields'], 'area_pricing' => $s['area_pricing'], 'unit' => $s['unit'],
                'width' => $s['width'] ?? null, 'height' => $s['height'] ?? null, 'characteristics' => $s['characteristics']] : []);
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['data.items' => $message]);
    }
}
