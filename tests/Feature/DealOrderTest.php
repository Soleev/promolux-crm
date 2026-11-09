<?php

use App\Filament\Resources\Deals\Pages\ManageDeals;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Order;
use App\Models\User;
use App\Services\CrmWriter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

function dealWithProductionOrder(User $manager): array
{
    $client = Client::create(['name' => 'Тестовый клиент', 'manager_id' => $manager->id]);
    $deal = Deal::create(['title' => 'Тестовые визитки', 'client_id' => $client->id, 'manager_id' => $manager->id, 'status' => 'won', 'currency' => 'UZS', 'items' => []]);
    $order = Order::create(['number' => 'PL-LINKED-TEST', 'deal_id' => $deal->id, 'client_id' => $client->id, 'manager_id' => $manager->id, 'status' => 'production', 'currency' => 'UZS']);

    return [$deal, $order];
}

it('shows independent production status and opens the linked order in both deal views', function (string $view) {
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($manager);
    [$deal, $order] = dealWithProductionOrder($manager);
    $before = $deal->refresh()->getRawOriginal();
    $page = Livewire::test(ManageDeals::class)->set('viewMode', $view)
        ->assertSee('Согласовано')->assertSee('Производство заказа')->assertSee('Производство')
        ->assertTableActionHidden('convert', $deal)->assertTableActionVisible('openOrder', $deal)
        ->mountTableAction('openOrder', $deal)
        ->assertMountedActionModalSee($order->number)->assertMountedActionModalSee('Производство');
    expect($deal->fresh()->getRawOriginal())->toBe($before);
    expect($deal->order->id)->toBe($order->id);
    expect($order->fresh()->status)->toBe('production');
    expect(app(CrmWriter::class)->convert($deal)->id)->toBe($order->id);
    expect(app(CrmWriter::class)->convert($deal)->id)->toBe($order->id);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('accruals', 0);
    $order->update(['status' => 'ready']);
    Livewire::test(ManageDeals::class)->set('viewMode', $view)->assertSee('Готов')->assertSee('Согласовано');
})->with(['table', 'kanban']);

it('keeps conversion available only for an agreed deal without an order', function () {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $this->actingAs($owner);
    $client = Client::create(['name' => 'Клиент']);
    $deal = Deal::create(['title' => 'Без заказа', 'client_id' => $client->id, 'status' => 'won', 'currency' => 'UZS', 'items' => []]);
    Livewire::test(ManageDeals::class)->set('viewMode', 'kanban')->assertSee('Заказ не создан')
        ->assertTableActionVisible('convert', $deal)->assertTableActionHidden('openOrder', $deal)
        ->callTableAction('convert', $deal)->assertHasNoTableActionErrors()
        ->assertTableActionHidden('convert', $deal)->assertTableActionVisible('openOrder', $deal);
    $this->assertDatabaseCount('orders', 1);
});

it('does not expose a linked order reassigned to another manager', function (string $view) {
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $other = User::factory()->create(['role' => 'manager', 'active' => true]);
    [$deal, $order] = dealWithProductionOrder($manager);
    $order->update(['manager_id' => $other->id]);
    $this->actingAs($manager);
    Livewire::test(ManageDeals::class)->set('viewMode', $view)->assertSee('Нет доступа к заказу')->assertDontSee($order->number)
        ->assertTableActionHidden('openOrder', $deal)->assertTableActionHidden('convert', $deal)
        ->call('mountAction', 'openOrder', [], ['table' => true, 'recordKey' => (string) $deal->id])
        ->assertActionNotMounted('openOrder');
    $this->actingAs($other);
    Livewire::test(ManageDeals::class)->set('viewMode', $view)->assertDontSee('Тестовые визитки')
        ->call('mountAction', 'openOrder', [], ['table' => true, 'recordKey' => (string) $deal->id])
        ->assertActionNotMounted('openOrder');
    $this->assertDatabaseCount('orders', 1);
})->with(['table', 'kanban']);
