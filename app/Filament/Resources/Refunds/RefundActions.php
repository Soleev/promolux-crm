<?php

namespace App\Filament\Resources\Refunds;

use App\Filament\CrmForms;
use App\Filament\OrderSelect;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Services\CrmAccess;
use App\Services\Money;
use App\Services\RefundAccess;
use App\Services\RefundService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RefundActions
{
    public static function request(): Action
    {
        return Action::make('requestRefund')
            ->label('Оформить возврат')->icon('heroicon-o-plus')
            ->authorize(fn (): bool => RefundAccess::canRequest(auth()->user()))
            ->databaseTransaction(false)
            ->modalHeading('Заявка на возврат')->modalSubmitActionLabel('Создать заявку')
            ->schema([
                self::key(),
                OrderSelect::make(true)->live()->afterStateUpdated(fn (Set $set) => $set('allocations', [])),
                CrmForms::money('amount', 'Сумма возврата')->suffix('')->live(onBlur: true),
                SchemaView::make('filament.refunds.summary')
                    ->visible(fn (Get $get): bool => filled($get('order_id')))
                    ->viewData(fn (Get $get): array => self::requestSummary($get('order_id'), $get('amount'))),
                Select::make('method')->label('Способ возврата')->options(RefundResource::METHODS)->required(),
                Textarea::make('reason')->label('Причина возврата')->required()->maxLength(2000),
                Repeater::make('allocations')->label('Распределение между исходными оплатами')
                    ->schema([
                        Select::make('payment_id')->label('Исходная оплата')->searchable()->required()
                            ->options(fn (Get $get): array => self::paymentOptions($get('../../order_id'))),
                        CrmForms::money('amount', 'Сумма из оплаты')->suffix(''),
                    ])
                    ->columns(2)->minItems(1)->required()->columnSpanFull(),
            ])
            ->action(function (array $data, $livewire): void {
                self::perform($livewire, fn () => app(RefundService::class)->create($data), 'Заявка создана');
            });
    }

    public static function details(): Action
    {
        return Action::make('refundDetails')->label('Подробнее')->icon('heroicon-o-eye')
            ->authorize(fn (): bool => RefundAccess::canViewAny(auth()->user()))
            ->modalHeading('Карточка возврата')->modalSubmitAction(false)
            ->modalContent(function (Refund $record): View {
                $refund = RefundAccess::query()->with(['order', 'client', 'creator', 'approver', 'executor'])->findOrFail($record->id);
                $cashier = auth()->user()->role === 'cashier';
                $management = RefundAccess::canRequest(auth()->user());
                if (! $cashier) {
                    $refund->load('allocations.payment');
                }
                if ($management) {
                    $refund->load('executionIssues');
                }

                return view('filament.refunds.details', compact('refund', 'cashier', 'management'));
            });
    }

    public static function approve(): Action
    {
        return Action::make('approveRefund')
            ->label('Согласовать')->icon('heroicon-o-check-circle')
            ->authorize(fn (Refund $record): bool => self::isOwner() && $record->status === Refund::CREATED)
            ->databaseTransaction(false)
            ->modalHeading('Согласование возврата')->modalSubmitActionLabel('Согласовать')
            ->modalContent(function (Refund $record): View {
                abort_unless(self::isOwner(), 403);
                $summary = null;
                $beneficiaries = [];
                $previewError = null;
                try {
                    $summary = app(RefundService::class)->preview($record);
                    $beneficiaries = User::query()->whereKey(array_unique(array_column($summary['commission_reversals'], 'beneficiary_id')))
                        ->pluck('name', 'id')->all();
                } catch (ValidationException $exception) {
                    $previewError = $exception->getMessage();
                }

                return view('filament.refunds.preview', ['refund' => $record, ...compact('summary', 'beneficiaries', 'previewError')]);
            })
            ->schema([
                self::key(),
                Select::make('source')->label('Источник выдачи денег')
                    ->options(array_combine(Refund::SOURCES, Refund::SOURCES))->required(),
            ])
            ->action(function (Refund $record, array $data, $livewire): void {
                self::perform($livewire, fn () => app(RefundService::class)->approve($record, $data['source'], $data['idempotency_key']), 'Возврат согласован');
            });
    }

    public static function execute(): Action
    {
        return Action::make('executeRefund')
            ->label('Подтвердить выдачу')->icon('heroicon-o-banknotes')
            ->authorize(function (Refund $record): bool {
                $actor = auth()->user();

                return $actor?->active === true && $record->status === Refund::APPROVED
                    && match ($record->method) {
                        'cash' => $actor->role === 'cashier',
                        'bank_transfer' => $actor->role === 'owner',
                        default => false,
                    };
            })
            ->databaseTransaction(false)
            ->modalHeading('Фактический возврат денег')->modalSubmitActionLabel('Подтвердить возврат')
            ->modalContent(fn (Refund $record): View => view('filament.refunds.execute', ['refund' => $record]))
            ->schema([
                self::key(),
                Checkbox::make('money_returned')->label('Подтверждаю фактический возврат всей суммы клиенту')->accepted()->required(),
                DatePicker::make('executed_at')->label('Дата фактического возврата')->native(false)
                    ->displayFormat('d.m.Y')->default(today('Asia/Tashkent'))->maxDate(today('Asia/Tashkent'))->required(),
                FileUpload::make('document')->label('Подтверждающий документ')->disk('local')->storeFiles(false)
                    ->visibility('private')->preventFilePathTampering()->previewable(false)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)
                    ->helperText('PDF, JPG или PNG, до 10 МБ.'),
            ])
            ->action(function (Refund $record, array $data, $livewire): void {
                self::perform($livewire, function () use ($record, $data): void {
                    $document = $data['document'] ?? null;
                    if ($document !== null && ! $document instanceof UploadedFile) {
                        throw ValidationException::withMessages(['document' => 'Загрузите файл подтверждения.']);
                    }
                    app(RefundService::class)->execute($record, $data['executed_at'], $data['idempotency_key'], $document);
                }, 'Фактический возврат подтверждён');
            });
    }

    public static function close(bool $reject): Action
    {
        $label = $reject ? 'Отклонить заявку' : 'Отменить заявку';
        $statuses = $reject ? [Refund::CREATED] : [Refund::CREATED, Refund::APPROVED];

        return Action::make($reject ? 'rejectRefund' : 'cancelRefund')
            ->label($label)->icon('heroicon-o-x-circle')->color('danger')
            ->authorize(fn (Refund $record): bool => self::isOwner() && in_array($record->status, $statuses, true))
            ->databaseTransaction(false)
            ->modalHeading($label)->modalSubmitActionLabel($label)
            ->schema([
                self::key(),
                Textarea::make('reason')->label('Причина')->maxLength(2000),
            ])
            ->action(function (Refund $record, array $data, $livewire) use ($reject): void {
                $service = app(RefundService::class);
                self::perform(
                    $livewire,
                    fn () => $reject
                        ? $service->reject($record, $data['reason'] ?? '', $data['idempotency_key'])
                        : $service->cancel($record, $data['reason'] ?? '', $data['idempotency_key']),
                    $reject ? 'Заявка отклонена' : 'Заявка отменена',
                );
            });
    }

    public static function flagIssue(): Action
    {
        return Action::make('flagRefundIssue')
            ->label('Зафиксировать ошибку проведения')->icon('heroicon-o-exclamation-triangle')
            ->authorize(fn (Refund $record): bool => self::isOwner() && $record->status === Refund::COMPLETED)
            ->databaseTransaction(false)
            ->modalHeading('Запись об ошибке проведения')->modalSubmitActionLabel('Записать')
            ->schema([
                self::key(),
                Textarea::make('reason')->label('Описание ошибки')->required()->maxLength(2000),
            ])
            ->action(function (Refund $record, array $data, $livewire): void {
                self::perform($livewire, fn () => app(RefundService::class)->flagExecutionIssue(
                    $record, $data['reason'], $data['idempotency_key'],
                ), 'Ошибка проведения зафиксирована');
            });
    }

    private static function key(): Hidden
    {
        return Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid())->required()->rule('uuid');
    }

    private static function isOwner(): bool
    {
        return auth()->user()?->active === true && auth()->user()->role === 'owner';
    }

    private static function order(mixed $id): ?Order
    {
        if (! RefundAccess::canRequest(auth()->user()) || ! is_scalar($id) || ! ctype_digit((string) $id)) {
            return null;
        }

        return CrmAccess::query(Order::class)->find($id);
    }

    private static function requestSummary(mixed $orderId, mixed $amount): array
    {
        $order = self::order($orderId);
        if (! $order) {
            return ['order' => null, 'summary' => null, 'retainedAfterMinor' => null];
        }

        $summary = app(RefundService::class)->summary($order);
        try {
            $requestedMinor = filled($amount) && is_string($amount) ? Money::parse($amount) : null;
        } catch (ValidationException) {
            $requestedMinor = null;
        }

        $retainedAfterMinor = $requestedMinor !== null && $requestedMinor > 0 && $requestedMinor <= $summary['available_minor']
            ? $summary['retained_minor'] - $requestedMinor
            : null;

        return compact('order', 'summary', 'retainedAfterMinor');
    }

    private static function paymentOptions(mixed $orderId): array
    {
        $order = self::order($orderId);
        if (! $order) {
            return [];
        }

        $paymentOptions = [];
        foreach (app(RefundService::class)->summary($order)['payments'] as $id => $payment) {
            if ($payment['available_minor'] > 0) {
                $paymentOptions[$id] = '№ '.$id.' · '.$payment['paid_at'].' · '
                    .$order->displayAmount($payment['available_minor']).' доступно';
            }
        }

        return $paymentOptions;
    }

    private static function perform($livewire, callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
            throw $exception;
        }
        $livewire->flushCachedTableRecords();
        Notification::make()->title($message)->success()->send();
    }
}
