<?php

use App\Filament\Resources\Deals\Pages\ManageDeals;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use App\Services\CrmWriter;
use App\Services\DealStages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

function kanbanDeal(User $manager, array $attributes = []): Deal
{
    $client = Client::create(['name' => 'Клиент '.$manager->id, 'manager_id' => $manager->id]);

    return Deal::create($attributes + ['title' => 'Вывеска', 'client_id' => $client->id, 'manager_id' => $manager->id, 'status' => 'new', 'currency' => 'UZS', 'items' => [['name' => 'Печать', 'quantity' => 2, 'price_minor' => 125000050]]]);
}

it('moves a deal through the table action and records the actor and status history', function () {
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $deal = kanbanDeal($manager);
    $this->actingAs($manager);
    Livewire::test(ManageDeals::class)
        ->callTableAction('changeStage', $deal, data: ['status' => 'qualifying', 'expected' => 'new'])
        ->assertHasNoTableActionErrors()->assertSee('Уточнение');
    expect($deal->fresh()->status)->toBe('qualifying');
    $event = Activity::where('subject_type', Deal::class)->where('subject_id', $deal->id)->where('event', 'updated')->sole();
    expect($event->actor_id)->toBe($manager->id);
    expect($event->changes['before']['status'])->toBe('new');
    expect($event->changes['after']['status'])->toBe('qualifying');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
});

it('requires a loss reason and reopens a lost deal only through qualifying', function () {
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $deal = kanbanDeal($manager, ['status' => 'proposal']);
    $this->actingAs($manager);
    $page = Livewire::test(ManageDeals::class)->callTableAction('changeStage', $deal, data: ['status' => 'lost', 'expected' => 'proposal', 'lost_reason' => ''])
        ->assertHasTableActionErrors(['lost_reason' => 'required']);
    expect($deal->fresh()->status)->toBe('proposal');
    Livewire::test(ManageDeals::class)->callTableAction('changeStage', $deal, data: ['status' => 'lost', 'expected' => 'proposal', 'lost_reason' => 'Клиент отложил проект'])
        ->assertHasNoTableActionErrors();
    expect($deal->fresh()->lost_reason)->toBe('Клиент отложил проект');
    expect(fn () => app(DealStages::class)->move($deal->id, 'won', 'lost'))->toThrow(ValidationException::class);
    app(DealStages::class)->move($deal->id, 'qualifying', 'lost');
    expect($deal->fresh()->status)->toBe('qualifying');
    expect($deal->fresh()->lost_reason)->toBeNull();
    expect(Activity::where('subject_type', Deal::class)->where('subject_id', $deal->id)->where('event', 'updated')->first()->changes['after']['lost_reason'])->toBe('Клиент отложил проект');
});

it('prefills a dragged target and rejects a stale stage submission', function () {
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $deal = kanbanDeal($manager);
    $this->actingAs($manager);
    $page = Livewire::test(ManageDeals::class)
        ->call('mountAction', 'changeStage', ['target' => 'proposal', 'expected' => 'new'], ['table' => true, 'recordKey' => (string) $deal->id])
        ->assertTableActionDataSet(['status' => 'proposal', 'expected' => 'new']);
    app(DealStages::class)->move($deal->id, 'estimate', 'new');
    $page->callMountedTableAction()->assertHasErrors(['status']);
    expect($deal->fresh()->status)->toBe('estimate');
});

it('rejects invalid and stale transitions without adding history', function (string $target, string $expected) {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $deal = kanbanDeal($owner, ['status' => 'won']);
    $this->actingAs($owner);
    expect(fn () => app(DealStages::class)->move($deal->id, $target, $expected))->toThrow(ValidationException::class);
    expect($deal->fresh()->status)->toBe('won');
    expect(Activity::where('subject_type', Deal::class)->where('subject_id', $deal->id)->where('event', 'updated')->count())->toBe(0);
})->with([['proposal', 'won'], ['unknown', 'won'], ['qualifying', 'new']]);

it('rejects another managers deal and hides its table row even with a forged filter', function () {
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $other = User::factory()->create(['role' => 'manager', 'active' => true]);
    $deal = kanbanDeal($other, ['title' => 'Чужая секретная сделка']);
    $this->actingAs($manager);
    Livewire::test(ManageDeals::class)->filterTable('manager_id', $other->id)
        ->assertDontSee('Чужая секретная сделка');
    expect(fn () => app(DealStages::class)->move($deal->id, 'proposal', 'new'))->toThrow(AuthorizationException::class);
    Livewire::test(ManageDeals::class)->call('mountAction', 'changeStage', [], ['table' => true, 'recordKey' => (string) $deal->id])
        ->assertActionNotMounted('changeStage');
    expect($deal->fresh()->status)->toBe('new');
});

