<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class Money
{
    public static function parse(string $value): int
    {
        $value = str_replace(',', '.', trim($value));
        if (! preg_match('/^\\d{1,12}(\\.\\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages(['amount' => 'Введите сумму с точностью до двух знаков после запятой.']);
        }
        [$whole,$fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function format(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function display(int $minor): string
    {
        [$whole, $fraction] = explode('.', self::format($minor));
        $grouped = strrev(implode(' ', str_split(strrev(ltrim($whole, '-')), 3)));

        return ($minor < 0 ? '-' : '').$grouped.($fraction === '00' ? '' : ','.$fraction).' сум';
    }

    public static function percent(int $minor, int $bps): int
    {
        return intdiv($minor * $bps + 5000, 10000);
    }
}
