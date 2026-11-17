<?php

namespace App\Services;

use App\Filament\CrmForms;
use App\Filament\Resources\CashReceipts\CashReceiptResource;
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
use Illuminate\Support\Facades\Gate;

class RecordDetails
{
    public static function get(Model $record): array
    {
        Gate::authorize('view', $record);
        if ($record instanceof Activity) {
            return ['fields' => [], 'items' => [], 'related' => [], 'accruals' => [], 'allocations' => [],
                'history' => ActivityHistory::journal(collect([$record->loadMissing('actor:id,name')]))];
        }
        $owner = auth()->user()->role === 'owner';
        $attributes = collect($record->getAttributes())->except(['password', 'remember_token', 'idempotency_key', 'attachment', 'document', 'ordered_minor', 'confirmed_minor', 'pending_minor'])->all();
        if (! $owner) {
            foreach (['kind', 'payer', 'rate_bps', 'calculation_status'] as $privateField) {
                unset($attributes[$privateField]);
            }
            if ($record instanceof Payout) {
                unset($attributes['notes']);
            }
        }
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
        if ($record instanceof Deal || $record instanceof Order) {
            $rows = $record instanceof Deal ? ($record->items ?? []) : $record->items->toArray();
            $display = fn (int $minor) => $record->currency === 'UZS' ? Money::display($minor) : Money::format($minor).' '.$record->currency;
            foreach ($rows as $row) {
                $snapshot = $row['catalog_snapshot'] ?? [];
                $details['items'][] = [
                    'name' => $row['name'], 'quantity' => $snapshot['area'] ?? $row['quantity'],
                    'unit' => $snapshot['unit'] ?? '', 'supplier' => $snapshot['supplier'] ?? null,
                    'price' => $display($snapshot['rate_minor'] ?? $row['price_minor']),
                    'total' => $display($row['quantity'] * $row['price_minor']),
                    'snapshot' => $snapshot,
                ];
            }
        }
        if ($record instanceof Order) {
            $details['balanceOrder'] = CrmAccess::query(Order::class)->withPaymentTotals()->findOrFail($record->id);
            $details['payments'] = CrmAccess::query(Payment::class)->where('order_id', $record->id)->where('currency', $record->currency)->latest('paid_at')->latest('id')->get();
            $details['pendingCash'] = CashReceiptResource::getEloquentQuery()->withoutEagerLoads()->where('order_id', $record->id)->where('status', 'pending')->latest('collected_at')->get();
        }
        if ($record instanceof Client) {
            if (isset($attributes['contacts'])) {
                $details['fields']['contacts'] = ActivityHistory::formatList('contacts', $attributes['contacts']);
            }
            foreach ([Deal::class => 'Сделка', Order::class => 'Заказ', Task::class => 'Задача'] as $model => $label) {
                foreach (CrmAccess::query($model)->where('client_id', $record->id)->get() as $r) {
                    $details['related'][] = $label.' #'.$r->id.' · '.($r->number ?? $r->title);
                }
            }
        }
        if ($owner && $record instanceof Payment) {
            $details['accruals'] = CrmAccess::query(Accrual::class)->where('payment_id', $record->id)->with('beneficiary')->get();
        }
        if ($owner && $record instanceof Payout) {
            $details['allocations'] = $record->allocations()->with('accrual.beneficiary')->get();
        }
        if (in_array($record::class, [Client::class, Deal::class, Order::class, Task::class]) || auth()->user()->role === 'owner') {
            $details['history'] = ActivityHistory::forRecord($record);
        }

        return $details;
    }
}
