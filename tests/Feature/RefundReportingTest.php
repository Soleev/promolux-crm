<?php

use App\Filament\Pages\ClientDebts;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundCommissionReversal;
use App\Models\User;
use App\Services\CrmWriter;
use App\Services\Ledger;
use App\Services\Money;
use App\Services\OrderCancellationService;
use App\Services\RefundAccess;
use App\Services\RefundService;
use App\Services\Report;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

function refundReportingScenario(): array
{
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $rop = User::factory()->create(['role' => 'rop', 'active' => true]);
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    test()->actingAs($owner);
    $client = Client::create(['name' => 'Тестовый клиент', 'manager_id' => $manager->id]);
    $order = app(CrmWriter::class)->save(Order::class, [
        'number' => (string) Str::ulid(), 'client_id' => $client->id, 'manager_id' => $manager->id,
        'status' => 'new', 'items' => [['name' => 'Тестовая позиция', 'price' => '10000000', 'quantity' => 1]],
    ]);
    $input = [
        'idempotency_key' => (string) Str::uuid(), 'order_id' => $order->id,
        'paid_at' => today()->toDateString(), 'amount' => '10000000', 'currency' => 'UZS',
    ];

    return [$owner, $rop, $manager, $order, $input];
}

function createReportingRefund(Payment $payment, string $amount): Refund
{
    return app(RefundService::class)->create([
        'order_id' => $payment->order_id,
        'idempotency_key' => (string) Str::uuid(),
        'amount' => $amount,
        'currency' => $payment->currency,
        'method' => 'bank_transfer',
        'reason' => 'Согласован возврат клиенту',
        'allocations' => [['payment_id' => $payment->id, 'amount' => $amount]],
    ]);
}

function payReportingAccruals(Payment $payment, string $payer, User $recipient): void
{
    $accruals = $payment->accruals()->where('payer', $payer)->get();
    app(Ledger::class)->payout([
        'idempotency_key' => (string) Str::uuid(),
        'payer' => $payer,
        'recipient_id' => $recipient->id,
        'paid_at' => today()->toDateString(),
        'currency' => $payment->currency,
        'amount' => Money::format($accruals->sum('amount_minor')),
        'allocations' => $accruals->map(fn ($row) => [
            'accrual_id' => $row->id,
            'amount' => Money::format($row->amount_minor),
        ])->all(),
    ]);
}

it('prevents restoration during unresolved and completed refunds and permits it after a declined request', function () {
    [, , , $order, $input] = refundReportingScenario();
    $payment = app(Ledger::class)->payment($input);
    $orders = app(OrderCancellationService::class);
    $refunds = app(RefundService::class);
    $orders->cancel($order, 'Отмена по решению клиента', (string) Str::uuid());
    $draft = createReportingRefund($payment, '100');
    $restore = fn () => $orders->restore($order, (string) Str::uuid());

    expect($restore)->toThrow(ValidationException::class);
    $refunds->reject($draft, 'Клиент оставил оплату', (string) Str::uuid());
    $restore();
    expect($order->fresh()->status)->toBe('new');

    $orders->cancel($order, 'Клиент подтвердил повторную отмену', (string) Str::uuid());
    $second = createReportingRefund($payment, '100');
    $second = $refunds->approve($second, 'Promolux', (string) Str::uuid());
    expect($restore)->toThrow(ValidationException::class);
    $refunds->execute($second, today()->toDateString(), (string) Str::uuid());
    expect($restore)->toThrow(ValidationException::class);
    expect($order->fresh()->status)->toBe('cancelled');
});

