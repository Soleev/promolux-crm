<?php

use App\Filament\Pages\MyDay;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Accrual;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashDesk;
use App\Services\CrmAccess;
use App\Services\CrmWriter;
use App\Services\FinancialTerms;
use App\Services\Ledger;
use Filament\Actions\ActionGroup;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

function financialAgreementSettings(User $owner, User $rop, int $ownerFunding = 250, int $ropFunding = 250): array
{
    return [
        'owner_id' => $owner->id,
        'rop_id' => $rop->id,
        'owner_funding_bps' => $ownerFunding,
        'rop_funding_bps' => $ropFunding,
    ];
}

function financialAgreementOrderInput(Client $client, User $seller): array
{
    return [
        'number' => (string) Str::ulid(),
        'client_id' => $client->id,
        'manager_id' => $seller->id,
        'status' => 'new',
        'items' => [['name' => 'Печать', 'quantity' => 1, 'price' => '10000']],
    ];
}

function financialAgreementOrder(User $seller): Order
{
    $writer = app(CrmWriter::class);
    $client = $writer->save(Client::class, ['name' => 'Тестовый клиент', 'manager_id' => $seller->id]);

    return $writer->save(Order::class, financialAgreementOrderInput($client, $seller));
}

function financialAgreementLegacyOrder(User $seller): Order
{
    $client = Client::create(['name' => 'Исторический клиент', 'manager_id' => $seller->id]);
    $input = financialAgreementOrderInput($client, $seller);
    unset($input['items']);
    $order = Order::create($input);
    $order->items()->create(['name' => 'Печать', 'quantity' => 1, 'price_minor' => 1000000]);

    return $order;
}

function financialAgreementPaymentInput(Order $order, string $amount = '1000'): array
{
    return [
        'idempotency_key' => (string) Str::uuid(),
        'order_id' => $order->id,
        'paid_at' => today()->toDateString(),
        'amount' => $amount,
    ];
}

function financialAgreementAccruals(Payment $payment): array
{
    return $payment->accruals()->orderBy('kind')->get()->mapWithKeys(fn (Accrual $accrual): array => [
        $accrual->kind => [$accrual->beneficiary_id, $accrual->payer, $accrual->rate_bps, $accrual->amount_minor],
    ])->all();
}

beforeEach(function () {
    foreach (['owner', 'rop', 'manager', 'designer', 'cashier'] as $role) {
        $this->{$role} = User::factory()->create(['role' => $role, 'active' => true]);
    }
    $this->actingAs($this->owner);
    $this->terms = app(FinancialTerms::class);
    $this->ledger = app(Ledger::class);
    $this->defaultAgreement = $this->terms->publish(null, financialAgreementSettings($this->owner, $this->rop));
});

it('captures the standard agreement on deal conversion and accrues only on payment', function () {
    $writer = app(CrmWriter::class);
    $client = $writer->save(Client::class, ['name' => 'Тестовый клиент', 'manager_id' => $this->manager->id]);
    $deal = $writer->save(Deal::class, [
        'title' => 'Тестовая сделка',
        'client_id' => $client->id,
        'manager_id' => $this->manager->id,
        'status' => 'won',
        'items' => [['name' => 'Печать', 'quantity' => 1, 'price' => '1000']],
    ]);
    $order = $writer->convert($deal);
    $terms = $order->financialTerms;
    expect($writer->convert($deal)->financialTerms->id)->toBe($terms->id)
        ->and($terms->seller_id)->toBe($this->manager->id)
        ->and($terms->seller_role)->toBe('manager')
        ->and($terms->agreement_version_id)->toBe($this->defaultAgreement->id);
    $this->assertDatabaseCount('accruals', 0);

    $payment = $this->ledger->payment(financialAgreementPaymentInput($order));
    expect($payment->calculation_status)->toBe('calculated')
        ->and($payment->order_financial_terms_id)->toBe($terms->id)
        ->and(financialAgreementAccruals($payment))->toEqual([
            'manager_owner' => [$this->manager->id, 'owner:'.$this->owner->id, 250, 2500],
            'manager_rop' => [$this->manager->id, 'rop:'.$this->rop->id, 250, 2500],
            'owner_gross' => [$this->owner->id, 'SFP', 500, 5000],
            'rop_gross' => [$this->rop->id, 'SFP', 500, 5000],
        ]);
});

