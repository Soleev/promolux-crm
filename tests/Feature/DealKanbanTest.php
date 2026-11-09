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
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

function kanbanDeal(User $manager, array $attributes = []): Deal
{
    $client = Client::create(['name' => 'Клиент '.$manager->id, 'manager_id' => $manager->id]);

    return Deal::create($attributes + ['title' => 'Вывеска', 'client_id' => $client->id, 'manager_id' => $manager->id, 'status' => 'new', 'currency' => 'UZS', 'items' => [['name' => 'Печать', 'quantity' => 2, 'price_minor' => 125000050]]]);
}

it('moves a deal through the board action and records the actor and status history', function () {
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $deal = kanbanDeal($manager);
    $this->actingAs($manager);
    Livewire::test(ManageDeals::class)->set('viewMode', 'kanban')
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

it('prefills a dragged target and rejects a stale board submission', function () {
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $deal = kanbanDeal($manager);
    $this->actingAs($manager);
    $page = Livewire::test(ManageDeals::class)->set('viewMode', 'kanban')
        ->call('mountAction', 'changeStage', ['target' => 'proposal', 'expected' => 'new'], ['table' => true, 'recordKey' => (string) $deal->id])
        ->assertTableActionDataSet(['status' => 'proposal', 'expected' => 'new']);
    app(DealStages::class)->move($deal->id, 'estimate', 'new');
    $page->callMountedTableAction()->assertHasErrors(['status']);
    expect($deal->fresh()->status)->toBe('estimate');
});

it('keeps role preview read only for stage changes', function () {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $deal = kanbanDeal($owner);
    $this->actingAs($owner);
    request()->attributes->set('preview_applied', true);
    try {
        expect(fn () => app(DealStages::class)->move($deal->id, 'proposal', 'new'))->toThrow(HttpException::class);
        expect($deal->fresh()->status)->toBe('new');
    } finally {
        request()->attributes->remove('preview_applied');
    }
});

it('rejects invalid and stale transitions without adding history', function (string $target, string $expected) {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $deal = kanbanDeal($owner, ['status' => 'won']);
    $this->actingAs($owner);
    expect(fn () => app(DealStages::class)->move($deal->id, $target, $expected))->toThrow(ValidationException::class);
    expect($deal->fresh()->status)->toBe('won');
    expect(Activity::where('subject_type', Deal::class)->where('subject_id', $deal->id)->where('event', 'updated')->count())->toBe(0);
})->with([['proposal', 'won'], ['unknown', 'won'], ['qualifying', 'new']]);

it('rejects another managers deal and hides its board card even with a forged filter', function () {
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $other = User::factory()->create(['role' => 'manager', 'active' => true]);
    $deal = kanbanDeal($other, ['title' => 'Чужая секретная сделка']);
    $this->actingAs($manager);
    Livewire::test(ManageDeals::class)->set('viewMode', 'kanban')->filterTable('manager_id', $other->id)
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
    $page = Livewire::test(ManageDeals::class)->set('viewMode', 'kanban');
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

it('shares filters and search across views and shows only the nearest permitted open task', function () {
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
        ->callAction('kanbanView')->assertSee('data-deal-kanban', false)->assertSee('Нужная вывеска')->assertDontSee('Другой проект')
        ->assertSee('2 500 001 сум')->callAction('tableView')->assertCanSeeTableRecords([$deal])->assertCanNotSeeTableRecords([$other]);
    $page->callAction('kanbanView')->searchTable('нет совпадений')->assertSee('По вашему запросу ничего не найдено')
        ->callTableAction('clearSelection')->assertSee('Нужная вывеска')->assertSee('Другой проект');
    $this->actingAs($manager);
    Livewire::test(ManageDeals::class)->set('viewMode', 'kanban')->assertSee('Позвонить сегодня')
        ->assertDontSee('Встреча завтра')->assertDontSee('Уже выполнено')->assertDontSee('Скрытая задача владельца');
});

it('denies board access to roles without deals permission', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role, 'active' => true]));
    $this->get('/crm/deals?view=kanban')->assertForbidden();
})->with(['designer', 'cashier']);
