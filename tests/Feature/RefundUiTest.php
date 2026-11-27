<?php

use App\Filament\Resources\Refunds\Pages\ManageRefunds;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

function refundUiScenario(): array
{
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $rop = User::factory()->create(['role' => 'rop', 'active' => true]);
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $client = Client::create(['name' => 'Тестовый клиент возврата', 'manager_id' => $manager->id]);
    $order = Order::create(['number' => (string) Str::ulid(), 'client_id' => $client->id, 'manager_id' => $manager->id, 'currency' => 'UZS', 'status' => 'new']);
    $payment = Payment::create([
        'idempotency_key' => (string) Str::uuid(), 'order_id' => $order->id, 'manager_id' => $manager->id,
        'paid_at' => today()->toDateString(), 'amount_minor' => 100000, 'currency' => 'UZS',
        'recipient' => 'SFP', 'method' => 'Перечисление', 'calculation_status' => 'needs_clarification',
    ]);

    return [$owner, $rop, $manager, $order, $payment];
}

function refundUiInput(Order $order, Payment $payment, string $method): array
{
    return [
        'order_id' => $order->id, 'amount' => '100.00', 'method' => $method, 'reason' => 'Тестовая причина возврата',
        'allocations' => [['payment_id' => $payment->id, 'amount' => '100.00']],
    ];
}

it('creates, approves and completes cash refunds through their authorized Filament actions', function () {
    [$owner, $rop, $manager, $order, $payment] = refundUiScenario();

    $this->actingAs($rop)->get('/crm/refunds')->assertOk();
    Livewire::test(ManageRefunds::class)
        ->callAction('requestRefund', data: refundUiInput($order, $payment, 'cash'))
        ->assertHasNoActionErrors();
    $refund = Refund::sole();
    expect($refund->created_by)->toBe($rop->id);

    Livewire::test(ManageRefunds::class)->assertTableActionHidden('approveRefund', $refund);
    $this->actingAs($owner);
    Livewire::test(ManageRefunds::class)->mountTableAction('approveRefund', $refund)
        ->assertMountedActionModalSee('Ожидаемое сторно после выдачи денег')
        ->assertMountedActionModalSee('Останется после этой выдачи')
        ->setTableActionData(['source' => 'SFP'])->callMountedTableAction()->assertHasNoTableActionErrors();
    $refund->refresh();
    expect($refund->status)->toBe(Refund::APPROVED);
    Livewire::test(ManageRefunds::class)->assertTableActionHidden('executeRefund', $refund);

    $cashier = User::factory()->create(['role' => 'cashier', 'active' => true]);
    $this->actingAs($cashier)->get('/crm/refunds')->assertOk();
    Livewire::test(ManageRefunds::class)->assertCanSeeTableRecords([$refund])
        ->mountTableAction('refundDetails', $refund)->assertMountedActionModalDontSee($refund->reason);

    Storage::fake('local');
    Livewire::test(ManageRefunds::class)->mountTableAction('executeRefund', $refund)
        ->assertMountedActionModalSee('Источник денег')
        ->setTableActionData([
            'money_returned' => true,
            'executed_at' => today()->toDateString(),
            'document' => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n"),
        ])->callMountedTableAction()->assertHasNoTableActionErrors();
    $refund->refresh();
    expect($refund->status)->toBe(Refund::COMPLETED)->and($refund->executed_by)->toBe($cashier->id);
    Storage::disk('local')->assertExists($refund->document);

    $document = route('crm.document', ['type' => 'refund', 'id' => $refund->id]);
    $this->get($document)->assertNotFound();
    $this->actingAs($manager)->get($document)->assertNotFound();
    $this->actingAs($owner)->get($document)->assertOk()->assertDownload()->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($rop)->get($document)->assertOk()->assertDownload();

    $order->update(['attachment' => 'documents/../'.$refund->document]);
    $this->actingAs($manager)->get(route('crm.document', ['type' => 'order', 'id' => $order->id]))->assertNotFound();
});

it('scopes refund records and rejects forged action mounts for roles without approval or record access', function () {
    [$owner, , $manager, $order, $payment] = refundUiScenario();
    $this->actingAs($owner);
    $refunds = app(RefundService::class);
    $bank = $refunds->create(refundUiInput($order, $payment, 'bank_transfer') + ['idempotency_key' => (string) Str::uuid()]);
    $refunds->approve($bank, 'Promolux', (string) Str::uuid());
    $bank->refresh();

    $this->actingAs($manager);
    Livewire::test(ManageRefunds::class)->assertCanSeeTableRecords([$bank])
        ->assertActionHidden('requestRefund')
        ->call('mountAction', 'executeRefund', [], ['recordKey' => (string) $bank->id, 'table' => true])
        ->assertSet('mountedActions', []);

    $outsider = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($outsider);
    Livewire::test(ManageRefunds::class)->assertCanNotSeeTableRecords([$bank])
        ->call('mountAction', 'refundDetails', [], ['recordKey' => (string) $bank->id, 'table' => true])
        ->assertSet('mountedActions', []);

    $cashier = User::factory()->create(['role' => 'cashier', 'active' => true]);
    $this->actingAs($cashier);
    Livewire::test(ManageRefunds::class)->assertCanNotSeeTableRecords([$bank]);
    expect($bank->fresh()->status)->toBe(Refund::APPROVED);
});

it('paginates hundreds of refunds with a bounded number of relation queries', function () {
    [$owner, , , $order, $payment] = refundUiScenario();
    $this->actingAs($owner);
    $first = app(RefundService::class)->create(refundUiInput($order, $payment, 'cash') + ['idempotency_key' => (string) Str::uuid()]);
    $measure = function (): array {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $page = Livewire::test(ManageRefunds::class);
            $queries = collect(DB::getQueryLog())->filter(fn ($entry) => preg_match('/"(orders|clients|refunds)"/', $entry['query']))->count();

            return [$page, $queries];
        } finally {
            DB::disableQueryLog();
        }
    };
    [, $baseline] = $measure();
    $records = collect(range(1, 240))->map(fn (int $index) => [
        'idempotency_key' => (string) Str::uuid(), 'number' => 'RF-BATCH-'.$index,
        'order_id' => $order->id, 'client_id' => $order->client_id, 'created_by' => $owner->id,
        'status' => Refund::CREATED, 'method' => 'cash', 'currency' => 'UZS', 'amount_minor' => 100,
        'reason' => 'Фикстура нагрузки', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($records->chunk(50) as $batch) {
        DB::table('refunds')->insert($batch->all());
    }
    [$page, $queries] = $measure();
    expect($queries)->toBe($baseline);
    $page->assertCanNotSeeTableRecords([$first])->searchTable($first->number)->assertCanSeeTableRecords([$first]);
});
