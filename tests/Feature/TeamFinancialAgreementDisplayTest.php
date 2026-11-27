<?php

use App\Filament\FinancialAgreementDisplay;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Services\FinancialTerms;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (['owner', 'rop', 'manager', 'designer'] as $role) {
        $this->{$role} = User::factory()->create(['role' => $role, 'active' => true]);
    }
    $this->actingAs($this->owner);
    $this->settings = ['owner_id' => $this->owner->id, 'rop_id' => $this->rop->id];
});

it('shows the currently effective individual terms and ignores scheduled versions', function () {
    $service = app(FinancialTerms::class);
    $service->publish(null, $this->settings + ['effective_from' => now()->subDays(10)]);
    $active = $service->publish($this->manager, $this->settings + [
        'owner_funding_bps' => 375, 'rop_funding_bps' => 125, 'effective_from' => now()->subDay(),
    ]);
    $service->publish($this->manager, $this->settings + [
        'owner_funding_bps' => 100, 'rop_funding_bps' => 200, 'effective_from' => now()->addDay(),
    ]);

    expect(FinancialAgreementDisplay::current($this->manager))->toMatchArray([
        'owner_funding_bps' => 375, 'rop_funding_bps' => 125, 'agreement_version_id' => $active->id,
    ]);
    Livewire::test(ManageUsers::class)->assertOk()->assertSee('Действующая ставка')
        ->assertSee('Источники финансирования')->assertSee('3,75 %')->assertSee('1,25 %')
        ->assertSee($active->effective_from->format('d.m.Y H:i'));
});

it('uses baseline conditions for new orders without inventing an effective date or designer commission', function () {
    expect(FinancialAgreementDisplay::current($this->manager))->toMatchArray([
        'owner_funding_bps' => 250, 'rop_funding_bps' => 250, 'effective_from' => null,
    ])->and(FinancialAgreementDisplay::current($this->designer))->toMatchArray([
        'owner_funding_bps' => 0, 'rop_funding_bps' => 0, 'effective_from' => null,
    ]);
    app(FinancialTerms::class)->publish(null, $this->settings);
    expect(FinancialAgreementDisplay::current($this->designer)['owner_funding_bps'])->toBe(0);
    $this->rop->update(['active' => false]);
    expect(FinancialAgreementDisplay::current($this->manager)['available'])->toBeFalse();
});

it('shows owner and ROP shares from SFP rather than zero internal commissions', function () {
    app(FinancialTerms::class)->publish(null, $this->settings);
    $otherRop = User::factory()->create(['role' => 'rop', 'active' => true]);
    $page = Livewire::test(ManageUsers::class)->assertOk();
    foreach ([$this->owner, $this->rop] as $recipient) {
        $page->assertTableColumnStateSet('agreement_rate', '5 %', $recipient)
            ->assertTableColumnStateSet('agreement_funding', 'SFP · до внутреннего распределения', $recipient);
    }
    $page->assertTableColumnStateSet('agreement_rate', 'Не определена', $otherRop)
        ->assertTableColumnStateSet('agreement_funding', 'Не определены', $otherRop)
        ->assertSee('Не получатель по действующим условиям');
});

it('hides financial columns from the ROP and rejects direct access to other staff defaults', function () {
    app(FinancialTerms::class)->publish($this->manager, $this->settings + ['owner_funding_bps' => 375, 'rop_funding_bps' => 125]);
    foreach ([$this->rop, $this->manager, $this->designer] as $actor) {
        $this->actingAs($actor);
        expect(FinancialAgreementDisplay::forUser($this->manager))->toBeNull();
        expect(fn () => FinancialAgreementDisplay::current($this->manager))->toThrow(HttpException::class);
        expect(fn () => FinancialAgreementDisplay::current(null))->toThrow(HttpException::class);
    }
    $this->actingAs($this->rop);
    Livewire::test(ManageUsers::class)->assertOk()->assertCanSeeTableRecords([$this->manager])
        ->assertDontSee('Действующая ставка')->assertDontSee('Источники финансирования')->assertDontSee('3,75 %');

    $this->owner->update(['active' => false]);
    $this->actingAs($this->owner);
    expect(FinancialAgreementDisplay::forUser($this->manager))->toBeNull();
    expect(fn () => FinancialAgreementDisplay::current($this->manager))->toThrow(HttpException::class);
});
