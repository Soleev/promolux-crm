<?php

use App\Filament\Resources\CashReceipts\CashOverview;
use App\Filament\Resources\CashReceipts\CashReceiptResource;
use App\Filament\Resources\CashReceipts\Pages\ManageCashReceipts;
use App\Models\User;
use App\Services\CashDesk;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
it('requires explicit physical receipt and keeps acceptance idempotent', function () {
    [$owner, $rop, $manager, $order, $input] = crmScenario();
    $this->actingAs($manager);
    $cash = app(CashDesk::class)->collect($input + ['collected_at' => today()->subDays(4)->toDateString()]);
    $cashier = User::factory()->create(['role' => 'cashier', 'active' => true]);
    $this->actingAs($cashier);
    $page = Livewire::test(ManageCashReceipts::class)->assertSee('Приём наличных в кассу SFP')->assertCanSeeTableRecords([$cash]);
    $page->callTableAction('accept', $cash, data: ['handed_at' => today()->toDateString(), 'physically_received' => false])->assertHasTableActionErrors();
    expect($cash->fresh()->status)->toBe('pending');
    Livewire::test(ManageCashReceipts::class)->callTableAction('accept', $cash, data: ['handed_at' => today()->subDay()->toDateString(), 'physically_received' => true])->assertHasNoTableActionErrors();
    expect($cash->fresh()->status)->toBe('accepted');
    expect(CashOverview::acceptedToday(CashReceiptResource::getEloquentQuery())->count())->toBe(1);
    $payment = app(Ledger::class)->acceptCash($cash, today()->toDateString());
    expect($payment->id)->toBe($cash->fresh()->payment_id);
    Livewire::test(ManageCashReceipts::class)->set('activeTab', 'today')->assertCanSeeTableRecords([$cash]);
    $this->travel(1)->days();
    expect(CashOverview::acceptedToday(CashReceiptResource::getEloquentQuery())->count())->toBe(0);
});

it('does not register removed impersonation endpoints', function () {
    expect(Route::has('crm.preview.start'))->toBeFalse()
        ->and(Route::has('crm.preview.stop'))->toBeFalse();
    $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
    $this->post('/crm/preview-role')->assertNotFound();
    $this->post('/crm/exit-preview')->assertNotFound();
});
