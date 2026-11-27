<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TaskWriter
{
    public function save(array $input, ?Task $task): Task
    {
        $actor = auth()->user();
        abort_unless($actor && CrmAccess::manage($actor, Task::class), 403);

        return DB::transaction(function () use ($actor, $input, $task) {
            $status = ['status' => 'required|in:open,in_progress,done,cancelled'];
            if ($task) {
                $task = Task::lockForUpdate()->findOrFail($task->id);
                Gate::authorize('update', $task);
                if ($task->statusOnlyFor($actor) || array_keys($input) === ['status']) {
                    abort_if(isset($input['manager_id']) && (string) $input['manager_id'] !== (string) $task->manager_id, 403);
                    $data = Validator::make($input, $status)->validate();
                    $this->assertOrderAllowsTask($task->order_id, $data['status']);
                    $task->update($data);

                    return $task;
                }
            }

            $input += ['manager_id' => $task?->manager_id ?? $actor->id];
            $data = Validator::make($input, $status + [
                'title' => 'required|string|max:255', 'manager_id' => 'required|integer',
                'due_at' => 'required|date', 'notes' => 'nullable|string|max:10000',
                'client_id' => 'nullable|integer', 'deal_id' => 'nullable|integer', 'order_id' => 'nullable|integer',
            ])->validate();
            if (! CrmAccess::taskAssignees($actor)->whereKey($data['manager_id'])->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['data.manager_id' => 'Выберите доступного активного исполнителя.']);
            }
            foreach (['client_id' => Client::class, 'deal_id' => Deal::class, 'order_id' => Order::class] as $key => $model) {
                if (isset($data[$key])) {
                    abort_unless(CrmAccess::query($model, $actor)->whereKey($data[$key])->exists(), 403);
                } elseif ($task?->$key && ! CrmAccess::query($model, $actor)->whereKey($task->$key)->exists()) {
                    // An inaccessible relation is absent from the form. Preserve it on edit.
                    unset($data[$key]);
                }
            }
            $orderId = array_key_exists('order_id', $data) ? $data['order_id'] : $task?->order_id;
            $this->assertOrderAllowsTask($orderId, $data['status']);
            $task ??= new Task;
            $task->fill($data)->save();

            return $task;
        }, 3);
    }

    private function assertOrderAllowsTask(?int $orderId, string $status): void
    {
        if ($orderId !== null && in_array($status, ['open', 'in_progress'], true)
            && Order::whereKey($orderId)->lockForUpdate()->value('status') === 'cancelled') {
            throw ValidationException::withMessages(['data.order_id' => 'К отменённому заказу нельзя привязать активную задачу.']);
        }
    }
}
