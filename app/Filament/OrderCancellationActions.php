<?php

namespace App\Filament;

use App\Models\Order;
use App\Services\OrderCancellationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OrderCancellationActions
{
    public static function cancel(): Action
    {
        return Action::make('cancelOrder')
            ->label('Отменить заказ')->icon('heroicon-o-x-circle')->color('danger')
            ->databaseTransaction(false)
            ->authorize(fn (Order $record): bool => auth()->user() !== null && app(OrderCancellationService::class)->canCancel(auth()->user(), $record))
            ->modalHeading('Отмена заказа')->modalSubmitActionLabel('Отменить заказ')
            ->modalSubmitAction(fn (Action $action, Order $record): Action => $action->disabled((int) $record->pending_minor > 0))
            ->modalContent(function (Order $record): View {
                Gate::authorize('view', $record);

                return view('filament.order-cancellation', ['order' => $record]);
            })
            ->fillForm(fn (Order $record): array => [
                'idempotency_key' => (string) Str::uuid(),
                'expected_status' => $record->status,
                'expected_confirmed_minor' => (int) $record->confirmed_minor,
                'expected_total_minor' => $record->total_minor,
            ])
            ->schema([
                self::idempotencyField(),
                Hidden::make('expected_status')->required()->rule('string'),
                Hidden::make('expected_confirmed_minor')->required()->rule('integer'),
                Hidden::make('expected_total_minor')->required()->rule('integer'),
                Textarea::make('reason')->label('Причина отмены')->required()->maxLength(2000),
            ])
            ->action(function (Order $record, array $data, $livewire): void {
                try {
                    app(OrderCancellationService::class)->cancel($record, $data['reason'], $data['idempotency_key'], [
                        'status' => $data['expected_status'],
                        'confirmed_minor' => (int) $data['expected_confirmed_minor'],
                        'total_minor' => (int) $data['expected_total_minor'],
                    ]);
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    throw $exception;
                }
                $livewire->flushCachedTableRecords();
                Notification::make()->title('Заказ отменён')->success()->send();
            });
    }

    public static function restore(): Action
    {
        return Action::make('restoreOrder')
            ->label('Восстановить заказ')->icon('heroicon-o-arrow-uturn-left')
            ->databaseTransaction(false)
            ->authorize(fn (Order $record): bool => auth()->user() !== null && app(OrderCancellationService::class)->canRestore(auth()->user(), $record))
            ->requiresConfirmation()
            ->modalHeading('Восстановление заказа')->modalSubmitActionLabel('Восстановить заказ')
            ->modalDescription('Заказ вернётся в производственный статус, сохранённый при отмене.')
            ->fillForm(fn (Order $record): array => [
                'idempotency_key' => (string) Str::uuid(),
                'expected_cancellation_id' => $record->currentCancellation?->id,
            ])
            ->schema([
                self::idempotencyField(),
                Hidden::make('expected_cancellation_id')->required()->rule('integer'),
            ])
            ->action(function (Order $record, array $data, $livewire): void {
                try {
                    app(OrderCancellationService::class)->restore($record, $data['idempotency_key'], (int) $data['expected_cancellation_id']);
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    throw $exception;
                }
                $livewire->flushCachedTableRecords();
                Notification::make()->title('Заказ восстановлен')->success()->send();
            });
    }

    private static function idempotencyField(): Hidden
    {
        return Hidden::make('idempotency_key')->required()->rule('uuid');
    }
}
