<?php

namespace App\Filament\Resources\CashReceipts;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;

class CashOverview
{
    public static function acceptedToday(Builder $query): Builder
    {
        return $query->where('status', 'accepted')->whereIn('payment_id', Payment::query()->where('created_at', '>=', today('Asia/Tashkent'))->where('created_at', '<', today('Asia/Tashkent')->addDay())->select('id'));
    }
}
