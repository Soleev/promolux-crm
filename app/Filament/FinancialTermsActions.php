<?php

namespace App\Filament;

use App\Models\Order;
use App\Models\User;
use App\Services\FinancialTerms;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View as SchemaView;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

final class FinancialTermsActions
{
    public static function standardAgreement(): Action
    {
        return self::publishAgreement('standardFinancialAgreement', 'Стандартные условия')
            ->modalContent(fn (): View => self::versions(null, true));
    }

    public static function individualAgreement(): Action
    {
        return self::publishAgreement('individualFinancialAgreement', 'Индивидуальные условия', individual: true)
            ->modalHeading(fn (User $record): string => 'Индивидуальные условия · '.$record->name)
            ->visible(fn (User $record): bool => self::isOwner() && $record->active && in_array($record->role, ['manager', 'designer'], true))
            ->modalContent(fn (User $record): View => self::versions($record->id, true));
    }

    public static function myAgreement(): Action
    {
        $canView = fn (): bool => auth()->user()?->active === true && in_array(auth()->user()->role, ['manager', 'designer', 'rop'], true);

        return Action::make('myFinancialAgreement')
            ->label('Мои финансовые условия')
            ->visible($canView)
            ->authorize($canView)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->modalContent(function () use ($canView): View {
                abort_unless($canView(), 403);

                return self::versions(auth()->id(), false);
            });
    }

    public static function confirmOrder(): Action
    {
        return Action::make('confirmFinancialTerms')
            ->label('Подтвердить финансовые условия')
            ->icon('heroicon-o-check-badge')
            ->authorize(self::isOwner(...))
            ->visible(fn (Order $record): bool => self::isOwner() && $record->financialTerms === null)
            ->modalSubmitActionLabel('Подтвердить условия')
            ->fillForm(fn (Order $record): array => [
                'seller_id' => $record->initial_seller_id,
                'seller_role' => $record->initial_seller_role,
            ])
            ->schema([
                Select::make('seller_id')->label('Сотрудник по заказу')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->disabled(fn (Order $record): bool => $record->initial_seller_id !== null)
                    ->searchable()->required(),
                Select::make('seller_role')->label('Роль сотрудника на дату заказа')
                    ->options(array_intersect_key(User::ROLE_LABELS, array_flip(['owner', 'rop', 'manager', 'designer'])))
                    ->disabled(fn (Order $record): bool => $record->initial_seller_role !== null)
                    ->required(),
                ...self::recipients(historical: true),
                ...self::fundingRates(),
                self::preview(),
                Textarea::make('reason')->label('Основание и источник подтверждения')->required()->minLength(5)->maxLength(5000),
            ])
            ->action(function (Order $record, array $data): void {
                app(FinancialTerms::class)->confirm($record, self::withBasisPoints($data));
                Notification::make()->title('Финансовые условия подтверждены')->success()->send();
            });
    }

    public static function showOrder(): Action
    {
        $canView = fn (Order $record): bool => self::canViewOrder($record);

        return Action::make('financialTerms')
            ->label('Финансовые условия')
            ->icon('heroicon-o-scale')
            ->modalHeading(fn (Order $record): string => 'Финансовые условия · заказ № '.$record->id)
            ->modalWidth('2xl')
            ->visible($canView)
            ->authorize($canView)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->modalContent(function (Order $record): View {
                abort_unless(self::canViewOrder($record), 403);

                $terms = $record->financialTerms;
                $ownerRate = $terms->owner_sfp_bps - $terms->owner_funding_bps;
                $ropRate = $terms->rop_sfp_bps - $terms->rop_funding_bps;
                $sellerRate = $terms->owner_funding_bps + $terms->rop_funding_bps;
                $hasSellerShare = in_array($terms->seller_role, ['manager', 'designer'], true);
                if (! self::isOwner()) {
                    $actor = auth()->id();
                    $myRate = ((int) $terms->owner_id === $actor ? $ownerRate : 0)
                        + ((int) $terms->rop_id === $actor ? $ropRate : 0)
                        + ($hasSellerShare && (int) $terms->seller_id === $actor ? $sellerRate : 0);

                    return view('filament.financial-terms.order', ['shares' => ['Моя доля' => self::percent($myRate)], 'confirmedAt' => $terms->confirmed_at, 'version' => $terms->agreementVersion?->version]);
                }

                $terms->loadMissing(['owner', 'rop', 'seller']);
                $shares = [
                    'Владелец · '.$terms->owner->name => self::percent($ownerRate),
                    'РОП · '.$terms->rop->name => self::percent($ropRate),
                ];
                if ($hasSellerShare) {
                    $shares['Сотрудник · '.$terms->seller->name] = self::percent($sellerRate);
                }

                return view('filament.financial-terms.order', ['shares' => $shares, 'confirmedAt' => $terms->confirmed_at, 'version' => $terms->agreementVersion?->version]);
            });
    }

