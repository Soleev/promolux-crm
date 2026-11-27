<?php

use App\Filament\Resources\Accruals\Pages\ManageAccruals;
use App\Models\Accrual;
use App\Models\CommissionSettlement;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Services\AccrualOverview;
use App\Services\CommissionSettlements;
use App\Services\CrmAccess;
use App\Services\FinancialTerms;
use App\Services\Ledger;
use App\Services\Report;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

it('groups funding entries by order and preserves the original seller after transfer', function () {
    [, , $manager, $order, $input] = crmScenario();
    $first = app(Ledger::class)->payment(array_replace($input, ['amount' => '4000']));
    $replacement = User::factory()->create(['role' => 'manager', 'active' => true]);
    $order->update(['manager_id' => $replacement->id]);
    $second = app(Ledger::class)->payment(array_replace($input, ['amount' => '6000', 'idempotency_key' => (string) Str::uuid()]));
    $overview = app(AccrualOverview::class);

    $rows = $overview->orders($manager)->get();
    expect($rows)->toHaveCount(1);
    expect((array) $rows->sole())->toMatchArray(['accrued' => 50000, 'paid' => 0, 'remaining' => 50000, 'order_id' => $order->id]);
    $details = $overview->payments($manager, $order->id, $manager->id, 'UZS');
    expect(array_keys((array) $details->first()))->toBe(['accrued', 'paid', 'remaining', 'payment_id', 'paid_at', 'payment_amount', 'currency']);
    expect($details->pluck('payment_id')->all())->toBe([$first->id, $second->id]);
    expect($details->pluck('accrued')->map(fn ($amount) => (int) $amount)->all())->toBe([20000, 30000]);
    expect($overview->orders($replacement)->get())->toBeEmpty();
    $this->assertDatabaseCount('accruals', 8);
    $this->actingAs($manager)->get('/crm/accruals')->assertOk()->assertSee($order->number);
});

it('searches orders and never serializes funding entries in manager Livewire responses', function () {
    [, , $manager, $order, $input] = crmScenario();
    app(Ledger::class)->payment($input);
    $this->actingAs($manager);

    $page = Livewire::test(ManageAccruals::class)->assertSee($order->number)
        ->set('orderSearch', 'no-such-order')->assertDontSee($order->number)
        ->set('orderSearch', $order->number)->assertSee($order->number)
        ->call('showPayments', $order->id, $manager->id, 'UZS')->assertSee('Оплата №');
    $payload = $page->html().json_encode($page->snapshot).json_encode($page->effects);
    foreach (['manager_owner', 'manager_rop', 'owner_gross', 'rop_gross', 'owner_funding_bps', 'rop_funding_bps', 'rate_bps', '2,5%', 'из доли владельца', 'из доли РОП', 'Финансы Promolux', 'SFP должен выплатить', 'Обязательства перед менеджерами', 'Вознаграждение после обязательств'] as $private) {
        expect($payload)->not->toContain($private);
    }
    expect($page->instance()->getTableRecords())->toHaveCount(0);
    $page->call('closeDetails')->assertDontSee('Оплата №');
});

it('rejects forged details for another beneficiary', function () {
    [$owner, , $manager, $order, $input] = crmScenario();
    app(Ledger::class)->payment($input);
    $this->actingAs($manager);

    Livewire::test(ManageAccruals::class)->call('showPayments', $order->id, $owner->id, 'UZS')->assertNotFound();
});

it('rechecks visibility after Livewire detail properties are forged', function () {
    [$owner, , $manager, $order, $input] = crmScenario();
    app(Ledger::class)->payment($input);
    $this->actingAs($manager);
    $page = Livewire::test(ManageAccruals::class)->call('showPayments', $order->id, $manager->id, 'UZS')
        ->set('detailBeneficiary', $owner->id)->assertDontSee('Оплата №');
    expect(fn () => $page->instance()->paymentDetails())->toThrow(HttpException::class);
});

