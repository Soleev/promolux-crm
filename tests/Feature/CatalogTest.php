<?php

use App\Filament\CrmForms;
use App\Filament\Resources\Catalog\Pages\ManageProduct;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\Accrual;
use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\CatalogSupplier;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Order;
use App\Models\User;
use App\Services\CrmWriter;
use App\Services\Ledger;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
    $this->actingAs($this->manager = User::factory()->create(['role' => 'manager', 'active' => true]));
    $client = Client::create(['name' => 'Catalog test', 'manager_id' => $this->manager->id]);
    $this->writer = app(CrmWriter::class);
    $this->banner = CatalogProduct::whereCode('pl-038')->sole();
    $this->design = CatalogProduct::whereCode('pl-071')->sole();
    $this->sale = ['number' => 'TEST-CATALOG', 'title' => 'Catalog test', 'client_id' => $client->id, 'status' => 'new', 'items' => [
        ['product_id' => $this->banner->id, 'width' => '2,4', 'height' => '2.4', 'quantity' => 1, 'price' => '3.33', 'characteristics' => ['Матовый']],
        ['product_id' => $this->design->id, 'quantity' => 1, 'price' => '0', 'supplier_id' => CatalogSupplier::whereCode('sfp')->sole()->id],
        ['name' => 'Нестандартная', 'price' => '10', 'quantity' => 3],
    ]];
});

it('seeds the exact assortment and preserves commercial overrides on rerun', function () {
    expect(CatalogProduct::all()->contains(fn ($p) => in_array('Тираж', $p->fields ?? [], true)))->toBeFalse();
    expect(CatalogCategory::count())->toBe(13)->and(CatalogProduct::count())->toBe(80)
        ->and(CatalogProduct::available()->count())->toBe(15)->and(CatalogSupplier::count())->toBe(2);
    $this->banner->update(['name' => 'Согласованное имя', 'reference_minor' => 124, 'active' => false]);
    $this->seed(CatalogSeeder::class);
    expect(CatalogProduct::count())->toBe(80)->and($this->banner->fresh()->name)->toBe('Согласованное имя')
        ->and((int) $this->banner->fresh()->reference_minor)->toBe(124)->and(CatalogProduct::available()->count())->toBe(14);
});

it('preserves historical rows and canonical totals through conversion, edits and disabling', function () {
    $this->sale['status'] = 'won';
    $deal = $this->writer->save(Deal::class, $this->sale);
    $order = $this->writer->convert($deal);
    expect($order->total_minor)->toBe(4918)
        ->and($order->items[0]->catalog_snapshot)->toEqual($deal->items[0]['catalog_snapshot'])
        ->and($order->items[0]->catalog_snapshot['area'])->toBe('5.7600')
        ->and($order->items[0]->catalog_snapshot['characteristics'])->toBe(['Матовый'])
        ->and($order->items[0]->catalog_snapshot['supplier_id'])->toEqual($this->banner->supplier_id)
        ->and($order->items[1]->catalog_snapshot['supplier_id'])->toEqual($this->design->alternative_ids[0])
        ->and($order->items[1]->price_minor)->toBe(0);
    $edit = CrmForms::editData($order->toArray(), $order);
    $this->banner->update(['name' => 'Другое название', 'active' => false, 'reference_minor' => 300]);
    $this->writer->save(Order::class, $edit, $order);
    expect($order->fresh()->items)->toHaveCount(3)->and($order->fresh()->total_minor)->toBe(4918)
        ->and($order->fresh()->items[0]->name)->toBe('Баннерная печать');
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('accruals', 0);
    $edit['items'][0]['product_id'] = $this->design->id;
    expect(fn () => $this->writer->save(Order::class, $edit, $order))->toThrow(ValidationException::class);
    expect($order->fresh()->total_minor)->toBe(4918);
});

it('rejects invalid sale inputs atomically', function (array $patch) {
    if (($patch['product_id'] ?? null) === 'draft') {
        $patch['product_id'] = CatalogProduct::whereCode('pl-003')->sole()->id;
    }
    $this->sale['items'][0] = array_replace($this->sale['items'][0], $patch);
    expect(fn () => $this->writer->save(Order::class, $this->sale))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
})->with([
    'draft' => [['product_id' => 'draft']], 'unknown supplier' => [['supplier_id' => 30000]],
    'unsupported field' => [['characteristics' => ['invalid' => 'value']]],
    'negative price' => [['price' => '-1']], 'invalid dimensions' => [['height' => '0.001']],
    'forged history' => [['line_key' => '00000000-0000-4000-8000-000000000001']],
]);

it('searches active products in Cyrillic and denies manager writes on the table', function () {
    Livewire::test(ManageProduct::class)->searchTable('баннерная')->assertCanSeeTableRecords([$this->banner])
        ->assertCanNotSeeTableRecords([$this->design])->assertTableActionHidden('edit', $this->banner)
        ->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $this->banner->id])
        ->assertActionNotMounted('edit');
    $this->get('/crm/catalog/suppliers')->assertForbidden();
});

it('keeps the catalog closed to non-sales roles', function ($role) {
    $this->actingAs(User::factory()->create(['role' => $role, 'active' => true]));
    $this->get('/crm/catalog/products')->assertForbidden();
})->with(['designer', 'cashier']);

it('retains every row when an existing order is saved from its Filament modal', function () {
    $order = $this->writer->save(Order::class, $this->sale);
    $this->design->update(['alternative_ids' => []]);
    $this->banner->update(['active' => false]);
    Livewire::test(ManageOrders::class)->mountTableAction('edit', $order)->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($order->fresh()->items)->toHaveCount(3)->and($order->fresh()->total_minor)->toBe(4918)
        ->and($order->fresh()->items[0]->catalog_snapshot['characteristics'])->toBe(['Матовый']);
});

it('lets the head of sales update a reference price without affecting a saved order', function () {
    $order = $this->writer->save(Order::class, $this->sale);
    $this->actingAs(User::factory()->create(['role' => 'rop', 'active' => true]));
    Livewire::test(ManageProduct::class)->mountTableAction('edit', $this->banner)
        ->setTableActionData(['reference_minor' => '123.45'])
        ->callMountedTableAction()->assertHasNoTableActionErrors();
    expect((int) $this->banner->fresh()->reference_minor)->toBe(12345)->and($order->fresh()->total_minor)->toBe(4918);
});

it('keeps historical integer prices, payments and commissions when editing a paid order', function () {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $rop = User::factory()->create(['role' => 'rop', 'active' => true]);
    $this->sale['items'] = [['name' => 'Тираж v0.3.7', 'quantity' => 150, 'price' => '123.45']];
    $order = $this->writer->save(Order::class, $this->sale);
    $this->actingAs($owner);
    $payment = app(Ledger::class)->payment(['order_id' => $order->id, 'owner_id' => $owner->id, 'rop_id' => $rop->id,
        'amount' => '9000', 'paid_at' => today()->toDateString(), 'idempotency_key' => (string) Str::uuid()]);
    $before = Accrual::orderBy('id')->get()->toArray();
    $original = $payment->fresh()->getRawOriginal();
    $data = CrmForms::editData($order->toArray(), $order);
    $this->writer->save(Order::class, $data, $order);
    expect($order->fresh()->total_minor)->toBe(1851750)
        ->and($order->fresh()->items->sole()->catalog_snapshot)->toBeNull()
        ->and($payment->fresh()->getRawOriginal())->toBe($original)
        ->and(Accrual::orderBy('id')->get()->toArray())->toBe($before);
});
