<?php

use App\Models\User;
use App\Services\Ledger;
use App\Services\RecordDetails;
use App\Services\Report;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('enforces financial confidentiality across pages, export and related records', function (string $role) {
    [$owner, $rop, $manager, $order, $input] = crmScenario();
    $payment = app(Ledger::class)->payment($input);
    $actor = match ($role) {
        'owner' => $owner, 'rop' => $rop, 'manager' => $manager,
        default => User::factory()->create(['role' => $role, 'active' => true]),
    };
    $this->actingAs($actor);
    $this->get('/crm/about')->assertOk()->assertSee('Подготовительный этап')->assertDontSee('5%');
    $export = $this->get('/crm-register.csv?manager='.$manager->id);
    $overview = $this->get('/crm/overview');
    $accruals = $this->get('/crm/accruals');
    $payouts = $this->get('/crm/payouts');
    $gross = $payment->accruals()->where('kind', 'owner_gross')->sole();
    if ($role === 'owner') {
        $export->assertOk();
        expect($export->streamedContent())->toContain($order->number);
        $overview->assertOk()->assertSee('Скачать реестр CSV');
        $accruals->assertOk()->assertSee('2,5%');
        $payouts->assertOk();
        expect(RecordDetails::get($gross)['fields'])->toHaveKey('rate_bps');
        expect(RecordDetails::get($payment)['accruals'])->toHaveCount(4);

        return;
    }
    $export->assertForbidden();
    expect(fn () => RecordDetails::get($gross))->toThrow(AuthorizationException::class);
    if (in_array($role, ['designer', 'cashier'], true)) {
        $overview->assertForbidden();
        $payouts->assertForbidden();
        $accruals->assertForbidden();
        expect(fn () => RecordDetails::get($payment))->toThrow(AuthorizationException::class);

        return;
    }
    $overview->assertOk()->assertDontSee('Скачать реестр CSV')->assertDontSee('После вознаграждения менеджеру');
    $payouts->assertOk()->assertDontSee('Плательщик');
    $accruals->assertOk()->assertDontSee('2,5%')->assertDontSee('5%');
    $own = $payment->accruals()->where('beneficiary_id', $actor->id)->firstOrFail();
    $details = RecordDetails::get($own);
    expect(array_intersect(['rate_bps', 'kind', 'payer'], array_keys($details['fields'])))->toBe([]);
    expect(RecordDetails::get($payment)['accruals'])->toBeEmpty();
    expect(app(Report::class)->build($actor, null, null)['currencies']['UZS'])->not->toHaveKey('owner_gross');
})->with(['owner', 'rop', 'manager', 'designer', 'cashier']);

it('applies the preview role to financial exports until the owner exits preview', function () {
    [$owner, , $manager] = crmScenario();
    $this->actingAs($owner)->post('/crm/preview-role', ['view_as' => 'user:'.$manager->id])->assertRedirect();
    $this->get('/crm-register.csv')->assertForbidden();
    $this->get('/crm/overview')->assertOk()->assertDontSee('Скачать реестр CSV');
    $this->post('/crm/exit-preview')->assertRedirect();
    $this->get('/crm-register.csv')->assertOk();
});
