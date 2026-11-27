<?php

use App\Filament\Pages\Overview;
use App\Models\Client;
use App\Models\CommissionSettlement;
use App\Models\Order;
use App\Models\User;
use App\Services\CrmWriter;
use App\Services\Ledger;
use App\Services\MonthlyCharts;
use App\Services\Report;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

function financialReportScenario(): array
{
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $rop = User::factory()->create(['role' => 'rop', 'active' => true]);
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    test()->actingAs($owner);
    $client = Client::create(['name' => 'Клиент отчёта', 'manager_id' => $manager->id]);
    $order = app(CrmWriter::class)->save(Order::class, [
        'number' => 'REPORT-1', 'client_id' => $client->id, 'manager_id' => $manager->id, 'status' => 'new',
        'items' => [['name' => 'Печать', 'price' => '10000000', 'quantity' => 1]],
    ]);
    $input = ['idempotency_key' => (string) Str::uuid(), 'order_id' => $order->id,
        'paid_at' => today()->toDateString(), 'amount' => '1000', 'currency' => 'UZS'];

    return [$owner, $rop, $manager, $order, $input];
}

it('uses calendar dates consistently for first-day accruals and chart totals', function () {
    $this->travelTo(now()->setDate(2026, 11, 15)->startOfDay());
    [$owner, , , , $input] = financialReportScenario();
    app(Ledger::class)->payment(array_replace($input, ['paid_at' => '2026-11-01', 'amount' => '1000']));

    $totals = app(Report::class)->build($owner, '2026-11-01 00:00:00', '2026-11-15')['currencies']['UZS'];
    $charts = app(MonthlyCharts::class)->build($owner, '2026-11-01 00:00:00', '2026-11-15', null, 'day');
    expect($totals)->toMatchArray(['owner_gross' => 5000, 'rop_gross' => 5000, 'manager_accrued' => 5000])
        ->and(array_sum(array_column($charts, 'earned')))->toBe(10000);
});

it('uses today for charts and summary when the end date is cleared', function () {
    $this->travelTo(now()->setDate(2026, 11, 15)->startOfDay());
    financialReportScenario();
    $page = Livewire::test(Overview::class)->set('until', null);
    expect($page->instance()->chartMonths)->toHaveCount(15);
});

it('exports negative money numerically while escaping dangerous recipient and order text', function () {
    $this->travelTo(now()->setDate(2026, 11, 15)->startOfDay());
    [$owner, , , $order, $input] = financialReportScenario();
    $owner->update(['name' => '=1+1']);
    $order->update(['number' => '-123']);
    $ledger = app(Ledger::class);
    $payment = $ledger->payment(array_replace($input, ['amount' => '1000']));
    $this->travelTo(now()->addDay());
    $ledger->reversePayment($payment, 'Исправление ввода');

    $csv = $this->get('/crm-register.csv?from=2026-11-16&until=2026-11-16')->assertOk()->streamedContent();
    $rows = collect(explode("\n", trim(substr($csv, 3))))->skip(1)
        ->map(fn ($line) => str_getcsv($line, ';', '"', ''));
    expect($rows->pluck(2)->unique()->all())->toBe(["'-123"])
        ->and($rows->pluck(3)->all())->toContain("'=1+1")
        ->and($rows->pluck(8)->all())->toContain('-50.00', '-25.00');
});

it('keeps other recipients SFP recoveries out of the personal rop chart', function () {
    $this->travelTo(now()->setDate(2026, 11, 15)->startOfDay());
    [$owner, $rop, , , $input] = financialReportScenario();
    app(Ledger::class)->payment($input);
    CommissionSettlement::create([
        'idempotency_key' => (string) Str::uuid(), 'beneficiary_id' => $owner->id,
        'payer' => 'SFP', 'currency' => 'UZS', 'amount_minor' => 1000, 'kind' => 'repayment',
        'method' => 'bank_transfer', 'reason' => 'Историческое погашение', 'settled_on' => '2026-11-15',
        'recorded_by' => $owner->id,
    ]);

    $chart = app(MonthlyCharts::class)->build($rop, '2026-11-01', '2026-11-15', null, 'day');
    expect($chart['2026-11-15']['balance'])->toBe(5000);
});

