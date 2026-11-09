<?php

namespace App\Services;

use App\Models\Deal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DealStages
{
    public static function targets(Deal $deal): array
    {
        if ($deal->order_exists ?? $deal->order()->exists()) {
            return [];
        }

        return match ($deal->status) {
            'new', 'qualifying', 'estimate', 'proposal' => array_values(array_diff(['new', 'qualifying', 'estimate', 'proposal', 'won', 'lost'], [$deal->status])),
            'won', 'lost' => ['qualifying'],
            default => [],
        };
    }

    public static function validateTransition(Deal $deal, string $target): void
    {
        if ($target !== $deal->status && ! in_array($target, self::targets($deal), true)) {
            throw ValidationException::withMessages(['status' => 'Переход недоступен. Закрытую сделку можно вернуть в «Уточнение», если заказ ещё не создан.']);
        }
    }

    public function move(int $id, string $target, string $expected, ?string $reason = null): Deal
    {
        abort_if(request()->attributes->get('preview_applied'), 403);

        return DB::transaction(function () use ($id, $target, $expected, $reason): Deal {
            $deal = Deal::query()->lockForUpdate()->findOrFail($id);
            Gate::authorize('update', $deal);
            if ($deal->status !== $expected) {
                throw ValidationException::withMessages(['status' => 'Этап уже изменён другим сотрудником. Обновите список и повторите действие.']);
            }
            $data = Validator::make(['status' => $target, 'lost_reason' => $reason === null ? null : trim($reason)], [
                'status' => ['required', Rule::in(['new', 'qualifying', 'estimate', 'proposal', 'won', 'lost'])],
                'lost_reason' => 'required_if:status,lost|nullable|string|max:5000',
            ], ['lost_reason.required_if' => 'Укажите причину проигрыша.'])->validate();
            self::validateTransition($deal, $target);
            $deal->fill(['status' => $target, 'lost_reason' => $target === 'lost' ? $data['lost_reason'] : null])->save();

            return $deal;
        });
    }
}
