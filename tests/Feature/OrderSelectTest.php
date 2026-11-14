<?php

use App\Filament\OrderSelect;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

it('finds orders by customer, short or technical number, and any item', function () {
    [, , , $order] = crmScenario();
    $order->client->update(['name' => 'SMZ']);
    $order->items()->first()->update(['name' => 'Каталоги', 'quantity' => 500, 'price_minor' => 200]);
    $label = "№ {$order->id} · SMZ · Каталоги, 500 шт. · Стоимость заказа: 1 000 сум";
    foreach (['SMZ', 'Ката', '№ '.$order->id, $order->number] as $search) {
        expect(OrderSelect::options($search))->toBe([$order->id => $label]);
    }
    foreach (['Баннеры', 'Плакаты'] as $name) {
        $order->items()->create(['name' => $name, 'quantity' => 1, 'price_minor' => 50]);
    }
    expect(OrderSelect::options('Плакаты')[$order->id])->toBe("№ {$order->id} · SMZ · Каталоги + ещё 2 позиции · Стоимость заказа: 1 001 сум");
});

it('formats an empty order and loads choices without per-order queries', function () {
    [, , , $first] = crmScenario();
    $empty = Order::create(['number' => 'EMPTY', 'client_id' => $first->client_id]);
    DB::enableQueryLog();
    try {
        $labels = OrderSelect::options();
        $queries = count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }
    expect($queries)->toBe(3)->and($labels[$empty->id])->toContain('Без позиций · Стоимость заказа: 0 сум');
    $empty->setRelation('client', null);
    expect(OrderSelect::label($empty))->toContain('Клиент не указан · Без позиций');
});

it('restricts order choices even when searching by the exact foreign order number', function () {
    [, , , $order] = crmScenario();
    $this->actingAs(User::factory()->create(['role' => 'designer', 'active' => true]));
    foreach (['', $order->number, (string) $order->id, $order->client->name, 'позиция'] as $search) {
        expect(OrderSelect::options($search))->toBe([]);
    }
});

it('posts a transfer against the ID returned by the order selector', function () {
    [, , , $order, $input] = crmScenario();
    $input['order_id'] = array_key_first(OrderSelect::options($order->number));
    Livewire::test(ManagePayments::class)->callAction('create', data: $input)->assertHasNoFormErrors();
    $payment = Payment::sole();
    expect($payment->order_id)->toBe($order->id)->and($payment->amount_minor)->toBe(1000000000)
        ->and((int) $payment->accruals()->where('payer', 'SFP')->sum('amount_minor'))->toBe(100000000);
});
