<?php

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\CatalogSupplier;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->promoluxSupplier = CatalogSupplier::create(['code' => 'promolux', 'name' => 'Цех № 1']);
    $this->sfpSupplier = CatalogSupplier::create(['code' => 'sfp', 'name' => 'Цех № 2']);
});

function sfpOrderItem(Order $order, string $name, ?CatalogSupplier $supplier): void
{
    $order->items()->create([
        'name' => $name, 'quantity' => 1, 'price_minor' => 987654321,
        'catalog_snapshot' => $supplier ? [
            'supplier_id' => $supplier->id, 'supplier' => $supplier->name,
            'area_pricing' => true, 'copies' => 4, 'width' => '3', 'height' => '2', 'rate_minor' => 1234567,
        ] : null,
    ]);
}

function sfpOrder(User $user, ?CatalogSupplier $supplier, string $status, ?string $due = null, string $itemName = 'Баннер'): Order
{
    $client = Client::create(['name' => 'Клиент экрана', 'manager_id' => $user->id]);
    $order = Order::create(['number' => (string) Str::ulid(), 'client_id' => $client->id, 'manager_id' => $user->id, 'currency' => 'UZS', 'status' => $status, 'due_at' => $due]);
    sfpOrderItem($order, $itemName, $supplier);

    return $order;
}

it('uses active statuses and Tashkent dates without inventing completion metrics', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 20:01:00', 'UTC'));
    $user = User::factory()->create(['role' => 'owner', 'active' => true]);
    sfpOrder($user, $this->sfpSupplier, 'production', '2026-11-22');
    sfpOrder($user, $this->sfpSupplier, 'new', '2026-11-21');
    sfpOrder($user, $this->sfpSupplier, 'design');
    sfpOrder($user, $this->promoluxSupplier, 'production', '2026-11-22');
    sfpOrder($user, $this->promoluxSupplier, 'production', '2026-11-21');
    $closed = [];
    foreach (['ready', 'delivered', 'cancelled'] as $status) {
        $closed[] = sfpOrder($user, $this->sfpSupplier, $status, '2026-11-21');
    }
    $this->actingAs($user)->get('/crm/sfp')->assertOk()->assertViewHas('working', 1)
        ->assertViewHas('dueToday', 1)->assertViewHas('overdue', 1)->assertViewHas('total', 3)
        ->assertSee('Нет данных')->assertDontSee('987654321')->assertDontSee('1234567');
    $queue = $this->get('/crm/sfp?view=queue')->assertOk();
    foreach ($closed as $order) {
        $queue->assertDontSee($order->number);
        $this->get('/crm/sfp?order='.$order->id)->assertNotFound();
    }
});

it('shows only SFP orders and items to every permitted CRM role', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'active' => true]);
    $sfp = sfpOrder($user, $this->sfpSupplier, 'new', null, 'Плакат для SFP');
    $promolux = sfpOrder($user, $this->promoluxSupplier, 'production', '2026-11-21', 'Наклейка для Promolux');
    $mixed = sfpOrder($user, $this->sfpSupplier, 'production', '2026-11-21', 'Баннер для SFP');
    sfpOrderItem($mixed, 'Табличка для Promolux', $this->promoluxSupplier);
    sfpOrderItem($mixed, 'Ручная позиция SFP', null);

    $this->actingAs($user)->get('/crm/sfp')->assertOk()->assertViewHas('total', 2)
        ->assertSee($sfp->number)->assertSee('Плакат для SFP')
        ->assertSee($mixed->number)->assertSee('Баннер для SFP')
        ->assertDontSee($promolux->number)->assertDontSee('Наклейка для Promolux')
        ->assertDontSee('Табличка для Promolux')->assertDontSee('Ручная позиция SFP');

    $this->get('/crm/sfp?order='.$mixed->id)->assertOk()
        ->assertSee('Баннер для SFP')->assertDontSee('Табличка для Promolux')
        ->assertDontSee('Ручная позиция SFP')->assertDontSee('987654321')->assertDontSee('1234567');
    $this->get('/crm/sfp?order='.$promolux->id)->assertNotFound()->assertDontSee('Наклейка для Promolux');
})->with(['owner', 'rop', 'manager', 'designer']);

