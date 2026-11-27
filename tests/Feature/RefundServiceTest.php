<?php

use App\Models\Activity;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundExecutionIssue;
use App\Models\User;
use App\Services\RefundCommissionReversals;
use App\Services\RefundService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

function refundFixturePayment(Order $order, int $amount = 1000000, ?string $currency = null, ?int $reversalOf = null): Payment
{
    return $order->payments()->create([
        'idempotency_key' => (string) Str::uuid(),
        'manager_id' => $order->manager_id,
        'paid_at' => today()->toDateString(),
        'amount_minor' => $amount,
        'currency' => $currency ?? $order->currency,
        'recipient' => 'SFP',
        'method' => 'Перечисление',
        'calculation_status' => 'needs_clarification',
        'reversal_of' => $reversalOf,
    ]);
}

function refundFixtureInput(Payment $payment, string $amount = '4000', string $method = 'bank_transfer'): array
{
    return [
        'order_id' => $payment->order_id,
        'idempotency_key' => (string) Str::uuid(),
        'amount' => $amount,
        'currency' => 'UZS',
        'reason' => 'Согласованный частичный возврат',
        'method' => $method,
        'allocations' => [['payment_id' => $payment->id, 'amount' => $amount]],
    ];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 14:21:00'));
    foreach (['owner', 'rop', 'manager', 'designer', 'cashier'] as $role) {
        $this->{$role} = User::factory()->create(['role' => $role, 'active' => true]);
    }
    $this->actingAs($this->owner);
    $this->client = Client::create(['name' => 'Тестовый клиент', 'manager_id' => $this->manager->id]);
    $this->order = Order::create([
        'number' => (string) Str::ulid(), 'client_id' => $this->client->id,
        'manager_id' => $this->manager->id, 'currency' => 'UZS', 'status' => 'production',
    ]);
    $this->payment = refundFixturePayment($this->order);
    $this->service = app(RefundService::class);
});

it('creates a refund without changing an order or money and makes exact creation retries idempotent', function (string $role) {
    $actor = $this->{$role};
    $this->actingAs($actor);
    $second = refundFixturePayment($this->order, 500000);
    $input = array_replace(refundFixtureInput($this->payment), [
        'client_id' => 50000,
        'status' => Refund::COMPLETED,
        'created_by' => $this->cashier->id,
        'reason' => '  Объём работ изменился  ',
        'allocations' => [
            ['payment_id' => $second->id, 'amount' => '1000'],
            ['payment_id' => $this->payment->id, 'amount' => '3000'],
        ],
    ]);
    $refund = $this->service->create($input);
    $input['allocations'] = array_reverse($input['allocations']);
    $input['idempotency_key'] = strtoupper($input['idempotency_key']);
    expect($this->service->create($input)->is($refund))->toBeTrue()
        ->and($refund->number)->toMatch('/^RF-[0-9A-Z]{26}$/')
        ->and($refund->status)->toBe(Refund::CREATED)
        ->and($refund->client_id)->toBe($this->client->id)
        ->and($refund->created_by)->toBe($actor->id)
        ->and($refund->reason)->toBe('Объём работ изменился')
        ->and($refund->amount_minor)->toBe(400000)
        ->and($this->order->fresh()->status)->toBe('production');
    expect(fn () => $this->service->create(array_replace($input, ['method' => 'cash'])))->toThrow(HttpException::class);
    $this->assertDatabaseCount('refunds', 1);
    $this->assertDatabaseCount('refund_allocations', 2);
    $this->assertDatabaseCount('payments', 2);
    $this->assertDatabaseCount('refund_commission_reversals', 0);
})->with(['owner', 'rop']);

