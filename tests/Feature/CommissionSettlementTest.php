<?php

use App\Filament\Pages\CommissionSettlements as SettlementPage;
use App\Models\Accrual;
use App\Models\Activity;
use App\Models\Client;
use App\Models\CommissionSettlement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\CommissionBalances;
use App\Services\CommissionSettlements;
use App\Services\CrmWriter;
use App\Services\Ledger;
use App\Services\Money;
use App\Services\RefundService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

function settlementFixturePayment(User $manager, string $amount): Payment
{
    $writer = app(CrmWriter::class);
    $client = $writer->save(Client::class, ['name' => 'Тестовый клиент', 'manager_id' => $manager->id]);
    $order = $writer->save(Order::class, [
        'client_id' => $client->id, 'manager_id' => $manager->id, 'status' => 'new', 'number' => (string) Str::ulid(),
        'items' => [['name' => 'Печать', 'quantity' => 1, 'price' => '10000']],
    ]);

    return app(Ledger::class)->payment([
        'order_id' => $order->id, 'paid_at' => today()->toDateString(), 'amount' => $amount,
        'idempotency_key' => (string) Str::uuid(),
    ]);
}

function settlementFixturePayout(Accrual $accrual, int $amount): void
{
    app(Ledger::class)->payout([
        'idempotency_key' => (string) Str::uuid(), 'payer' => $accrual->payer,
        'recipient_id' => $accrual->beneficiary_id, 'paid_at' => today()->toDateString(),
        'amount' => Money::format($amount),
        'allocations' => [['accrual_id' => $accrual->id, 'amount' => Money::format($amount)]],
    ]);
}

function settlementFixtureRefund(Payment $payment): Refund
{
    $service = app(RefundService::class);
    $refund = $service->create([
        'idempotency_key' => (string) Str::uuid(), 'order_id' => $payment->order_id, 'amount' => '4000',
        'reason' => 'Тестовый возврат', 'method' => 'bank_transfer',
        'allocations' => [['payment_id' => $payment->id, 'amount' => '4000']],
    ]);
    $service->approve($refund, 'SFP', (string) Str::uuid());

    return $service->execute($refund, today()->toDateString(), (string) Str::uuid());
}

function settlementFixtureInput(Accrual $accrual, string $kind, string $amount): array
{
    return [
        'idempotency_key' => (string) Str::uuid(), 'payer' => $accrual->payer,
        'beneficiary_id' => $accrual->beneficiary_id, 'settled_on' => today()->toDateString(),
        'reason' => 'Операция по встречной задолженности', 'kind' => $kind, 'amount' => $amount,
        'method' => 'bank_transfer',
    ];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00', 'Asia/Tashkent'));
    $this->owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $this->rop = User::factory()->create(['role' => 'rop', 'active' => true]);
    $this->manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($this->owner);
    $this->payment = settlementFixturePayment($this->manager, '10000');
    $this->internal = $this->payment->accruals()->where('kind', 'manager_owner')->sole();
    $this->external = $this->payment->accruals()->where('kind', 'owner_gross')->sole();
    settlementFixturePayout($this->internal, $this->internal->amount_minor);
    settlementFixturePayout($this->external, $this->external->amount_minor);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'Asia/Tashkent'));
    $this->refund = settlementFixtureRefund($this->payment);
    $this->balances = app(CommissionBalances::class);
    $this->settlements = app(CommissionSettlements::class);
});

