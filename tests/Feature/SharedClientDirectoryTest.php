<?php

use App\Filament\Resources\Clients\Pages\ManageClients;
use App\Filament\Resources\Deals\Pages\ManageDeals;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Task;
use App\Models\User;
use App\Services\ClientDirectory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->a = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->b = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->client = Client::create(['manager_id' => $this->a->id, 'name' => 'Print Example', 'phone' => '+998 (90) 123-45-67', 'email' => 'Print@example.test', 'notes' => 'PRIVATE-NOTE', 'source' => 'PRIVATE-SOURCE', 'contacts' => [['name' => 'Рабочий контакт', 'phone' => '123', 'private' => 'PRIVATE-JSON']]]);
    $this->actingAs($this->b);
});

it('shows only common contacts and independently scoped commercial records in the modal', function () {
    $shared = ['client_id' => $this->client->id];
    $order = Order::create($shared + ['manager_id' => $this->a->id, 'number' => 'PRIVATE-ORDER', 'status' => 'production']);
    $deal = Deal::create($shared + ['manager_id' => $this->a->id, 'title' => 'PRIVATE-DEAL', 'status' => 'estimate']);
    $mine = Deal::create($shared + ['manager_id' => $this->b->id, 'title' => 'Допустимая сделка', 'status' => 'new']);
    Task::create($shared + ['manager_id' => $this->a->id, 'title' => 'PRIVATE-TASK', 'due_at' => now(), 'status' => 'open']);
    $this->client->recordActivity('communication', ['message' => 'PRIVATE-CALL']);
    $page = Livewire::test(ManageClients::class)->searchTable('Print@example.test')->assertCanSeeTableRecords([$this->client])
        ->assertTableActionHidden('edit', $this->client)->assertTableActionHidden('communication', $this->client)
        ->mountTableAction('details', $this->client);
    $card = $page->instance()->getMountedAction()->getModalContent()->render();
    expect($card)->toContain('Рабочий контакт', 'Допустимая сделка', 'С этим клиентом уже работает другой менеджер.');
    foreach (['PRIVATE-NOTE', 'PRIVATE-SOURCE', 'PRIVATE-JSON', 'PRIVATE-ORDER', 'PRIVATE-DEAL', 'PRIVATE-TASK', 'PRIVATE-CALL'] as $secret) {
        $page->assertDontSee($secret);
        expect($card)->not->toContain($secret);
    }
    $this->get('/crm/deals?tableAction=details&tableActionRecord='.$deal->id)->assertDontSee($deal->title);
    $this->get('/crm/orders?tableAction=details&tableActionRecord='.$order->id)->assertDontSee($order->number);
    $this->get('/crm-documents/order/'.$order->id)->assertNotFound();
    $this->get('/crm-register.csv')->assertForbidden();
    $mine->update(['manager_id' => $this->a->id]);
    $page->call('$refresh');
    expect($page->instance()->getMountedAction()->getModalContent()->render())->not->toContain('Допустимая сделка');
});

it('identifies duplicates by full normalized numbers, email or similar name within role scope', function () {
    foreach ([['', '90 1234567', ''], ['', '00998901234567', ''], ['', '', 'PRINT@example.test'], ['print', '', '']] as $args) {
        expect(ClientDirectory::matches(...$args))->toHaveKey($this->client->id);
    }
    expect(ClientDirectory::matches('', '+1 290 1234567', ''))->toBeEmpty();
    foreach (['owner', 'rop', 'designer', 'cashier'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role, 'active' => true]));
        expect(ClientDirectory::visible()->whereKey($this->client->id)->exists())->toBe(in_array($role, ['owner', 'rop']));
        expect((bool) ClientDirectory::matches('Print', '', ''))->toBe(in_array($role, ['owner', 'rop']));
    }
});

it('keeps the directory paginated without per-client authorization queries', function () {
    $measure = function () {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page = Livewire::test(ManageClients::class);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$page, $count];
    };
    $this->actingAs($this->a);
    [$small,$queries] = $measure();
    for ($i = 0; $i < 35; $i++) {
        Client::create(['name' => 'Synthetic '.$i, 'manager_id' => $this->a->id]);
    }
    [$large,$larger] = $measure();
    expect($larger)->toBeLessThanOrEqual($queries + 2);
    $large->assertCanNotSeeTableRecords([$this->client])->searchTable('Print')->assertCanSeeTableRecords([$this->client]);
});

it('finds an existing shared client in the actual create forms while keeping task choices scoped', function () {
    foreach ([ManageDeals::class, ManageOrders::class] as $page) {
        $component = Livewire::test($page)->mountAction('create')->instance();
        $schema = $component->getSchema($component->getMountedActionSchemaName());
        $field = $schema->getFlatFields()['client_id'];
        expect($field->getSearchResults('Print@example.test'))->toHaveKey($this->client->id);
    }
    $component = Livewire::test(ManageTasks::class)->mountAction('create')->instance();
    $field = $component->getSchema($component->getMountedActionSchemaName())->getFlatFields()['client_id'];
    expect($field->getOptions())->not->toHaveKey($this->client->id);
});
