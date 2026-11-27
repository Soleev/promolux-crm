<?php

use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundCommissionReversal;
use App\Models\User;
use App\Services\CrmWriter;
use App\Services\FinancialTerms;
use App\Services\Ledger;
use App\Services\RefundCommissionReversals;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

function reversalFixturePayment(Order $order, string $amount): Payment
{
    return app(Ledger::class)->payment([
        'order_id' => $order->id,
        'idempotency_key' => (string) Str::uuid(),
        'amount' => $amount,
        'paid_at' => today()->toDateString(),
    ]);
}

function reversalFixtureRefund(Order $order, array $allocations): Refund
{
    $refund = Refund::create([
        'order_id' => $order->id,
        'client_id' => $order->client_id,
        'number' => 'RF-'.Str::ulid(),
        'idempotency_key' => (string) Str::uuid(),
        'amount_minor' => array_sum($allocations),
        'currency' => $order->currency,
        'reason' => 'Тестовый возврат',
        'method' => 'bank_transfer',
        'created_by' => auth()->id(),
    ]);
    foreach ($allocations as $paymentId => $amount) {
        $refund->allocations()->create(['payment_id' => $paymentId, 'amount_minor' => $amount]);
    }

    return $refund;
}

function postReversalFixture(Refund $refund, string $executedAt = '2026-11-25 14:30:00'): void
{
    DB::transaction(function () use ($refund, $executedAt) {
        $refund->order()->lockForUpdate()->firstOrFail();
        Payment::whereKey($refund->allocations()->pluck('payment_id')->all())->orderBy('id')->lockForUpdate()->get();
        $refund->forceFill(['status' => Refund::COMPLETED, 'executed_at' => $executedAt])->save();
        app(RefundCommissionReversals::class)->record($refund);
    });
}

function reversalFixtureOrder(User $manager): Order
{
    $writer = app(CrmWriter::class);
    $client = $writer->save(Client::class, ['name' => 'Тестовый клиент', 'manager_id' => $manager->id]);

    return $writer->save(Order::class, [
        'number' => (string) Str::ulid(),
        'client_id' => $client->id,
        'manager_id' => $manager->id,
        'status' => 'new',
        'items' => [['name' => 'Печать', 'quantity' => 1, 'price' => '10000000']],
    ]);
}

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $this->rop = User::factory()->create(['role' => 'rop', 'active' => true]);
    $this->manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($this->owner);
});

it('previews standard reversal and records immutable original shares only after customer payment', function () {
    $order = reversalFixtureOrder($this->manager);
    $payment = reversalFixturePayment($order, '10000000');
    $refund = reversalFixtureRefund($order, [$payment->id => 400_000_000]);
    $service = app(RefundCommissionReversals::class);

    $expected = ['owner_gross' => 20_000_000, 'rop_gross' => 20_000_000, 'manager_owner' => 10_000_000, 'manager_rop' => 10_000_000];
    expect(collect($service->preview($refund))->pluck('amount_minor', 'kind')->all())->toBe($expected);
    expect(fn () => DB::transaction(fn () => $service->record($refund)))->toThrow(LogicException::class);
    $this->assertDatabaseCount('refund_commission_reversals', 0);

    postReversalFixture($refund);
    $reversals = RefundCommissionReversal::with('accrual')->orderBy('id')->get();
    expect($reversals->pluck('amount_minor', 'accrual.kind')->all())->toBe($expected)
        ->and($reversals->first()->recorded_at->toDateTimeString())->toBe('2026-11-25 14:30:00')
        ->and($payment->accruals()->where('kind', 'owner_gross')->value('amount_minor'))->toBe(50_000_000);
    expect(fn () => $reversals->first()->update(['amount_minor' => 1]))->toThrow(ValidationException::class);
    expect(fn () => $reversals->first()->delete())->toThrow(ValidationException::class);

    DB::transaction(fn () => $service->record($refund));
    $this->assertDatabaseCount('refund_commission_reversals', 4);
});

it('reverses the saved unequal funding shares after reassignment and role changes', function () {
    app(FinancialTerms::class)->publish($this->manager, [
        'owner_id' => $this->owner->id,
        'rop_id' => $this->rop->id,
        'owner_funding_bps' => 400,
        'rop_funding_bps' => 150,
    ]);
    $order = reversalFixtureOrder($this->manager);
    $payment = reversalFixturePayment($order, '10000000');
    $newManager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $order->update(['manager_id' => $newManager->id]);
    $this->manager->update(['role' => 'designer', 'active' => false]);
    $this->rop->update(['role' => 'manager']);
    User::factory()->create(['role' => 'rop', 'active' => true]);

    $refund = reversalFixtureRefund($order, [$payment->id => 400_000_000]);
    postReversalFixture($refund);

    $rows = RefundCommissionReversal::with('accrual')->get()->keyBy('accrual.kind');
    expect($rows['manager_owner']->amount_minor)->toBe(16_000_000)
        ->and($rows['manager_owner']->accrual->payer)->toBe('owner:'.$this->owner->id)
        ->and($rows['manager_owner']->accrual->beneficiary_id)->toBe($this->manager->id)
        ->and($rows['manager_rop']->amount_minor)->toBe(6_000_000)
        ->and($rows['manager_rop']->accrual->payer)->toBe('rop:'.$this->rop->id)
        ->and($rows['rop_gross']->accrual->beneficiary_id)->toBe($this->rop->id)
        ->and($rows['rop_gross']->accrual->payer)->toBe('SFP');
});

it('rounds the cumulative target and exactly reverses original cents across sequential refunds', function () {
    $order = reversalFixtureOrder($this->manager);
    $payment = reversalFixturePayment($order, '1.01');
    $source = $payment->accruals()->where('kind', 'owner_gross')->sole();
    $ownerParts = [];
    foreach ([33, 34, 34] as $amount) {
        $refund = reversalFixtureRefund($order, [$payment->id => $amount]);
        postReversalFixture($refund);
        $ownerParts[] = RefundCommissionReversal::where('refund_id', $refund->id)->where('accrual_id', $source->id)->value('amount_minor');
    }
    expect($ownerParts)->toBe([2, 1, 2]);
    foreach ($payment->accruals as $accrual) {
        expect((int) RefundCommissionReversal::where('accrual_id', $accrual->id)->sum('amount_minor'))->toBe($accrual->amount_minor);
    }
});

it('isolates each source payment and leaves historical receipts without accruals alone', function () {
    $order = reversalFixtureOrder($this->manager);
    $modern = reversalFixturePayment($order, '1000');
    $historical = $order->payments()->create([
        'idempotency_key' => (string) Str::uuid(),
        'paid_at' => today()->toDateString(),
        'amount_minor' => 40_000,
        'currency' => $order->currency,
        'calculation_status' => 'needs_clarification',
    ]);
    $refund = reversalFixtureRefund($order, [$modern->id => 50_000, $historical->id => 40_000]);
    postReversalFixture($refund);

    $rows = RefundCommissionReversal::with('accrual')->orderBy('id')->get();
    expect($rows->pluck('accrual.payment_id')->unique()->values()->all())->toBe([$modern->id])
        ->and($rows->pluck('amount_minor', 'accrual.kind')->all())->toBe([
            'owner_gross' => 2500, 'rop_gross' => 2500, 'manager_owner' => 1250, 'manager_rop' => 1250,
        ]);
    expect($historical->accruals()->exists())->toBeFalse();
});