it('paginates grouped orders with Russian labels and resets pagination when searching', function (string $role) {
    app()->setLocale('ru');
    [$owner, $rop, $manager, $order, $input] = crmScenario();
    app(Ledger::class)->payment($input);
    for ($index = 0; $index < 10; $index++) {
        settlementFixturePayment($manager, '1000');
    }
    $this->actingAs(match ($role) {
        'owner' => $owner, 'rop' => $rop, default => $manager,
    });

    $page = Livewire::test(ManageAccruals::class)->assertDontSee($order->number)
        ->assertSeeText('Следующая')->assertDontSeeText('pagination.next')->assertDontSeeText('pagination.previous')
        ->call('nextPage', 'ordersPage')->assertSeeText('Предыдущая')
        ->assertDontSeeText('pagination.next')->assertDontSeeText('pagination.previous')
        ->call('previousPage', 'ordersPage');
    expect($page->get('orderAccruals')->currentPage())->toBe(1);
    $page->call('setPage', $role === 'manager' ? 2 : 4, 'ordersPage')->assertSee($order->number)
        ->set('orderSearch', $order->number)->assertSee($order->number);
    expect($page->get('orderAccruals')->total())->toBe($role === 'manager' ? 1 : 3);
    expect($page->get('orderAccruals')->currentPage())->toBe(1);
})->with(['owner', 'rop', 'manager']);

it('shows every beneficiary to owner and rop without changing personal scopes', function (string $role) {
    [$owner, $rop, $manager, $order, $input] = crmScenario();
    app(Ledger::class)->payment($input);
    $actor = $role === 'owner' ? $owner : $rop;
    $overview = app(AccrualOverview::class);
    $before = app(Report::class)->build($actor, null, null);
    $this->actingAs($actor);

    $page = Livewire::test(ManageAccruals::class)->assertSee('Финансы Promolux')->assertSee($manager->name)
        ->assertSee('SFP должен выплатить')->assertSee('Обязательства перед менеджерами')
        ->assertSee('Вознаграждение после обязательств перед менеджерами')
        ->assertSee('1 000 000 сум')->assertSee('500 000 сум')->assertSee('250 000 сум')->assertDontSee('1 500 000 сум')
        ->assertSee('Начисленные вознаграждения не означают фактически полученные деньги.')
        ->assertDontSee('Мои начисления · за всё время')
        ->call('showPayments', $order->id, $manager->id, 'UZS')->assertSee('Оплата №');
    expect($overview->orders($actor)->get()->pluck('beneficiary_id')->sort()->values()->all())->toBe(collect([$owner->id, $rop->id, $manager->id])->sort()->values()->all());
    expect($page->get('personalSummary')->sole()['accrued'])->toBe(50000000);
    $summary = $page->get('overallSummary')->sole();
    expect($summary)->not->toHaveKeys(['accrued', 'paid', 'remaining']);
    foreach (['owner_gross', 'rop_gross', 'internal'] as $category) {
        expect($summary['obligations'][$category])->toBe(['currency' => 'UZS', 'accrued' => 50000000,
            'paid' => 0, 'settled' => 0, 'remaining' => 50000000]);
    }
    expect($summary['remuneration'])->toBe(['owner' => 25000000, 'rop' => 25000000]);
    expect($summary['sfp_remaining'])->toBe(100000000);
    expect($summary['employees'])->toBe([['beneficiary_id' => $manager->id, 'name' => $manager->name, 'remaining' => 50000000]]);
    expect(app(Report::class)->build($actor, null, null))->toBe($before);
    if ($role === 'rop') {
        expect(CrmAccess::query(Accrual::class, $rop)->pluck('beneficiary_id')->unique()->all())->toBe([$rop->id]);
        expect(CrmAccess::manage($rop, Payout::class))->toBeFalse();
        $this->get('/crm-register.csv')->assertForbidden();
        $this->get('/crm/overview')->assertOk()->assertDontSee('Скачать реестр CSV');
    }
})->with(['owner', 'rop']);

