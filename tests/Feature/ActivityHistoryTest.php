<?php

use App\Filament\Resources\Activities\Pages\ManageActivities;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityHistory;
use App\Services\CashDesk;
use App\Services\Ledger;
use App\Services\RecordDetails;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

it('interprets real sales changes and formats dates, statuses, actors and known item snapshots', function () {
    [$owner, $rop, $manager, $order] = crmScenario();
    $client = $order->client;
    $client->update(['phone' => '+000000']);
    $client->recordActivity('communication', ['message' => 'Обсудили макет']);
    $history = collect(ActivityHistory::forRecord($client));
    expect($history->pluck('title')->all())->toContain('Создан клиент', 'Изменены контактные данные клиента', 'Добавлена заметка о звонке или встрече')
        ->and($history->first()['actor'])->toBe($owner->name);
    $order->update(['due_at' => '2026-11-18']);
    $order->update(['due_at' => '2026-11-20']);
    $order->update(['status' => 'ready']);
    $order->update(['manager_id' => $rop->id]);
    $order->recordActivity('items_replaced', ['before' => [['name' => 'А', 'quantity' => 2, 'price_minor' => 1000]], 'after' => [['name' => 'Б', 'quantity' => 3, 'price_minor' => 1201]]]);
    $events = collect(ActivityHistory::forRecord($order))->keyBy(fn ($row) => $row['id']);
    $find = fn ($title) => $events->firstWhere('title', $title)['fields'][0];
    expect($find('Изменён срок заказа'))->toMatchArray(['before' => '18.11.2026', 'after' => '20.11.2026'])
        ->and($find('Изменён статус заказа')['after'])->toBe('Готов')
        ->and($find('Изменён ответственный'))->toMatchArray(['before' => $manager->name, 'after' => $rop->name])
        ->and($find('Изменена стоимость заказа')['after'])->toContain('36,03 сум');

    $deal = Deal::create(['title' => 'Печать', 'client_id' => $client->id, 'manager_id' => $manager->id, 'status' => 'new']);
    foreach (['proposal' => 'Изменён этап сделки', 'won' => 'Сделка согласована', 'lost' => 'Сделка признана проигранной'] as $status => $title) {
        $deal->update(['status' => $status]);
        expect(ActivityHistory::forRecord($deal)[0]['title'])->toBe($title);
    }
    $task = Task::create(['title' => 'Позвонить', 'manager_id' => $manager->id, 'due_at' => now(), 'status' => 'open']);
    $task->update(['status' => 'done']);
    expect(array_column(ActivityHistory::forRecord($task), 'title'))->toBe(['Задача выполнена', 'Создана задача']);
    $task->recordActivity('updated', ['after' => ['due_at' => '2026-11-20T08:30:00Z']]);
    expect(ActivityHistory::forRecord($task)[0]['fields'][0])->toBe(['label' => 'Срок выполнения', 'after' => '20.11.2026 13:30']);
});

it('keeps old audit payloads intact and presents only escaped known values', function () {
    [, , $manager, $order] = crmScenario();
    $client = $order->client;
    $client->recordActivity('communication', ['message' => '<b>Звонок</b>', 'password' => 'HIDDEN-PASSWORD', 'token' => 'HIDDEN-TOKEN']);
    $client->recordActivity('updated', ['after' => ['phone' => null, 'rate_bps' => 'HIDDEN-RATE', 'contacts' => [['name' => 'Контакт', 'private' => 'HIDDEN-NESTED']]]]);
    $client->recordActivity('legacy', ['changes' => ['key' => 'HIDDEN-OLD']]);
    $snapshot = Activity::orderBy('id')->pluck('changes', 'id')->all();
    $this->actingAs($manager);
    $history = RecordDetails::get($client)['history'];
    $this->view('filament.activity-list', ['events' => $history])->assertSee('&lt;b&gt;Звонок&lt;/b&gt;', false)
        ->assertDontSee('<b>Звонок</b>', false)->assertSee('Не указано')->assertSee('Контакт')->assertDontSee('HIDDEN-');
    expect($history[0]['fields'])->toBeEmpty()
        ->and($history[1]['fields'][0])->not->toHaveKey('before')
        ->and(Activity::orderBy('id')->pluck('changes', 'id')->all())->toBe($snapshot);
});

it('separates collecting cash, confirmation and reversal in the history', function () {
    [$owner, , , , $input] = crmScenario();
    $cash = app(CashDesk::class)->collect($input + ['collected_at' => today()->toDateString()]);
    $cashTitle = fn () => ActivityHistory::journal(Activity::where('subject_type', $cash::class)->where('subject_id', $cash->id)->with('actor')->latest('id')->get())[0]['title'];
    expect($cashTitle())->toBe('Зарегистрировано получение наличных');
    $this->actingAs(User::factory()->create(['role' => 'cashier', 'active' => true]));
    $payment = app(Ledger::class)->acceptCash($cash, today()->toDateString());
    $this->actingAs($owner);
    expect($cashTitle())->toBe('Подтверждена передача наличных в SFP')
        ->and(ActivityHistory::forRecord($payment)[0]['title'])->toBe('Зарегистрирована оплата');
    $reversal = app(Ledger::class)->reversePayment($payment, 'Ошибка проведения');
    expect(ActivityHistory::forRecord($reversal)[0]['title'])->toBe('Проведено сторно');
});