    private static function publishAgreement(string $name, string $label, bool $individual = false): Action
    {
        return Action::make($name)
            ->label($label)
            ->visible(self::isOwner(...))
            ->authorize(self::isOwner(...))
            ->modalSubmitActionLabel('Опубликовать версию')
            ->modalDescription('Новые условия применяются только к новым заказам. Финансовые условия существующих заказов и проведённые операции не изменяются.')
            ->fillForm(function (?User $record = null) use ($individual): array {
                abort_if($individual && $record === null, 404);
                $current = FinancialAgreementDisplay::current($individual ? $record : null);

                return [
                    'owner_id' => $current['owner_id'],
                    'rop_id' => $current['rop_id'],
                    'owner_funding_percent' => self::percentInput($current['owner_funding_bps']),
                    'rop_funding_percent' => self::percentInput($current['rop_funding_bps']),
                    'effective_from' => null,
                ];
            })
            ->schema([
                ...self::recipients(),
                ...($individual ? self::fundingRates() : []),
                self::preview(standard: ! $individual),
                DateTimePicker::make('effective_from')->label('Действует с')->native(false)
                    ->helperText('Оставьте пустым, чтобы применить условия с момента публикации.'),
            ])
            ->action(function (array $data, ?User $record = null) use ($individual): void {
                abort_if($individual && $record === null, 404);
                app(FinancialTerms::class)->publish($individual ? $record : null, $individual ? self::withBasisPoints($data) : $data);
                Notification::make()->title('Версия условий опубликована')->success()->send();
            });
    }

    private static function recipients(bool $historical = false): array
    {
        return collect(['owner' => 'Получатель доли владельца', 'rop' => 'Получатель доли РОП'])
            ->map(fn (string $label, string $role): Select => Select::make($role.'_id')->label($label)
                ->options(fn (): array => User::query()
                    ->when(! $historical, fn ($query) => $query->where('active', true)->where('role', $role))
                    ->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()->live()->required())
            ->values()->all();
    }

    private static function fundingRates(): array
    {
        return collect(['owner' => 'владельца', 'rop' => 'РОП'])
            ->map(fn (string $label, string $role): TextInput => TextInput::make($role.'_funding_percent')
                ->label('Доля сотрудника за счёт '.$label)->suffix('%')->inputMode('decimal')
                ->rule('regex:/^(?:[0-4](?:[.,][0-9]{1,2})?|5(?:[.,]0{1,2})?)$/D')
                ->validationMessages(['regex' => 'Укажите процент от 0 до 5, не более двух знаков после запятой.'])
                ->live(onBlur: true)->required()->helperText('От 0 до 5 %. Например: 2,5.'))
            ->values()->all();
    }

    private static function percentInput(int $basisPoints): string
    {
        return sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100);
    }

    private static function basisPoints(mixed $percent): ?int
    {
        if (! is_scalar($percent) || ! preg_match('/^(?:[0-4](?:[.,][0-9]{1,2})?|5(?:[.,]0{1,2})?)$/D', (string) $percent)) {
            return null;
        }
        $parts = explode('.', str_replace(',', '.', (string) $percent));

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private static function withBasisPoints(array $data): array
    {
        foreach (['owner', 'rop'] as $role) {
            $field = $role.'_funding_percent';
            $value = self::basisPoints($data[$field] ?? null);
            if ($value === null) {
                throw ValidationException::withMessages([$field => 'Укажите процент от 0 до 5.']);
            }
            $data[$role.'_funding_bps'] = $value;
            unset($data[$field]);
        }

        return $data;
    }

    private static function preview(bool $standard = false): SchemaView
    {
        return SchemaView::make('filament.financial-terms.preview')->viewData(function (Get $get) use ($standard): array {
            abort_unless(self::isOwner(), 403);
            $owner = $standard ? 250 : self::basisPoints($get('owner_funding_percent'));
            $rop = $standard ? 250 : self::basisPoints($get('rop_funding_percent'));

            return ['standard' => $standard, 'shares' => $owner === null || $rop === null ? [] : [
                'Владелец · '.(User::find($get('owner_id'))?->name ?? 'Получатель не выбран') => self::percent(500 - $owner),
                'РОП · '.(User::find($get('rop_id'))?->name ?? 'Получатель не выбран') => self::percent(500 - $rop),
                'Сотрудник' => self::percent($owner + $rop),
            ]];
        });
    }

    private static function versions(?int $seller, bool $owner): View
    {
        abort_if($owner && ! self::isOwner(), 403);
        $versions = app(FinancialTerms::class)->visibleVersions(auth()->user())
            ->when($owner, fn ($query) => $query->with(['owner:id,name', 'rop:id,name']))
            ->where('seller_id', $seller)->orderByDesc('effective_from')->orderByDesc('id')->get();
        $rows = $versions->map(fn ($version): array => [
            'version' => $version->version,
            'effective' => $version->effective_from->format('d.m.Y H:i'),
            'seller' => self::percent($version->owner_funding_bps + $version->rop_funding_bps),
            'scheduled' => $version->effective_from->isFuture(),
            ...($owner ? [
                'owner' => $version->owner->name.' · '.self::percent($version->owner_sfp_bps - $version->owner_funding_bps),
                'rop' => $version->rop->name.' · '.self::percent($version->rop_sfp_bps - $version->rop_funding_bps),
            ] : []),
        ]);

        return view('filament.financial-terms.versions', ['rows' => $rows, 'owner' => $owner]);
    }

    private static function canViewOrder(Order $order): bool
    {
        $user = auth()->user();
        $terms = $order->financialTerms;

        return $user?->active === true && $terms !== null && (
            self::isOwner()
            || in_array($user->id, [(int) $terms->owner_id, (int) $terms->rop_id], true)
            || ($user->id === (int) $terms->seller_id && in_array($terms->seller_role, ['manager', 'designer'], true))
        );
    }

    private static function isOwner(): bool
    {
        return auth()->user()?->active === true && auth()->user()->role === 'owner';
    }

    private static function percent(int $basisPoints): string
    {
        return sprintf('%d,%02d %%', intdiv($basisPoints, 100), $basisPoints % 100);
    }
}