it('uses a custom commission with unequal funding and keeps the SFP rates fixed', function () {
    $custom = $this->terms->publish($this->manager, financialAgreementSettings($this->owner, $this->rop, 400, 150));
    $order = financialAgreementOrder($this->manager);
    $payment = $this->ledger->payment(financialAgreementPaymentInput($order));

    expect($order->financialTerms->agreement_version_id)->toBe($custom->id)
        ->and(financialAgreementAccruals($payment))->toEqual([
            'manager_owner' => [$this->manager->id, 'owner:'.$this->owner->id, 400, 4000],
            'manager_rop' => [$this->manager->id, 'rop:'.$this->rop->id, 150, 1500],
            'owner_gross' => [$this->owner->id, 'SFP', 500, 5000],
            'rop_gross' => [$this->rop->id, 'SFP', 500, 5000],
        ]);
});

it('recognizes both SFP accruals on personal orders without a seller commission', function (string $role) {
    $order = financialAgreementOrder($this->{$role});
    $payment = $this->ledger->payment(financialAgreementPaymentInput($order));

    expect($order->financialTerms->seller_role)->toBe($role)
        ->and($payment->calculation_status)->toBe('calculated')
        ->and(financialAgreementAccruals($payment))->toEqual([
            'owner_gross' => [$this->owner->id, 'SFP', 500, 5000],
            'rop_gross' => [$this->rop->id, 'SFP', 500, 5000],
        ]);
})->with(['owner', 'rop']);

it('keeps the booked commission after a seller changes role between installments', function () {
    $order = financialAgreementOrder($this->manager);
    $first = $this->ledger->payment(financialAgreementPaymentInput($order, '3000'));
    app(CrmWriter::class)->save(User::class, ['role' => 'designer'] + $this->manager->only(['name', 'email', 'active']), $this->manager);
    $second = $this->ledger->payment(financialAgreementPaymentInput($order, '7000'));

    expect($order->fresh()->financialTerms->seller_role)->toBe('manager')
        ->and($first->accruals()->where('kind', 'manager_owner')->sole()->amount_minor)->toBe(7500)
        ->and($second->accruals()->where('kind', 'manager_owner')->sole()->amount_minor)->toBe(17500)
        ->and($order->fresh()->payment_status)->toBe('Оплачен');
    $this->assertDatabaseHas('user_role_histories', [
        'user_id' => $this->manager->id,
        'previous_role' => 'manager',
        'role' => 'designer',
        'changed_by' => $this->owner->id,
    ]);
});

it('applies a new rate only to orders booked after the new version takes effect', function () {
    $order = financialAgreementOrder($this->manager);
    $next = $this->terms->publish($this->manager, financialAgreementSettings($this->owner, $this->rop, 200, 100));
    $newOrder = financialAgreementOrder($this->manager);
    $before = $this->ledger->payment(financialAgreementPaymentInput($order));
    $after = $this->ledger->payment(financialAgreementPaymentInput($newOrder));

    expect($order->fresh()->financialTerms->agreement_version_id)->toBe($this->defaultAgreement->id)
        ->and($newOrder->financialTerms->agreement_version_id)->toBe($next->id)
        ->and($before->accruals()->where('kind', 'manager_rop')->sole()->amount_minor)->toBe(2500)
        ->and($after->accruals()->where('kind', 'manager_rop')->sole()->amount_minor)->toBe(1000);
});

