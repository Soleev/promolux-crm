<?php

use App\Filament\Pages\MyDay;
use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Task;
use App\Models\User;
use App\Services\CrmAccess;
use App\Services\CrmWriter;
use App\Services\RecordDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (['manager', 'designer', 'owner', 'rop', 'cashier'] as $role) {
        $this->{$role} = User::factory()->create(['role' => $role, 'active' => true]);
    }
    $this->other = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($this->manager);
    $this->data = ['title' => 'Согласовать цветопробу', 'status' => 'open',
        'due_at' => today()->setTime(18, 0)->toDateTimeString(), 'manager_id' => $this->designer->id];
    $this->writer = app(CrmWriter::class);
});

it('tracks a designer assignment through both dashboards, task forms, filter and audit', function () {
    $client = Client::create(['name' => 'Private customer', 'manager_id' => $this->manager->id]);
    $deal = Deal::create(['title' => 'Private sale', 'manager_id' => $this->manager->id, 'client_id' => $client->id, 'status' => 'new']);
    $order = Order::create(['number' => 'PRIVATE-TASK-ORDER', 'manager_id' => $this->manager->id, 'client_id' => $client->id]);
    $links = ['client_id' => $client->id, 'deal_id' => $deal->id, 'order_id' => $order->id];
    $form = Livewire::test(ManageTasks::class)->mountAction('create')->setActionData($this->data + $links);
    $form->callMountedAction()->assertHasNoActionErrors();
    $task = Task::sole();
    expect($task->creator->is($this->manager))->toBeTrue()->and($task->manager->is($this->designer))->toBeTrue();
    $personal = $this->writer->save(Task::class, array_replace($this->data, ['title' => 'Личная задача', 'manager_id' => $this->manager->id]));
    Livewire::test(MyDay::class)->assertViewHas('today', fn ($rows) => $rows->modelKeys() === [$personal->id])
        ->assertViewHas('delegated', fn ($rows) => $rows->modelKeys() === [$task->id]);
    Livewire::test(ManageTasks::class)->filterTable('delegated')->assertCanSeeTableRecords([$task])->assertCanNotSeeTableRecords([$personal]);
    Livewire::test(ManageTasks::class)->mountTableAction('edit', $task)->setTableActionData(['notes' => 'Проверить контраст'])
        ->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($task->fresh()->manager_id)->toEqual($this->designer->id);

    $this->actingAs($this->designer);
    foreach ([$client, $deal, $order] as $related) {
        expect(Gate::allows('view', $related))->toBeFalse();
    }
    $detail = RecordDetails::get($task->fresh())['fields'];
    expect($detail['creator_name'])->toBe($this->manager->name)->and($detail['assignee_name'])->toBe($this->designer->name)
        ->and(array_intersect_key($detail, $links))->toBe([]);
    Livewire::test(ManageTasks::class)->mountTableAction('edit', $task)
        ->setTableActionData(['status' => 'in_progress'])->callMountedTableAction()->assertHasNoTableActionErrors();
    Livewire::test(MyDay::class)->assertSee($task->title)->assertDontSee('Private customer')->assertDontSee('Private sale')
        ->call('complete', $task->id)->assertOk();
    $task->refresh();
    expect($task->status)->toBe('done')->and($task->created_by)->toEqual($this->manager->id)
        ->and($task->only(array_keys($links)))->toEqual($links);
    expect(Activity::where('subject_type', Task::class)->where('subject_id', $task->id)
        ->where('actor_id', $this->designer->id)->where('event', 'updated')->latest('id')->firstOrFail()->changes['after']['status'])->toBe('done');
    $this->actingAs($this->manager);
    Livewire::test(MyDay::class)->assertViewHas('delegated', fn ($rows) => $rows->first()->status === 'done');
    $this->actingAs($this->other);
    expect(CrmAccess::query(Task::class)->whereKey($task->id)->exists())->toBeFalse();
    expect(fn () => $this->writer->save(Task::class, ['status' => 'open'], $task))->toThrow(AuthorizationException::class);
});

it('validates recipients for each role and never accepts a submitted creator', function () {
    $inactive = User::factory()->create(['role' => 'designer', 'active' => false]);
    foreach ([$this->other, $this->owner, $this->rop, $this->cashier, $inactive] as $user) {
        expect(fn () => $this->writer->save(Task::class, array_replace($this->data, ['manager_id' => $user->id])))
            ->toThrow(ValidationException::class);
    }
    expect(CrmAccess::taskAssignees($this->manager)->pluck('id')->sort()->values()->all())->toEqual([$this->manager->id, $this->designer->id]);
    $task = $this->writer->save(Task::class, $this->data + ['created_by' => $this->other->id]);
    $this->actingAs($this->designer);
    expect(fn () => $this->writer->save(Task::class, array_replace($this->data, ['manager_id' => $this->manager->id])))->toThrow(ValidationException::class);
    $this->writer->save(Task::class, ['status' => 'in_progress', 'created_by' => $this->designer->id, 'title' => 'Other'], $task);
    expect($task->fresh()->title)->toBe($this->data['title'])->and($task->fresh()->created_by)->toEqual($this->manager->id);
    foreach ([$this->owner, $this->rop] as $actor) {
        $this->actingAs($actor);
        $assigned = $this->writer->save(Task::class, array_replace($this->data, ['manager_id' => $this->cashier->id]));
        expect($assigned->created_by)->toEqual($actor->id);
    }
    $this->actingAs($this->cashier);
    $this->get('/crm/tasks')->assertForbidden();
});

it('preserves the unknown creator of pre-migration tasks on edit and reassignment', function () {
    $id = DB::table('tasks')->insertGetId(array_replace($this->data, ['manager_id' => $this->manager->id]));
    $task = Task::findOrFail($id);
    $this->writer->save(Task::class, ['status' => 'done'], $task);
    expect($task->fresh()->created_by)->toBeNull()->and(RecordDetails::get($task)['fields']['creator_name'])->toBe('Не указан');
    $this->actingAs($this->owner);
    $this->writer->save(Task::class, $this->data, $task);
    $this->actingAs($this->manager);
    expect(Gate::allows('view', $task))->toBeFalse();
});

it('keeps future assigned work visible and revokes creator access after reassignment to another manager', function () {
    $task = $this->writer->save(Task::class, array_replace($this->data, ['due_at' => today()->addDays(2)->toDateTimeString()]));
    $this->actingAs($this->designer);
    Livewire::test(MyDay::class)->assertViewHas('upcoming', fn ($rows) => $rows->modelKeys() === [$task->id])
        ->assertViewHas('today', fn ($rows) => $rows->isEmpty());
    $this->actingAs($this->owner);
    $this->writer->save(Task::class, array_replace($this->data, ['manager_id' => $this->other->id]), $task);
    foreach ([$this->designer, $this->manager] as $user) {
        expect(CrmAccess::view($user, $task))->toBeFalse();
    }
    expect($task->fresh()->created_by)->toEqual($this->manager->id);
});
