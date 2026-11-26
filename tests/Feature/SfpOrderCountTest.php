<?php

use Illuminate\Pagination\LengthAwarePaginator;

it('declines the order count in each SFP queue heading', function (int $total, string $totalLabel, int $working, string $workingLabel) {
    $this->app->setLocale('en');

    $this->view('sfp.content', [
        'mode' => 'home', 'total' => $total, 'working' => $working,
        'dueToday' => 0, 'overdue' => 0, 'queue' => [], 'production' => [], 'urgent' => [],
    ])
        ->assertSee('<h2>Очередь производства</h2><span>'.$totalLabel.'</span>', false)
        ->assertSee('<h2>Сейчас в производстве</h2><span>'.$workingLabel.'</span>', false);

    $this->view('sfp.content', [
        'mode' => 'queue', 'total' => $total, 'queue' => new LengthAwarePaginator([], $total, 20),
    ])->assertSee('<h2>Вся очередь производства</h2><span>'.$totalLabel.'</span>', false);
})->with([
    'one and zero' => [1, '1 заказ', 0, '0 заказов'],
    'two and one' => [2, '2 заказа', 1, '1 заказ'],
    'five and two' => [5, '5 заказов', 2, '2 заказа'],
    'teens' => [14, '14 заказов', 11, '11 заказов'],
    'larger counts' => [24, '24 заказа', 21, '21 заказ'],
]);
