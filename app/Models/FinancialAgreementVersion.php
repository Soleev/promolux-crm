<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scope_key', 'version', 'seller_id', 'owner_id', 'rop_id', 'owner_sfp_bps', 'rop_sfp_bps', 'owner_funding_bps', 'rop_funding_bps', 'effective_from', 'published_by'])]
class FinancialAgreementVersion extends Model
{
    use Audited, Immutable;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'owner_sfp_bps' => 'integer',
            'rop_sfp_bps' => 'integer',
            'owner_funding_bps' => 'integer',
            'rop_funding_bps' => 'integer',
            'effective_from' => 'immutable_datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rop(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
