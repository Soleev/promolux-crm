<?php

use App\Filament\Pages\Overview;
use App\Filament\Resources\CashReceipts\Pages\ManageCashReceipts;
use App\Filament\Resources\Clients\Pages\ManageClients;
use App\Models\CashReceipt;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Order;
use App\Models\User;
use App\Services\CashDesk;
use App\Services\CrmWriter;
use App\Services\Ledger;
use App\Services\MonthlyCharts;
use App\Services\Report;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

function crmScenario(string $currency = 'UZS'): array
{
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $rop = User::factory()->create(['role' => 'rop', 'active' => true]);
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $client = Client::create(['name' => 'Тестовый клиент', 'manager_id' => $manager->id]);
    $order = Order::create(['number' => (string) Str::ulid(), 'client_id' => $client->id, 'manager_id' => $manager->id, 'currency' => $currency, 'status' => 'new']);
    $order->items()->create(['name' => 'Тестовая позиция', 'price_minor' => 1000000000, 'quantity' => 1]);
    test()->actingAs($owner);
    $input = ['idempotency_key' => (string) Str::uuid(), 'order_id' => $order->id, 'paid_at' => today()->toDateString(), 'amount' => '10000000', 'currency' => $currency, 'owner_id' => $owner->id, 'rop_id' => $rop->id];

    return [$owner, $rop, $manager, $order, $input];
}

it('distributes ten million only after receipt and keeps the original recipients on retry and reassignment', function () {
    [$owner,$rop,$manager,$order,$input] = crmScenario();
    expect($order->payment_status)->toBe('Не оплачен');
    $this->assertDatabaseCount('accruals', 0);
    $ledger = app(Ledger::class);
    $payment = $ledger->payment($input);
    $payment->save();
    $ledger->payment($input);
    $newManager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $order->update(['manager_id' => $newManager->id]);
    $this->assertDatabaseCount('accruals', 4);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('accruals', ['kind' => 'owner_gross', 'amount_minor' => 50000000, 'beneficiary_id' => $owner->id, 'rate_bps' => 500]);
    $this->assertDatabaseHas('accruals', ['kind' => 'manager_rop', 'beneficiary_id' => $manager->id, 'rate_bps' => 250]);
    $totals = app(Report::class)->build($owner, null, null)['currencies']['UZS'];
    expect($totals)->toMatchArray(['owner_net' => 25000000, 'rop_net' => 25000000, 'manager_accrued' => 50000000, 'sfp_remaining' => 100000000, 'manager_remaining' => 50000000]);
    expect(app(Report::class)->register($newManager, null, null))->toBeEmpty();
});

it('accrues only each instalment and rejects foreign currency and separates expense payers', function () {
    [$owner,,,$order,$input] = crmScenario();
    $ledger = app(Ledger::class);
    $ledger->payment(array_replace($input, ['amount' => '4000000']));
    expect($order->fresh()->payment_status)->toBe('Частично оплачен');
    $ledger->payment(array_replace($input, ['idempotency_key' => (string) Str::uuid(), 'amount' => '6000000']));
    Expense::create(['paid_at' => today(), 'payer' => 'SFP', 'category' => 'Тест аренды', 'currency' => 'UZS', 'amount_minor' => 900000]);
    $totals = app(Report::class)->build($owner, null, null)['currencies'];
    expect($order->fresh()->payment_status)->toBe('Оплачен')
        ->and($totals['UZS'])->toMatchArray(['owner_net' => 25000000, 'sfp_expenses' => 900000, 'promolux_expenses' => 0, 'sfp_remaining' => 100000000])
        ->and(array_keys($totals))->toBe(['UZS']);
    expect(fn () => $ledger->payment(array_replace($input, ['idempotency_key' => (string) Str::uuid(), 'currency' => 'USD'])))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('payments', 2);
});

