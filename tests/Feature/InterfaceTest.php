<?php

use App\Filament\Resources\Clients\Pages\ManageClients;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use App\Services\RecordDetails;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

it('keeps the navigation order and shows the clock and release on every permitted page', function (string $role, array $allowed) {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 15:16:17', 'Asia/Tashkent'));
    $this->actingAs(User::factory()->create(['role' => $role, 'active' => true]));
    foreach (['overview', 'clients', 'deals', 'orders', 'tasks', 'payments', 'cash-receipts', 'accruals', 'payouts', 'expenses', 'users', 'activities', 'about'] as $page) {
        $response = $this->get('/crm/'.$page);
        if (in_array($page, $allowed)) {
            $response->assertOk()->assertSee('data-crm-clock', false)->assertSee('07.11.2026')->assertSee('15:16:17')->assertSee('v'.config('app.version'));
        } else {
            $response->assertForbidden();
        }
    }
    if ($role === 'owner') {
        $this->get('/crm/overview')->assertSeeInOrder(['Главная', 'Продажи', 'Финансы', 'Управление']);
    }
    $this->travelBack();
})->with([
    ['owner', ['overview', 'clients', 'deals', 'orders', 'tasks', 'payments', 'cash-receipts', 'accruals', 'payouts', 'expenses', 'users', 'activities', 'about']],
    ['rop', ['overview', 'clients', 'deals', 'orders', 'tasks', 'payments', 'cash-receipts', 'accruals', 'payouts', 'users', 'about']],
    ['manager', ['overview', 'clients', 'deals', 'orders', 'tasks', 'payments', 'cash-receipts', 'accruals', 'payouts', 'about']],
    ['designer', ['clients', 'orders', 'tasks', 'payments', 'cash-receipts', 'about']],
    ['cashier', ['cash-receipts', 'about']],
]);

it('offers a working contextual create action and replaces it with search reset', function () {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $this->actingAs($owner);
    Livewire::test(ManageClients::class)->assertSee('Клиентов пока нет')
        ->callTableAction('createFromEmpty', data: ['name' => 'Новый клиент', 'contacts' => []])
        ->assertHasNoTableActionErrors();
    $client = Client::where('name', 'Новый клиент')->sole();
    $page = Livewire::test(ManageClients::class)->searchTable('Несуществующий клиент')
        ->assertCanNotSeeTableRecords([$client])->assertSee('По вашему запросу ничего не найдено')
        ->assertTableActionHidden('createFromEmpty')->assertTableActionVisible('clearSelection');
    Livewire::test(ManageClients::class)->assertCanNotSeeTableRecords([$client])->assertSee('По вашему запросу ничего не найдено');
    $page->callTableAction('clearSelection')->assertCanSeeTableRecords([$client]);
    Livewire::test(ManageClients::class)->assertCanSeeTableRecords([$client]);
});

it('filters dates and deadlines without exposing another managers tasks', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00:00', 'Asia/Tashkent'));
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $other = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($manager);
    $overdue = Task::create(['title' => 'Позвонить', 'manager_id' => $manager->id, 'due_at' => now()->subDay(), 'status' => 'open']);
    $today = Task::create(['title' => 'Встреча', 'manager_id' => $manager->id, 'due_at' => now()->addHour(), 'status' => 'open']);
    $done = Task::create(['title' => 'Завершено', 'manager_id' => $manager->id, 'due_at' => now()->subHour(), 'status' => 'done']);
    $foreign = Task::create(['title' => 'Чужая задача', 'manager_id' => $other->id, 'due_at' => now()->subDay(), 'status' => 'open']);
    $page = Livewire::test(ManageTasks::class)->filterTable('deadline', 'overdue')
        ->assertCanSeeTableRecords([$overdue])->assertCanNotSeeTableRecords([$today, $done, $foreign]);
    $page->filterTable('deadline', 'today')->assertCanSeeTableRecords([$today])->assertCanNotSeeTableRecords([$overdue, $done, $foreign]);
    $page->removeTableFilters()->filterTable('period', ['from' => '2026-11-08', 'until' => '2026-11-09'])
        ->assertSee('По вашему запросу ничего не найдено')->callTableAction('clearSelection')
        ->assertCanSeeTableRecords([$overdue, $today, $done])->assertCanNotSeeTableRecords([$foreign]);
    $this->travelBack();
});

it('formats financial records and filters clarification payments without changing them', function () {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($owner);
    $client = Client::create(['name' => 'Клиент', 'manager_id' => $manager->id]);
    $order = Order::create(['number' => 'PL-TEST', 'client_id' => $client->id, 'manager_id' => $manager->id, 'status' => 'design', 'currency' => 'UZS']);
    $payment = Payment::create(['order_id' => $order->id, 'manager_id' => $manager->id, 'idempotency_key' => (string) Str::uuid(), 'amount_minor' => 5000000001, 'currency' => 'UZS', 'paid_at' => '2026-11-07', 'calculation_status' => 'needs_clarification', 'method' => 'Перечисление']);
    $before = $payment->refresh()->getRawOriginal();
    Livewire::test(ManagePayments::class)->assertSee('50 000 000,01 сум')->assertSee('07.11.2026')
        ->filterTable('calculation_status', 'calculated')->assertCanNotSeeTableRecords([$payment])
        ->filterTable('calculation_status', 'needs_clarification')->assertCanSeeTableRecords([$payment]);
    $fields = RecordDetails::get($payment)['fields'];
    expect($fields)->toMatchArray(['amount_minor' => '50 000 000,01 сум', 'paid_at' => '07.11.2026', 'calculation_status' => 'Требует уточнения']);
    expect(RecordDetails::get($order)['fields']['status'])->toBe('Макет');
    expect($payment->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('accruals', 0);

    $this->actingAs($manager);
    Livewire::test(ManagePayments::class)->assertTableActionHidden('createFromEmpty');
    $outsider = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($outsider);
    Livewire::test(ManagePayments::class)->searchTable('PL-TEST')->assertCanNotSeeTableRecords([$payment]);
});
