<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'seller_id', 'seller_role', 'owner_id', 'rop_id', 'owner_sfp_bps', 'rop_sfp_bps', 'owner_funding_bps', 'rop_funding_bps', 'agreement_version_id', 'policy_version', 'confirmed_by', 'confirmed_at', 'confirmation_reason'])]
class OrderFinancialTerms extends Model
{
    use Audited, Immutable;

    protected $table = 'order_financial_terms';

    protected function casts(): array
    {
        return [
            'owner_sfp_bps' => 'integer',
            'rop_sfp_bps' => 'integer',
            'owner_funding_bps' => 'integer',
            'rop_funding_bps' => 'integer',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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

    public function agreementVersion(): BelongsTo
    {
        return $this->belongsTo(FinancialAgreementVersion::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
