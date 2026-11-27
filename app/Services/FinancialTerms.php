<?php

namespace App\Services;

use App\Models\FinancialAgreementVersion;
use App\Models\Order;
use App\Models\OrderFinancialTerms;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinancialTerms
{
    private const COMMISSION_ROLES = ['manager', 'designer'];

    private const SELLER_ROLES = ['owner', 'rop', 'manager', 'designer'];

    private const STANDARD_FUNDING_BPS = 250;

    private const SFP_SHARE_BPS = 500;

    public function capture(Order $order): void
    {
        if (! $order->wasRecentlyCreated || $order->initial_seller_id !== null || $order->manager_id === null) {
            return;
        }

        $seller = User::findOrFail($order->manager_id);
        $order->forceFill(['initial_seller_id' => $seller->id, 'initial_seller_role' => $seller->role])->saveQuietly();

        if (! $seller->active || ! in_array($seller->role, self::SELLER_ROLES, true)) {
            return;
        }

        $version = in_array($seller->role, self::COMMISSION_ROLES, true)
            ? $this->versionAt($order, $seller->id)
            : null;
        $version ??= $this->versionAt($order, null);
        $owner = $this->currentParticipant('owner', $version?->owner_id);
        $rop = $this->currentParticipant('rop', $version?->rop_id);
        if ($owner === null || $rop === null) {
            return;
        }

        $funding = match (true) {
            $version?->seller_id !== null => [$version->owner_funding_bps, $version->rop_funding_bps],
            $seller->role === 'manager' => [self::STANDARD_FUNDING_BPS, self::STANDARD_FUNDING_BPS],
            default => [0, 0],
        };

        $order->setRelation('financialTerms', $order->financialTerms()->create([
            'seller_id' => $seller->id,
            'seller_role' => $seller->role,
            'owner_id' => $owner->id,
            'rop_id' => $rop->id,
            'owner_sfp_bps' => $version?->owner_sfp_bps ?? self::SFP_SHARE_BPS,
            'rop_sfp_bps' => $version?->rop_sfp_bps ?? self::SFP_SHARE_BPS,
            'owner_funding_bps' => $funding[0],
            'rop_funding_bps' => $funding[1],
            'agreement_version_id' => $version?->id,
            'policy_version' => $version ? 'agreement-v1' : 'standard-v1',
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
        ]));
    }

    public function confirm(Order $order, array $input): OrderFinancialTerms
    {
        $actor = $this->authorizedOwner();

        return DB::transaction(function () use ($order, $input, $actor): OrderFinancialTerms {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->financialTerms()->exists()) {
                throw ValidationException::withMessages(['seller_id' => 'Финансовые условия заказа уже зафиксированы.']);
            }

            $input['seller_id'] ??= $order->initial_seller_id;
            $input['seller_role'] ??= $order->initial_seller_role;
            if (is_string($input['reason'] ?? null)) {
                $input['reason'] = trim($input['reason']);
            }

            $data = Validator::make($input, $this->fundingRules() + [
                'seller_id' => 'required|integer|exists:users,id',
                'seller_role' => ['required', Rule::in(self::SELLER_ROLES)],
                'reason' => 'required|string|min:5|max:5000',
            ])->validate();

            if ($order->initial_seller_id !== null
                && ((int) $data['seller_id'] !== (int) $order->initial_seller_id || $data['seller_role'] !== $order->initial_seller_role)) {
                throw ValidationException::withMessages(['seller_id' => 'Первоначальный продавец и его роль уже зафиксированы.']);
            }

            if (! in_array($data['seller_role'], self::COMMISSION_ROLES, true)
                && ((int) $data['owner_funding_bps'] !== 0 || (int) $data['rop_funding_bps'] !== 0)) {
                throw ValidationException::withMessages(['owner_funding_bps' => 'Для этой роли внутренняя комиссия не начисляется.']);
            }

            return $order->financialTerms()->create([
                'seller_id' => $data['seller_id'],
                'seller_role' => $data['seller_role'],
                'owner_id' => $data['owner_id'],
                'rop_id' => $data['rop_id'],
                'owner_sfp_bps' => self::SFP_SHARE_BPS,
                'rop_sfp_bps' => self::SFP_SHARE_BPS,
                'owner_funding_bps' => $data['owner_funding_bps'],
                'rop_funding_bps' => $data['rop_funding_bps'],
                'policy_version' => 'manual-v1',
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'confirmation_reason' => $data['reason'],
            ]);
        }, 3);
    }

    public function publish(?User $seller, array $input): FinancialAgreementVersion
    {
        $actor = $this->authorizedOwner();
        if ($seller === null) {
            $input += ['owner_funding_bps' => self::STANDARD_FUNDING_BPS, 'rop_funding_bps' => self::STANDARD_FUNDING_BPS];
        }

        $data = Validator::make($input, $this->fundingRules() + [
            'effective_from' => 'nullable|date',
        ])->validate();

        if ($seller === null
            && ((int) $data['owner_funding_bps'] !== self::STANDARD_FUNDING_BPS || (int) $data['rop_funding_bps'] !== self::STANDARD_FUNDING_BPS)) {
            throw ValidationException::withMessages(['owner_funding_bps' => 'Для общих условий действует стандартная ставка по 2,5% из каждой доли.']);
        }

        return DB::transaction(function () use ($seller, $data, $actor): FinancialAgreementVersion {
            if ($seller !== null) {
                $current = User::query()->find($seller->id);
                if (! $current?->active || ! in_array($current->role, self::COMMISSION_ROLES, true)) {
                    throw ValidationException::withMessages(['seller_id' => 'Индивидуальные условия доступны активному менеджеру или дизайнеру.']);
                }
            }

            if (! $this->currentParticipant('owner', (int) $data['owner_id'])) {
                throw ValidationException::withMessages(['owner_id' => 'Выберите активного владельца.']);
            }
            if (! $this->currentParticipant('rop', (int) $data['rop_id'])) {
                throw ValidationException::withMessages(['rop_id' => 'Выберите активного РОП.']);
            }

            $scope = $this->scope($seller?->id);
            $previous = FinancialAgreementVersion::query()->where('scope_key', $scope)->orderByDesc('version')->lockForUpdate()->first();

            return FinancialAgreementVersion::create([
                'scope_key' => $scope,
                'version' => ($previous?->version ?? 0) + 1,
                'seller_id' => $seller?->id,
                'owner_id' => $data['owner_id'],
                'rop_id' => $data['rop_id'],
                'owner_sfp_bps' => self::SFP_SHARE_BPS,
                'rop_sfp_bps' => self::SFP_SHARE_BPS,
                'owner_funding_bps' => $data['owner_funding_bps'],
                'rop_funding_bps' => $data['rop_funding_bps'],
                'effective_from' => $data['effective_from'] ?? now(),
                'published_by' => $actor->id,
            ]);
        }, 3);
    }

    public function visibleVersions(User $actor): Builder
    {
        $versions = FinancialAgreementVersion::query();
        if (! $actor->active) {
            return $versions->whereRaw('1 = 0');
        }

        return $actor->role === 'owner' ? $versions : $versions->where('seller_id', $actor->id);
    }

    private function fundingRules(): array
    {
        $rate = 'required|integer|between:0,'.self::SFP_SHARE_BPS;

        return [
            'owner_id' => 'required|integer|exists:users,id',
            'rop_id' => 'required|integer|different:owner_id|exists:users,id',
            'owner_funding_bps' => $rate,
            'rop_funding_bps' => $rate,
        ];
    }

    private function currentParticipant(string $role, ?int $id): ?User
    {
        $users = User::query()->where('role', $role)->where('active', true);
        if ($id !== null) {
            return $users->find($id);
        }

        $active = $users->limit(2)->get();

        return $active->count() === 1 ? $active->first() : null;
    }

    private function versionAt(Order $order, ?int $sellerId): ?FinancialAgreementVersion
    {
        return FinancialAgreementVersion::query()
            ->where('scope_key', $this->scope($sellerId))
            ->where('effective_from', '<=', $order->created_at)
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();
    }

    private function scope(?int $sellerId): string
    {
        return $sellerId === null ? 'global' : 'seller:'.$sellerId;
    }

    private function authorizedOwner(): User
    {
        $actor = auth()->user();
        abort_unless($actor?->active && $actor->role === 'owner', 403);

        return $actor;
    }
}
