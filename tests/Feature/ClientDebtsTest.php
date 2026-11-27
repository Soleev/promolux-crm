<?php

use App\Filament\Pages\ClientDebts;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Services\CashDesk;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

it('follows the signed customer balance from collection through handover, full payment and reversal', function () {
    [$owner, , $manager, $order, $input] = crmScenario();
    $page = Livewire::test(ClientDebts::class)->assertCanSeeTableRecords([$order])->assertSee('10 000 000 сум');
    $ledger = app(Ledger::class);
    $ledger->payment(array_replace($input, ['amount' => '1000000']));
    $cash = app(CashDesk::class)->collect(array_replace($input, ['amount' => '2000000', 'collected_at' => today()->toDateString()]));
    $page->call('$refresh')->assertViewHas('totals', fn ($v) => (int) $v->sole()->amount === 700000000);
    $this->actingAs(User::factory()->create(['role' => 'cashier', 'active' => true]));
    $ledger->acceptCash($cash, today()->toDateString());
    $this->actingAs($owner);
    $page->call('$refresh')->assertViewHas('totals', fn ($v) => (int) $v->sole()->amount === 700000000);
    $last = $ledger->payment(array_replace($input, ['amount' => '7000000', 'idempotency_key' => (string) Str::uuid()]));
    $page->call('$refresh')->assertSee('Нет задолженностей')->assertViewHas('orderCount', 0);
    $ledger->payment(array_replace($input, ['amount' => '1.01', 'idempotency_key' => (string) Str::uuid()]));
    $page->call('$refresh')->assertCanNotSeeTableRecords([$order]);
    $ledger->reversePayment($last, 'Проверка сторно оплаты');
    $page->call('$refresh')->assertSee('6 999 998,99 сум')->assertViewHas('clientCount', 1);
    $order->update(['status' => 'cancelled']);
    $page->call('$refresh')->assertCanNotSeeTableRecords([$order])->assertViewHas('clientCount', 0);
});

it('applies current order permissions to debts, filters, search, totals and Livewire requests', function () {
    [$owner, $rop, $manager, $order] = crmScenario();
    $other = User::factory()->create(['role' => 'manager', 'active' => true]);
    $client = Client::create(['manager_id' => $other->id, 'name' => 'Скрытый клиент']);
    $foreign = Order::create(['number' => 'PRIVATE-55', 'client_id' => $client->id, 'manager_id' => $other->id, 'currency' => 'UZS', 'status' => 'ready']);
    $foreign->items()->create(['name' => 'Плакат', 'quantity' => 1, 'price_minor' => 50000]);
    foreach ([$owner, $rop] as $viewer) {
        $this->actingAs($viewer);
        Livewire::test(ClientDebts::class)->assertViewHas('orderCount', 2)->assertViewHas('clientCount', 2)
            ->sortTable('client_name', 'asc')->searchTable('Скрытый клиент')->assertViewHas('orderCount', 1)
            ->assertCanSeeTableRecords([$foreign])->searchTable('№ '.$foreign->id)->assertViewHas('orderCount', 1)
            ->searchTable('')->filterTable('amount', 'small')->assertCanSeeTableRecords([$foreign])->assertCanNotSeeTableRecords([$order])
            ->assertViewHas('totals', fn ($v) => (int) $v->sole()->amount === 50000);
    }
    $this->actingAs($manager);
    $page = Livewire::test(ClientDebts::class)->assertCanSeeTableRecords([$order])->assertViewHas('orderCount', 1)
        ->assertDontSee($other->name)->assertDontSee($client->name);
    $page->searchTable($foreign->number)->assertViewHas('orderCount', 0)->searchTable('')
        ->filterTable('manager_id', $other->id)->assertViewHas('clientCount', 0)->removeTableFilters();
    $order->update(['manager_id' => $other->id]);
    $page->call('$refresh')->assertViewHas('orderCount', 0)->assertCanNotSeeTableRecords([$order]);
    foreach (['designer', 'cashier'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role, 'active' => true]));
        $this->get('/crm/client-debts')->assertForbidden();
        expect(ClientDebts::canAccess())->toBeFalse();
        $page->call('$refresh')->assertForbidden();
        $this->actingAs($manager);
        $page = Livewire::test(ClientDebts::class);
    }
});

it('paginates hundreds of orders with bounded queries, distinct clients and separate currency totals', function () {
    [, , $manager, $first] = crmScenario();
    $make = function (int $n, string $currency = 'UZS') use ($manager, $first) {
        return Order::create(['number' => 'BATCH-'.$n, 'manager_id' => $manager->id, 'client_id' => $first->client_id, 'status' => 'ready', 'currency' => $currency]);
    };
    $empty = $make(0);
    $measure = function () {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $page = Livewire::test(ClientDebts::class);
            $count = collect(DB::getQueryLog())->filter(fn ($row) => str_contains($row['query'], 'orders'))->count();

            return [$page, $count];
        } finally {
            DB::disableQueryLog();
        }
    };
    [, $baseline] = $measure();
    foreach (range(1, 201) as $n) {
        $order = $make($n, $n === 201 ? 'EUR' : 'UZS');
        $order->items()->create(['name' => 'Тираж', 'quantity' => 2, 'price_minor' => $n]);
    }
    [$page, $queries] = $measure();
    expect($queries)->toBe($baseline);
    $page->assertViewHas('orderCount', 202)->assertViewHas('clientCount', 1)->assertCanNotSeeTableRecords([$empty])
        ->assertViewHas('totals', fn ($v) => (int) $v->keyBy('currency')['UZS']->amount === 1000040200 && (int) $v->keyBy('currency')['EUR']->amount === 402)
        ->call('gotoPage', 2)->assertCanNotSeeTableRecords([$first])->assertViewHas('orderCount', 202)
        ->filterTable('status', 'ready')->assertViewHas('orderCount', 201)
        ->searchTable('BATCH-201')->assertCanSeeTableRecords([$order])->assertViewHas('orderCount', 1);
});