it('checks current object access, journal access and finance navigation for each role', function (string $role) {
    [$owner, $rop, $manager, $order] = crmScenario();
    $viewer = match ($role) {
        'owner' => $owner, 'rop' => $rop, 'manager' => $manager,
        default => User::factory()->create(['role' => $role, 'active' => true]),
    };
    $order->update(['manager_id' => $viewer->id]);
    $order->recordActivity('updated', ['after' => ['description' => 'Уникальная отметка истории']]);
    $this->actingAs($viewer);
    if ($role !== 'cashier') {
        expect(RecordDetails::get($order)['history'][0]['fields'][0]['after'])->toBe('Уникальная отметка истории');
    }
    if (in_array($role, ['owner', 'rop', 'manager'])) {
        $this->get('/crm/client-debts')->assertOk()->assertSeeInOrder(['Финансы', 'Долги клиентов', 'Оплаты клиентов', 'Передача наличных', 'Начисления и остатки', 'Выплаты']);
    } else {
        $this->get('/crm/cash-receipts')->assertOk()->assertDontSee('Долги клиентов');
    }
    $journal = $this->get('/crm/activities');
    if ($role === 'owner') {
        $journal->assertOk()->assertSee('История действий')->assertSee('Уникальная отметка истории');

        return;
    }
    $journal->assertForbidden();
    Livewire::test(ManageActivities::class)->assertForbidden();
    $order->update(['manager_id' => $owner->id]);
    if ($role !== 'rop') {
        expect(fn () => RecordDetails::get($order))->toThrow(AuthorizationException::class);
        $response = $this->get('/crm/orders?tableAction=details&tableActionRecord='.$order->id);
        $response->assertDontSee('Уникальная отметка истории');
        if ($role !== 'cashier') {
            expect(fn () => Livewire::test(ManageOrders::class)->callTableAction('details', $order))
                ->toThrow(ActionNotResolvableException::class);
        }
    }
})->with(['owner', 'rop', 'manager', 'designer', 'cashier']);

it('filters and paginates the journal and batches object lookups', function () {
    [$owner, , , $order] = crmScenario();
    $order->recordActivity('updated', ['after' => ['description' => 'Первая отметка']]);
    $measure = function () use ($order) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            ActivityHistory::journal(Activity::where('subject_type', Order::class)->where('subject_id', $order->id)->with('actor')->latest('id')->limit(50)->get());

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    };
    $before = $measure();
    foreach (range(1, 120) as $i) {
        $order->recordActivity('updated', ['after' => ['manager_id' => $owner->id, 'description' => 'Страница истории '.$i.'!']]);
    }
    expect($measure())->toBeLessThanOrEqual($before);
    Activity::create(['subject_type' => 'UnknownLegacyType', 'subject_id' => 10, 'event' => 'created', 'changes' => ['title' => 'HIDDEN-UNKNOWN']]);
    Activity::create(['subject_type' => Client::class, 'subject_id' => 9999999, 'event' => 'updated', 'changes' => ['name' => 'HIDDEN-MISSING']]);
    $page = Livewire::test(ManageActivities::class)->assertSee('Объект недоступен')->assertDontSee('HIDDEN-')
        ->filterTable('subject_type', Order::class)->filterTable('event', 'updated')->filterTable('actor_id', $owner->id)
        ->assertSee('Страница истории 120!')->assertDontSee('Первая отметка')
        ->call('gotoPage', 2)->assertDontSee('Страница истории 120!')->assertSee('Страница истории 110!');
});

it('redacts an inaccessible historical relationship after reassignment without suppressing the accessible order', function () {
    [, , $manager, $order] = crmScenario();
    $other = User::factory()->create(['role' => 'manager', 'active' => true]);
    $previous = Client::create(['name' => 'Закрытое прежнее название', 'manager_id' => $other->id]);
    $order->recordActivity('updated', ['before' => ['client_id' => $previous->id], 'after' => ['client_id' => $order->client_id]]);
    $this->actingAs($manager);
    $history = RecordDetails::get($order)['history'];
    expect($history[0]['fields'][0])->toBe(['label' => 'Клиент', 'before' => 'Объект недоступен', 'after' => $order->client->name]);
    $this->view('filament.activity-list', ['events' => $history])->assertDontSee($previous->name);
});

it('rechecks journal visibility on an already mounted Livewire page', function () {
    [, , $manager, $order] = crmScenario();
    $order->recordActivity('updated', ['after' => ['description' => 'Закрытая страница журнала']]);
    $page = Livewire::test(ManageActivities::class)->assertSee('Закрытая страница журнала');
    $this->actingAs($manager);
    $page->call('$refresh')->assertForbidden();
});