it('carries the unpaid balance and allocates a partial payout over two months', function () {
    [$owner,$rop,,$order,$input] = crmScenario();
    $ledger = app(Ledger::class);
    $one = $ledger->payment(array_replace($input, ['paid_at' => today()->subMonthsNoOverflow(2)->startOfMonth()->toDateString(), 'amount' => '1000']));
    $two = $ledger->payment(array_replace($input, ['idempotency_key' => (string) Str::uuid(), 'paid_at' => today()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'amount' => '1000']));
    $allocations = [['accrual_id' => $one->accruals()->where('kind', 'owner_gross')->first()->id, 'amount' => '50'], ['accrual_id' => $two->accruals()->where('kind', 'owner_gross')->first()->id, 'amount' => '25']];
    $data = ['idempotency_key' => (string) Str::uuid(), 'payer' => 'SFP', 'recipient_id' => $rop->id, 'paid_at' => today()->toDateString(), 'amount' => '75', 'currency' => 'UZS', 'allocations' => $allocations];
    $paid = $ledger->payout($data);
    $ledger->payout($data);
    $totals = app(Report::class)->build($owner, today()->startOfMonth()->toDateString(), null)['currencies']['UZS'];
    expect($totals)->toMatchArray(['sfp_paid' => 7500, 'sfp_remaining' => 12500, 'owner_gross' => 0, 'manager_remaining' => 10000]);
    $this->assertDatabaseCount('payouts', 1);
    expect(fn () => $ledger->reversePayment($one, 'Исправление'))->toThrow(ValidationException::class);
    expect(fn () => $ledger->payout(array_replace($data, ['idempotency_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    $ledger->reversePayout($paid, 'Исправление выплаты');
    $ledger->reversePayment($one, 'Исправление оплаты');
    expect(app(Report::class)->build($owner, null, null)['currencies']['UZS']['sfp_remaining'])->toBe(10000);
});

it('retains every minor unit at the rounding boundary and flags unassigned orders', function () {
    [$owner,,,$order,$input] = crmScenario();
    $ledger = app(Ledger::class);
    $ledger->payment(array_replace($input, ['amount' => '0.15']));
    $totals = app(Report::class)->build($owner, null, null)['currencies']['UZS'];
    expect($totals['owner_net'] + $totals['rop_net'] + $totals['manager_accrued'])->toBe(2);
    $order->update(['manager_id' => null]);
    $pending = $ledger->payment(array_replace($input, ['idempotency_key' => (string) Str::uuid()]));
    expect($pending->calculation_status)->toBe('needs_clarification')->and($pending->accruals)->toBeEmpty();
});

it('restricts manager lists, documents, exports, actions and dashboard values to their own data', function () {
    [$owner,$rop,$manager,$order,$input] = crmScenario();
    app(Ledger::class)->payment($input);
    $outsider = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($outsider);
    $this->get('/crm/orders')->assertOk()->assertDontSee($order->number);
    $this->get('/crm-documents/order/'.$order->id)->assertNotFound();
    $this->get('/crm/expenses')->assertForbidden();
    $this->get('/crm/users')->assertForbidden();
    $this->get('/crm/overview')->assertOk()->assertDontSee('Начислено владельцу от SFP');
    expect($this->get('/crm-register.csv?manager='.$manager->id)->streamedContent())->not->toContain($order->number);
    expect(fn () => app(CrmWriter::class)->save(Order::class, [], $order))->toThrow(AuthorizationException::class);
    $this->actingAs($rop)->get('/crm/overview')->assertOk()->assertDontSee('Доля владельца после менеджера');
    $this->actingAs($manager);
    expect(app(Report::class)->register($manager, null, null))->toHaveCount(2);
});

it('renders all panel sections and creates a client through its Filament form', function () {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $this->get('/crm/orders')->assertRedirect('/crm/login');
    $this->actingAs($owner);
    foreach (['overview', 'clients', 'deals', 'orders', 'tasks', 'payments', 'accruals', 'payouts', 'expenses', 'users', 'activities'] as $page) {
        $this->get('/crm/'.$page)->assertOk();
    }
    Livewire::test(ManageClients::class)
        ->callAction('create', data: ['name' => 'Тест формы', 'contacts' => []])->assertHasNoFormErrors();
    $this->assertDatabaseHas('clients', ['name' => 'Тест формы']);
});

it('lets both the owner and the sales lead assign a client to the sales lead', function () {
    [$owner, $rop] = crmScenario();
    foreach ([$owner, $rop] as $actor) {
        $this->actingAs($actor);
        Livewire::test(ManageClients::class)->callAction('create', data: [
            'name' => 'Клиент РОП '.$actor->id, 'manager_id' => $rop->id, 'contacts' => [],
        ])->assertHasNoFormErrors();
        $this->assertDatabaseHas('clients', ['name' => 'Клиент РОП '.$actor->id, 'manager_id' => $rop->id]);
    }
});

it('lets the owner personally handle a client and deal through conversion and receipt', function () {
    [$owner, , , , $input] = crmScenario();
    Livewire::test(ManageClients::class)->callAction('create', data: [
        'name' => 'Личный клиент владельца', 'manager_id' => $owner->id, 'contacts' => [],
    ])->assertHasNoFormErrors();
    $client = Client::where('name', 'Личный клиент владельца')->sole();
    expect($client->manager_id)->toBe($owner->id);

    $writer = app(CrmWriter::class);
    $deal = $writer->save(Deal::class, ['title' => 'Лично ведёт владелец', 'client_id' => $client->id, 'manager_id' => $owner->id, 'currency' => 'UZS', 'status' => 'won', 'items' => [['name' => 'Печать', 'quantity' => 1, 'price' => '100']]]);
    $order = $writer->convert($deal);
    expect($order->manager_id)->toBe($owner->id);
    $payment = app(Ledger::class)->payment(array_replace($input, ['order_id' => $order->id, 'amount' => '100', 'rop_id' => null]));
    expect($payment->calculation_status)->toBe('needs_clarification')->and($payment->accruals)->toBeEmpty()->and($order->fresh()->paid_minor)->toBe(10000);
});

it('charts prior debt, separately dated payments and role-specific settlements in one currency', function () {
    $this->travelTo(Carbon::parse('2026-11-05 12:00'));
    [$owner, $rop, $manager, $order, $input] = crmScenario();
    $ledger = app(Ledger::class);
    $old = $ledger->payment(array_replace($input, ['paid_at' => '2026-05-01', 'amount' => '1000']));
    $ledger->payment(array_replace($input, ['paid_at' => '2026-10-03', 'amount' => '200', 'idempotency_key' => (string) Str::uuid()]));
    $ledger->payout(['idempotency_key' => (string) Str::uuid(), 'currency' => 'UZS', 'amount' => '25', 'payer' => 'SFP', 'recipient_id' => $owner->id, 'paid_at' => '2026-11-01',
        'allocations' => [['accrual_id' => $old->accruals()->where('kind', 'owner_gross')->sole()->id, 'amount' => '25']]]);
    $charts = app(MonthlyCharts::class);
    $months = $charts->build($owner, '2026-06-01', '2026-11-05', null, 'month');
    expect($months)->toHaveCount(6)
        ->and($months['2026-06'])->toMatchArray(['balance' => 10000, 'earned' => 0, 'paid' => 0])
        ->and($months['2026-10'])->toMatchArray(['payments' => 20000, 'orders' => 0, 'earned' => 2000, 'balance' => 12000])
        ->and($months['2026-11'])->toMatchArray(['orders' => 1000000000, 'payments' => 0, 'paid' => 2500, 'balance' => 9500]);
    expect($charts->build($rop, '2026-06-01', null, null, 'month')['2026-11']['balance'])->toBe(6000)
        ->and($charts->build($manager, '2026-06-01', null, null, 'month')['2026-11']['balance'])->toBe(6000);
    $other = User::factory()->create(['role' => 'manager', 'active' => true]);
    expect($charts->build($other, '2026-06-01', null, $manager->id, 'month')['2026-11'])->toMatchArray(['orders' => 0, 'paid' => 0, 'balance' => 0]);
    $days = $charts->build($owner, '2026-11-02', '2026-11-05', null, 'day');
    expect(array_keys($days))->toBe(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'])
        ->and($days['2026-11-02'])->toMatchArray(['balance' => 9500, 'paid' => 0, 'earned' => 0])
        ->and($days['2026-11-05']['orders'])->toBe(1000000000);
    $partial = $charts->build($owner, '2026-11-02', '2026-11-04', null, 'month');
    expect($partial['2026-11'])->toMatchArray(['orders' => 0, 'balance' => 9500, 'paid' => 0]);
    Livewire::test(Overview::class)->assertSee('Суммы по дням · сум')->set('chartInterval', 'month')->assertSee('Суммы по месяцам · сум')->assertDontSee('USD');
});

it('credits cash to SFP once when the cashier accepts it from the collector', function () {
    [$owner,$rop,$manager,$order,$input] = crmScenario();
    $cashier = User::factory()->create(['role' => 'cashier', 'active' => true]);
    $this->actingAs($rop);
    $data = ['order_id' => $order->id, 'amount' => '100', 'collected_at' => today()->subDay()->toDateString(), 'owner_id' => $owner->id, 'rop_id' => $rop->id, 'idempotency_key' => (string) Str::uuid()];
    Livewire::test(ManageCashReceipts::class)
        ->callAction('collect', data: $data)->assertHasNoFormErrors();
    $cash = CashReceipt::sole();
    expect($cash->collector_id)->toBe($rop->id)->and($order->fresh()->paid_minor)->toBe(10000);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('accruals', 0);
    expect(app(CashDesk::class)->collect($data)->id)->toBe($cash->id);
    expect(fn () => app(Ledger::class)->acceptCash($cash, today()->toDateString()))->toThrow(HttpException::class);
    $order->update(['manager_id' => null]);
    $this->actingAs($cashier);
    $this->get('/crm')->assertRedirect('/crm/cash-receipts');
    $this->get('/crm/cash-receipts')->assertOk()->assertSee($order->number);
    $this->get('/crm/overview')->assertForbidden();
    $this->get('/crm/payments')->assertForbidden();
    $this->get('/crm-register.csv')->assertForbidden();
    expect(fn () => app(Ledger::class)->acceptCash($cash, today()->subDays(2)->toDateString()))->toThrow(ValidationException::class);
    $payment = app(Ledger::class)->acceptCash($cash, today()->toDateString());
    expect(app(Ledger::class)->acceptCash($cash, today()->toDateString())->id)->toBe($payment->id)
        ->and($cash->fresh()->cashier_id)->toBe($cashier->id)->and($cash->fresh()->status)->toBe('accepted')
        ->and($payment->paid_at->toDateString())->toBe(today()->toDateString())->and($order->fresh()->paid_minor)->toBe(10000);
    $this->assertDatabaseHas('accruals', ['payment_id' => $payment->id, 'kind' => 'manager_owner', 'beneficiary_id' => $manager->id, 'amount_minor' => 250]);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('accruals', 4);
    $this->actingAs($owner);
    app(Ledger::class)->reversePayment($payment, 'Исправление записи');
    expect($cash->fresh()->status)->toBe('reversed')->and($order->fresh()->paid_minor)->toBe(0);
});

it('records a transfer immediately and cancels a pending cash entry without posting it', function () {
    [$owner,$rop,,$order,$input] = crmScenario();
    $payment = app(Ledger::class)->payment($input);
    expect($payment->recipient)->toBe('SFP')->and($payment->method)->toBe('Перечисление');
    $this->assertDatabaseCount('cash_receipts', 0);
    $service = app(CashDesk::class);
    $cash = $service->collect(['order_id' => $order->id, 'amount' => '200', 'collected_at' => today()->toDateString(), 'owner_id' => $owner->id, 'rop_id' => $rop->id, 'idempotency_key' => (string) Str::uuid()]);
    $service->cancel($cash, 'Ошибка регистрации');
    expect($order->fresh()->paid_minor)->toBe($payment->amount_minor)->and($cash->fresh()->status)->toBe('cancelled');
    $this->assertDatabaseCount('payments', 1);
});

it('lets the owner browse the selected employee and return without granting write access', function () {
    [$owner,,$manager,$order] = crmScenario();
    $other = User::factory()->create(['active' => true, 'role' => 'manager']);
    $otherClient = Client::create(['name' => 'Другой клиент', 'manager_id' => $other->id]);

    $this->get('/crm/overview')->assertOk()->assertSee('Просмотр роли');
    $this->post('/crm/preview-role', ['view_as' => 'user:'.$manager->id])->assertRedirect('/crm');
    $this->get('/crm/clients')->assertOk()->assertSee('Вернуться к владельцу')->assertSee($order->client->name)->assertDontSee($otherClient->name);
    $this->get('/crm/expenses')->assertForbidden();
    $this->post('/crm/preview-role', ['view_as' => 'cashier'])->assertForbidden();
    $this->post('/crm/exit-preview')->assertRedirect('/crm');
    $this->get('/crm/expenses')->assertOk();
    expect($owner->fresh()->role)->toBe('owner')->and($manager->fresh()->role)->toBe('manager');

    foreach (['rop' => '/crm/orders', 'cashier' => '/crm/cash-receipts', 'designer' => '/crm/orders', 'manager' => '/crm/clients'] as $role => $page) {
        $this->post('/crm/preview-role', ['view_as' => $role])->assertRedirect('/crm');
        $this->get($page)->assertOk()->assertSee('Вернуться к владельцу');
        $this->post('/crm/exit-preview')->assertRedirect('/crm');
    }
    $this->actingAs($manager)->post('/crm/preview-role', ['view_as' => 'user:'.$owner->id])->assertForbidden();
    $this->post('/crm/exit-preview')->assertForbidden();
});

it('lets a designer take their own orders, transfers and cash without access to other orders or settlements', function () {
    [$owner,,$manager,$foreignOrder,$input] = crmScenario();
    $designer = User::factory()->create(['active' => true, 'role' => 'designer']);
    $this->actingAs($designer);
    $writer = app(CrmWriter::class);
    $client = $writer->save(Client::class, ['name' => 'Клиент дизайнера']);
    $order = $writer->save(Order::class, ['number' => 'DES-1', 'client_id' => $client->id, 'manager_id' => $manager->id, 'status' => 'new', 'items' => [['name' => 'Макет', 'quantity' => 1, 'price' => '100']]]);
    expect($order->manager_id)->toBe($designer->id);
    $this->get('/crm/orders')->assertOk()->assertSee('DES-1')->assertDontSee($foreignOrder->number);
    $this->get('/crm/payments')->assertOk()->assertSee('Записать перечисление');
    foreach (['/crm/users', '/crm/payouts', '/crm/accruals', '/crm-register.csv'] as $url) {
        $this->get($url)->assertForbidden();
    }
    $ledger = app(Ledger::class);
    expect(fn () => $ledger->payment($input))->toThrow(ModelNotFoundException::class);
    $payment = $ledger->payment(array_replace($input, ['order_id' => $order->id, 'amount' => '50']));
    expect($payment->recipient)->toBe('SFP')->and($payment->accruals)->toBeEmpty();
    $cashData = ['idempotency_key' => (string) Str::uuid(), 'amount' => '50', 'collected_at' => today()->toDateString(), 'order_id' => $order->id];
    $desk = app(CashDesk::class);
    expect(fn () => $desk->collect(array_replace($cashData, ['order_id' => $foreignOrder->id])))->toThrow(ModelNotFoundException::class);
    $cash = $desk->collect($cashData);
    expect($cash->collector_id)->toBe($designer->id)->and($order->fresh()->paid_minor)->toBe(10000);
    expect(fn () => $ledger->acceptCash($cash, today()->toDateString()))->toThrow(HttpException::class);
    $cashier = User::factory()->create(['active' => true, 'role' => 'cashier']);
    $this->actingAs($cashier);
    $ledger->acceptCash($cash, today()->toDateString());
    expect($order->fresh()->paid_minor)->toBe(10000);
});

it('converts an agreed deal once and copies positions at their exact prices', function () {
    [$owner,,$manager,$order] = crmScenario();
    $writer = app(CrmWriter::class);
    $deal = $writer->save(Deal::class, ['title' => 'Тестовая сделка', 'client_id' => $order->client_id, 'manager_id' => $manager->id, 'currency' => 'UZS', 'status' => 'won', 'items' => [['name' => 'Печать', 'quantity' => 3, 'price' => '12.35']]]);
    $converted = $writer->convert($deal);
    expect($writer->convert($deal)->id)->toBe($converted->id)->and($converted->total_minor)->toBe(3705)->and($converted->currency)->toBe('UZS');
});