it('does not transfer a booked commission when the order is reassigned', function () {
    $order = financialAgreementOrder($this->manager);
    $replacement = User::factory()->create(['role' => 'manager', 'active' => true]);
    $order = app(CrmWriter::class)->save(Order::class, ['number' => $order->number] + financialAgreementOrderInput($order->client, $replacement), $order);
    $payment = $this->ledger->payment(financialAgreementPaymentInput($order));

    expect($order->manager_id)->toBe($replacement->id)
        ->and($order->financialTerms->seller_id)->toBe($this->manager->id)
        ->and($payment->manager_id)->toBe($this->manager->id)
        ->and($payment->accruals()->where('kind', 'manager_owner')->sole()->beneficiary_id)->toBe($this->manager->id)
        ->and($payment->accruals()->where('kind', 'manager_rop')->sole()->beneficiary_id)->toBe($this->manager->id);
});

it('keeps the seller as beneficiary on a booked order after deactivation', function () {
    $order = financialAgreementOrder($this->manager);
    app(CrmWriter::class)->save(User::class, ['active' => false] + $this->manager->only(['name', 'email', 'role']), $this->manager);
    $payment = $this->ledger->payment(financialAgreementPaymentInput($order));

    expect($payment->calculation_status)->toBe('calculated')
        ->and($payment->accruals()->where('kind', 'manager_owner')->sole()->beneficiary_id)->toBe($this->manager->id);
});

it('keeps a booked ROP beneficiary after a replacement is published', function () {
    $order = financialAgreementOrder($this->manager);
    app(CrmWriter::class)->save(User::class, ['active' => false] + $this->rop->only(['name', 'email', 'role']), $this->rop);
    $replacement = User::factory()->create(['role' => 'rop', 'active' => true]);
    $this->terms->publish(null, financialAgreementSettings($this->owner, $replacement));
    $newOrder = financialAgreementOrder($this->manager);

    $before = $this->ledger->payment(financialAgreementPaymentInput($order));
    $after = $this->ledger->payment(financialAgreementPaymentInput($newOrder));
    expect($before->accruals()->where('kind', 'rop_gross')->sole()->beneficiary_id)->toBe($this->rop->id)
        ->and($before->accruals()->where('kind', 'manager_rop')->sole()->payer)->toBe('rop:'.$this->rop->id)
        ->and($after->accruals()->where('kind', 'rop_gross')->sole()->beneficiary_id)->toBe($replacement->id)
        ->and($after->accruals()->where('kind', 'manager_rop')->sole()->payer)->toBe('rop:'.$replacement->id);
});

it('accepts cash under terms booked before later versions without recognizing it twice', function () {
    $order = financialAgreementOrder($this->manager);
    $this->actingAs($this->manager);
    $cashDesk = app(CashDesk::class);
    $input = [
        'idempotency_key' => (string) Str::uuid(),
        'order_id' => $order->id,
        'collected_at' => today()->toDateString(),
        'amount' => '1000',
    ];
    $receipt = $cashDesk->collect($input);
    expect($cashDesk->collect($input)->is($receipt))->toBeTrue()
        ->and($receipt->order_financial_terms_id)->toBe($order->financialTerms->id);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('accruals', 0);

    $this->actingAs($this->owner);
    $this->terms->publish($this->manager, financialAgreementSettings($this->owner, $this->rop, 400, 150));
    $this->actingAs($this->cashier);
    $payment = $this->ledger->acceptCash($receipt, today()->toDateString());

    expect($this->ledger->acceptCash($receipt, today()->toDateString())->is($payment))->toBeTrue()
        ->and($payment->order_financial_terms_id)->toBe($receipt->order_financial_terms_id)
        ->and($payment->accruals()->where('kind', 'manager_owner')->sole()->amount_minor)->toBe(2500)
        ->and($payment->accruals()->where('kind', 'manager_rop')->sole()->amount_minor)->toBe(2500);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('accruals', 4);
});

