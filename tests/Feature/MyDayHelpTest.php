<?php

use App\Filament\Pages\Help;
use App\Filament\Pages\MyDay;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Task;
use App\Models\User;
use App\Services\CrmWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

function dailyAssignments(): array
{
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $client = Client::create(['name' => 'Тестовый клиент', 'manager_id' => $manager->id]);
    $order = Order::create(['number' => 'QA-DAY', 'client_id' => $client->id, 'manager_id' => $manager->id, 'status' => 'ready']);

    return [$manager, $order];
}

it('opens only permitted work and help for the effective role', function (string $role, array $allowed) {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => $role]));
    $help = $this->get('/crm/help')->assertOk();
    foreach (Help::TOPICS as $topic => $destination) {
        $marker = 'id="help-'.$topic.'"';
        in_array($topic, $allowed, true) ? $help->assertSee($marker, false) : $help->assertDontSee($marker, false);
    }
    $help->assertDontSee('2,5%')->assertDontSee('5%');
    if ($role === 'cashier') {
        $this->get('/crm/my-day')->assertForbidden();
        Livewire::test(MyDay::class)->assertForbidden();
        $help->assertSee('Подтвердить приём')->assertDontSee('Записать наличные от клиента');
    } else {
        $this->get('/crm/my-day')->assertOk()->assertSee('На сегодня задач нет')->assertSee('У вас нет активных заказов');
        $this->get('/crm/tasks')->assertSee('/crm/help#help-tasks', false);
    }
})->with([
    ['owner', ['my-day', 'clients', 'deals', 'orders', 'tasks', 'payments', 'cash', 'debts', 'history', 'team']],
    ['rop', ['my-day', 'clients', 'deals', 'orders', 'tasks', 'payments', 'cash', 'debts', 'team']],
    ['manager', ['my-day', 'clients', 'deals', 'orders', 'tasks', 'payments', 'cash', 'debts']],
    ['designer', ['my-day', 'clients', 'orders', 'tasks', 'payments', 'cash']],
    ['cashier', ['cash']],
]);

it('searches role-specific help and escapes search text', function () {
    $this->actingAs(User::factory()->create(['role' => 'manager', 'active' => true]));
    $this->get('/crm/help?q='.urlencode('перечислен'))->assertOk()->assertSee('id="help-payments"', false)->assertDontSee('id="help-clients"', false);
    $this->get('/crm/help?q='.urlencode('<b>private</b>'))->assertOk()->assertSee('<b>private</b>')->assertDontSee('<b>private</b>', false);
});

it('separates Tashkent deadlines, scopes linked records and completes through the existing writer', function () {
    [$manager,$order] = dailyAssignments();
    $this->actingAs($manager);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 00:01', 'Asia/Tashkent'));
    $deal = Deal::create(['title' => 'Контакт-доступен', 'client_id' => $order->client_id, 'manager_id' => $manager->id, 'status' => 'new']);
    $make = fn ($title, $due, $status = 'open') => Task::create(['title' => $title, 'manager_id' => $manager->id, 'deal_id' => $deal->id, 'order_id' => $order->id, 'status' => $status, 'due_at' => $due]);
    $late = $make('До полуночи', '2026-11-15 23:59');
    $today = $make('После полуночи', '2026-11-16 00:00');
    $make('Поздно вечером', '2026-11-16 23:59');
    $make('Готовое вчера', '2026-11-15', 'done');
    $make('Отмена вчера', '2026-11-15', 'cancelled');
    $future = $make('Завтрашняя задача', '2026-11-17');
    $component = Livewire::test(MyDay::class)->assertSee('Контакт-доступен')
        ->assertDontSee('Готовое вчера')->assertDontSee('Отмена вчера')
        ->assertSee('tableActionRecord='.$order->id, false)
        ->assertViewHas('today', fn ($rows) => $rows->pluck('title')->all() === ['После полуночи', 'Поздно вечером'])
        ->assertViewHas('late', fn ($rows) => $rows->modelKeys() === [$late->id, $today->id])
        ->assertViewHas('orders', fn ($rows) => $rows->contains($order));
    $component->call('complete', $today->id)->assertOk();
    expect($today->fresh()->status)->toBe('done');
    $count = Activity::count();
    $component->call('complete', $today->id);
    expect(Activity::count())->toBe($count);
    $rop = User::factory()->create(['role' => 'rop', 'active' => true]);
    $deal->update(['manager_id' => $rop->id]);
    $order->update(['manager_id' => $rop->id]);
    $future->update(['manager_id' => $rop->id]);
    $component->call('$refresh')->assertDontSee('Контакт-доступен')->assertViewHas('orders', fn ($rows) => $rows->isEmpty());
    $component->call('complete', $future->id)->assertNotFound();
    expect($future->fresh()->status)->toBe('open');
});

it('bounds daily lists and query count when assignments grow', function () {
    [$manager,$order] = dailyAssignments();
    $this->actingAs($manager);
    $work = function ($start, $end) use ($manager, $order) {
        for ($i = $start; $i < $end; $i++) {
            Task::create(['title' => 'Задача '.$i, 'manager_id' => $manager->id, 'status' => 'open', 'due_at' => today()->setTime(12, 0)]);
            Order::create(['number' => 'WORK-'.$i, 'client_id' => $order->client_id, 'manager_id' => $manager->id, 'status' => 'design']);
        }
    };
    $measure = function () {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/crm/my-day')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $work(0, 10);
    $small = $measure();
    $work(10, 205);
    expect($measure())->toBeLessThanOrEqual($small + 1);
    Livewire::test(MyDay::class)->assertViewHas('orders', fn ($rows) => $rows->count() === 8)
        ->assertViewHas('today', fn ($rows) => $rows->count() === 8);
    $order->update(['status' => 'delivered']);
    Livewire::test(MyDay::class)->assertViewHas('orders', fn ($rows) => ! $rows->contains($order));
});

it('keeps the mixed payment register at its original URL and gives it a distinct bank icon', function () {
    foreach (['owner', 'rop', 'manager', 'designer', 'cashier'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role, 'active' => true]));
        $response = $this->get('/crm/payments');
        if ($role === 'cashier') {
            $response->assertForbidden();
        } else {
            $response->assertOk()->assertSee('Оплаты клиентов')->assertSee('наличные после приёма кассиром');
        }
    }
    expect(PaymentResource::getNavigationIcon())->toBe('heroicon-o-building-library');
});

it('keeps a task deadline compulsory and excludes cancelled and delivered orders', function () {
    [$manager,$order] = dailyAssignments();
    $this->actingAs($manager);
    foreach (['delivered', 'cancelled'] as $status) {
        $order->update(compact('status'));
        Livewire::test(MyDay::class)->assertViewHas('orders', fn ($rows) => $rows->isEmpty());
    }
    expect(fn () => app(CrmWriter::class)->save(Task::class, [
        'title' => 'Без срока', 'manager_id' => $manager->id, 'status' => 'open',
    ]))->toThrow(ValidationException::class);
});