it('rejects invalid or substituted refund amounts, currency, sources and reasons', function () {
    $input = refundFixtureInput($this->payment);
    foreach ([
        ['amount' => '0'], ['amount' => '-0.01'], ['currency' => 'USD'], ['method' => 'unknown'], ['reason' => '  '],
        ['allocations' => [['payment_id' => $this->payment->id, 'amount' => '3999']]],
        ['allocations' => [
            ['payment_id' => $this->payment->id, 'amount' => '2000'],
            ['payment_id' => $this->payment->id, 'amount' => '2000'],
        ]],
        ['allocations' => [['payment_id' => 50000, 'amount' => '4000']]],
    ] as $changes) {
        expect(fn () => $this->service->create(array_replace($input, $changes)))->toThrow(ValidationException::class);
    }
    $other = Order::create(['number' => (string) Str::ulid(), 'client_id' => $this->client->id, 'currency' => 'UZS']);
    $foreignPayment = refundFixturePayment($other);
    $otherCurrency = refundFixturePayment($this->order, currency: 'USD');
    foreach ([$foreignPayment, $otherCurrency] as $wrongPayment) {
        expect(fn () => $this->service->create(array_replace($input, ['allocations' => [
            ['payment_id' => $wrongPayment->id, 'amount' => '4000'],
        ]])))->toThrow(ValidationException::class);
    }
    $this->assertDatabaseCount('refunds', 0);
});

it('uses the current user role and availability at the approval transaction', function () {
    $this->actingAs($this->rop);
    $refund = $this->service->create(refundFixtureInput($this->payment));
    $approveKey = (string) Str::uuid();
    expect(fn () => $this->service->approve($refund, 'SFP', $approveKey))->toThrow(HttpException::class);
    expect(fn () => $this->service->preview($refund))->toThrow(HttpException::class);
    foreach ([$this->manager, $this->designer, $this->cashier] as $actor) {
        $this->actingAs($actor);
        expect(fn () => $this->service->create(refundFixtureInput($this->payment)))->toThrow(HttpException::class);
    }
    $this->actingAs($this->owner);
    User::query()->whereKey($this->owner->id)->update(['active' => false]);
    expect(fn () => $this->service->approve($refund, 'SFP', $approveKey))->toThrow(HttpException::class);
    $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => Refund::CREATED]);
});

it('reserves only approved requests and recalculates stale requests at both order and payment level', function () {
    $secondPayment = refundFixturePayment($this->order, 300000);
    $first = $this->service->create(refundFixtureInput($this->payment, '6000'));
    $second = $this->service->create(refundFixtureInput($this->payment, '5000'));
    $this->service->approve($first, 'SFP', (string) Str::uuid());
    $summary = $this->service->summary($this->order);
    expect($summary['available_minor'])->toBe(700000)
        ->and($summary['payments'][$this->payment->id]['available_minor'])->toBe(400000)
        ->and($summary['payments'][$secondPayment->id]['available_minor'])->toBe(300000)
        ->and(fn () => $this->service->approve($second, 'SFP', (string) Str::uuid()))->toThrow(ValidationException::class);
    $cancelled = $this->service->cancel($first, 'Согласовали другую сумму', (string) Str::uuid());
    expect($cancelled->status)->toBe(Refund::CANCELLED);
    $approved = $this->service->approve($second, 'Promolux', (string) Str::uuid());
    $this->actingAs($this->cashier);
    expect(fn () => $this->service->execute($approved, today()->toDateString(), (string) Str::uuid()))->toThrow(HttpException::class);
    $this->actingAs($this->owner);
    $returned = $this->service->execute($approved, today()->toDateString(), (string) Str::uuid());
    expect($returned->status)->toBe(Refund::COMPLETED)
        ->and($this->service->summary($this->order)['completed_minor'])->toBe(500000)
        ->and(fn () => $this->service->create(refundFixtureInput($this->payment, '5000.01')))->toThrow(ValidationException::class);
});