it('credits future accruals against the same original internal account before allowing payouts', function () {
    $payer = $this->internal->payer;
    $account = fn (string $date): int => $this->balances->balance($this->manager->id, $payer, 'UZS', $date);
    expect($account('2026-11-24'))->toBe(0)
        ->and($account('2026-11-25'))->toBe(-10000);

    $future = settlementFixturePayment($this->manager, '1000');
    $credit = $future->accruals()->where('kind', 'manager_owner')->sole();
    expect($account('2026-11-25'))->toBe(-7500);
    expect(fn () => settlementFixturePayout($credit, $credit->amount_minor))->toThrow(ValidationException::class);
    $otherPayerCredit = $future->accruals()->where('kind', 'manager_rop')->sole();
    settlementFixturePayout($otherPayerCredit, $otherPayerCredit->amount_minor);
    expect($account('2026-11-25'))->toBe(-7500);

    $later = settlementFixturePayment($this->manager, '4000')->accruals()->where('kind', 'manager_owner')->sole();
    expect($account('2026-11-25'))->toBe(2500);
    expect(fn () => settlementFixturePayout($later, 2501))->toThrow(ValidationException::class);
    settlementFixturePayout($later, 2500);
    expect($account('2026-11-25'))->toBe(0);
});

it('records actual repayments in separate internal and SFP accounts, with payload-bound replay', function () {
    $this->manager->update(['active' => false]);
    $internal = settlementFixtureInput($this->internal, CommissionSettlement::REPAYMENT, '40');
    $external = settlementFixtureInput($this->external, CommissionSettlement::REPAYMENT, '200');
    $entry = $this->settlements->record($internal);
    expect($entry->amount_minor)->toBe(4000)
        ->and($this->settlements->record($internal)->id)->toBe($entry->id)
        ->and($this->balances->balance($this->manager->id, $this->internal->payer, 'UZS', '2026-11-25'))->toBe(-6000);
    expect(fn () => $this->settlements->record(array_replace($internal, ['amount' => '41'])))
        ->toThrow(HttpException::class);

    $this->settlements->record($external);
    expect($this->balances->balance($this->owner->id, 'SFP', 'UZS', '2026-11-25'))->toBe(0)
        ->and($this->balances->balance($this->manager->id, $this->internal->payer, 'UZS', '2026-11-25'))->toBe(-6000)
        ->and(Activity::where('subject_type', CommissionSettlement::class)->where('event', 'created')->count())->toBe(2);
    $this->assertDatabaseCount('commission_settlements', 2);
});

it('allows only the marketer to write off actual internal debt with a reason and date', function () {
    $input = settlementFixtureInput($this->internal, CommissionSettlement::WRITE_OFF, '100');
    $this->actingAs($this->rop);
    expect(fn () => $this->settlements->record($input))->toThrow(HttpException::class);
    $this->actingAs($this->owner);
    foreach ([
        ['amount' => '100.01'],
        ['reason' => '   '],
        ['settled_on' => '2026-11-24'],
        ['payer' => 'SFP', 'beneficiary_id' => $this->owner->id],
    ] as $invalid) {
        expect(fn () => $this->settlements->record(array_replace($input, $invalid)))->toThrow(ValidationException::class);
    }
    $this->assertDatabaseCount('commission_settlements', 0);

    $writeOff = $this->settlements->record($input);
    expect($writeOff->method)->toBeNull()
        ->and($this->balances->balance($this->manager->id, $this->internal->payer, 'UZS', '2026-11-25'))->toBe(0)
        ->and($this->balances->balance($this->owner->id, 'SFP', 'UZS', '2026-11-25'))->toBe(-20000);
    expect(fn () => $writeOff->update(['amount_minor' => 1]))->toThrow(ValidationException::class);
    expect(fn () => $writeOff->delete())->toThrow(ValidationException::class);
});

it('opens the settlement page for the marketer and records an approved action through Livewire', function () {
    $url = SettlementPage::getUrl();
    $this->actingAs($this->rop)->get($url)->assertForbidden();
    $this->actingAs($this->manager)->get($url)->assertForbidden();
    $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('Расчёты по комиссиям');
    $input = settlementFixtureInput($this->internal, CommissionSettlement::REPAYMENT, '40');

    Livewire::test(SettlementPage::class)
        ->mountAction(CommissionSettlement::REPAYMENT)
        ->fillForm(['beneficiary_id' => $input['beneficiary_id']])
        ->fillForm(Arr::except($input, ['beneficiary_id', 'kind']) + ['money_transferred' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();
    $this->get($url)->assertOk()->assertSee($input['reason']);
    $this->assertDatabaseCount('commission_settlements', 1);
});
