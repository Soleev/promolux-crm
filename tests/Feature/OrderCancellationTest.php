<?php

use App\Filament\Pages\ClientDebts;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\Activity;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\Task;
use App\Models\User;
use App\Services\CashDesk;
use App\Services\CrmWriter;
use App\Services\Ledger;
use App\Services\MonthlyCharts;
use App\Services\OrderCancellationService;
use App\Services\Report;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

it('allows each authorized role to cancel an unpaid order and records the operation once', function (string $role) {
    [$owner, $rop, $manager, $order] = crmScenario();
    $actor = compact('owner', 'rop', 'manager')[$role];
    $this->actingAs($actor);
    $service = app(OrderCancellationService::class);
    $key = (string) Str::uuid();

    $cancellation = $service->cancel($order, 'Клиент отказался от изготовления', $key);

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($cancellation->previous_status)->toBe('new')
        ->and($cancellation->cancelled_by)->toBe($actor->id)
        ->and($service->cancel($order, 'Клиент отказался от изготовления', $key)->id)->toBe($cancellation->id);
    $this->assertDatabaseCount('order_cancellations', 1);
    $this->assertDatabaseCount('payments', 0);
    expect(Activity::where('subject_type', Order::class)->where('subject_id', $order->id)->where('event', 'order_cancelled')->sole()->changes['reason'])
        ->toBe('Клиент отказался от изготовления');
    expect(fn () => $service->cancel($order, 'Другая причина', $key))->toThrow(HttpException::class);
    expect(fn () => $order->fresh()->delete())->toThrow(ValidationException::class);
})->with(['owner', 'rop', 'manager']);

it('excludes a cancelled production order, preserves task history and alerts each assignee once', function () {
    [$owner, $rop, $manager, $order] = crmScenario();
    $designer = User::factory()->create(['role' => 'designer', 'active' => true]);
    $order->update(['status' => 'production']);
    $sfp = \App\Models\CatalogSupplier::firstOrCreate(
    ['code' => 'sfp'],
    ['name' => 'Цех SFP']
);

$order->items()->firstOrFail()->update([
    'catalog_snapshot' => ['supplier_id' => $sfp->id],
]);
    $assignees = [$manager, $designer, $designer, $rop];
    $tasks = collect(['open', 'in_progress', 'done', 'cancelled'])->map(
        fn (string $status, int $index) => Task::create([
            'title' => 'Проверка производства '.$status,
            'manager_id' => $assignees[$index]->id,
            'order_id' => $order->id,
            'status' => $status,
            'due_at' => now()->addDay(),
        ])
    );

    $this->get('/crm/sfp?order='.$order->id)->assertOk();
    app(OrderCancellationService::class)->cancel($order, 'Печать остановлена', (string) Str::uuid());

    expect($tasks->map(fn (Task $task) => $task->fresh()->status)->all())->toBe(['cancelled', 'cancelled', 'done', 'cancelled'])
        ->and(Activity::where('subject_type', Task::class)->where('subject_id', $tasks[1]->id)->where('event', 'updated')->sole()->changes['before']['status'])->toBe('in_progress');
    foreach ([$manager, $designer, $rop] as $recipient) {
        expect($recipient->notifications()->sole()->data['title'])->toContain('отменён');
    }
    expect($owner->notifications()->count())->toBe(0);
    $this->get('/crm/sfp?order='.$order->id)->assertNotFound();
    Livewire::test(ClientDebts::class)->assertCanNotSeeTableRecords([$order])->assertViewHas('orderCount', 0);
});

it('checks role, current responsibility, reason, pending cash and delivery under server control', function () {
    [$owner, $rop, $manager, $order, $paymentInput] = crmScenario();
    $service = app(OrderCancellationService::class);
    $cancel = fn (string $reason = 'Клиент отказался') => $service->cancel($order, $reason, (string) Str::uuid());

    $this->actingAs(User::factory()->create(['role' => 'manager', 'active' => true]));
    expect($cancel)->toThrow(ModelNotFoundException::class);
    $this->actingAs($manager);
    expect(fn () => $cancel('  '))->toThrow(ValidationException::class);

    $this->actingAs($owner);
    $pending = app(CashDesk::class)->collect(array_replace($paymentInput, ['amount' => '25', 'collected_at' => today()->toDateString()]));
    foreach ([$owner, $rop, $manager] as $actor) {
        $this->actingAs($actor);
        expect($cancel)->toThrow(ValidationException::class);
    }
    $this->actingAs($owner);
    app(CashDesk::class)->cancel($pending, 'Деньги возвращены до передачи в кассу');
    $order->update(['status' => 'delivered']);
    expect($cancel)->toThrow(ValidationException::class);
    $this->assertDatabaseCount('order_cancellations', 0);

    $order->update(['status' => 'production']);
    $owner->update(['active' => false]);
    expect($cancel)->toThrow(HttpException::class);
});