it('matches the saved supplier even when the current catalog product uses a different default', function () {
    $user = User::factory()->create(['role' => 'owner', 'active' => true]);
    $category = CatalogCategory::create(['code' => 'fixture', 'name' => 'Тестовая категория']);
    $product = CatalogProduct::create([
        'code' => 'fixture-banner', 'name' => 'Баннер каталога', 'category_id' => $category->id,
        'type' => 'banner', 'unit' => 'м²', 'supplier_id' => $this->promoluxSupplier->id,
        'alternative_ids' => [$this->sfpSupplier->id], 'active' => true,
    ]);
    $sfp = sfpOrder($user, $this->sfpSupplier, 'production');
    $sfp->items()->sole()->update(['product_id' => $product->id]);
    $legacy = sfpOrder($user, null, 'production', null, 'Ручной заказ SFP');

    $this->actingAs($user)->get('/crm/sfp?view=queue')->assertOk()->assertViewHas('total', 1)
        ->assertSee($sfp->number)->assertDontSee($legacy->number);
    $this->get('/crm/sfp?order='.$legacy->id)->assertNotFound();
});

it('reapplies the filter on automatic refresh requests', function () {
    $user = User::factory()->create(['role' => 'owner', 'active' => true]);
    $mixed = sfpOrder($user, $this->sfpSupplier, 'production', null, 'Баннер для SFP');
    sfpOrderItem($mixed, 'Табличка для Promolux', $this->promoluxSupplier);

    $this->actingAs($user)->get('/crm/sfp?view=queue')->assertOk()->assertSee($mixed->number);
    $this->get('/crm/sfp?fragment=1&view=queue')->assertOk()->assertViewIs('sfp.content')
        ->assertSee('Баннер для SFP')->assertDontSee('Табличка для Promolux');
    $this->get('/crm/sfp?fragment=1&order='.$mixed->id)->assertOk()->assertViewIs('sfp.content')
        ->assertSee('Баннер для SFP')->assertDontSee('Табличка для Promolux');

    $item = $mixed->items()->where('name', 'Баннер для SFP')->sole();
    $item->update(['catalog_snapshot' => [
        ...$item->catalog_snapshot,
        'supplier_id' => $this->promoluxSupplier->id,
        'supplier' => $this->promoluxSupplier->name,
    ]]);

    $this->get('/crm/sfp?fragment=1&view=queue')->assertOk()->assertViewHas('total', 0)
        ->assertDontSee($mixed->number)->assertDontSee('Табличка для Promolux');
    $this->get('/crm/sfp?fragment=1&order='.$mixed->id)->assertNotFound()->assertDontSee('Табличка для Promolux');
});

it('restricts the screen and each detail to existing order permissions', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'active' => true]);
    $other = User::factory()->create(['role' => 'manager', 'active' => true]);
    $own = sfpOrder($user, $this->sfpSupplier, 'production');
    $foreign = sfpOrder($other, $this->sfpSupplier, 'production');
    $this->actingAs($user);
    if ($role === 'cashier') {
        $this->get('/crm/sfp')->assertForbidden();
        $this->get('/crm/sfp?fragment=1&order='.$own->id)->assertForbidden();
    } else {
        $this->get('/crm/sfp?order='.$own->id)->assertOk()->assertSee('Количество: 4')->assertDontSee('987654321');
        $response = $this->get('/crm/sfp?fragment=1&order='.$foreign->id);
        in_array($role, ['owner', 'rop']) ? $response->assertOk() : $response->assertNotFound();
        $this->get('/crm/sfp?fragment=1')->assertViewHas('total', in_array($role, ['owner', 'rop']) ? 2 : 1);
    }
})->with(['owner', 'rop', 'manager', 'designer', 'cashier']);

it('rejects guests disabled users and writes', function () {
    $this->get('/crm/sfp')->assertRedirect('/crm/login');
    $user = User::factory()->create(['role' => 'owner', 'active' => false]);
    $this->actingAs($user)->get('/crm/sfp?fragment=1')->assertForbidden();
    $this->post('/crm/sfp')->assertStatus(405);
});

