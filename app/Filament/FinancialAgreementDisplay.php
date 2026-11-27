<?php

namespace App\Filament;

use App\Models\FinancialAgreementVersion;
use App\Models\User;

/** Presentation of current conditions for new orders; never used to post finances. */
final class FinancialAgreementDisplay
{
    public static function current(?User $seller): array
    {
        abort_unless(self::isOwner(), 403);

        $individual = $seller && in_array($seller->role, ['manager', 'designer'], true)
            ? self::version($seller->id)
            : null;
        $version = $individual ?? self::version(null);
        $owner = self::participant('owner', $version?->owner_id);
        $rop = self::participant('rop', $version?->rop_id);
        $defaultRate = $seller === null || $seller->role === 'manager' ? 250 : 0;

        return [
            'owner_id' => $owner?->id,
            'rop_id' => $rop?->id,
            'owner_name' => $owner?->name,
            'rop_name' => $rop?->name,
            'owner_sfp_bps' => $version?->owner_sfp_bps ?? 500,
            'rop_sfp_bps' => $version?->rop_sfp_bps ?? 500,
            'owner_funding_bps' => $individual?->owner_funding_bps ?? $defaultRate,
            'rop_funding_bps' => $individual?->rop_funding_bps ?? $defaultRate,
            'effective_from' => $version?->effective_from,
            'agreement_version_id' => $version?->id,
            'source' => $individual ? 'Индивидуальные условия' : ($version ? 'Стандартное соглашение' : 'Базовые условия'),
            'available' => $owner !== null && $rop !== null && ($seller === null || ($seller->active && in_array($seller->role, ['owner', 'rop', 'manager', 'designer'], true))),
        ];
    }

    public static function forUser(User $record): ?array
    {
        // Only the owner can view the current conditions for new orders, and only if the user is active and has a valid role.
        if (! self::isOwner() || ! $record->active || ! in_array($record->role, ['owner', 'rop', 'manager', 'designer'], true)) {
            return null;
        }

        $cache = request()->attributes->get(self::class);
        if (! $cache instanceof \WeakMap) {
            $cache = new \WeakMap;
            request()->attributes->set(self::class, $cache);
        }

        if (! isset($cache[$record])) {
            $terms = self::current($record);
            if (in_array($record->role, ['owner', 'rop'], true) && (int) $terms[$record->role.'_id'] !== (int) $record->id) {
                $terms['available'] = false;
                $terms['source'] = 'Не получатель по действующим условиям';
            }
            $cache[$record] = $terms;
        }

        return $cache[$record];
    }

    public static function percent(int $basisPoints): string
    {
        $decimal = rtrim(rtrim(sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100), '0'), '.');

        return str_replace('.', ',', $decimal).' %';
    }

    public static function isOwner(): bool
    {
        return auth()->user()?->active === true && auth()->user()->role === 'owner';
    }

    private static function version(?int $sellerId): ?FinancialAgreementVersion
    {
        return FinancialAgreementVersion::query()
            ->where('scope_key', $sellerId === null ? 'global' : 'seller:'.$sellerId)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    private static function participant(string $role, ?int $id): ?User
    {
        $users = User::query()->where('role', $role)->where('active', true);
        if ($id !== null) {
            return $users->find($id);
        }

        $matches = $users->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