it('keeps designer cashier and screen access unchanged', function (string $role) {
    $actor = User::factory()->create(['role' => $role, 'active' => true]);
    $this->actingAs($actor)->get('/crm/accruals')->assertForbidden();
})->with(['designer', 'cashier', 'sfp_screen']);

it('denies disabled accounts and redirects guests', function () {
    $this->get('/crm/accruals')->assertRedirect('/crm/login');
    $this->actingAs(User::factory()->create(['role' => 'manager', 'active' => false]))->get('/crm/accruals')->assertForbidden();
});

it('uses posted funding shares for other commission recipients despite later role changes', function () {
    [$owner, $rop, $manager, , $input] = crmScenario();
    app(Ledger::class)->payment(array_replace($input, ['amount' => '1000']));
    $designer = User::factory()->create(['role' => 'designer', 'active' => true]);
    app(FinancialTerms::class)->publish($designer, ['owner_id' => $owner->id, 'rop_id' => $rop->id,
        'owner_funding_bps' => 375, 'rop_funding_bps' => 125]);
    settlementFixturePayment($designer, '1000');
    app(FinancialTerms::class)->publish($designer, ['owner_id' => $owner->id, 'rop_id' => $rop->id,
        'owner_funding_bps' => 100, 'rop_funding_bps' => 200]);
    $manager->update(['role' => 'designer', 'active' => false]);

    $summary = app(AccrualOverview::class)->fundingSummary($rop)->sole();
    expect($summary['obligations']['internal']['accrued'])->toBe(10000);
    expect($summary['remuneration'])->toBe(['owner' => 3750, 'rop' => 6250]);
    expect(collect($summary['employees'])->keyBy('beneficiary_id')->map(fn ($row) => $row['remaining'])->all())
        ->toEqual([$manager->id => 5000, $designer->id => 5000]);
});

it('keeps SFP payouts with their accrued beneficiary even if a different person receives the money', function () {
    [$owner, $rop, , , $input] = crmScenario();
    $ledger = app(Ledger::class);
    $payment = $ledger->payment(array_replace($input, ['amount' => '10000']));
    $accrual = $payment->accruals()->where('kind', 'owner_gross')->sole();
    $payout = $ledger->payout(['idempotency_key' => (string) Str::uuid(), 'payer' => 'SFP',
        'recipient_id' => $rop->id, 'paid_at' => today()->toDateString(), 'amount' => '100',
        'allocations' => [['accrual_id' => $accrual->id, 'amount' => '100']]]);

    $summary = app(AccrualOverview::class)->fundingSummary($owner)->sole();
    expect($summary['obligations']['owner_gross'])->toMatchArray(['accrued' => 50000, 'paid' => 10000, 'remaining' => 40000]);
    expect($summary['obligations']['rop_gross'])->toMatchArray(['paid' => 0, 'remaining' => 50000]);
    expect($summary['obligations']['internal'])->toMatchArray(['paid' => 0, 'remaining' => 50000]);
    expect($summary['remuneration'])->toBe(['owner' => 25000, 'rop' => 25000]);
    expect($summary['sfp_remaining'])->toBe(90000);
    $ledger->reversePayout($payout, 'Исправление выплаты');
    $ledger->reversePayment($payment, 'Исправление оплаты');
    $reversed = app(AccrualOverview::class)->fundingSummary($owner)->sole();
    foreach ($reversed['obligations'] as $obligation) {
        expect($obligation)->toMatchArray(['accrued' => 0, 'paid' => 0, 'remaining' => 0]);
    }
    expect($reversed['remuneration'])->toBe(['owner' => 0, 'rop' => 0]);
    expect($reversed['sfp_remaining'])->toBe(0);
    expect(collect($reversed['employees'])->sum('remaining'))->toBe(0);
});