it('distinguishes posting a refund from approval and from correcting an erroneous payment', function () {
    $this->payment->accruals()->create([
        'beneficiary_id' => $this->owner->id, 'kind' => 'owner_gross', 'payer' => 'SFP',
        'rate_bps' => 500, 'amount_minor' => 50000, 'currency' => 'UZS',
    ]);
    $refund = $this->service->create(refundFixtureInput($this->payment, '4000', 'cash'));
    $preview = $this->service->preview($refund);
    expect($preview['retained_minor'])->toBe(600000)
        ->and($preview['commission_reversals'][0]['amount_minor'])->toBe(20000);
    $approvalKey = (string) Str::uuid();
    expect($this->service->approve($refund, 'Promolux', $approvalKey)->approved_by)->toBe($this->owner->id);
    expect(fn () => $this->service->approve($refund, 'SFP', $approvalKey))->toThrow(HttpException::class);
    $this->assertDatabaseCount('refund_commission_reversals', 0);
    $date = today()->toDateString();
    $executeKey = (string) Str::uuid();
    expect(fn () => $this->service->execute($refund, $date, $executeKey))->toThrow(HttpException::class);

    $this->actingAs($this->cashier);
    expect(fn () => $this->service->execute($refund, today()->subDay()->toDateString(), $executeKey))->toThrow(ValidationException::class);
    $completed = $this->service->execute($refund, $date, $executeKey);
    expect($this->service->execute($refund, $date, $executeKey)->is($completed))->toBeTrue()
        ->and($completed->executed_by)->toBe($this->cashier->id)
        ->and($completed->executed_at->toDateString())->toBe($date)
        ->and($completed->source)->toBe('Promolux')
        ->and($completed->method)->toBe('cash')
        ->and($completed->status)->toBe(Refund::COMPLETED);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('refund_commission_reversals', 1);
    $this->assertDatabaseHas('refund_commission_reversals', ['refund_id' => $refund->id, 'amount_minor' => 20000]);

    $this->actingAs($this->owner);
    $remainder = $this->service->create(refundFixtureInput($this->payment, '6000'));
    $this->service->approve($remainder, 'SFP', (string) Str::uuid());
    $this->service->execute($remainder, $date, (string) Str::uuid());
    expect($this->service->summary($this->order)['available_minor'])->toBe(0)
        ->and($this->order->fresh()->status)->toBe('production');
    $this->assertDatabaseHas('refund_commission_reversals', ['refund_id' => $remainder->id, 'amount_minor' => 30000]);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('payouts', 0);
    $this->assertDatabaseCount('accruals', 1);
});

it('does not use pending cash or a corrected payment to fund a refund', function () {
    $this->order->cashReceipts()->create([
        'idempotency_key' => (string) Str::uuid(), 'manager_id' => $this->manager->id,
        'collector_id' => $this->manager->id, 'managed_sale' => true, 'status' => 'pending',
        'amount_minor' => 400000, 'collected_at' => today()->toDateString(),
    ]);
    refundFixturePayment($this->order, -$this->payment->amount_minor, reversalOf: $this->payment->id);
    expect($this->service->summary($this->order)['available_minor'])->toBe(0)
        ->and(fn () => $this->service->create(refundFixtureInput($this->payment)))->toThrow(ValidationException::class);
});