it('balances exact minor-unit rounding and rejects a conflicting replay', function () {
    $order = financialAgreementOrder($this->manager);
    $input = financialAgreementPaymentInput($order, '0.15');
    $payment = $this->ledger->payment($input);
    $accruals = $payment->accruals()->pluck('amount_minor', 'kind');
    $ownerGross = $accruals['owner_gross'];
    $ropGross = $accruals['rop_gross'];
    $ownerFunding = $accruals['manager_owner'] ?? 0;
    $ropFunding = $accruals['manager_rop'] ?? 0;
    $sellerTotal = $ownerFunding + $ropFunding;

    expect($ownerGross)->toBe(1)
        ->and($ropGross)->toBe(1)
        ->and($sellerTotal)->toBe(1)
        ->and($ownerFunding)->toBeLessThanOrEqual($ownerGross)
        ->and($ropFunding)->toBeLessThanOrEqual($ropGross)
        ->and($ownerGross - $ownerFunding + $ropGross - $ropFunding + $sellerTotal)->toBe(2)
        ->and($this->ledger->payment($input)->is($payment))->toBeTrue();
    expect(fn () => $this->ledger->payment(['amount' => '0.16'] + $input))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('payments', 1);
    expect($payment->accruals()->count())->toBe($accruals->count());
});

it('assigns a rounding unit to the actual funding source when the other source has a zero rate', function () {
    $this->terms->publish($this->manager, financialAgreementSettings($this->owner, $this->rop, 0, 500));
    $order = financialAgreementOrder($this->manager);
    $payment = $this->ledger->payment(financialAgreementPaymentInput($order, '0.10'));

    expect(financialAgreementAccruals($payment))->toEqual([
        'manager_rop' => [$this->manager->id, 'rop:'.$this->rop->id, 500, 1],
        'owner_gross' => [$this->owner->id, 'SFP', 500, 0],
        'rop_gross' => [$this->rop->id, 'SFP', 500, 1],
    ]);
});

it('allocates and reverses a payout against custom commission while retaining booked terms', function () {
    $this->terms->publish($this->manager, financialAgreementSettings($this->owner, $this->rop, 400, 150));
    $order = financialAgreementOrder($this->manager);
    $payment = $this->ledger->payment(financialAgreementPaymentInput($order));
    $commission = $payment->accruals()->where('kind', 'manager_owner')->sole();
    $data = [
        'idempotency_key' => (string) Str::uuid(),
        'paid_at' => today()->toDateString(),
        'recipient_id' => $this->manager->id,
        'payer' => 'owner:'.$this->owner->id,
        'amount' => '20.25',
        'allocations' => [['accrual_id' => $commission->id, 'amount' => '20.25']],
    ];
    $payout = $this->ledger->payout($data);

    expect($this->ledger->payout($data)->is($payout))->toBeTrue()
        ->and($commission->outstanding_minor)->toBe(1975);
    expect(fn () => $this->ledger->reversePayment($payment, 'Корректировка оплаты'))->toThrow(ValidationException::class);

    $this->ledger->reversePayout($payout, 'Корректировка выплаты');
    $correction = $this->ledger->reversePayment($payment, 'Корректировка оплаты');
    expect($correction->order_financial_terms_id)->toBe($payment->order_financial_terms_id)
        ->and($correction->accruals()->where('kind', 'manager_owner')->sole()->amount_minor)->toBe(-4000)
        ->and($commission->fresh()->amount_minor)->toBe(4000);
});

