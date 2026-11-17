<?php

use App\Services\BannerPrice;

it('prices square metres exactly with one half-up rounding per line', function ($w, $h, $copies, $rate, $area, $total) {
    expect(BannerPrice::calculate($w, $h, $copies, $rate))->toBe(['area' => $area, 'total' => $total]);
})->with([
    ['3', '2', 2, 12500, '12.0000', 150000],
    ['1,2', '2', 1, 12500, '2.4000', 30000],
    ['2.4', '2.4', 1, 333, '5.7600', 1918],
    ['0.01', '0.50', 1, 100, '0.0050', 1],
]);
