<?php

use App\Filament\CrmForms;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\CatalogSupplier;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Services\CrmWriter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

it('opens an order from the owner table and shows its details with the stored items', function (string $status, string $layout) {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $this->actingAs($owner);
    $client = Client::create(['name' => 'Тестовый заказчик', 'manager_id' => $owner->id]);
    $sfp = CatalogSupplier::create(['code' => 'sfp', 'name' => 'Цех SFP']);
    $items = [['name' => 'Ручная позиция', 'quantity' => 2, 'price' => '1200']];
    if ($layout === 'mixed') {
        $promolux = CatalogSupplier::create(['code' => 'promolux', 'name' => 'Цех Promolux']);
        $category = CatalogCategory::create(['code' => 'test-card', 'name' => 'Тестовая категория']);
        foreach ([$sfp, $promolux] as $supplier) {
            $product = CatalogProduct::create([
                'code' => 'test-card-'.$supplier->code, 'name' => 'Каталожная позиция '.$supplier->name,
                'category_id' => $category->id, 'type' => 'banner', 'unit' => 'м²', 'supplier_id' => $supplier->id,
                'active' => true, 'area_pricing' => true,
            ]);
            $items[] = [
                'product_id' => $product->id, 'quantity' => 3, 'price' => '10',
                'width' => '1', 'height' => '2', 'supplier_id' => $supplier->id,
            ];
        }
    }
    $order = app(CrmWriter::class)->save(Order::class, [
        'number' => 'TEST-CARD-'.strtoupper($status), 'client_id' => $client->id,
        'manager_id' => $owner->id, 'status' => $status, 'items' => $items,
    ]);

    if ($layout !== 'manual') {
        $order->items()->create([
            'name' => 'Позиция с неполным снимком', 'quantity' => 1, 'price_minor' => 450000,
            'catalog_snapshot' => ['supplier_id' => $sfp->id, 'copies' => 4, 'rate_minor' => 90000],
        ]);
    }

    $table = Livewire::test(ManageOrders::class)->assertCanSeeTableRecords([$order]);
    $rowAction = $table->instance()->getTable()->getRecordAction($order);
    expect($rowAction)->toBe('edit');
    $context = ['table' => true, 'recordKey' => (string) $order->id];
    $table->call('mountAction', $rowAction, [], $context)->assertOk()->assertSet('mountedActions.0.name', 'edit');

    $itemNames = $order->items()->pluck('name')->all();
    Livewire::test(ManageOrders::class)->call('mountAction', 'details', [], $context)
        ->assertOk()->assertSet('mountedActions.0.name', 'details')->assertMountedActionModalSee($itemNames);

    if ($layout === 'mixed') {
        $editorItems = collect($table->get('mountedActions.0.data.items'));
        $catalogItem = $editorItems->firstWhere('name', 'Каталожная позиция Цех SFP');
        expect((int) $catalogItem['quantity'])->toBe(3)->and($catalogItem['price'])->toBe('10.00');
    }
    if ($layout !== 'manual') {
        $editorItems = collect($table->get('mountedActions.0.data.items'));
        $legacyItem = $editorItems->firstWhere('name', 'Позиция с неполным снимком');
        expect((int) $legacyItem['quantity'])->toBe(1)->and($legacyItem['price'])->toBe('4500.00')
            ->and($legacyItem['line_key'] ?? null)->toBeNull();

        $original = $order->items()->get()->toArray();
        $data = CrmForms::editData($order->toArray(), $order);
        expect(fn () => app(CrmWriter::class)->save(Order::class, $data, $order))
            ->toThrow(ValidationException::class, 'Сохранение недоступно');
        expect($order->items()->get()->toArray())->toBe($original);
    }
})->with([
    'ordinary' => ['new', 'manual'],
    'mixed' => ['production', 'mixed'],
    'cancelled' => ['cancelled', 'incomplete'],
    'delivered' => ['delivered', 'incomplete'],
]);
