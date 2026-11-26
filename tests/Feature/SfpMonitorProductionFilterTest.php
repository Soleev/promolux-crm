<?php

use App\Models\CatalogSupplier;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

it('filters orders and details in a dedicated SFP monitor session', function () {
    $password = Str::random(32);
    $monitor = User::factory()->create(['role' => 'sfp_screen', 'active' => true, 'password' => $password]);
    $firstManager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $secondManager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $sfpSupplier = CatalogSupplier::create(['code' => 'sfp', 'name' => 'Цех № 2']);
    $promoluxSupplier = CatalogSupplier::create(['code' => 'promolux', 'name' => 'Цех № 1']);

    $makeOrder = function (User $manager, string $number, array $suppliers): Order {
        $client = Client::create(['name' => 'Тестовый клиент', 'manager_id' => $manager->id]);
        $order = Order::create([
            'number' => $number, 'client_id' => $client->id, 'manager_id' => $manager->id,
            'currency' => 'UZS', 'status' => 'production',
        ]);
        foreach ($suppliers as $name => $supplier) {
            $order->items()->create([
                'name' => $name, 'quantity' => 1, 'price_minor' => 987654321,
                'catalog_snapshot' => [
                    'supplier_id' => $supplier->id, 'supplier' => $supplier->name,
                    'characteristics' => ['internal' => 'MONITOR-PRIVATE-FIXTURE'],
                ],
            ]);
        }

        return $order;
    };

    $sfp = $makeOrder($firstManager, 'TEST-SFP-001', ['Тестовый плакат SFP' => $sfpSupplier]);
    $mixed = $makeOrder($secondManager, 'TEST-MIXED-002', [
        'Тестовый баннер SFP' => $sfpSupplier,
        'Тестовые наклейки Promolux' => $promoluxSupplier,
    ]);
    $promolux = $makeOrder($secondManager, 'TEST-PROMOLUX-003', ['Тестовая вывеска Promolux' => $promoluxSupplier]);

    $detailUrl = route('sfp.screen', ['order' => $mixed->id]);
    $this->get($detailUrl)->assertRedirect(route('filament.admin.auth.login'));
    $this->get(route('sfp.login'))->assertOk()->assertViewIs('sfp.login');
    $this->post(route('sfp.login.submit'), ['email' => $monitor->email, 'password' => $password])
        ->assertRedirect(route('sfp.screen'))->assertSessionHas('sfp_monitor_session', true);
    $this->assertAuthenticatedAs($monitor, 'web');

    $this->get(route('sfp.screen'))->assertOk()->assertViewHas('total', 2)->assertViewHas('working', 2)
        ->assertSee([$sfp->number, $mixed->number, 'Тестовый плакат SFP', 'Тестовый баннер SFP'])
        ->assertDontSee([$promolux->number, 'Тестовые наклейки Promolux', 'Тестовая вывеска Promolux']);
    $this->get(route('sfp.screen', ['view' => 'queue', 'fragment' => 1]))
        ->assertOk()->assertViewIs('sfp.content')
        ->assertViewHas('queue', fn ($queue) => $queue->total() === 2 && $queue->pluck('id')->all() === [$sfp->id, $mixed->id])
        ->assertDontSee(['Тестовые наклейки Promolux', 'Тестовая вывеска Promolux']);

    $this->get($detailUrl)->assertOk()->assertSee('Тестовый баннер SFP')
        ->assertDontSee(['Тестовые наклейки Promolux', 'MONITOR-PRIVATE-FIXTURE', '987654321']);
    $this->get(route('sfp.screen', ['order' => $mixed->id, 'fragment' => 1]))
        ->assertOk()->assertViewIs('sfp.content')
        ->assertViewHas('detail', fn (Order $order) => $order->items->pluck('name')->all() === ['Тестовый баннер SFP'])
        ->assertDontSee(['Тестовые наклейки Promolux', 'MONITOR-PRIVATE-FIXTURE', '987654321']);
    $this->get(route('sfp.screen', ['order' => $promolux->id]))->assertNotFound();
    $this->get(route('sfp.screen', ['order' => $promolux->id, 'fragment' => 1]))->assertNotFound();

    $this->get(route('filament.admin.home'))->assertForbidden();
    $this->get(route('crm.export'))->assertForbidden();
    $this->get(route('crm.document', ['type' => 'order', 'id' => $sfp->id]))->assertForbidden();
    $this->post(route('sfp.logout'))->assertRedirect(route('sfp.login'))->assertSessionMissing('sfp_monitor_session');
    $this->assertGuest('web');
    $this->get($detailUrl)->assertRedirect(route('filament.admin.auth.login'));
});
