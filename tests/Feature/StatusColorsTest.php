<?php

use App\Filament\CrmTableUi;
use App\Filament\Resources\Deals\Pages\ManageDeals;
use App\Models\CatalogSupplier;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Order;
use App\Models\User;
use App\Services\RecordDetails;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

it('uses the approved semantic color for existing statuses', function (string $status, string $color) {
    expect(CrmTableUi::color($status))->toBe($color);
})->with([
    ['new', 'gray'], ['open', 'gray'], ['created', 'gray'],
    ['qualifying', 'info'], ['design', 'info'], ['production', 'info'], ['in_progress', 'info'],
    ['estimate', 'warning'], ['pending', 'warning'], ['approved', 'warning'], ['needs_clarification', 'warning'],
    ['proposal', 'primary'], ['won', 'success'], ['ready', 'success'], ['delivered', 'success'],
    ['done', 'success'], ['accepted', 'success'], ['completed', 'success'], ['calculated', 'success'],
    ['lost', 'danger'], ['cancelled', 'danger'], ['reversed', 'danger'], ['rejected', 'danger'], ['overdue', 'danger'],
]);

it('keeps deal tab table and modal colors consistent', function (string $status, string $color) {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $this->actingAs($owner);
    $client = Client::create(['name' => 'Клиент']);
    $deal = Deal::create(['title' => 'Сделка', 'client_id' => $client->id, 'manager_id' => $owner->id,
        'status' => $status, 'currency' => 'UZS', 'items' => []]);
    $page = Livewire::test(ManageDeals::class)->set('activeTab', $status)->assertCanSeeTableRecords([$deal]);
    expect($page->instance()->getTabs()[$status]->getBadgeColor())->toBe($color);
    $column = $page->instance()->getTable()->getColumn('status')->record($deal);
    expect($column->getColor($status))->toBe($color);
    $html = view('filament.record', ['record' => $deal, 'details' => RecordDetails::get($deal)])->render();
    preg_match('/Статус<\/dt><dd[^>]*>(.*?)<\/dd>/s', $html, $statusMarkup);
    expect($statusMarkup[1])->toContain('fi-badge');
    if ($color === 'gray') {
        expect($statusMarkup[1])->not->toContain('fi-color-');
    } else {
        expect($statusMarkup[1])->toContain('fi-color-'.$color);
    }
})->with([['new', 'gray'], ['qualifying', 'info'], ['estimate', 'warning'], ['proposal', 'primary'], ['won', 'success'], ['lost', 'danger']]);

it('uses the same production color on SFP cards detail and refreshed fragments', function () {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $supplier = CatalogSupplier::create(['code' => 'sfp', 'name' => 'SFP']);
    $client = Client::create(['name' => 'Клиент', 'manager_id' => $owner->id]);
    $order = Order::create(['number' => 'COLOR-1', 'client_id' => $client->id, 'manager_id' => $owner->id, 'status' => 'production', 'currency' => 'UZS']);
    $order->items()->create(['name' => 'Печать', 'quantity' => 1, 'price_minor' => 100,
        'catalog_snapshot' => ['supplier_id' => $supplier->id]]);
    $this->actingAs($owner);
    foreach (['/crm/sfp', '/crm/sfp?fragment=1', '/crm/sfp?order='.$order->id] as $url) {
        $this->get($url)->assertOk()->assertSee('data-status-color="info"', false);
    }
});