it('pays a designer only under an individual agreement and forbids sales assignments to cashiers', function () {
    $without = financialAgreementOrder($this->designer);
    $before = $this->ledger->payment(financialAgreementPaymentInput($without));
    expect(financialAgreementAccruals($before))->toEqual([
        'owner_gross' => [$this->owner->id, 'SFP', 500, 5000],
        'rop_gross' => [$this->rop->id, 'SFP', 500, 5000],
    ]);

    $agreement = $this->terms->publish($this->designer, financialAgreementSettings($this->owner, $this->rop, 120, 180));
    $with = financialAgreementOrder($this->designer);
    $this->actingAs($this->designer);
    $payment = $this->ledger->payment(financialAgreementPaymentInput($with));
    expect($with->financialTerms->agreement_version_id)->toBe($agreement->id)
        ->and($payment->accruals()->where('beneficiary_id', $this->designer->id)->sum('amount_minor'))->toEqual(3000);

    $this->actingAs($this->owner);
    expect(fn () => app(CrmWriter::class)->save(Order::class, financialAgreementOrderInput($with->client, $this->cashier)))->toThrow(ValidationException::class);
    $this->actingAs($this->cashier);
    expect(fn () => app(CrmWriter::class)->save(Order::class, financialAgreementOrderInput($with->client, $this->cashier)))->toThrow(HttpException::class);
});

it('leaves historical operations untouched while requiring manual confirmation for future accruals', function () {
    $legacy = financialAgreementLegacyOrder($this->manager);
    $oldPayment = $this->ledger->payment(financialAgreementPaymentInput($legacy));
    expect($oldPayment->calculation_status)->toBe('needs_clarification')
        ->and($oldPayment->accruals)->toBeEmpty();

    $this->terms->confirm($legacy, financialAgreementSettings($this->owner, $this->rop) + [
        'seller_id' => $this->manager->id,
        'seller_role' => 'manager',
        'reason' => 'Условия исторического заказа проверены вручную.',
    ]);
    $newPayment = $this->ledger->payment(financialAgreementPaymentInput($legacy));

    $confirmed = $legacy->fresh()->financialTerms;
    expect($confirmed->confirmed_by)->toBe($this->owner->id)
        ->and($confirmed->confirmation_reason)->toBe('Условия исторического заказа проверены вручную.')
        ->and($newPayment->calculation_status)->toBe('calculated')
        ->and($newPayment->order_financial_terms_id)->toBe($confirmed->id)
        ->and($newPayment->accruals)->toHaveCount(4)
        ->and($oldPayment->fresh()->calculation_status)->toBe('needs_clarification')
        ->and($oldPayment->fresh()->accruals)->toBeEmpty();
});

it('accepts legacy cash before manual confirmation and leaves those accruals unresolved', function () {
    $order = financialAgreementLegacyOrder($this->manager);
    $this->actingAs($this->manager);
    $receipt = app(CashDesk::class)->collect([
        'order_id' => $order->id,
        'collected_at' => today()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
        'amount' => '1000',
    ]);
    expect($receipt->order_financial_terms_id)->toBeNull();

    $this->actingAs($this->cashier);
    $payment = $this->ledger->acceptCash($receipt, today()->toDateString());
    expect($receipt->fresh()->status)->toBe('accepted')
        ->and($payment->order_financial_terms_id)->toBeNull()
        ->and($payment->calculation_status)->toBe('needs_clarification')
        ->and($payment->accruals)->toBeEmpty();
});

it('limits publishing to the owner and limits accrual visibility to the beneficiary', function () {
    $ownAgreement = $this->terms->publish($this->manager, financialAgreementSettings($this->owner, $this->rop, 400, 150));
    $this->terms->publish($this->designer, financialAgreementSettings($this->owner, $this->rop, 100, 200));
    $order = financialAgreementOrder($this->manager);
    $payment = $this->ledger->payment(financialAgreementPaymentInput($order));
    $this->actingAs($this->manager);
    expect(fn () => $this->terms->publish($this->manager, financialAgreementSettings($this->owner, $this->rop, 100, 200)))->toThrow(HttpException::class);
    expect(CrmAccess::query(Accrual::class)->where('payment_id', $payment->id)->orderBy('kind')->pluck('kind')->all())
        ->toBe(['manager_owner', 'manager_rop'])
        ->and($this->terms->visibleVersions($this->manager)->pluck('id')->all())->toBe([$ownAgreement->id]);

    $outsider = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($outsider);
    expect(CrmAccess::query(Accrual::class)->where('payment_id', $payment->id)->count())->toBe(0)
        ->and($this->terms->visibleVersions($outsider)->exists())->toBeFalse();
});