it('paginates the full queue and keeps undated orders last', function () {
    $user = User::factory()->create(['role' => 'owner', 'active' => true]);
    $undated = sfpOrder($user, $this->sfpSupplier, 'new');
    $promolux = sfpOrder($user, $this->promoluxSupplier, 'production', '2026-11-22');
    for ($i = 0; $i < 21; $i++) {
        sfpOrder($user, $this->sfpSupplier, 'design', '2026-11-23');
    }
    $this->actingAs($user)->get('/crm/sfp?view=queue&fragment=1')->assertOk()
        ->assertViewHas('queue', fn ($queue) => $queue->count() === 20 && $queue->total() === 22 && $queue->lastPage() === 2)
        ->assertDontSee($promolux->number)->assertDontSee('fragment=1');
    $this->get('/crm/sfp?view=queue&page=2')->assertOk()
        ->assertViewHas('queue', fn ($queue) => $queue->count() === 2 && $queue->last()->id === $undated->id);
});

it('allows a monitor to see only the active production queue across managers', function () {
    $monitor = User::factory()->create(['role' => 'sfp_screen', 'active' => true]);
    $first = User::factory()->create(['role' => 'manager', 'active' => true]);
    $second = User::factory()->create(['role' => 'manager', 'active' => true]);
    $a = sfpOrder($first, $this->sfpSupplier, 'production');
    $b = sfpOrder($second, $this->sfpSupplier, 'design');
    $closed = sfpOrder($second, $this->sfpSupplier, 'cancelled');
    $item = $b->items()->sole();
    $item->update(['catalog_snapshot' => [
        ...$item->catalog_snapshot,
        'characteristics' => ['private' => 'FINANCIAL-SECRET-MARKER'],
    ]]);

    $this->actingAs($monitor)->get('/crm/sfp')->assertOk()->assertViewHas('total', 2)
        ->assertSee($a->number)->assertSee($b->number)->assertDontSee($closed->number);
    $this->get('/crm/sfp?fragment=1&order='.$b->id)->assertOk()->assertSee('Количество: 4')
        ->assertDontSee('987654321')->assertDontSee('FINANCIAL-SECRET-MARKER');
    $this->get('/crm/sfp?order='.$closed->id)->assertNotFound();
    foreach (['/crm', '/crm/clients', '/crm/orders', '/crm-register.csv', '/crm-documents/order/'.$a->id] as $path) {
        $this->get($path)->assertForbidden();
    }
    $this->post('/crm/sfp')->assertStatus(405);
    expect($monitor->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')))->toBeFalse();
    expect(\App\Services\CrmAccess::query(Order::class, $monitor)->count())->toBe(0);
    expect(\App\Services\CrmAccess::manage($monitor, Order::class))->toBeFalse();
    expect(\App\Services\CrmAccess::taskAssignees($first)->whereKey($monitor->id)->exists())->toBeFalse();
});

it('authenticates an active monitor with the existing web guard and logs out', function () {
    $monitor = User::factory()->create(['role' => 'sfp_screen', 'active' => true, 'password' => 'monitor-test-password']);
    $this->get('/crm/sfp/login')->assertOk()->assertSee('Вход на экран производства');
    $this->post('/crm/sfp/login', ['email' => $monitor->email, 'password' => 'monitor-test-password'])->assertRedirect(route('sfp.screen'));
    $this->assertAuthenticatedAs($monitor, 'web');
    $this->get('/crm/sfp')->assertOk();
    $this->post('/crm/sfp/logout')->assertRedirect(route('sfp.login'));
    $this->assertGuest('web');
});

it('rejects non-monitor and inactive credentials at the dedicated login', function () {
    foreach ([['owner', true], ['sfp_screen', false]] as [$role, $active]) {
        $user = User::factory()->create(['role' => $role, 'active' => $active, 'password' => 'monitor-test-password']);
        $this->post('/crm/sfp/login', ['email' => $user->email, 'password' => 'monitor-test-password'])->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }
});

it('blocks repeated login attempts', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/crm/sfp/login', ['email' => 'wrong@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
    }
    $this->post('/crm/sfp/login', ['email' => 'wrong@example.test', 'password' => 'wrong-password'])->assertTooManyRequests();
});

it('rejects a disabled monitor and prevents an old monitor session from acquiring CRM access', function () {
    $monitor = User::factory()->create(['role' => 'sfp_screen', 'active' => false]);
    $this->actingAs($monitor)->get('/crm/sfp?fragment=1')->assertForbidden();
    $monitor->update(['role' => 'owner', 'active' => true]);
    $this->actingAs($monitor->fresh())->withSession(['sfp_monitor_session' => true])->get('/crm')->assertForbidden();
    $this->assertGuest('web');
});
