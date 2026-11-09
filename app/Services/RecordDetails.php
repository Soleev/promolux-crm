<?php

namespace App\Services;

use App\Filament\CrmForms;
use App\Models\Accrual;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class RecordDetails
{
    public static function get(Model $record): array
    {
        $attributes = collect($record->getAttributes())->except(['password', 'remember_token', 'idempotency_key', 'attachment', 'document'])->all();
        foreach (['amount_minor', 'price_minor'] as $key) {
            if (isset($attributes[$key])) {
                $attributes[$key] = Money::display((int) $attributes[$key]);
            }
        }
        foreach (['created_at', 'updated_at', 'paid_at', 'due_at', 'email_verified_at'] as $key) {
            if (! empty($attributes[$key])) {
                $withTime = in_array($key, ['created_at', 'updated_at', 'email_verified_at']) || ($key === 'due_at' && $record instanceof Task);
                $attributes[$key] = CarbonImmutable::parse($attributes[$key])->timezone('Asia/Tashkent')->format($withTime ? 'd.m.Y H:i' : 'd.m.Y');
            }
        }
        if (isset($attributes['status'])) {
            $labels = match ($record::class) {
                Deal::class => CrmForms::dealStatuses(),
                Order::class => CrmForms::orderStatuses(),
                Task::class => CrmForms::taskStatuses(),
                default => [],
            };
            $attributes['status'] = $labels[$attributes['status']] ?? $attributes['status'];
        }
        if (isset($attributes['calculation_status'])) {
            $attributes['calculation_status'] = ['calculated' => 'Начислено', 'needs_clarification' => 'Требует уточнения'][$attributes['calculation_status']] ?? $attributes['calculation_status'];
        }
        if (isset($attributes['kind'])) {
            $attributes['kind'] = CrmForms::kindLabels()[$attributes['kind']] ?? $attributes['kind'];
        }
        if ($record instanceof User) {
            $attributes['role'] = User::ROLE_LABELS[$record->role] ?? $record->role;
            $attributes['active'] = $record->active ? 'Разрешён' : 'Отключён';
        }
        $details = ['fields' => $attributes, 'items' => [], 'related' => [], 'history' => [], 'accruals' => [], 'allocations' => []];
        if ($record instanceof Order) {
            $details['items'] = $record->items->map(fn ($i) => ['name' => $i->name, 'quantity' => $i->quantity, 'price' => Money::display($i->price_minor), 'total' => Money::display($i->price_minor * $i->quantity)])->all();
            $details['fields']['total'] = Money::display($record->total_minor);
            $details['fields']['payment_status'] = $record->payment_status;
            $details['related'] = CrmAccess::query(Payment::class)->where('order_id', $record->id)->get()->map(fn ($p) => 'Оплата #'.$p->id.' · '.$p->paid_at->format('d.m.Y').' · '.Money::display($p->amount_minor))->all();
        }
        if ($record instanceof Client) {
            foreach ([Deal::class => 'Сделка', Order::class => 'Заказ', Task::class => 'Задача'] as $model => $label) {
                foreach (CrmAccess::query($model)->where('client_id', $record->id)->get() as $r) {
                    $details['related'][] = $label.' #'.$r->id.' · '.($r->number ?? $r->title);
                }
            }
        }
        if ($record instanceof Payment) {
            $details['accruals'] = CrmAccess::query(Accrual::class)->where('payment_id', $record->id)->with('beneficiary')->get();
        }
        if ($record instanceof Payout) {
            $details['allocations'] = $record->allocations()->with('accrual.beneficiary')->get();
        }
        if (in_array($record::class, [Client::class, Deal::class, Order::class, Task::class]) || auth()->user()->role === 'owner') {
            $details['history'] = Activity::where('subject_type', $record::class)->where('subject_id', $record->id)->with('actor')->latest('id')->limit(50)->get();
        }

        return $details;
    }
}