it('rejects overfunding and edits to already published versions or confirmed terms', function () {
    expect(fn () => $this->terms->publish($this->manager, financialAgreementSettings($this->owner, $this->rop, 501, 0)))
        ->toThrow(ValidationException::class);
    $order = financialAgreementOrder($this->manager);
    expect(fn () => $this->defaultAgreement->update(['owner_funding_bps' => 300]))->toThrow(ValidationException::class);
    expect(fn () => $order->financialTerms->update(['owner_funding_bps' => 300]))->toThrow(ValidationException::class);
    expect($this->defaultAgreement->fresh()->owner_funding_bps)->toBe(250)
        ->and($order->fresh()->financialTerms->owner_funding_bps)->toBe(250);
});

it('publishes and confirms financial terms in Filament with owner-only mutations', function () {
    $legacy = financialAgreementLegacyOrder($this->manager);
    $unresolved = financialAgreementLegacyOrder($this->manager);
    $participants = ['owner_id' => $this->owner->id, 'rop_id' => $this->rop->id];

    Livewire::test(ManageUsers::class)
        ->callAction('standardFinancialAgreement', data: $participants)
        ->assertHasNoActionErrors()
        ->callTableAction('individualFinancialAgreement', $this->manager, data: $participants + ['owner_funding_percent' => '4', 'rop_funding_percent' => '1,5'])
        ->assertHasNoTableActionErrors();
    $this->assertDatabaseHas('financial_agreement_versions', $participants + ['seller_id' => null, 'version' => 2]);
    $this->assertDatabaseHas('financial_agreement_versions', financialAgreementSettings($this->owner, $this->rop, 400, 150) + ['seller_id' => $this->manager->id]);
    $this->terms->publish($this->designer, financialAgreementSettings($this->owner, $this->rop, 100, 200));

    $confirmation = [
        'seller_id' => $this->manager->id,
        'seller_role' => 'manager',
        'reason' => 'Подтверждено по соглашению заказа.',
    ];
    Livewire::test(ManageOrders::class)
        ->callTableAction('confirmFinancialTerms', $legacy, data: $participants + ['owner_funding_percent' => '4', 'rop_funding_percent' => '1.5'] + $confirmation)
        ->assertHasNoTableActionErrors();
    $this->assertDatabaseHas('order_financial_terms', [
        'order_id' => $legacy->id,
        'confirmed_by' => $this->owner->id,
        'confirmation_reason' => $confirmation['reason'],
    ]);

    $this->actingAs($this->rop);
    Livewire::test(ManageUsers::class)->assertActionHidden('standardFinancialAgreement')
        ->assertTableActionHidden('individualFinancialAgreement', $this->manager);

    $this->actingAs($this->manager);
    Livewire::test(ManageOrders::class)->assertTableActionHidden('confirmFinancialTerms', $unresolved)
        ->mountTableAction('financialTerms', $legacy)->assertMountedActionModalSee('Моя доля')->assertMountedActionModalSee('5,50 %')
        ->assertMountedActionModalDontSee($this->owner->name)->assertMountedActionModalDontSee($this->rop->name);
    Livewire::test(MyDay::class)->mountAction('myFinancialAgreement')
        ->assertMountedActionModalSee('5,50 %')->assertMountedActionModalDontSee('3,00 %');
});

