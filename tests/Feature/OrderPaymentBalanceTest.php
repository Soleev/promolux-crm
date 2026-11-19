<?php

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\Order;
use App\Models\User;
use App\Services\CashDesk;
use App\Services\Ledger;
use App\Services\RecordDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

it('shows the outstanding balance through partial payments, settlement, overpayment and reversal', function () {
    [, , , $order, $input] = crmScenario();
    $ledger = app(Ledger::class);
    $assertBalance = function (int $paid, string $percent, string $outstanding, string $status) use ($order) {
        $row = OrderResource::getEloquentQuery()->findOrFail($order->id);
        expect($row->paid_minor)->toBe($paid)->and($row->payment_status)->toBe($status);
        $this->view('filament.order-payment', ['order' => $row])
            ->assertSee($percent)->assertSeeInOrder(['Осталось получить', $outstanding]);
        $this->view('filament.order-payment-compact', ['order' => $row])
            ->assertSee($percent)->assertSee($status)->assertSee($row->displayAmount($paid))
            ->assertSee($row->displayAmount(abs($row->total_minor - $paid)));
    };
    $assertBalance(0, '0,0%', '10 000 000 сум', 'Не оплачен');
    $first = array_replace($input, ['amount' => '1234567.89']);
    $ledger->payment($first);
    $ledger->payment($first);
    $assertBalance(123456789, '12,3%', '8 765 432,11 сум', 'Частично оплачен');
    $ledger->payment(array_replace($input, ['amount' => '765432.11', 'idempotency_key' => (string) Str::uuid()]));
    $assertBalance(200000000, '20,0%', '8 000 000 сум', 'Частично оплачен');
    $ledger->payment(array_replace($input, ['amount' => '8000000', 'idempotency_key' => (string) Str::uuid()]));
    $assertBalance(1000000000, '100,0%', '0 сум', 'Оплачен');
    $extra = $ledger->payment(array_replace($input, ['amount' => '12500', 'idempotency_key' => (string) Str::uuid()]));
    $assertBalance(1001250000, '100,1%', '0 сум', 'Переплата');
    $this->get('/crm/orders')->assertOk()->assertSeeInOrder(['Переплата', '12 500 сум']);
    $ledger->reversePayment($extra, 'Исправление тестовой оплаты');
    $assertBalance(1000000000, '100,0%', '0 сум', 'Оплачен');
});

it('counts cash once on receipt and keeps the order history scoped to the viewer', function () {
    [$owner, $rop, $manager, $order, $input] = crmScenario();
    $this->actingAs($manager);
    $cash = app(CashDesk::class)->collect($input + ['collected_at' => today()->toDateString()]);
    $details = RecordDetails::get($order);
    expect($details['balanceOrder']->paid_minor)->toBe(1000000000)
        ->and($details['pendingCash']->modelKeys())->toBe([$cash->id])
        ->and($details['payments'])->toBeEmpty();

    $cashier = User::factory()->create(['role' => 'cashier', 'active' => true]);
    $this->actingAs($cashier);
    $ledger = app(Ledger::class);
    $payment = $ledger->acceptCash($cash, today()->toDateString());
    $ledger->acceptCash($cash, today()->toDateString());
    $this->actingAs($manager);
    $details = RecordDetails::get($order);
    expect($details['balanceOrder']->paid_minor)->toBe(1000000000)
        ->and($details['pendingCash'])->toBeEmpty()->and($details['payments']->modelKeys())->toBe([$payment->id]);

    $designer = User::factory()->create(['role' => 'designer', 'active' => true]);
    $order->update(['manager_id' => $designer->id]);
    foreach ([$owner, $rop, $designer] as $viewer) {
        $this->actingAs($viewer);
        $details = RecordDetails::get($order);
        expect($details['balanceOrder']->paid_minor)->toBe(1000000000)
            ->and($details['payments']->count())->toBe($viewer->is($designer) ? 0 : 1);
    }
    foreach ([$manager, $cashier] as $viewer) {
        $this->actingAs($viewer);
        expect(OrderResource::getEloquentQuery()->find($order->id))->toBeNull()
            ->and(fn () => RecordDetails::get($order))->toThrow(AuthorizationException::class);
    }
});

it('loads empty orders and payment indicators without additional queries per row', function () {
    [, , , $first] = crmScenario();
    $empty = Order::create(['number' => 'EMPTY', 'client_id' => $first->client_id, 'currency' => 'UZS']);
    $rows = OrderResource::getEloquentQuery()->get();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        foreach ($rows as $row) {
            $component = $this->view('filament.order-payment', ['order' => $row]);
            if ($row->is($empty)) {
                $component->assertSee('Оплачено: —')->assertDontSee('<progress', false);
                $this->view('filament.order-payment-compact', ['order' => $row])->assertSee('—')->assertDontSee('<progress', false);
            }
        }
        expect(DB::getQueryLog())->toBeEmpty();
    } finally {
        DB::disableQueryLog();
    }
});

it('renders a page of orders with a bounded number of financial and authorization queries', function () {
    [, , , $first] = crmScenario();
    $measure = function () {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->get('/crm/orders')->assertOk();

            return collect(DB::getQueryLog())->filter(fn ($entry) => preg_match('/\b(?:orders|order_items|payments|cash_receipts)\b/i', $entry['query']))->count();
        } finally {
            DB::disableQueryLog();
        }
    };
    $one = $measure();
    foreach (range(1, 6) as $index) {
        Order::create(['number' => 'BATCH-'.$index, 'client_id' => $first->client_id, 'currency' => 'UZS']);
    }
    expect($measure())->toBe($one);
});

it('keeps list and history amounts in the order currency', function () {
    [, , , $order, $input] = crmScenario();
    $payment = app(Ledger::class)->payment($input);
    // Historical fixture only. The current payment form accepts UZS exclusively.
    $foreign = $payment->replicate();
    $foreign->currency = 'EUR';
    $foreign->idempotency_key = (string) Str::uuid();
    $foreign->save();
    $details = RecordDetails::get($order);
    expect($details['payments']->modelKeys())->toBe([$payment->id])
        ->and($details['balanceOrder']->paid_minor)->toBe($payment->amount_minor)
        ->and($order->fresh()->paid_minor)->toBe($payment->amount_minor);
});

it('keeps order balances attached to their rows through sorting, search, filters and pagination', function () {
    [, , , $first, $input] = crmScenario();
    app(Ledger::class)->payment($input);
    foreach (range(1, 10) as $i) {
        $other = Order::create(['number' => 'SORT-'.$i, 'client_id' => $first->client_id, 'currency' => 'UZS', 'status' => 'production']);
    }
    $page = Livewire::test(ManageOrders::class)
        ->set('tableRecordsPerPage', 10)->sortTable('number', 'asc')
        ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$other])
        ->assertSee('100,0%');
    $page->call('gotoPage', 2, $page->instance()->getTablePaginationPageName())
        ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$first])->assertDontSee('100,0%');
    $page->searchTable($first->number)->assertCanSeeTableRecords([$first])->assertSee('100,0%')
        ->filterTable('status', 'production')->assertCanNotSeeTableRecords([$first]);
});

it('formats a historical foreign-currency order consistently in both payment views', function () {
    [, , , $order] = crmScenario('EUR');
    $row = OrderResource::getEloquentQuery()->findOrFail($order->id);
    foreach (['filament.order-payment', 'filament.order-payment-compact'] as $view) {
        $this->view($view, ['order' => $row])->assertSee('10000000.00 EUR')->assertDontSee('сум');
    }
});
