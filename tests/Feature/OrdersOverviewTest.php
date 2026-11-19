<?php

use App\Filament\Resources\Orders\OrderOverview;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Orders\Widgets\OrderStats;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

it('scopes order statistics and tabs for every role across the complete portfolio', function (string $role) {
    $actor = User::factory()->create(['role' => $role, 'active' => true]);
    $other = User::factory()->create(['role' => 'manager', 'active' => true]);
    $client = Client::create(['name' => 'Orders stats', 'manager_id' => $actor->id]);
    $own = collect();
    foreach (['new', 'design', 'production', 'ready', 'delivered', 'cancelled'] as $status) {
        foreach (range(1, 6) as $i) {
            $order = Order::create(['number' => "$status-$i", 'client_id' => $client->id, 'manager_id' => $actor->id, 'status' => $status, 'currency' => 'UZS']);
            $order->items()->create(['name' => 'Position', 'quantity' => 3, 'price_minor' => 12345]);
            $own->push($order);
        }
    }
    $foreign = Order::create(['number' => 'USD', 'client_id' => $client->id, 'manager_id' => $actor->id, 'status' => 'new', 'currency' => 'USD']);
    $foreign->items()->create(['name' => 'Foreign', 'quantity' => 10, 'price_minor' => 999999]);
    $hidden = Order::create(['number' => 'OTHER', 'client_id' => $client->id, 'manager_id' => $other->id, 'status' => 'new', 'currency' => 'UZS']);
    $hidden->items()->create(['name' => 'Other', 'quantity' => 2, 'price_minor' => 100]);
    $this->actingAs($actor);
    $all = in_array($role, ['owner', 'rop']);
    if ($role === 'cashier') {
        expect(OrderOverview::counts())->toBe([])->and(OrderOverview::openValue())->toBe(0);
        Livewire::test(ManageOrders::class)->assertForbidden();

        return;
    }
    expect(array_sum(OrderOverview::counts()))->toBe($all ? 38 : 37)
        ->and(OrderOverview::openValue())->toBe(24 * 3 * 12345 + ($all ? 200 : 0))
        ->and(OrderOverview::foreignOpenCount())->toBe(1);
    $page = Livewire::test(ManageOrders::class);
    expect((int) $page->instance()->getTabs()['all']->getBadge())->toBe($all ? 38 : 37);
    foreach (['new', 'design', 'production', 'ready', 'delivered', 'cancelled'] as $status) {
        $page->set('activeTab', $status)
            ->assertCanSeeTableRecords($own->where('status', $status))
            ->assertCanNotSeeTableRecords($own->where('status', '!=', $status));
    }
    $page->set('activeTab', 'all')->searchTable('USD')->assertCanSeeTableRecords([$foreign])->assertCanNotSeeTableRecords($own);
    expect((int) $page->instance()->getTabs()['all']->getBadge())->toBe($all ? 38 : 37);
    Livewire::test(OrderStats::class)->assertSee('Только UZS')->assertSee('Не включены заказы в другой валюте: 1');
})->with(['owner', 'rop', 'manager', 'designer', 'cashier']);

it('keeps the gross open value unchanged by payments and handles an empty portfolio', function () {
    [, , , $order, $input] = crmScenario();
    expect(OrderOverview::openValue())->toBe(1000000000);
    app(Ledger::class)->payment(array_replace($input, ['amount' => '100']));
    expect(OrderOverview::openValue())->toBe(1000000000);
    Livewire::test(ManageOrders::class)->assertSee('9 999 900 сум')->assertSee('100 сум');
    $order->update(['status' => 'delivered']);
    expect(OrderOverview::openValue())->toBe(0);
    $user = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($user);
    Livewire::test(OrderStats::class)->assertSee('0 сум');
    expect(OrderOverview::counts())->toBe([]);
});
