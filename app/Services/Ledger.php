<?php

namespace App\Services;

use App\Models\Accrual;
use App\Models\CashReceipt;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Ledger
{
    private function owner(): void
    {
        abort_unless(auth()->user()?->active && auth()->user()?->role === 'owner', 403);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['data.amount' => $message]);
    }

    public function payment(array $input): Payment
    {
        $actor = auth()->user();
        abort_unless($actor?->active && in_array($actor->role, ['owner', 'designer'], true), 403);

        return $this->postReceipt($input);
    }

    public function acceptCash(CashReceipt $receipt, string $date): Payment
    {
        $actor = auth()->user();
        abort_unless($actor?->active && $actor->role === 'cashier', 403);

        return DB::transaction(function () use ($receipt, $date, $actor) {
            $cash = CashReceipt::lockForUpdate()->findOrFail($receipt->id);
            if ($cash->status === 'accepted' && $cash->cashier_id === $actor->id) {
                return Payment::findOrFail($cash->payment_id);
            }
            if ($cash->status !== 'pending') {
                $this->fail('Эту запись уже обработали.');
            }
            Validator::make(['handed_at' => $date], ['handed_at' => 'required|date_format:Y-m-d|before_or_equal:today|after_or_equal:'.$cash->collected_at->toDateString()])->validate();
            $payment = $this->postReceipt([
                'idempotency_key' => (string) Str::uuid(), 'order_id' => $cash->order_id, 'paid_at' => $date,
                'amount' => Money::format($cash->amount_minor), 'owner_id' => $cash->owner_id, 'rop_id' => $cash->rop_id,
            ], $cash);
            $cash->update(['status' => 'accepted', 'cashier_id' => $actor->id, 'handed_at' => $date, 'payment_id' => $payment->id]);

            return $payment;
        }, 3);
    }

    private function postReceipt(array $input, ?CashReceipt $cash = null): Payment
    {
        $data = Validator::make($input + ['currency' => 'UZS'], [
            'idempotency_key' => 'required|uuid', 'order_id' => 'required|exists:orders,id',
            'paid_at' => 'required|date|before_or_equal:today', 'amount' => 'required|string',
            'currency' => ['required', Rule::in(['UZS'])],
            'owner_id' => 'nullable|exists:users,id', 'rop_id' => 'nullable|exists:users,id',
            'method' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:5000', 'document' => 'nullable|string|max:255',
        ])->validate();
        $amount = Money::parse($data['amount']);
        if ($amount <= 0) {
            $this->fail('Сумма оплаты должна быть больше нуля.');
        }

        return DB::transaction(function () use ($data, $amount, $cash) {
            $orders = auth()->user()->role === 'designer' ? CrmAccess::query(Order::class) : Order::query();
            $order = $orders->lockForUpdate()->findOrFail($data['order_id']);
            if ($existing = Payment::where('idempotency_key', $data['idempotency_key'])->first()) {
                if ($existing->order_id != $order->id || $existing->amount_minor !== $amount || $existing->currency !== $data['currency'] || $existing->paid_at->toDateString() !== $data['paid_at']) {
                    $this->fail('Этот ключ уже использован для другой оплаты.');
                }

                return $existing;
            }
            if ($order->currency !== $data['currency']) {
                $this->fail('Валюта оплаты должна совпадать с валютой заказа.');
            }
            $owner = null;
            $rop = null;
            $managerId = $cash ? $cash->manager_id : $order->manager_id;
            $hasSalesManager = $cash ? $cash->managed_sale : $order->manager?->role === 'manager';
            if ($hasSalesManager) {
                $owner = $cash ? User::find($cash->owner_id) : User::where('role', 'owner')->where('active', true)->find($data['owner_id'] ?? null);
                $rop = $cash ? User::find($cash->rop_id) : User::where('role', 'rop')->where('active', true)->find($data['rop_id'] ?? null);
                if (! $owner || ! $rop) {
                    $this->fail('Для начисления выберите владельца, РОП и менеджера заказа.');
                }
            }
            $payment = Payment::create([
                'idempotency_key' => $data['idempotency_key'], 'order_id' => $order->id, 'manager_id' => $managerId,
                'paid_at' => $data['paid_at'], 'amount_minor' => $amount, 'currency' => $data['currency'], 'recipient' => 'SFP',
                'method' => $cash ? 'Наличные через кассира' : 'Перечисление', 'notes' => $data['notes'] ?? null, 'document' => $data['document'] ?? null,
                'calculation_status' => $hasSalesManager ? 'calculated' : 'needs_clarification',
            ]);
            if ($owner && $rop) {
                $total = Money::percent($amount, 1000);
                $ownerGross = min($total, Money::percent($amount, 500));
                $ropGross = $total - $ownerGross;
                $managerTotal = min($total, Money::percent($amount, 500));
                $ownerManager = max($managerTotal - $ropGross, min($ownerGross, Money::percent($amount, 250)));
                $ropManager = $managerTotal - $ownerManager;
                foreach ([
                    ['owner_gross', $owner->id, 'SFP', 500, $ownerGross],
                    ['rop_gross', $rop->id, 'SFP', 500, $ropGross],
                    ['manager_owner', $managerId, 'owner:'.$owner->id, 250, $ownerManager],
                    ['manager_rop', $managerId, 'rop:'.$rop->id, 250, $ropManager],
                ] as [$kind,$beneficiary,$payer,$rate,$sum]) {
                    Accrual::create(['payment_id' => $payment->id, 'beneficiary_id' => $beneficiary, 'kind' => $kind, 'payer' => $payer, 'rate_bps' => $rate, 'amount_minor' => $sum, 'currency' => $payment->currency]);
                }
            }

            return $payment;
        }, 3);
    }

    public function payout(array $input): Payout
    {
        $this->owner();
        $data = Validator::make($input + ['currency' => 'UZS'], [
            'idempotency_key' => 'required|uuid', 'payer' => 'required|string|max:100',
            'recipient_id' => 'required|exists:users,id', 'paid_at' => 'required|date|before_or_equal:today',
            'amount' => 'required|string', 'currency' => ['required', Rule::in(['UZS'])],
            'notes' => 'nullable|string|max:5000', 'allocations' => 'required|array|min:1',
            'allocations.*.accrual_id' => 'required|integer|distinct|exists:accruals,id',
            'allocations.*.amount' => 'required|string',
        ])->validate();
        $amount = Money::parse($data['amount']);
        if ($amount <= 0) {
            $this->fail('Сумма выплаты должна быть больше нуля.');
        }

        return DB::transaction(function () use ($data, $amount) {
            User::whereKey(auth()->id())->lockForUpdate()->firstOrFail();
            if ($existing = Payout::where('idempotency_key', $data['idempotency_key'])->first()) {
                $incoming = collect($data['allocations'])->mapWithKeys(fn ($a) => [(int) $a['accrual_id'] => Money::parse($a['amount'])])->sortKeys()->all();
                $saved = $existing->allocations->pluck('amount_minor', 'accrual_id')->sortKeys()->all();
                if ($existing->amount_minor !== $amount || $existing->payer !== $data['payer'] || $existing->recipient_id != (int) $data['recipient_id'] || $existing->currency !== $data['currency'] || $existing->paid_at->toDateString() !== $data['paid_at'] || $incoming !== $saved) {
                    $this->fail('Этот ключ уже использован для другой выплаты.');
                }

                return $existing;
            }
            $rows = [];
            $sum = 0;
            foreach (collect($data['allocations'])->sortBy('accrual_id') as $row) {
                $accrual = Accrual::query()->lockForUpdate()->findOrFail($row['accrual_id']);
                $part = Money::parse($row['amount']);
                if ($data['paid_at'] < $accrual->payment->paid_at->toDateString()) {
                    $this->fail('Дата выплаты должна быть не раньше даты начисления.');
                }
                if ($part <= 0 || $part > $accrual->outstanding_minor || $accrual->currency !== $data['currency'] || $accrual->payer !== $data['payer']) {
                    $this->fail('Проверьте валюту, плательщика и невыплаченный остаток начисления.');
                }
                if ($accrual->payer !== 'SFP' && $accrual->beneficiary_id != (int) $data['recipient_id']) {
                    $this->fail('Выплата менеджеру должна поступать получателю начисления.');
                }
                if (Payment::where('reversal_of', $accrual->payment_id)->exists()) {
                    $this->fail('Оплата сторнирована.');
                }
                $sum += $part;
                $rows[] = ['accrual_id' => $accrual->id, 'amount_minor' => $part];
            }
            if ($sum !== $amount) {
                $this->fail('Распределение должно точно совпадать с суммой выплаты.');
            }
            $payout = Payout::create(collect($data)->except(['amount', 'allocations'])->all() + ['amount_minor' => $amount]);
            foreach ($rows as $row) {
                $payout->allocations()->create($row);
            }

            return $payout;
        }, 3);
    }

    public function reversePayment(Payment $payment, string $reason): Payment
    {
        $this->owner();
        if (mb_strlen(trim($reason)) < 5) {
            $this->fail('Укажите причину корректировки (не менее 5 символов).');
        }

        return DB::transaction(function () use ($payment, $reason) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->reversal_of || Payment::where('reversal_of', $payment->id)->exists()) {
                $this->fail('Операция уже сторнирована.');
            }
            foreach ($payment->accruals as $accrual) {
                if ((int) $accrual->allocations()->sum('amount_minor') !== 0) {
                    $this->fail('Есть выплаты по начислениям. Возврат требует отдельного согласования.');
                }
            }
            $reversal = Payment::create(collect($payment->getAttributes())->except(['id', 'created_at', 'updated_at', 'idempotency_key', 'amount_minor', 'notes', 'reversal_of', 'paid_at'])->all() + [
                'idempotency_key' => (string) Str::uuid(), 'amount_minor' => -$payment->amount_minor,
                'notes' => $reason, 'reversal_of' => $payment->id, 'paid_at' => today()->toDateString(),
            ]);
            foreach ($payment->accruals as $a) {
                $reversal->accruals()->create(collect($a->getAttributes())->except(['id', 'created_at', 'updated_at', 'payment_id', 'amount_minor'])->all() + ['amount_minor' => -$a->amount_minor]);
            }

            if ($cash = CashReceipt::where('payment_id', $payment->id)->first()) {
                $cash->update(['status' => 'reversed', 'reason' => $reason]);
            }

            return $reversal;
        }, 3);
    }

    public function reversePayout(Payout $payout, string $reason): Payout
    {
        $this->owner();
        if (mb_strlen(trim($reason)) < 5) {
            $this->fail('Укажите причину корректировки (не менее 5 символов).');
        }

        return DB::transaction(function () use ($payout, $reason) {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if ($payout->reversal_of || Payout::where('reversal_of', $payout->id)->exists()) {
                $this->fail('Операция уже сторнирована.');
            }
            $reversal = Payout::create(collect($payout->getAttributes())->except(['id', 'created_at', 'updated_at', 'idempotency_key', 'amount_minor', 'notes', 'reversal_of', 'paid_at'])->all() + [
                'idempotency_key' => (string) Str::uuid(), 'amount_minor' => -$payout->amount_minor,
                'notes' => $reason, 'reversal_of' => $payout->id, 'paid_at' => today()->toDateString(),
            ]);
            foreach ($payout->allocations as $a) {
                $reversal->allocations()->create(['accrual_id' => $a->accrual_id, 'amount_minor' => -$a->amount_minor]);
            }

            return $reversal;
        }, 3);
    }
}