it('separates refund balances and debt repayments in external and internal obligations', function () {
    [$owner, , , , $input] = crmScenario();
    $payment = app(Ledger::class)->payment(array_replace($input, ['amount' => '10000']));
    foreach ($payment->accruals()->get() as $accrual) {
        settlementFixturePayout($accrual, $accrual->amount_minor);
    }
    settlementFixtureRefund($payment);
    $overview = app(AccrualOverview::class);
    $before = $overview->fundingSummary($owner)->sole();
    foreach ($before['obligations'] as $obligation) {
        expect($obligation)->toMatchArray(['accrued' => 30000, 'paid' => 50000, 'remaining' => -20000]);
    }
    $internal = $payment->accruals()->where('kind', 'manager_owner')->sole();
    $external = $payment->accruals()->where('kind', 'owner_gross')->sole();
    app(CommissionSettlements::class)->record(settlementFixtureInput($internal, CommissionSettlement::WRITE_OFF, '60'));
    app(CommissionSettlements::class)->record(settlementFixtureInput($external, CommissionSettlement::REPAYMENT, '100'));

    $after = $overview->fundingSummary($owner)->sole();
    expect($after['obligations']['owner_gross'])->toMatchArray(['paid' => 50000, 'settled' => 10000, 'remaining' => -10000]);
    expect($after['obligations']['rop_gross'])->toMatchArray(['settled' => 0, 'remaining' => -20000]);
    expect($after['obligations']['internal'])->toMatchArray(['paid' => 50000, 'settled' => 6000, 'remaining' => -14000]);
    expect($after['remuneration'])->toBe(['owner' => 15000, 'rop' => 15000]);
    expect($after['unassigned_sfp_settled'])->toBe(0);
    expect($after['sfp_remaining'])->toBe(-30000);
    expect(collect($after['employees'])->sum('remaining'))->toBe(-14000);
    Livewire::test(ManageAccruals::class)->assertSee('-100 сум')->assertSee('-200 сум')->assertSee('-140 сум');
});

it('shows shared historical SFP repayments once without inventing an allocation between roles', function () {
    [$owner, , , , $input] = crmScenario();
    $payment = app(Ledger::class)->payment(array_replace($input, ['amount' => '10000']));
    $external = $payment->accruals()->where('kind', 'owner_gross')->sole();
    settlementFixturePayout($external, $external->amount_minor);
    settlementFixtureRefund($payment);
    $historical = Payment::create(['idempotency_key' => (string) Str::uuid(), 'order_id' => $payment->order_id,
        'paid_at' => today(), 'amount_minor' => 100000, 'currency' => 'UZS', 'calculation_status' => 'calculated']);
    $historical->accruals()->create(['beneficiary_id' => $owner->id, 'kind' => 'rop_gross', 'payer' => 'SFP',
        'rate_bps' => 500, 'amount_minor' => 5000, 'currency' => 'UZS']);
    app(CommissionSettlements::class)->record(settlementFixtureInput($external, CommissionSettlement::REPAYMENT, '100'));

    $summary = app(AccrualOverview::class)->fundingSummary($owner)->sole();
    expect($summary['unassigned_sfp_settled'])->toBe(10000);
    expect($summary['obligations']['owner_gross'])->toMatchArray(['settled' => 0, 'remaining' => -20000]);
    expect($summary['obligations']['rop_gross'])->toMatchArray(['settled' => 0, 'remaining' => 35000]);
    expect($summary['obligations']['internal']['settled'])->toBe(0);
    expect($summary['sfp_remaining'])->toBe(25000);
    Livewire::test(ManageAccruals::class)->assertSee('не распределена между ролями');
});

it('does not disclose the funding summary to a manager', function () {
    [, , $manager, , $input] = crmScenario();
    app(Ledger::class)->payment($input);
    $this->actingAs($manager);

    expect(fn () => app(AccrualOverview::class)->fundingSummary($manager))->toThrow(HttpException::class);
    expect(fn () => Livewire::test(ManageAccruals::class)->instance()->overallSummary())->toThrow(HttpException::class);
});

