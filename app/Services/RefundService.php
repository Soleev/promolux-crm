<?php

namespace App\Services;

use App\Models\Accrual;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundAllocation;
use App\Models\RefundExecutionIssue;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class RefundService
{
    private const CREATORS = ['owner', 'rop'];

    public function create(array $input): Refund
    {
        $this->authorizeActor(auth()->user(), self::CREATORS);
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, [
            'order_id' => 'required|integer|min:1',
            'idempotency_key' => 'required|uuid',
            'amount' => 'required|string',
            'currency' => 'sometimes|required|string|size:3',
            'reason' => 'required|string|max:2000',
            'method' => ['required', Rule::in(Refund::METHODS)],
            'allocations' => 'required|array|min:1',
            'allocations.*' => 'required|array:payment_id,amount',
            'allocations.*.payment_id' => 'required|integer|min:1|distinct',
            'allocations.*.amount' => 'required|string',
        ])->validate();
        $key = strtolower($data['idempotency_key']);
        $amount = Money::parse($data['amount']);
        $parts = $this->parseAllocations($data['allocations'], $amount);

        return $this->transaction(function () use ($data, $key, $amount, $parts): Refund {
            $order = CrmAccess::query(Order::class)->lockForUpdate()->findOrFail($data['order_id']);
            $actor = $this->lockedActor(self::CREATORS);
            $currency = $data['currency'] ?? $order->currency;
            if ($currency !== $order->currency) {
                $this->fail('currency', 'Валюта возврата должна совпадать с валютой заказа.');
            }

            if ($existing = Refund::query()->where('idempotency_key', $key)->first()) {
                abort_unless(
                    $existing->order_id === $order->id && $existing->created_by === $actor->id
                        && $existing->currency === $currency && $existing->amount_minor === $amount
                        && $existing->reason === $data['reason'] && $existing->method === $data['method']
                        && $this->allocations($existing) === $parts,
                    409, 'Ключ уже использован для другой заявки.',
                );

                return $existing;
            }

            $this->requireUndeliveredOrder($order);
            $this->checkCapacity($order, $parts);
            $refund = Refund::create([
                'number' => 'RF-'.strtoupper((string) Str::ulid()),
                'idempotency_key' => $key,
                'order_id' => $order->id,
                'client_id' => $order->client_id,
                'amount_minor' => $amount,
                'currency' => $currency,
                'reason' => $data['reason'],
                'method' => $data['method'],
                'created_by' => $actor->id,
            ]);
            foreach ($parts as $paymentId => $part) {
                $refund->allocations()->create(['payment_id' => $paymentId, 'amount_minor' => $part]);
            }

            return $refund;
        });
    }

    public function approve(Refund $refund, string $source, string $idempotencyKey): Refund
    {
        Validator::make(['source' => $source], ['source' => ['required', Rule::in(Refund::SOURCES)]])->validate();
        $key = $this->operationKey($idempotencyKey);

        return $this->change($refund, ['owner'], function (Refund $locked, Order $order, User $actor) use ($source, $key): Refund {
            if ($this->isReplay($locked->approval_key, $key, $locked->approved_by === $actor->id && $locked->source === $source)) {
                return $locked;
            }
            $this->requireStatus($locked, [Refund::CREATED]);
            $this->requireUndeliveredOrder($order);
            $this->checkCapacity($order, $this->allocations($locked));

            return $this->save($locked, [
                'status' => Refund::APPROVED,
                'source' => $source,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'approval_key' => $key,
            ]);
        });
    }

    public function reject(Refund $refund, string $reason, string $idempotencyKey): Refund
    {
        return $this->close($refund, Refund::REJECTED, $reason, $idempotencyKey, [Refund::CREATED]);
    }

    public function cancel(Refund $refund, string $reason, string $idempotencyKey): Refund
    {
        return $this->close($refund, Refund::CANCELLED, $reason, $idempotencyKey, [Refund::CREATED, Refund::APPROVED]);
    }

    public function execute(Refund $refund, string $executedAt, string $idempotencyKey, ?UploadedFile $document = null): Refund
    {
        $this->authorizeActor(auth()->user(), ['owner', 'cashier']);
        Validator::make(['executed_at' => $executedAt, 'document' => $document], [
            'executed_at' => 'required|date_format:Y-m-d|before_or_equal:today',
            'document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ])->validate();
        $key = $this->operationKey($idempotencyKey);
        $documentPath = null;
        $realPath = $document?->getRealPath();
        $documentHash = is_string($realPath) ? hash_file('sha256', $realPath) : null;
        if ($document !== null && ! is_string($documentHash)) {
            $this->fail('document', 'Документ подтверждения недоступен.');
        }

        try {
            $completed = $this->change($refund, ['owner', 'cashier'], function (Refund $locked, Order $order, User $actor) use ($executedAt, $key, $document, $documentHash, &$documentPath): Refund {
                abort_unless($actor->role === ($locked->method === 'cash' ? 'cashier' : 'owner'), 403);
                if ($this->isReplay($locked->execution_key, $key,
                    $locked->executed_by === $actor->id && $locked->executed_at?->toDateString() === $executedAt && $locked->document_hash === $documentHash)) {
                    return $locked;
                }
                $this->requireStatus($locked, [Refund::APPROVED]);
                $this->requireUndeliveredOrder($order);
                if ($executedAt < $locked->approved_at->toDateString()) {
                    $this->fail('executed_at', 'Дата выдачи не может предшествовать дате согласования.');
                }
                $this->checkCapacity($order, $this->allocations($locked), $locked->id, $executedAt);
                if ($document !== null && $documentPath === null) {
                    $documentPath = $this->storeDocument($locked, $document);
                }

                $this->save($locked, [
                    'status' => Refund::COMPLETED,
                    'executed_by' => $actor->id,
                    'executed_at' => $executedAt,
                    'execution_key' => $key,
                    'document' => $documentPath,
                    'document_hash' => $documentHash,
                ]);
                app(RefundCommissionReversals::class)->record($locked);

                return $locked;
            }, lockCommissionAccounts: true);
        } catch (Throwable $exception) {
            $this->discardDocument($documentPath);

            throw $exception;
        }
        if ($documentPath !== $completed->document) {
            $this->discardDocument($documentPath);
        }

        return $completed;
    }

    public function flagExecutionIssue(Refund $refund, string $reason, string $idempotencyKey): RefundExecutionIssue
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => 'required|string|max:2000'])->validate();
        $key = $this->operationKey($idempotencyKey);

        return $this->change($refund, ['owner'], function (Refund $locked, Order $order, User $actor) use ($reason, $key): RefundExecutionIssue {
            if ($existing = RefundExecutionIssue::query()->where('idempotency_key', $key)->first()) {
                abort_unless($existing->refund_id === $locked->id && $existing->actor_id === $actor->id && $existing->reason === $reason, 409);

                return $existing;
            }
            $this->requireStatus($locked, [Refund::COMPLETED]);

            return $locked->executionIssues()->create([
                'actor_id' => $actor->id,
                'reason' => $reason,
                'idempotency_key' => $key,
            ]);
        });
    }

    public function summary(Order $order): array
    {
        $this->authorizeActor(auth()->user(), self::CREATORS);

        return DB::transaction(function () use ($order): array {
            $locked = CrmAccess::query(Order::class)->lockForUpdate()->findOrFail($order->getKey());
            $this->lockedActor(self::CREATORS);
            $capacity = $this->capacity($locked);

            return $capacity + ['retained_minor' => $capacity['confirmed_minor'] - $capacity['completed_minor']];
        });
    }

    public function preview(Refund $refund): array
    {
        return $this->change($refund, ['owner'], function (Refund $locked, Order $order): array {
            $this->requireStatus($locked, [Refund::CREATED]);
            $this->requireUndeliveredOrder($order);
            $parts = $this->allocations($locked);
            $capacity = $this->capacity($order, $parts);
            $this->assertCapacity($parts, $capacity);

            return $capacity + [
                'retained_minor' => $capacity['confirmed_minor'] - $capacity['completed_minor'] - $locked->amount_minor,
                'commission_reversals' => app(RefundCommissionReversals::class)->preview($locked),
            ];
        });
    }

    private function close(Refund $refund, string $status, string $reason, string $idempotencyKey, array $from): Refund
    {
        $reason = trim($reason);
        $reason = $reason === '' ? null : $reason;
        Validator::make(['reason' => $reason], ['reason' => 'nullable|string|max:2000'])->validate();
        $key = $this->operationKey($idempotencyKey);

        return $this->change($refund, ['owner'], function (Refund $locked, Order $order, User $actor) use ($status, $reason, $key, $from): Refund {
            if ($this->isReplay($locked->closure_key, $key,
                $locked->closed_by === $actor->id && $locked->status === $status && $locked->close_reason === $reason)) {
                return $locked;
            }
            $this->requireStatus($locked, $from);

            return $this->save($locked, [
                'status' => $status,
                'closed_by' => $actor->id,
                'closed_at' => now(),
                'close_reason' => $reason,
                'closure_key' => $key,
            ]);
        });
    }

    private function change(Refund $refund, array $roles, Closure $operation, bool $lockCommissionAccounts = false): mixed
    {
        $this->authorizeActor(auth()->user(), $roles);

        return $this->transaction(function () use ($refund, $roles, $operation, $lockCommissionAccounts): mixed {
            $order = Order::query()->lockForUpdate()->findOrFail($refund->getRawOriginal('order_id'));
            $locked = Refund::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($refund->getKey());
            $beneficiaries = $lockCommissionAccounts
                ? Accrual::query()->whereIn('payment_id', $locked->allocations()->select('payment_id'))->distinct()->pluck('beneficiary_id')->all()
                : [];
            $actor = $this->lockedActor($roles, $beneficiaries);

            return $operation($locked, $order, $actor);
        });
    }

    private function lockedActor(array $roles, array $beneficiaries = []): User
    {
        $actorId = auth()->id();
        $actor = User::query()->whereKey([...$beneficiaries, $actorId])->orderBy('id')->lockForUpdate()->get()->firstWhere('id', $actorId);
        $this->authorizeActor($actor, $roles);

        return $actor;
    }

    private function authorizeActor(?User $actor, array $roles): void
    {
        abort_unless($actor?->active && in_array($actor->role, $roles, true), 403);
    }

    private function transaction(Closure $operation): mixed
    {
        try {
            return DB::transaction($operation, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Ключ уже использован для другой операции.');
        }
    }

    private function operationKey(string $key): string
    {
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => 'required|uuid'])->validate();

        return strtolower($key);
    }

    private function isReplay(?string $storedKey, string $key, bool $matches): bool
    {
        if ($storedKey !== $key) {
            return false;
        }
        abort_unless($matches, 409, 'Ключ уже использован для другой операции.');

        return true;
    }

    private function requireStatus(Refund $refund, array $from): void
    {
        if (! in_array($refund->status, $from, true)) {
            $this->fail('status', 'Операция недоступна в текущем статусе заявки.');
        }
    }

    private function requireUndeliveredOrder(Order $order): void
    {
        if ($order->status === 'delivered') {
            $this->fail('order_id', 'Для выданного заказа требуется отдельное оформление возврата продукции.');
        }
    }

    private function save(Refund $refund, array $attributes): Refund
    {
        $refund->forceFill($attributes)->save();

        return $refund;
    }

    private function parseAllocations(array $rows, int $amount): array
    {
        if ($amount <= 0) {
            $this->fail('amount', 'Сумма возврата должна быть больше нуля.');
        }

        $parts = [];
        $remaining = $amount;
        foreach ($rows as $row) {
            $part = Money::parse($row['amount']);
            if ($part <= 0 || $part > $remaining) {
                $this->fail('allocations', 'Распределение должно состоять из положительных сумм и совпадать с суммой возврата.');
            }
            $parts[(int) $row['payment_id']] = $part;
            $remaining -= $part;
        }
        if ($remaining !== 0) {
            $this->fail('allocations', 'Распределение должно совпадать с суммой возврата.');
        }
        ksort($parts, SORT_NUMERIC);

        return $parts;
    }

    private function allocations(Refund $refund): array
    {
        return $refund->allocations()->orderBy('payment_id')->pluck('amount_minor', 'payment_id')->all();
    }

    private function checkCapacity(Order $order, array $parts, ?int $excludeRefund = null, ?string $executedAt = null): void
    {
        $this->assertCapacity($parts, $this->capacity($order, $parts, $excludeRefund, $executedAt));
    }

    private function assertCapacity(array $parts, array $capacity): void
    {
        if ($parts === [] || count($capacity['payments']) !== count($parts)) {
            $this->fail('allocations', 'Выберите действующие оплаты этого заказа в той же валюте.');
        }
        if (array_sum($parts) > $capacity['available_minor']) {
            $this->fail('amount', 'Сумма возврата превышает доступную подтверждённую оплату.');
        }
        foreach ($capacity['payments'] as $payment) {
            if ($payment['requested_minor'] > $payment['available_minor']) {
                $this->fail('allocations', 'Сумма возврата превышает доступный остаток отдельной оплаты.');
            }
        }
    }

    private function capacity(Order $order, ?array $parts = null, ?int $excludeRefund = null, ?string $executedAt = null): array
    {
        $payments = $order->payments()->where('currency', $order->currency)
            ->when($parts !== null, fn ($query) => $query->whereIn('id', array_keys($parts)))
            ->where('amount_minor', '>', 0)->whereNull('reversal_of')
            ->whereNotIn('id', Payment::query()->whereNotNull('reversal_of')->select('reversal_of'))
            ->when($executedAt, fn ($query) => $query->whereDate('paid_at', '<=', $executedAt))
            ->orderBy('id')->lockForUpdate()->get(['id', 'amount_minor', 'paid_at', 'method']);

        $committed = Refund::query()->where('order_id', $order->id)->where('currency', $order->currency)
            ->when($excludeRefund, fn ($query) => $query->whereKeyNot($excludeRefund))
            ->whereIn('status', [Refund::APPROVED, Refund::COMPLETED]);
        $completed = (int) (clone $committed)->where('status', Refund::COMPLETED)->sum('amount_minor');
        $approved = (int) (clone $committed)->where('status', Refund::APPROVED)->sum('amount_minor');
        $confirmed = (int) $order->payments()->where('currency', $order->currency)->sum('amount_minor');
        $perPayment = RefundAllocation::query()->whereIn('payment_id', $payments->modelKeys())
            ->whereIn('refund_id', $committed->select('id'))
            ->groupBy('payment_id')->selectRaw('payment_id, SUM(amount_minor) AS total_minor')
            ->pluck('total_minor', 'payment_id');

        return [
            'confirmed_minor' => $confirmed,
            'approved_minor' => $approved,
            'completed_minor' => $completed,
            'available_minor' => max(0, $confirmed - $approved - $completed),
            'payments' => $payments->mapWithKeys(function (Payment $payment) use ($parts, $perPayment): array {
                $reserved = (int) ($perPayment[$payment->id] ?? 0);

                return [$payment->id => [
                    'received_minor' => $payment->amount_minor,
                    'committed_minor' => $reserved,
                    'available_minor' => max(0, $payment->amount_minor - $reserved),
                    'requested_minor' => $parts[$payment->id] ?? 0,
                    'paid_at' => $payment->paid_at->toDateString(),
                    'method' => $payment->method,
                ]];
            })->all(),
        ];
    }

    private function storeDocument(Refund $refund, UploadedFile $document): string
    {
        $path = Storage::disk('local')->putFile('refund-documents/'.$refund->id, $document);
        if ($path === false) {
            $this->fail('document', 'Документ подтверждения недоступен.');
        }

        return $path;
    }

    private function discardDocument(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