it('reports approved liabilities as of their date and recognizes refunds and both groups of storno on actual execution', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00'));
    [$owner, $rop, $manager, , $input] = refundReportingScenario();
    $payment = app(Ledger::class)->payment($input);
    payReportingAccruals($payment, 'SFP', $owner);
    payReportingAccruals($payment, 'owner:'.$owner->id, $manager);
    payReportingAccruals($payment, 'rop:'.$rop->id, $manager);

    $report = app(Report::class);
    $old = $report->build($owner, '2026-10-01', '2026-10-31')['currencies']['UZS'];
    expect($old)->toMatchArray([
        'client_payments' => 1_000_000_000, 'client_refunds' => 0,
        'client_net_payments' => 1_000_000_000, 'client_refund_due' => 0,
        'manager_storno' => 0, 'sfp_storno' => 0, 'employee_debt' => 0, 'sfp_debt' => 0,
    ]);
    $oldRegister = $report->register($owner, '2026-10-01', '2026-10-31')->all();

    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    $service = app(RefundService::class);
    $refund = createReportingRefund($payment, '4000000');
    $service->approve($refund, 'SFP', (string) Str::uuid());
    $approval = $report->build($owner, '2026-11-01', '2026-11-04')['currencies']['UZS'];
    expect($approval)->toMatchArray([
        'client_payments' => 0, 'client_refunds' => 0, 'client_net_payments' => 0, 'client_refund_due' => 400_000_000,
        'manager_storno' => 0, 'sfp_storno' => 0, 'employee_debt' => 0, 'sfp_debt' => 0,
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00:00'));
    $service->execute($refund, '2026-11-06', (string) Str::uuid());
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('payouts', 3);
    $this->assertDatabaseCount('accruals', 4);
    $this->assertDatabaseCount('refund_commission_reversals', 4);
    expect($payment->fresh()->paid_at->toDateString())->toBe('2026-10-15')
        ->and(RefundCommissionReversal::where('refund_id', $refund->id)->get()
            ->map(fn (RefundCommissionReversal $row) => $row->recorded_at->toDateString())->unique()->all())
        ->toBe(['2026-11-06'])
        ->and($report->build($owner, '2026-10-01', '2026-10-31')['currencies']['UZS'])->toBe($old)
        ->and($report->build($owner, '2026-11-01', '2026-11-04')['currencies']['UZS'])->toBe($approval)
        ->and($report->build($owner, '2026-11-06', '2026-11-06')['currencies']['UZS'])->toMatchArray([
            'client_payments' => 0, 'client_refunds' => 400_000_000, 'client_net_payments' => -400_000_000, 'client_refund_due' => 0,
            'manager_storno' => 20_000_000, 'sfp_storno' => 40_000_000,
            'manager_paid' => 0, 'sfp_paid' => 0, 'employee_debt' => 20_000_000, 'sfp_debt' => 40_000_000,
            'manager_remaining' => -20_000_000, 'sfp_remaining' => -40_000_000,
        ])
        ->and($report->build($owner, null, '2026-11-07')['currencies']['UZS'])->toMatchArray([
            'client_payments' => 1_000_000_000, 'client_refunds' => 400_000_000, 'client_net_payments' => 600_000_000,
        ]);

    $reversalRows = $report->register($owner, '2026-11-06', '2026-11-06');
    expect($report->register($owner, '2026-10-01', '2026-10-31')->all())->toBe($oldRegister)
        ->and($reversalRows)->toHaveCount(4)
        ->and($reversalRows->pluck('payment_id')->unique()->all())->toBe([$payment->id])
        ->and($reversalRows->pluck('month')->unique()->all())->toBe(['2026-10'])
        ->and($reversalRows->sum('accrued'))->toBe(0)
        ->and($reversalRows->sum('storno'))->toBe(60_000_000);
    foreach ($reversalRows as $row) {
        expect($row['remaining'])->toBe($row['opening'] - $row['storno']);
    }
    $refundCsv = $this->get('/crm-register.csv?type=refunds&from=2026-11-06&until=2026-11-06')
        ->assertOk()->assertDownload('promolux-refunds.csv')->streamedContent();
    expect($refundCsv)->toContain($refund->number)->toContain('2026-11-06')->toContain('4000000.00');
});

it('keeps a cancelled approval in the historical liability and excludes cancelled orders from receivables', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    [$owner, , $manager, $cancelled, $input] = refundReportingScenario();
    $active = Order::create([
        'number' => 'ACTIVE-REFUND-TEST', 'status' => 'new', 'client_id' => $cancelled->client_id, 'manager_id' => $manager->id, 'currency' => 'UZS',
    ]);
    $active->items()->create(['name' => 'Тестовая активная позиция', 'quantity' => 1, 'price_minor' => 300_000_000]);
    $received = app(Ledger::class)->payment(array_replace($input, ['amount' => '5000000']));
    app(OrderCancellationService::class)->cancel($cancelled, 'Отмена по запросу клиента', (string) Str::uuid());
    $refund = createReportingRefund($received, '2000000');
    $service = app(RefundService::class);
    $service->approve($refund, 'Promolux', (string) Str::uuid());

    $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00:00'));
    $service->cancel($refund, 'Клиент отказался от возврата', (string) Str::uuid());
    $report = app(Report::class);
    expect($report->build($owner, '2026-11-04', '2026-11-04')['currencies']['UZS']['client_refund_due'])->toBe(200_000_000)
        ->and($report->build($owner, null, null)['currencies']['UZS'])->toMatchArray([
            'order_total' => 300_000_000, 'cancelled_order_total' => 1_000_000_000,
            'client_payments' => 500_000_000, 'client_refunds' => 0, 'client_refund_due' => 0,
        ])
        ->and($cancelled->fresh()->receivable_minor)->toBe(0)
        ->and($active->fresh()->receivable_minor)->toBe(300_000_000);
    Livewire::test(ClientDebts::class)->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$cancelled]);
});

