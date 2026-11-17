<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class BannerPrice
{
    /** Dimensions in centimetres give an exact area in ten-thousandths of a square metre. */
    public static function calculate(string $width, string $height, int $copies, int $rate): array
    {
        $area = self::centimetres($width) * self::centimetres($height);
        if ($copies < 1 || $copies > 1000000 || $rate < 0 || $rate > 100000000000) {
            throw ValidationException::withMessages(['data.items' => 'Проверьте количество и цену.']);
        }
        $area *= $copies;
        if ($area > 10000000000) {
            throw ValidationException::withMessages(['data.items' => 'Площадь превышает 1 000 000 м².']);
        }

        // Split before multiplying to keep each intermediate value within signed BIGINT.
        $total = intdiv($area, 10000) * $rate + intdiv(($area % 10000) * $rate + 5000, 10000);

        return ['area' => intdiv($area, 10000).'.'.str_pad((string) ($area % 10000), 4, '0', STR_PAD_LEFT), 'total' => $total];
    }

    private static function centimetres(string $metres): int
    {
        if (! preg_match('/^\d{1,3}([.,]\d{1,2})?$/D', $metres) || ($value = Money::parse($metres)) < 1) {
            throw ValidationException::withMessages(['data.items' => 'Размер в метрах должен быть от 0,01 до 999,99.']);
        }

        return $value;
    }
}