it('records controlled terminal statuses and keeps completed refunds and execution issue history immutable', function () {
    $refund = $this->service->create(refundFixtureInput($this->payment));
    $key = (string) Str::uuid();
    expect(fn () => $this->service->execute($refund, today()->toDateString(), $key))->toThrow(ValidationException::class);
    $rejected = $this->service->reject($refund, 'Отказ клиента', $key);
    expect($rejected->status)->toBe(Refund::REJECTED)
        ->and($this->service->reject($refund, 'Отказ клиента', $key)->is($rejected))->toBeTrue()
        ->and(fn () => $this->service->approve($refund, 'SFP', (string) Str::uuid()))->toThrow(ValidationException::class);
    $another = $this->service->create(refundFixtureInput($this->payment));
    $this->service->approve($another, 'SFP', (string) Str::uuid());
    $completed = $this->service->execute($another, today()->toDateString(), (string) Str::uuid());
    $allocation = $completed->allocations()->sole();
    expect(fn () => $completed->forceFill(['reason' => 'Попытка изменения'])->save())->toThrow(ValidationException::class)
        ->and(fn () => $completed->delete())->toThrow(ValidationException::class)
        ->and(fn () => $allocation->delete())->toThrow(ValidationException::class)
        ->and(fn () => $this->service->cancel($completed, 'Попытка отмены', (string) Str::uuid()))->toThrow(ValidationException::class);
    $issueKey = (string) Str::uuid();
    $issue = $this->service->flagExecutionIssue($another, 'Нужна проверка подтверждения', $issueKey);
    expect($this->service->flagExecutionIssue($another, 'Нужна проверка подтверждения', $issueKey)->is($issue))->toBeTrue()
        ->and($this->service->summary($this->order)['completed_minor'])->toBe(400000)
        ->and($another->fresh()->status)->toBe(Refund::COMPLETED);
    expect(fn () => $issue->delete())->toThrow(ValidationException::class);
    $this->assertDatabaseCount('refund_execution_issues', 1);
    $this->assertDatabaseHas('activities', ['subject_type' => RefundExecutionIssue::class, 'subject_id' => $issue->id, 'event' => 'created']);
    expect(Activity::where('subject_type', Refund::class)->where('subject_id', $completed->id)->pluck('event')->all())
        ->toBe(['created', 'updated', 'updated']);
});

it('stores an optional confirmation on the private disk and replays by content', function () {
    Storage::fake('local');
    Storage::fake('public');
    $refund = $this->service->create(refundFixtureInput($this->payment));
    $this->service->approve($refund, 'SFP', (string) Str::uuid());
    $key = (string) Str::uuid();
    $date = today()->toDateString();
    $contents = "%PDF-1.4\n% test fixture\n%%EOF\n";
    $document = UploadedFile::fake()->createWithContent('test-confirmation.pdf', $contents);
    $complete = $this->service->execute($refund, $date, $key, $document);
    expect($complete->document)->toStartWith('refund-documents/'.$refund->id.'/');
    Storage::disk('local')->assertExists($complete->document);
    Storage::disk('public')->assertMissing($complete->document);
    $same = UploadedFile::fake()->createWithContent('renamed.pdf', $contents);
    expect($this->service->execute($refund, $date, $key, $same)->document)->toBe($complete->document)
        ->and(Storage::disk('local')->files('refund-documents/'.$refund->id))->toHaveCount(1);
});

it('rolls back the execution event and discards a new upload when commission posting fails', function () {
    Storage::fake('local');
    $refund = $this->service->create(refundFixtureInput($this->payment));
    $this->service->approve($refund, 'SFP', (string) Str::uuid());
    app()->instance(RefundCommissionReversals::class, new class extends RefundCommissionReversals
    {
        public function record(Refund $refund): void
        {
            throw new RuntimeException('Тестовый сбой сторно');
        }
    });
    $document = UploadedFile::fake()->createWithContent('test-confirmation.pdf', "%PDF-1.4\n% test fixture\n%%EOF\n");
    expect(fn () => $this->service->execute($refund, today()->toDateString(), (string) Str::uuid(), $document))
        ->toThrow(RuntimeException::class, 'Тестовый сбой сторно');
    $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => Refund::APPROVED, 'document' => null, 'executed_at' => null]);
    expect(Storage::disk('local')->allFiles())->toBeEmpty()
        ->and(Activity::where('subject_type', Refund::class)->where('subject_id', $refund->id)->pluck('event')->all())
        ->toBe(['created', 'updated']);
});

it('leaves delivered orders to the separate product return workflow', function () {
    $this->order->update(['status' => 'delivered']);
    expect(fn () => $this->service->create(refundFixtureInput($this->payment)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('refunds', 0);
});