it('formats grouped totals and payment details with grouped digits and a decimal comma', function () {
    [, , $manager, , $input] = crmScenario();
    $payment = app(Ledger::class)->payment(array_replace($input, ['amount' => '1000']));
    $historical = Payment::create(['idempotency_key' => (string) Str::uuid(), 'order_id' => $payment->order_id,
        'manager_id' => $manager->id, 'paid_at' => today(), 'amount_minor' => 754084499040, 'currency' => 'UZS', 'calculation_status' => 'calculated']);
    $historical->accruals()->create(['beneficiary_id' => $manager->id, 'kind' => 'manager_owner', 'payer' => 'owner',
        'rate_bps' => 500, 'amount_minor' => 37704224952, 'currency' => 'UZS']);
    $this->actingAs($manager);

    Livewire::test(ManageAccruals::class)->assertSee('377 042 299,52 сум')
        ->call('showPayments', $payment->order_id, $manager->id, 'UZS')
        ->assertSee('377 042 249,52 сум')->assertSee('7 540 844 990,40 сум')
        ->assertDontSee('377042249.52')->assertDontSee('7540844990.40');
});

it('nets payout and payment reversals within the original payment', function () {
    [, , $manager, $order, $input] = crmScenario();
    $ledger = app(Ledger::class);
    $payment = $ledger->payment(array_replace($input, ['amount' => '1000']));
    $accrual = $payment->accruals()->where('kind', 'manager_owner')->sole();
    $payout = $ledger->payout(['idempotency_key' => (string) Str::uuid(), 'payer' => $accrual->payer,
        'recipient_id' => $manager->id, 'paid_at' => today()->toDateString(), 'amount' => '10',
        'allocations' => [['accrual_id' => $accrual->id, 'amount' => '10']]]);
    $overview = app(AccrualOverview::class);
    expect($overview->summary($manager)->sole())->toMatchArray(['accrued' => 5000, 'paid' => 1000, 'remaining' => 4000]);
    $ledger->reversePayout($payout, 'Исправление выплаты');
    $ledger->reversePayment($payment, 'Исправление оплаты');

    expect($overview->summary($manager)->sole())->toMatchArray(['accrued' => 0, 'paid' => 0, 'remaining' => 0]);
    $details = $overview->payments($manager, $order->id, $manager->id, 'UZS');
    expect($details)->toHaveCount(1);
    expect((array) $details->sole())->toMatchArray(['payment_id' => $payment->id, 'accrued' => 0, 'paid' => 0, 'remaining' => 0]);
});

it('includes refunds and debt settlements without clamping negative balances', function () {
    [, , $manager, $order, $input] = crmScenario();
    $payment = app(Ledger::class)->payment(array_replace($input, ['amount' => '10000']));
    foreach ($payment->accruals()->where('beneficiary_id', $manager->id)->get() as $accrual) {
        settlementFixturePayout($accrual, $accrual->amount_minor);
    }
    settlementFixtureRefund($payment);
    $overview = app(AccrualOverview::class);
    expect($overview->summary($manager)->sole())->toMatchArray(['accrued' => 30000, 'paid' => 50000, 'remaining' => -20000]);
    $accrual = $payment->accruals()->where('kind', 'manager_owner')->sole();
    app(CommissionSettlements::class)->record(settlementFixtureInput($accrual, CommissionSettlement::REPAYMENT, '40'));
    app(CommissionSettlements::class)->record(settlementFixtureInput($accrual, CommissionSettlement::WRITE_OFF, '60'));

    expect($overview->summary($manager)->sole())->toMatchArray(['accrued' => 30000, 'paid' => 50000, 'settled' => 10000, 'remaining' => -10000]);
    expect((int) $overview->orders($manager)->sole()->remaining)->toBe(-20000);
    expect((int) $overview->payments($manager, $order->id, $manager->id, 'UZS')->sole()->remaining)->toBe(-20000);
});