it('reserves payment corrections while a refund is approved or executed and releases a cancelled reservation', function () {
    [$owner, , , , $input] = refundReportingScenario();
    $ledger = app(Ledger::class);
    $refunds = app(RefundService::class);
    $payment = $ledger->payment($input);
    $draft = createReportingRefund($payment, '4000000');
    $refunds->approve($draft, 'SFP', (string) Str::uuid());
    expect(fn () => $ledger->reversePayment($payment, 'Исправление ошибочного ввода'))->toThrow(ValidationException::class);
    $refunds->cancel($draft, 'Снимем резерв до исправления', (string) Str::uuid());
    $reversal = $ledger->reversePayment($payment, 'Исправление ошибочного ввода');
    expect($reversal->reversal_of)->toBe($payment->id)
        ->and($reversal->amount_minor)->toBe(-$payment->amount_minor);

    $another = $ledger->payment(array_replace($input, ['idempotency_key' => (string) Str::uuid()]));
    $completed = createReportingRefund($another, '4000000');
    $refunds->approve($completed, 'SFP', (string) Str::uuid());
    $refunds->execute($completed, today()->toDateString(), (string) Str::uuid());
    expect(fn () => $ledger->reversePayment($another, 'Оплата с выполненным возвратом'))->toThrow(ValidationException::class);

    $data = app(Report::class)->build($owner, null, null)['currencies']['UZS'];
    expect($data)->toMatchArray([
        'client_payments' => 1_000_000_000, 'client_refunds' => 400_000_000,
        'client_net_payments' => 600_000_000, 'client_refund_due' => 0,
    ]);
    $this->assertDatabaseCount('payments', 3);
});

it('keeps refund and report access scoped to the current order and preserved commission permissions', function () {
    [$owner, $rop, $seller, $order, $input] = refundReportingScenario();
    $refunds = app(RefundService::class);
    $payment = app(Ledger::class)->payment($input);
    $refund = createReportingRefund($payment, '4000000');
    $refunds->approve($refund, 'SFP', (string) Str::uuid());
    $refunds->execute($refund, today()->toDateString(), (string) Str::uuid());

    $newManager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $outsider = User::factory()->create(['role' => 'manager', 'active' => true]);
    $cashier = User::factory()->create(['role' => 'cashier', 'active' => true]);
    $order->update(['manager_id' => $newManager->id]);

    $report = app(Report::class);
    expect(RefundAccess::query($rop)->find($refund->id))->not->toBeNull()
        ->and(RefundAccess::query($newManager)->find($refund->id))->not->toBeNull()
        ->and(RefundAccess::query($seller)->find($refund->id))->toBeNull()
        ->and(RefundAccess::query($outsider)->find($refund->id))->toBeNull()
        ->and(RefundAccess::query($cashier)->find($refund->id))->toBeNull();

    $sellerReport = $report->build($seller, null, null, $newManager->id)['currencies']['UZS'];
    $newManagerReport = $report->build($newManager, null, null)['currencies']['UZS'];
    $ropReport = $report->build($rop, null, null)['currencies']['UZS'];
    expect($sellerReport)->toMatchArray(['client_payments' => 0, 'client_refunds' => 0, 'client_refund_due' => 0, 'manager_storno' => 20_000_000])
        ->and($newManagerReport)->toMatchArray(['client_payments' => 1_000_000_000, 'client_refunds' => 400_000_000, 'client_net_payments' => 600_000_000, 'manager_storno' => 0])
        ->and($ropReport['client_refunds'])->toBe(400_000_000);
    foreach ([$sellerReport, $newManagerReport, $ropReport] as $staffReport) {
        foreach (['sfp_storno', 'sfp_debt', 'owner_net'] as $restricted) {
            expect($staffReport)->not->toHaveKey($restricted);
        }
    }
    $this->actingAs($newManager)->get('/crm-register.csv?type=refunds')->assertForbidden();
    expect(fn () => $report->register($newManager, null, null))->toThrow(HttpException::class)
        ->and(fn () => $report->build($cashier, null, null))->toThrow(HttpException::class)
        ->and($report->build($owner, null, null)['currencies']['UZS']['sfp_storno'])->toBe(40_000_000);
});