it('reconciles opening balances period payments and unassigned settlements without future operations', function () {
    $this->travelTo(now()->setDate(2026, 12, 15)->startOfDay());
    [$owner, $rop, $manager, , $input] = financialReportScenario();
    $ledger = app(Ledger::class);
    $payment = $ledger->payment(array_replace($input, ['paid_at' => '2026-10-15']));
    $accrual = $payment->accruals()->where('kind', 'owner_gross')->sole();
    foreach ([['2026-10-20', '10'], ['2026-11-15', '15'], ['2026-12-01', '20']] as [$date, $amount]) {
        $ledger->payout(['idempotency_key' => (string) Str::uuid(), 'payer' => 'SFP', 'recipient_id' => $owner->id,
            'paid_at' => $date, 'currency' => 'UZS', 'amount' => $amount,
            'allocations' => [['accrual_id' => $accrual->id, 'amount' => $amount]]]);
    }
    foreach ([['2026-10-20', 'SFP', $owner->id, 'repayment', 100],
        ['2026-11-20', 'SFP', $owner->id, 'repayment', 200],
        ['2026-11-21', 'owner:'.$owner->id, $manager->id, 'write_off', 300],
        ['2026-12-01', 'SFP', $owner->id, 'repayment', 900]] as [$date, $payer, $recipient, $kind, $amount]) {
        CommissionSettlement::create(['idempotency_key' => (string) Str::uuid(), 'beneficiary_id' => $recipient,
            'payer' => $payer, 'currency' => 'UZS', 'amount_minor' => $amount, 'kind' => $kind,
            'method' => 'bank_transfer', 'reason' => 'Историческая операция', 'settled_on' => $date, 'recorded_by' => $owner->id]);
    }
    $report = app(Report::class);
    $rows = $report->register($owner, '2026-11-01', '2026-11-30');
    $sfp = $rows->where('payer', 'SFP');
    expect($sfp->sum('opening'))->toBe(9100)
        ->and($sfp->sum('accrued'))->toBe(0)->and($sfp->sum('paid'))->toBe(1500)
        ->and($sfp->sum('repaid'))->toBe(200)->and($sfp->sum('remaining'))->toBe(7800);
    expect($rows->where('payer', '!=', 'SFP')->sum('remaining'))->toBe(5300);
    foreach ($rows as $row) {
        expect($row['remaining'])->toBe($row['opening'] + $row['accrued'] - $row['paid'] - $row['storno'] + $row['repaid'] + $row['written_off']);
    }
    expect($report->build($owner, '2026-11-01', '2026-11-30')['currencies']['UZS'])
        ->toMatchArray(['sfp_opening' => 9100, 'sfp_remaining' => 7800, 'manager_remaining' => 5300, 'manager_written_off' => 300]);
    expect(app(MonthlyCharts::class)->build($owner, '2026-11-01', '2026-11-30', null, 'month')['2026-11']['balance'])->toBe(7800);
    $filtered = $report->register($owner, '2026-11-01', '2026-11-30', $manager->id);
    expect($filtered->whereNull('payment_id'))->toBeEmpty()
        ->and($filtered->where('payer', 'SFP')->sum('remaining'))->toBe(7500);
    $csv = $this->get('/crm-register.csv?from=2026-11-01&until=2026-11-30')->assertOk()->streamedContent();
    $csvRows = collect(explode("\n", trim(substr($csv, 3))))->skip(1)->map(fn ($line) => str_getcsv($line, ';', '"', ''));
    expect($csvRows)->toHaveCount($rows->count());
    expect($csvRows->where(6, 'SFP')->sum(fn ($row) => (int) round((float) $row[13] * 100)))->toBe(7800);
    expect($this->get('/crm-register.csv?manager='.$manager->id)->assertOk()->streamedContent())->toContain('Частичный баланс');
});

it('omits closed old accruals without movement and includes their payout reversals on the actual date', function () {
    $this->travelTo(now()->setDate(2026, 10, 20)->startOfDay());
    [$owner, , , , $input] = financialReportScenario();
    $ledger = app(Ledger::class);
    $payment = $ledger->payment($input);
    $accrual = $payment->accruals()->where('kind', 'owner_gross')->sole();
    $payout = $ledger->payout(['idempotency_key' => (string) Str::uuid(), 'payer' => 'SFP',
        'recipient_id' => $owner->id, 'paid_at' => '2026-10-20', 'currency' => 'UZS', 'amount' => '50',
        'allocations' => [['accrual_id' => $accrual->id, 'amount' => '50']]]);
    $report = app(Report::class);
    expect($report->register($owner, '2026-11-01', '2026-11-30')->where('beneficiary', $owner->name))->toBeEmpty();
    $this->travelTo(now()->setDate(2026, 11, 15));
    $ledger->reversePayout($payout, 'Исправление выплаты');
    expect($report->register($owner, '2026-11-01', '2026-11-14')->where('beneficiary', $owner->name))->toBeEmpty();
    $row = $report->register($owner, '2026-11-01', '2026-11-15')->where('beneficiary', $owner->name)->sole();
    expect($row)->toMatchArray(['opening' => 0, 'accrued' => 0, 'paid' => -5000, 'remaining' => 5000]);
    $this->travelTo(now()->setDate(2026, 11, 16));
    $ledger->reversePayment($payment, 'Исправление оплаты');
    expect($report->register($owner, '2026-11-01', '2026-11-30')->where('beneficiary', $owner->name)->sole())
        ->toMatchArray(['opening' => 0, 'accrued' => -5000, 'paid' => -5000, 'remaining' => 0]);
    expect($report->register($owner, '2026-12-01', '2026-12-31'))->toBeEmpty();
});
