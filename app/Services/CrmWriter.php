<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CrmWriter
{
    public function save(string $model, array $input, ?Model $record = null): Model
    {
        $user = auth()->user();
        abort_unless($user && CrmAccess::manage($user, $model), 403);
        if ($record) {
            Gate::authorize('update', $record);
        }
        $rules = match ($model) {
            Client::class => ['name' => 'required|string|max:255', 'manager_id' => 'nullable|exists:users,id', 'phone' => 'nullable|string|max:100', 'email' => 'nullable|email|max:255', 'source' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:10000', 'contacts' => 'nullable|array', 'contacts.*.name' => 'required|string|max:255', 'contacts.*.phone' => 'nullable|string|max:100', 'contacts.*.email' => 'nullable|email|max:255'],
            Deal::class => ['title' => 'required|string|max:255', 'client_id' => 'required|exists:clients,id', 'manager_id' => 'nullable|exists:users,id', 'status' => ['required', Rule::in(['new', 'qualifying', 'estimate', 'proposal', 'won', 'lost'])], 'currency' => ['required', Rule::in(['UZS'])], 'description' => 'nullable|string|max:10000', 'lost_reason' => 'required_if:status,lost|nullable|string|max:5000'],
            Order::class => ['number' => ['required', 'string', 'max:100', Rule::unique('orders')->ignore($record?->id)], 'client_id' => 'required|exists:clients,id', 'manager_id' => 'nullable|exists:users,id', 'currency' => ['required', Rule::in(['UZS'])], 'status' => ['required', Rule::in(['new', 'design', 'production', 'ready', 'delivered', 'cancelled'])], 'due_at' => 'nullable|date', 'description' => 'nullable|string|max:10000', 'attachment' => 'nullable|string|max:255'],
            Task::class => ['title' => 'required|string|max:255', 'manager_id' => 'required|exists:users,id', 'client_id' => 'nullable|exists:clients,id', 'deal_id' => 'nullable|exists:deals,id', 'order_id' => 'nullable|exists:orders,id', 'due_at' => 'required|date', 'status' => ['required', Rule::in(['open', 'in_progress', 'done', 'cancelled'])], 'notes' => 'nullable|string|max:10000'],
            Expense::class => ['paid_at' => 'required|date', 'category' => 'required|string|max:255', 'amount' => 'required|string', 'currency' => ['required', Rule::in(['UZS'])], 'payer' => ['required', Rule::in(['SFP', 'Promolux'])], 'order_id' => 'nullable|exists:orders,id', 'notes' => 'nullable|string|max:10000'],
            User::class => ['name' => 'required|string|max:255', 'email' => ['required', 'email', Rule::unique('users')->ignore($record?->id)], 'password' => [$record ? 'nullable' : 'required', 'string', 'min:12'], 'role' => ['required', Rule::in(array_keys(User::ROLE_LABELS))], 'active' => 'required|boolean'],
            default => abort(403),
        };
        if (in_array($model, [Order::class, Deal::class])) {
            $rules += ['items' => 'required|array|min:1', 'items.*.name' => 'required|string|max:255', 'items.*.quantity' => 'required|integer|min:1|max:1000000', 'items.*.price' => 'required|string'];
        }
        if ($user->handlesOwnOrders()) {
            $input['manager_id'] = $user->id;
        }
        $data = Validator::make($input + ['currency' => 'UZS'], $rules)->validate();
        if ($model === User::class) {
            abort_if($user->role === 'rop' && ($data['role'] !== 'manager' || ($record && $record->role !== 'manager')), 403);
            if ($record?->id === $user->id && ($data['role'] !== 'owner' || ! $data['active'])) {
                throw ValidationException::withMessages(['data.role' => 'Нельзя отключить или понизить собственную учётную запись владельца.']);
            }
            if (empty($data['password'])) {
                unset($data['password']);
            }
        }
        foreach (['client_id' => Client::class, 'deal_id' => Deal::class, 'order_id' => Order::class] as $key => $related) {
            if (! empty($data[$key])) {
                abort_unless(CrmAccess::query($related)->whereKey($data[$key])->exists(), 403);
            }
        }
        if (! empty($data['manager_id'])) {
            $manager = User::findOrFail($data['manager_id']);
            if (! $manager->active || ($model !== Task::class && ! CrmAccess::salesAssignees($user, $record)->whereKey($manager->id)->exists())) {
                throw ValidationException::withMessages(['data.manager_id' => 'Выберите активного менеджера.']);
            }
        }
        if ($model === Expense::class) {
            $data['amount_minor'] = Money::parse($data['amount']);
            unset($data['amount']);
            if ($data['amount_minor'] <= 0) {
                throw ValidationException::withMessages(['data.amount' => 'Сумма должна быть больше нуля.']);
            }
        }

        return DB::transaction(function () use ($model, $record, $data) {
            $items = $data['items'] ?? null;
            unset($data['items']);
            if ($items !== null) {
                $items = array_map(function ($item) {
                    $price = Money::parse($item['price']);
                    if ($price > 100000000000) {
                        throw ValidationException::withMessages(['data.items' => 'Цена позиции слишком велика.']);
                    }

                    return ['name' => $item['name'], 'quantity' => (int) $item['quantity'], 'price_minor' => $price];
                }, $items);
                if (array_sum(array_map(fn ($i) => $i['quantity'] * $i['price_minor'], $items)) > 99999999999999) {
                    throw ValidationException::withMessages(['data.items' => 'Сумма заказа слишком велика.']);
                }
            }
            if ($model === Order::class && $record) {
                $record = Order::query()->lockForUpdate()->findOrFail($record->id);
                if (($record->payments()->exists() || $record->cashReceipts()->exists()) && ($data['currency'] !== $record->currency || $data['client_id'] != $record->client_id)) {
                    throw ValidationException::withMessages(['data.currency' => 'После оплаты нельзя менять валюту и клиента заказа.']);
                }
            }
            if ($model === Deal::class) {
                if ($record) {
                    $record = Deal::query()->lockForUpdate()->findOrFail($record->id);
                    Gate::authorize('update', $record);
                    DealStages::validateTransition($record, $data['status']);
                }
                $data['items'] = $items;
                if ($data['status'] !== 'lost') {
                    $data['lost_reason'] = null;
                }
            }
            $record ??= new $model;
            $record->fill($data)->save();
            if ($model === Order::class && $items !== null) {
                $record->recordActivity('items_replaced', ['before' => $record->items()->get()->toArray(), 'after' => $items]);
                $record->items()->delete();
                $record->items()->createMany($items);
            }
            if ($model === User::class) {
                Activity::create(['actor_id' => auth()->id(), 'subject_type' => User::class, 'subject_id' => $record->id, 'event' => 'account_saved', 'changes' => collect($data)->except('password')->all()]);
            }

            return $record;
        });
    }

    public function convert(Deal $deal): Order
    {
        Gate::authorize('update', $deal);

        return DB::transaction(function () use ($deal) {
            $deal = Deal::query()->lockForUpdate()->findOrFail($deal->id);
            if ($deal->status !== 'won') {
                throw ValidationException::withMessages(['record' => 'Сначала согласуйте сделку.']);
            }
            if ($deal->order) {
                return $deal->order;
            }
            $order = Order::create(['number' => 'PL-'.strtoupper((string) Str::ulid()), 'client_id' => $deal->client_id, 'deal_id' => $deal->id, 'manager_id' => $deal->manager_id, 'currency' => $deal->currency, 'status' => 'new', 'description' => $deal->description]);
            $order->items()->createMany($deal->items ?? []);
            $deal->recordActivity('converted', ['order_id' => $order->id]);

            return $order;
        });
    }
}
