<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ClientDirectory
{
    public static function visible(): Builder
    {
        $user = auth()->user();

        return $user?->active && $user->role === 'manager' ? Client::query() : CrmAccess::query(Client::class);
    }

    public static function forSale(?Model $sale): Builder
    {
        if (! $sale) {
            return self::visible();
        }
        $query = CrmAccess::query(Client::class);
        if (auth()->user()?->role === 'manager' && CrmAccess::view(auth()->user(), $sale)) {
            // Only the persisted link can survive an edit outside the previous scope.
            return self::visible()->where(fn ($q) => $q->whereIn('id', $query->select('id'))->orWhereKey($sale->client_id));
        }

        return $query;
    }

    public static function listings(): Builder
    {
        $other = fn ($q) => $q->where('manager_id', '!=', auth()->id());

        return self::visible()->select(['id', 'manager_id', 'name', 'email', 'phone'])->withExists([
            'deals as other_deal' => fn ($q) => $other($q)->whereNotIn('status', ['won', 'lost']),
            'orders as other_order' => fn ($q) => $other($q)->whereNotIn('status', ['delivered', 'cancelled']),
        ]);
    }

    public static function card(Client $record): array
    {
        $client = self::listings()->addSelect('contacts')->findOrFail($record->id);
        $groups = [];
        foreach ([Deal::class => 'Мои сделки', Order::class => 'Мои заказы', Task::class => 'Мои задачи'] as $type => $heading) {
            $groups[$heading] = CrmAccess::query($type)->where('client_id', $client->id)->latest('id')
                ->pluck($type === Order::class ? 'id' : 'title');
        }
        $contacts = [];
        foreach (is_array($client->contacts) ? $client->contacts : [] as $contact) {
            if (is_array($contact)) {
                $fields = array_filter(array_intersect_key($contact, array_flip(['name', 'phone', 'email'])), 'is_string');
                if ($fields) {
                    $contacts[] = implode(' · ', $fields);
                }
            }
        }

        return compact('client', 'groups', 'contacts');
    }

    public static function phone(?string $input): ?string
    {
        $input = trim($input ?? '');
        if (! preg_match('/^\+?[\d\s().-]+$/D', $input)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $input);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (! str_starts_with($input, '+') && strlen($digits) === 9) {
            $digits = '998'.$digits;
        }

        return preg_match('/^[1-9]\d{6,14}$/D', $digits) ? $digits : null;
    }

    public static function matches(?string $name, ?string $phone, ?string $email): array
    {
        $phone = self::phone($phone);
        $email = mb_strtolower(trim($email ?? ''));
        $name = mb_strtolower(trim($name ?? ''));
        if (! $phone && $email === '' && mb_strlen($name) < 3) {
            return [];
        }
        $found = [];
        foreach (self::visible()->select('id', 'name', 'phone', 'email')->lazyById(500) as $client) {
            $rank = match (true) {
                $phone !== null && self::phone($client->phone) === $phone => 1,
                $email !== '' && mb_strtolower(trim($client->email ?? '')) === $email => 2,
                mb_strlen($name) >= 3 && str_contains(mb_strtolower($client->name), $name) => 3,
                default => null,
            };
            if ($rank !== null && count($found[$rank] ?? []) < 5) {
                $found[$rank][$client->id] = $client->name;
            }
        }

        return array_slice(($found[1] ?? []) + ($found[2] ?? []) + ($found[3] ?? []), 0, 5, true);
    }
}
