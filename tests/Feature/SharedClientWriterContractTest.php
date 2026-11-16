<?php

use App\Models\Client;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Task;
use App\Models\User;
use App\Services\CrmWriter;
use App\Services\Ledger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->managerA = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->managerB = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->writer = app(CrmWriter::class);
    $this->actingAs($this->managerA);
    $this->client = $this->writer->save(Client::class, ['name' => 'Synthetic client', 'notes' => 'Private A']);
    $this->input = ['title' => 'Synthetic deal', 'number' => 'TEST-01', 'manager_id' => $this->managerA->id,
        'client_id' => $this->client->id, 'status' => 'new', 'currency' => 'UZS', 'items' => [['name' => 'Sample', 'quantity' => 1, 'price' => '100.00']]];
    $this->actingAs($this->managerB);
});

it('lets B create their own commercial record with the shared client and retain that client on edit', function (string $model) {
    $record = $this->writer->save($model, $this->input);
    expect($record->manager_id)->toBe($this->managerB->id);
    $this->writer->save($model, $this->input + ['description' => 'Updated'], $record);
    expect($record->fresh()->client_id)->toBe($this->client->id)
        ->and($this->client->fresh()->manager_id)->toBe($this->managerA->id);
})->with([Deal::class, Order::class]);

it('keeps foreign clients read only and existing commercial ownership intact', function () {
    expect(Gate::allows('update', $this->client))->toBeFalse();
    $this->actingAs($this->managerA);
    $foreign = $this->writer->save(Order::class, $this->input);
    $this->actingAs($this->managerB);
    expect(Gate::allows('view', $foreign))->toBeFalse()
        ->and(Gate::allows('update', $foreign))->toBeFalse();
});

it('preserves task assignment limits and rejects changing an existing order to an unrelated client of A', function () {
    $own = $this->writer->save(Client::class, ['name' => 'Client B']);
    $order = $this->writer->save(Order::class, array_replace($this->input, ['client_id' => $own->id]));
    foreach ([
        fn () => $this->writer->save(Order::class, $this->input, $order),
        fn () => $this->writer->save(Task::class, ['title' => 'Task', 'client_id' => $this->client->id, 'status' => 'open', 'due_at' => now()->toDateTimeString()]),
    ] as $operation) {
        try {
            $operation();
            $this->fail('Expected a permission check');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(403);
        }
    }
    expect($order->fresh()->client_id)->toBe($own->id);
});

it('checks the original sale before accepting an otherwise valid client and assignee', function (string $model) {
    $this->actingAs($this->managerA);
    $foreign = $this->writer->save($model, $this->input);
    $this->actingAs($this->managerB);
    $own = $this->writer->save(Client::class, ['name' => 'Own client B']);
    $input = array_replace($this->input, ['client_id' => $own->id, 'manager_id' => $this->managerB->id]);
    expect(fn () => $this->writer->save($model, $input, $foreign))->toThrow(AuthorizationException::class);
    expect($foreign->fresh()->client_id)->toBe($this->client->id)
        ->and($foreign->fresh()->manager_id)->toBe($this->managerA->id);
})->with([Deal::class, Order::class]);

it('retains the client on a paid order even for an otherwise eligible replacement', function () {
    $sale = $this->writer->save(Order::class, $this->input);
    $own = $this->writer->save(Client::class, ['name' => 'Own client B']);
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $rop = User::factory()->create(['role' => 'rop', 'active' => true]);
    $this->actingAs($owner);
    app(Ledger::class)->payment(['order_id' => $sale->id, 'owner_id' => $owner->id, 'rop_id' => $rop->id, 'amount' => '1', 'paid_at' => today()->toDateString(), 'idempotency_key' => (string) Str::uuid()]);
    $this->actingAs($this->managerB);
    expect(fn () => $this->writer->save(Order::class, array_replace($this->input, ['client_id' => $own->id]), $sale))->toThrow(ValidationException::class);
    expect($sale->fresh()->client_id)->toBe($this->client->id);
});
