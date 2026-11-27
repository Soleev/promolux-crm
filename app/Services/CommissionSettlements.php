<?php

namespace App\Services;

use App\Models\CommissionSettlement;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommissionSettlements
{
    public function __construct(private readonly CommissionBalances $balances) {}

    public function record(array $input): CommissionSettlement
    {
        abort_unless(auth()->user()?->active && auth()->user()->role === 'owner', 403);
        $data = Validator::make($input + ['currency' => 'UZS'], [
            'idempotency_key' => 'required|uuid',
            'beneficiary_id' => 'required|integer|exists:users,id',
            'payer' => ['required', 'string', 'regex:/^(SFP|(owner|rop):[1-9][0-9]*)$/D'],
            'currency' => ['required', Rule::in(['UZS'])],
            'amount' => 'required|string',
            'kind' => ['required', Rule::in([CommissionSettlement::REPAYMENT, CommissionSettlement::WRITE_OFF])],
            'method' => ['exclude_unless:kind,repayment', 'required', Rule::in(Refund::METHODS)],
            'reason' => 'required|string|max:2000',
            'settled_on' => 'required|date_format:Y-m-d|before_or_equal:today',
        ])->validate();
        $amount = Money::parse($data['amount']);
        $reason = trim($data['reason']);
        if ($amount <= 0 || $reason === '') {
            throw ValidationException::withMessages(['record' => 'Укажите положительную сумму и причину расчёта.']);
        }
        if ($data['kind'] === CommissionSettlement::WRITE_OFF && $data['payer'] === 'SFP') {
            throw ValidationException::withMessages(['payer' => 'Списание доступно только для внутренней задолженности сотрудника.']);
        }

        $values = Arr::except($data, ['amount']);
        $values['idempotency_key'] = strtolower($values['idempotency_key']);
        $values['beneficiary_id'] = (int) $values['beneficiary_id'];
        $values['amount_minor'] = $amount;
        $values['reason'] = $reason;
        $values['method'] ??= null;

        try {
            return DB::transaction(function () use ($values, $amount): CommissionSettlement {
                $actorId = auth()->id();
                $users = User::query()->whereKey([$values['beneficiary_id'], $actorId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $actor = $users->get($actorId);
                abort_unless($actor?->active && $actor->role === 'owner', 403);
                $values['recorded_by'] = $actor->id;
                if ($existing = CommissionSettlement::where('idempotency_key', $values['idempotency_key'])->first()) {
                    $saved = $existing->only(array_keys($values));
                    $saved['beneficiary_id'] = (int) $saved['beneficiary_id'];
                    $saved['recorded_by'] = (int) $saved['recorded_by'];
                    $saved['settled_on'] = $existing->settled_on->toDateString();
                    ksort($saved);
                    ksort($values);
                    abort_unless($saved === $values, 409);

                    return $existing;
                }

                $before = $this->balances->balance($values['beneficiary_id'], $values['payer'], $values['currency'], $values['settled_on']);
                $current = $this->balances->balance($values['beneficiary_id'], $values['payer'], $values['currency'], today()->toDateString());
                if ($amount > min(-$before, -$current)) {
                    throw ValidationException::withMessages(['amount' => 'Сумма превышает встречную задолженность по исходному плательщику.']);
                }

                return CommissionSettlement::create($values);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Ключ уже использован для другой операции.');
        }
    }
}
