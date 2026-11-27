<?php

use App\Filament\CrmForms;
use App\Models\Accrual;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Services\CrmWriter;
use App\Services\FinancialTerms;
use App\Services\Ledger;
use App\Services\RecordDetails;
use App\Services\Report;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

it('shows the posted rates in accruals cards and reports after an agreement changes', function () {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $rop = User::factory()->create(['role' => 'rop', 'active' => true]);
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($owner);
    $settings = ['owner_id' => $owner->id, 'rop_id' => $rop->id, 'owner_funding_bps' => 375, 'rop_funding_bps' => 125];
    app(FinancialTerms::class)->publish($manager, $settings);
    $client = Client::create(['name' => 'Клиент', 'manager_id' => $manager->id]);
    $order = app(CrmWriter::class)->save(Order::class, [
        'number' => (string) Str::ulid(), 'client_id' => $client->id, 'manager_id' => $manager->id, 'status' => 'new',
        'items' => [['name' => 'Печать', 'quantity' => 1, 'price' => '1000']],
    ]);
    $payment = app(Ledger::class)->payment(['idempotency_key' => (string) Str::uuid(), 'order_id' => $order->id, 'paid_at' => today()->toDateString(), 'amount' => '1000']);
    app(FinancialTerms::class)->publish($manager, array_replace($settings, ['owner_funding_bps' => 100, 'rop_funding_bps' => 200]));
    $accrual = $payment->accruals()->where('kind', 'manager_owner')->sole();

    $this->get('/crm/accruals')->assertOk()->assertSee('3,75%')->assertSee('1,25%')->assertDontSee('2,5%');
    expect(RecordDetails::get($accrual)['fields'])->toMatchArray([
        'kind' => 'Сотруднику из доли владельца · 3,75%', 'rate_bps' => '3,75%',
    ]);
    $paymentDetails = RecordDetails::get($payment);
    expect(view('filament.record', ['record' => $payment, 'details' => $paymentDetails])->render())->toContain('3,75%', '1,25%');
    expect(app(Report::class)->register($owner, null, null)->pluck('kind')->all())->toContain(
        'Сотруднику из доли владельца · 3,75%', 'Сотруднику из доли РОП · 1,25%',
    );
    expect($accrual->fresh()->rate_bps)->toBe(375);

    $this->actingAs($manager);
    $this->get('/crm/accruals')->assertOk()->assertDontSee('3,75%')->assertDontSee('1,25%');
    expect(RecordDetails::get($accrual)['fields'])->not->toHaveKeys(['rate_bps', 'kind', 'payer']);
});

it('never invents a missing historical rate and preserves confirmed zero and fractional rates', function () {
    expect(CrmForms::accrualLabel(new Accrual(['kind' => 'manager_owner'])))->toBe('Сотруднику из доли владельца · Не подтверждена')
        ->and(CrmForms::rateLabel(0))->toBe('0%')
        ->and(CrmForms::rateLabel(1))->toBe('0,01%')
        ->and(CrmForms::rateLabel(250))->toBe('2,5%');
});
