<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\Refund;
use App\Models\Task;
use App\Models\User;
use Closure;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrderCancellationService
{
    private const MANAGEMENT = ['owner', 'rop'];

    private const CANCELLABLE = ['new', 'design', 'production', 'ready'];

    public const RESTORATION_BLOCKING_REFUND_STATUSES = [Refund::CREATED, Refund::APPROVED, Refund::COMPLETED];

    public function canCancel(User $actor, Order $order): bool
    {
        return $actor->active && in_array($order->status, self::CANCELLABLE, true)
            && (in_array($actor->role, self::MANAGEMENT, true)
                || ($actor->role === 'manager' && (int) $order->manager_id === (int) $actor->id
                    && $this->confirmedPayment($order) <= 0));
    }

    public function canRestore(User $actor, Order $order): bool
    {
        return $actor->active && in_array($actor->role, self::MANAGEMENT, true)
            && $order->status === 'cancelled' && $order->currentCancellation !== null
            && ! $this->hasBlockingRefund($order);
    }

    public function cancel(Order $order, string $reason, string $idempotencyKey, ?array $expected = null): OrderCancellation
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason, 'idempotency_key' => $idempotencyKey], [
            'reason' => 'required|string|max:2000',
            'idempotency_key' => 'required|uuid',
        ])->validate();
        $idempotencyKey = strtolower($idempotencyKey);

        return $this->transaction(function () use ($order, $reason, $idempotencyKey, $expected): OrderCancellation {
            [$order, $actor] = $this->lockOrderAndActor($order, [...self::MANAGEMENT, 'manager']);
            if ($existing = OrderCancellation::where('idempotency_key', $idempotencyKey)->first()) {
                abort_unless($existing->order_id === $order->id && $existing->cancelled_by === $actor->id && $existing->reason === $reason, 409);

                return $existing;
            }
            if (! in_array($order->status, self::CANCELLABLE, true)) {
                throw ValidationException::withMessages(['record' => 'Выданный или уже отменённый заказ нельзя отменить.']);
            }
            $confirmedPayment = $order->payments()->where('currency', $order->currency)
                ->lockForUpdate()->pluck('amount_minor')->sum();
            abort_if($actor->role === 'manager' && $confirmedPayment > 0, 403);
            if ($order->cashReceipts()->where('status', 'pending')->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['record' => 'Урегулируйте наличные у сотрудника перед отменой заказа.']);
            }
            if ($expected !== null && (($expected['status'] ?? null) !== $order->status
                || filter_var($expected['confirmed_minor'] ?? null, FILTER_VALIDATE_INT) !== $confirmedPayment
                || filter_var($expected['total_minor'] ?? null, FILTER_VALIDATE_INT) !== $order->total_minor
                || (array_key_exists('returned_minor', $expected)
                    && filter_var($expected['returned_minor'], FILTER_VALIDATE_INT) !== $order->refunds()
                        ->where('currency', $order->currency)->where('status', Refund::COMPLETED)
                        ->lockForUpdate()->pluck('amount_minor')->sum()))) {
                throw ValidationException::withMessages(['record' => 'Данные заказа изменились. Откройте подтверждение отмены заново.']);
            }

            $cancellation = OrderCancellation::create([
                'order_id' => $order->id,
                'previous_status' => $order->status,
                'reason' => $reason,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);
            $order->update(['status' => 'cancelled']);
            $order->recordActivity('order_cancelled', ['reason' => $reason, 'cancellation_id' => $cancellation->id]);
            $this->cancelTasksAndNotify($order);

            return $cancellation;
        });
    }

    public function restore(Order $order, string $idempotencyKey, ?int $expectedCancellationId = null): OrderCancellation
    {
        Validator::make(['idempotency_key' => $idempotencyKey], ['idempotency_key' => 'required|uuid'])->validate();
        $idempotencyKey = strtolower($idempotencyKey);

        return $this->transaction(function () use ($order, $idempotencyKey, $expectedCancellationId): OrderCancellation {
            [$order, $actor] = $this->lockOrderAndActor($order, self::MANAGEMENT);
            if ($existing = OrderCancellation::where('restore_idempotency_key', $idempotencyKey)->first()) {
                abort_unless($existing->order_id === $order->id && $existing->restored_by === $actor->id, 409);

                return $existing;
            }
            $cancellation = $order->status === 'cancelled' ? $order->currentCancellation : null;
            if (! $cancellation || ! in_array($cancellation->previous_status, self::CANCELLABLE, true)) {
                throw ValidationException::withMessages(['record' => 'Предыдущий статус заказа для восстановления недоступен.']);
            }
            if ($expectedCancellationId !== null && $cancellation->id !== $expectedCancellationId) {
                throw ValidationException::withMessages(['record' => 'Заказ был повторно отменён. Откройте подтверждение восстановления заново.']);
            }

            // Refund transitions use the same order lock.
            if ($order->refunds()->whereIn('status', self::RESTORATION_BLOCKING_REFUND_STATUSES)->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['record' => 'Нельзя восстановить заказ с выполненным или неурегулированным возвратом.']);
            }
            $cancellation->forceFill([
                'restored_by' => $actor->id,
                'restored_at' => now(),
                'restore_idempotency_key' => $idempotencyKey,
            ])->save();
            $order->update(['status' => $cancellation->previous_status]);
            $order->recordActivity('order_restored', ['status' => $order->status, 'cancellation_id' => $cancellation->id]);

            return $cancellation;
        });
    }

    private function confirmedPayment(Order $order): int
    {
        if (array_key_exists('confirmed_minor', $order->getAttributes())) {
            return (int) $order->confirmed_minor;
        }

        return (int) $order->payments()->where('currency', $order->currency)->sum('amount_minor');
    }

    private function hasBlockingRefund(Order $order): bool
    {
        return array_key_exists('has_blocking_refunds', $order->getAttributes())
            ? (bool) $order->has_blocking_refunds
            : $order->refunds()->whereIn('status', self::RESTORATION_BLOCKING_REFUND_STATUSES)->exists();
    }

    private function lockOrderAndActor(Order $order, array $roles): array
    {
        $sessionActor = auth()->user();
        abort_unless($sessionActor?->active && in_array($sessionActor->role, $roles, true), 403);
        $order = CrmAccess::query(Order::class, $sessionActor)->lockForUpdate()->findOrFail($order->id);
        $actor = User::query()->lockForUpdate()->find($sessionActor->id);
        abort_unless($actor?->active && in_array($actor->role, $roles, true), 403);
        if ($actor->role !== $sessionActor->role) {
            $order = CrmAccess::query(Order::class, $actor)->lockForUpdate()->findOrFail($order->id);
        }

        return [$order, $actor];
    }

    private function transaction(Closure $operation): OrderCancellation
    {
        try {
            return DB::transaction($operation, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Ключ уже использован для другой операции.');
        }
    }

    private function cancelTasksAndNotify(Order $order): void
    {
        $tasks = Task::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get(['id', 'manager_id', 'status']);
        foreach ($tasks as $task) {
            if (in_array($task->status, ['open', 'in_progress'], true)) {
                $task->update(['status' => 'cancelled']);
            }
        }

        $recipientIds = $tasks->pluck('manager_id')->push($order->manager_id)->filter()->unique();
        $recipients = User::whereKey($recipientIds->all())->get(['id']);
        Notification::sendNow($recipients, FilamentNotification::make()
            ->title('Заказ № '.$order->id.' отменён')->warning()->toDatabase());
    }
}