it('keeps conversion idempotent and prevents changing the stage after conversion even through edit', function () {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $deal = kanbanDeal($owner, ['status' => 'won']);
    $this->actingAs($owner);
    $page = Livewire::test(ManageDeals::class);
    $page->callTableAction('convert', $deal)->assertHasNoTableActionErrors();
    $page->assertTableActionHidden('convert', $deal)->assertTableActionVisible('openOrder', $deal);
    $order = app(CrmWriter::class)->convert($deal);
    expect(app(CrmWriter::class)->convert($deal)->id)->toBe($order->id);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
    expect(fn () => app(DealStages::class)->move($deal->id, 'qualifying', 'won'))->toThrow(ValidationException::class);
    expect(fn () => app(CrmWriter::class)->save(Deal::class, ['title' => $deal->title, 'client_id' => $deal->client_id, 'manager_id' => $owner->id, 'status' => 'qualifying', 'items' => [['name' => 'Печать', 'quantity' => 2, 'price' => '1250000.50']]], $deal))->toThrow(ValidationException::class);
    expect($deal->fresh()->status)->toBe('won');
    expect(Activity::where('subject_type', Deal::class)->where('subject_id', $deal->id)->where('event', 'converted')->count())->toBe(1);
});

it('preserves table filters and search and loads only the nearest permitted open task', function () {
    $this->freezeTime();
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $deal = kanbanDeal($manager, ['title' => 'Нужная вывеска']);
    $other = kanbanDeal($owner, ['title' => 'Другой проект']);
    foreach ([['Позвонить сегодня', 'open', 1], ['Встреча завтра', 'open', 24], ['Уже выполнено', 'done', -1]] as [$title, $status, $hours]) {
        Task::create(['title' => $title, 'manager_id' => $manager->id, 'deal_id' => $deal->id, 'status' => $status, 'due_at' => now()->addHours($hours)]);
    }
    Task::create(['title' => 'Скрытая задача владельца', 'manager_id' => $owner->id, 'deal_id' => $deal->id, 'status' => 'open', 'due_at' => now()]);
    $this->actingAs($owner);
    $page = Livewire::test(ManageDeals::class)->filterTable('manager_id', $manager->id)->searchTable('Нужная')
        ->assertDontSee('data-deal-kanban', false)->assertSee('Нужная вывеска')->assertDontSee('Другой проект')
        ->assertCanSeeTableRecords([$deal])->assertCanNotSeeTableRecords([$other]);
    $page->searchTable('нет совпадений')->assertSee('По вашему запросу ничего не найдено')
        ->callTableAction('clearSelection')->assertSee('Нужная вывеска')->assertSee('Другой проект');
    $this->actingAs($manager);
    $page = Livewire::test(ManageDeals::class);
    expect($page->instance()->getTableRecords()->first()->tasks->pluck('title')->all())->toBe(['Позвонить сегодня']);
});

it('keeps the board inaccessible through old links actions and livewire state', function () {
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($manager);
    $this->get('/crm/deals?view=kanban')->assertOk()->assertDontSee('data-deal-kanban', false);
    Livewire::withQueryParams(['view' => 'kanban'])->test(ManageDeals::class)
        ->assertSet('viewMode', 'table')->assertActionDoesNotExist('kanbanView')->assertActionDoesNotExist('tableView');
    expect(fn () => Livewire::test(ManageDeals::class)->set('viewMode', 'kanban'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('counts all permitted deals by stage independently of pagination and search', function (string $role) {
    $actor = User::factory()->create(['role' => $role, 'active' => true]);
    $other = User::factory()->create(['role' => 'manager', 'active' => true]);
    for ($i = 0; $i < 27; $i++) {
        kanbanDeal($actor, ['title' => 'Сделка '.$i]);
    }
    $lost = kanbanDeal($actor, ['status' => 'lost', 'lost_reason' => 'Отказ']);
    $foreign = kanbanDeal($other);
    $this->actingAs($actor);
    $page = Livewire::test(ManageDeals::class)->set('tableRecordsPerPage', 10)->searchTable('Сделка 1');
    $tabs = $page->instance()->getTabs();
    expect(array_keys($tabs))->toBe(['all', 'new', 'qualifying', 'estimate', 'proposal', 'won', 'lost'])
        ->and((int) $tabs['new']->getBadge())->toBe($role === 'manager' ? 27 : 28)
        ->and((int) $tabs['lost']->getBadge())->toBe(1);
    $page->searchTable('')->set('activeTab', 'lost')->assertCanSeeTableRecords([$lost])->assertCanNotSeeTableRecords([$foreign]);
    $page->set('activeTab', 'new')->assertCanNotSeeTableRecords([$lost]);
    $this->assertModelExists($lost);
})->with(['owner', 'rop', 'manager']);

it('denies board access to roles without deals permission', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role, 'active' => true]));
    $this->get('/crm/deals?view=kanban')->assertForbidden();
})->with(['designer', 'cashier']);
