<?php

namespace App\Services;

use App\Models\CashReceipt;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CashDesk
{
    public function collect(array $input): CashReceipt
    {
        $actor = auth()->user();
        abort_unless($actor?->active && in_array($actor->role, ['owner', 'rop', 'manager', 'designer'], true), 403);
        $data = Validator::make($input, [
            'order_id' => 'required|integer', 'idempotency_key' => 'required|uuid', 'amount' => 'required|string',
            'collector_id' => 'nullable|integer', 'collected_at' => 'required|date_format:Y-m-d|before_or_equal:today',
        ])->validate();
        $amount = Money::parse($data['amount']);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Введите сумму больше нуля.']);
        }
        $collector = $data['collector_id'] ?? $actor->id;
        abort_if($actor->role !== 'owner' && $collector != $actor->id, 403);

        return DB::transaction(function () use ($actor, $data, $amount, $collector) {
            $order = CrmAccess::query(Order::class, $actor)->lockForUpdate()->findOrFail($data['order_id']);
            if ($existing = CashReceipt::where('idempotency_key', $data['idempotency_key'])->first()) {
                abort_unless($existing->order_id == $order->id && $existing->collector_id == $collector && $existing->amount_minor === $amount && $existing->collected_at->toDateString() === $data['collected_at'], 409);

                return $existing;
            }
            abort_unless(User::whereKey($collector)->where('active', true)->whereIn('role', ['owner', 'rop', 'manager', 'designer'])->exists(), 422);
            if ($order->status === 'cancelled') {
    throw ValidationException::withMessages([
        'amount' => 'Нельзя принимать оплату по отменённому заказу.',
    ]);
}
            $terms = $order->financialTerms;

            return CashReceipt::create([
                'order_id' => $order->id,
                'order_financial_terms_id' => $terms?->id,
                'manager_id' => $terms?->seller_id ?? $order->initial_seller_id ?? $order->manager_id,
                'managed_sale' => $terms?->seller_role === 'manager',
                'owner_id' => $terms?->owner_id,
                'rop_id' => $terms?->rop_id,
                'collector_id' => $collector,
                'amount_minor' => $amount,
                'collected_at' => $data['collected_at'],
                'idempotency_key' => $data['idempotency_key'],
                'status' => 'pending',
            ]);
        }, 3);
    }

    public function cancel(CashReceipt $receipt, string $reason): void
    {
        abort_unless(auth()->user()?->active && auth()->user()->role === 'owner', 403);
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:5|max:2000'])->validate();
        DB::transaction(function () use ($receipt, $reason) {
            $locked = CashReceipt::lockForUpdate()->findOrFail($receipt->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['reason' => 'Отменить можно только запись до передачи.']);
            }
            $locked->update(['status' => 'cancelled', 'reason' => $reason]);
        });
    }
}