it('keeps posted payments and accruals on cancellation and separates order totals in the report and chart', function (string $role) {
    [$owner, $rop, $manager, $order, $input] = crmScenario();
    $service = app(OrderCancellationService::class);
    $payment = app(Ledger::class)->payment(array_replace($input, ['amount' => '5000000']));
    $initialAccruals = $payment->accruals()->pluck('amount_minor', 'id')->all();
    $key = (string) Str::uuid();
    $reason = 'Клиент согласовал отмену заказа';
    $this->actingAs($manager);
    expect(fn () => $service->cancel($order, $reason, $key))->toThrow(HttpException::class);

    $this->actingAs(compact('owner', 'rop')[$role]);
    $service->cancel($order, $reason, $key);

    $this->assertDatabaseCount('payments', 1);
    expect($payment->accruals()->pluck('amount_minor', 'id')->all())->toBe($initialAccruals);
    $report = app(Report::class)->build($owner, null, null);
    expect($report)->toMatchArray(['orders' => 1, 'active_orders' => 0, 'cancelled_orders' => 1])
        ->and($report['currencies']['UZS'])->toMatchArray([
            'order_total' => 0, 'cancelled_order_total' => 1000000000, 'client_payments' => 500000000,
        ]);
    $date = today()->toDateString();
    expect(app(MonthlyCharts::class)->build($owner, $date, $date, null, 'day')[$date])->toMatchArray([
        'orders' => 0, 'cancelled_orders' => 1000000000, 'payments' => 500000000,
    ]);
})->with(['owner', 'rop']);

it('restores a recorded previous status only through the authorized restoration', function () {
    [$owner, $rop, $manager, $order] = crmScenario();
    $order->update(['status' => 'design']);
    $service = app(OrderCancellationService::class);
    $service->cancel($order, 'Приостановить изготовление', (string) Str::uuid());
    $key = (string) Str::uuid();

    $this->actingAs($manager);
    expect(fn () => $service->restore($order, $key))->toThrow(HttpException::class)
        ->and(fn () => app(CrmWriter::class)->save(Order::class, [], $order->fresh()))->toThrow(AuthorizationException::class);
    $this->actingAs($rop);
    $restored = $service->restore($order, $key);
    expect($restored->restored_by)->toBe($rop->id)
        ->and($order->fresh()->status)->toBe('design')
        ->and($service->restore($order, $key)->id)->toBe($restored->id)
        ->and(Activity::where('subject_type', Order::class)->where('subject_id', $order->id)->where('event', 'order_restored')->count())->toBe(1);

    $legacy = Order::create(['number' => (string) Str::ulid(), 'client_id' => $order->client_id, 'status' => 'cancelled']);
    expect(fn () => $service->restore($legacy, (string) Str::uuid()))->toThrow(ValidationException::class);
});

it('uses a dedicated Livewire action and rejects cancellation through the regular editor', function () {
    [, , , $order] = crmScenario();
    $input = array_replace($order->only(['number', 'client_id', 'manager_id', 'currency']), [
        'status' => 'cancelled',
        'items' => [['name' => 'Макет', 'price' => '100', 'quantity' => 1]],
    ]);
    expect(fn () => app(CrmWriter::class)->save(Order::class, $input, $order))->toThrow(ValidationException::class);

    $component = Livewire::test(ManageOrders::class)->mountTableAction('cancelOrder', $order);
    $component->setTableActionData(['reason' => 'Заказ больше не нужен'])->callMountedTableAction()->assertHasNoTableActionErrors();
    expect(OrderCancellation::sole()->reason)->toBe('Заказ больше не нужен')
        ->and($order->fresh()->status)->toBe('cancelled');
    Livewire::test(ManageOrders::class)
        ->mountTableAction('restoreOrder', $order->fresh())->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($order->fresh()->status)->toBe('new');
});
it('rejects new payments and cash collection for cancelled orders', function () {
    [$owner, $rop, $manager, $order, $input] = crmScenario();

    app(OrderCancellationService::class)->cancel(
        $order,
        'Клиент отказался от заказа',
        (string) Str::uuid()
    );

    expect(fn () => app(Ledger::class)->payment($input))
        ->toThrow(ValidationException::class);

    $this->actingAs($manager);

    expect(fn () => app(CashDesk::class)->collect([
        'order_id' => $order->id,
        'idempotency_key' => (string) Str::uuid(),
        'amount' => '100000',
        'collected_at' => today()->toDateString(),
    ]))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('cash_receipts', 0);
    $this->assertDatabaseCount('accruals', 0);
});