it('keeps currencies separate in historical accruals', function () {
    [$owner, , $manager, , $input] = crmScenario();
    $payment = app(Ledger::class)->payment(array_replace($input, ['amount' => '1000']));
    $historical = Payment::create(['idempotency_key' => (string) Str::uuid(), 'order_id' => $payment->order_id,
        'manager_id' => $manager->id, 'paid_at' => today(), 'amount_minor' => 4920, 'currency' => 'USD', 'calculation_status' => 'calculated']);
    $historical->accruals()->create(['beneficiary_id' => $manager->id, 'kind' => 'manager_owner', 'payer' => 'owner', 'rate_bps' => 250, 'amount_minor' => 123, 'currency' => 'USD']);

    expect(app(AccrualOverview::class)->summary($manager)->keyBy('currency')->map(fn ($row) => $row['remaining'])->all())->toBe(['USD' => 123, 'UZS' => 5000]);
    expect(app(AccrualOverview::class)->orders($manager)->get())->toHaveCount(2);
    $funding = app(AccrualOverview::class)->fundingSummary($owner)->keyBy('currency');
    expect($funding['USD']['obligations']['internal']['remaining'])->toBe(123);
    expect($funding['UZS']['obligations']['internal']['remaining'])->toBe(5000);
    expect(collect($funding['USD']['employees'])->sum('remaining'))->toBe(123);
    expect(collect($funding['UZS']['employees'])->sum('remaining'))->toBe(5000);
    expect($funding['USD']['sfp_remaining'])->toBe(0);
    expect($funding['UZS']['sfp_remaining'])->toBe(10000);
    $this->actingAs($manager);
    Livewire::test(ManageAccruals::class)->assertSee('1,23 USD')->assertSee('50 сум');
});

it('reconciles employee balances by historical recipient including negative and zero balances', function () {
    [$owner, , $manager, , $input] = crmScenario();
    $payment = app(Ledger::class)->payment(array_replace($input, ['amount' => '10000']));
    foreach ($payment->accruals()->where('beneficiary_id', $manager->id)->get() as $accrual) {
        settlementFixturePayout($accrual, $accrual->amount_minor);
    }
    settlementFixtureRefund($payment);
    $internal = $payment->accruals()->where('kind', 'manager_owner')->sole();
    app(CommissionSettlements::class)->record(settlementFixtureInput($internal, CommissionSettlement::REPAYMENT, '40'));
    $manager->update(['name' => 'Получатель <em>архив</em>', 'role' => 'designer', 'active' => false]);
    $other = User::factory()->create(['name' => $manager->name, 'role' => 'manager', 'active' => true]);
    settlementFixturePayment($other, '3000');
    $paidRecipient = User::factory()->create(['name' => 'Получатель без долга', 'role' => 'manager', 'active' => true]);
    $paidPayment = settlementFixturePayment($paidRecipient, '1000');
    foreach ($paidPayment->accruals()->where('beneficiary_id', $paidRecipient->id)->get() as $accrual) {
        settlementFixturePayout($accrual, $accrual->amount_minor);
    }

    $page = Livewire::test(ManageAccruals::class)->assertSee('-10 сум')->assertSee('-160 сум')->assertSee('150 сум')
        ->assertSee($manager->name)->assertSee($paidRecipient->name)->assertDontSee('<em>архив</em>', escape: false);
    $summary = $page->get('overallSummary')->sole();
    expect(collect($summary['employees'])->keyBy('beneficiary_id')->map(fn ($row) => $row['remaining'])->all())
        ->toEqual([$manager->id => -16000, $other->id => 15000, $paidRecipient->id => 0]);
    expect(collect($summary['employees'])->sum('remaining'))->toBe($summary['obligations']['internal']['remaining'])->toBe(-1000);
    expect($summary['sfp_remaining'])->toBe(100000);
    expect($summary['remuneration'])->toBe(['owner' => 25000, 'rop' => 25000]);
    $page->set('orderSearch', 'no-matching-order')->assertSee('-10 сум')->assertSee($paidRecipient->name);
    $this->actingAs($other);
    $managerPage = Livewire::test(ManageAccruals::class)->assertDontSee($paidRecipient->name)->assertDontSee('Финансы Promolux');
    expect($managerPage->html().json_encode($managerPage->effects).json_encode($managerPage->snapshot))->not->toContain($paidRecipient->name);
});