it('prefills effective agreement recipients and percentages and shows preview and version history', function () {
    $this->terms->publish($this->manager, financialAgreementSettings($this->owner, $this->rop, 325, 125));
    $this->terms->publish($this->manager, financialAgreementSettings($this->owner, $this->rop, 100, 100) + ['effective_from' => now()->addDay()]);
    $page = Livewire::test(ManageUsers::class)->mountTableAction('individualFinancialAgreement', $this->manager)
        ->assertMountedActionModalSee('Индивидуальные условия · '.$this->manager->name)
        ->assertMountedActionModalSee('Новые условия применяются только к новым заказам')
        ->assertMountedActionModalSee('Предварительное распределение')
        ->assertMountedActionModalSee('4,50 %')
        ->assertMountedActionModalSee('История версий соглашения')
        ->assertMountedActionModalSee('Версия 1')->assertMountedActionModalSee('Версия 2')
        ->assertMountedActionModalSee('Запланированная версия');
    $data = $page->get('mountedActions')[0]['data'];
    expect($data)->toMatchArray(['owner_id' => $this->owner->id, 'rop_id' => $this->rop->id, 'owner_funding_percent' => '3.25', 'rop_funding_percent' => '1.25']);
    $page->callMountedTableAction()->assertHasNoTableActionErrors();
    $this->assertDatabaseHas('financial_agreement_versions', ['seller_id' => $this->manager->id, 'version' => 3, 'owner_funding_bps' => 325, 'rop_funding_bps' => 125]);
});

it('rejects invalid percentage input without publishing a version', function (string $value) {
    Livewire::test(ManageUsers::class)->callTableAction('individualFinancialAgreement', $this->manager, data: [
        'owner_id' => $this->owner->id, 'rop_id' => $this->rop->id,
        'owner_funding_percent' => $value, 'rop_funding_percent' => '0',
    ])->assertHasTableActionErrors(['owner_funding_percent']);
    $this->assertDatabaseMissing('financial_agreement_versions', ['seller_id' => $this->manager->id]);
})->with(['5.01', '2.555', '-1', '250', '1e0']);

it('stores exact fractional percentages and displays zero distribution without changing existing orders', function () {
    $order = financialAgreementOrder($this->manager);
    $before = $order->financialTerms->fresh()->getAttributes();
    Livewire::test(ManageUsers::class)->callTableAction('individualFinancialAgreement', $this->manager, data: [
        'owner_id' => $this->owner->id, 'rop_id' => $this->rop->id,
        'owner_funding_percent' => '0,01', 'rop_funding_percent' => '5.00',
    ])->assertHasNoTableActionErrors();
    $this->assertDatabaseHas('financial_agreement_versions', ['seller_id' => $this->manager->id, 'owner_funding_bps' => 1, 'rop_funding_bps' => 500]);
    expect($order->fresh()->financialTerms->getAttributes())->toBe($before);
    Livewire::test(ManageUsers::class)->mountTableAction('individualFinancialAgreement', $this->designer)
        ->assertMountedActionModalSee('0,00 %');
});

it('rejects another sellers terms even when that order is assigned to the viewer', function () {
    $order = financialAgreementOrder($this->manager);
    $outsider = User::factory()->create(['role' => 'manager', 'active' => true]);
    $order->update(['manager_id' => $outsider->id]);
    $this->actingAs($outsider);
    Livewire::test(ManageOrders::class)->assertCanSeeTableRecords([$order])
        ->mountTableAction('financialTerms', $order)->assertActionNotMounted('financialTerms');
    $this->actingAs($this->rop);
    Livewire::test(ManageUsers::class)->mountTableAction('individualFinancialAgreement', $this->manager)
        ->assertActionNotMounted('individualFinancialAgreement');
});

it('keeps order terms actions inside the actions menu and labels read-only dialogs close', function () {
    $order = financialAgreementOrder($this->manager);
    $page = Livewire::test(ManageOrders::class);
    $actions = $page->instance()->getTable()->getRecordActions();
    expect($actions)->toHaveCount(1)->and($actions[0])->toBeInstanceOf(ActionGroup::class)
        ->and(array_keys($actions[0]->getFlatActions()))->toContain('financialTerms', 'confirmFinancialTerms');
    $page->mountTableAction('financialTerms', $order)->assertMountedActionModalSee('Закрыть');
    $this->actingAs($this->manager);
    Livewire::test(MyDay::class)->mountAction('myFinancialAgreement')->assertMountedActionModalSee('Закрыть');
});
