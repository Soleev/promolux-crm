<?php

namespace App\Filament\Resources;

use App\Filament\CrmForms;
use App\Filament\CrmTableUi;
use App\Models\Accrual;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Task;
use App\Models\User;
use App\Services\CrmAccess;
use App\Services\CrmWriter;
use App\Services\Ledger;
use App\Services\Money;
use App\Services\RecordDetails;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

abstract class CrmResource extends Resource
{
    protected static bool $isGloballySearchable = false;

    protected static bool $hasTitleCaseModelLabel = false;

    public static function getEloquentQuery(): Builder
    {
        return CrmAccess::query(static::getModel());
    }

    public static function canViewAny(): bool
    {
        $u = auth()->user();
        if (! $u?->active || $u->role === 'cashier') {
            return false;
        }

        if ($u->role === 'designer') {
            return in_array(static::getModel(), [Client::class, Order::class, Task::class, Payment::class], true);
        }

        return match (static::getModel()) {
            Expense::class,Activity::class => $u->role === 'owner',
            User::class => in_array($u->role, ['owner', 'rop']),
            default => true,
        };
    }

    public static function canCreate(): bool
    {
        return ! in_array(static::getModel(), [Accrual::class, Activity::class]) && CrmAccess::manage(auth()->user(), static::getModel());
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return static::canCreate() ? Response::allow() : Response::deny();
    }

    public static function canEdit(Model $record): bool
    {
        return ! in_array(static::getModel(), [Payment::class, Accrual::class, Payout::class, Expense::class, Activity::class]) && parent::canEdit($record);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(CrmForms::fields(static::getModel()))->columns(2);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()->label(CrmTableUi::labels(static::getModel())[2] ?: 'Добавить')->using(fn (array $data) => match (static::getModel()) {
            Payment::class => app(Ledger::class)->payment($data),
            Payout::class => app(Ledger::class)->payout($data),
            default => app(CrmWriter::class)->save(static::getModel(), $data),
        });
    }

    public static function table(Table $table): Table
    {
        $model = static::getModel();
        $text = fn ($name, $label) => TextColumn::make($name)->label($label)->searchable()->sortable()->wrap()->limit(50)->tooltip(fn ($state) => $state);
        $money = fn ($name, $label) => TextColumn::make($name)->label($label)->formatStateUsing(fn ($state) => Money::display((int) $state))->alignEnd();
        $columns = match ($model) {
            Client::class => [$text('name', 'Клиент'), $text('phone', 'Телефон'), $text('manager.name', 'Менеджер'), $text('source', 'Источник')],
            Deal::class => [$text('title', 'Сделка'), $text('client.name', 'Клиент'), $text('manager.name', 'Менеджер'), $text('status', 'Этап')->formatStateUsing(fn ($state) => CrmForms::dealStatuses()[$state] ?? $state)->badge()->color(fn ($state) => CrmTableUi::color($state)), $text('currency', 'Валюта')],
            Order::class => [$text('number', 'Заказ'), $text('client.name', 'Клиент'), $text('manager.name', 'Менеджер'), $money('total_minor', 'Стоимость'), $text('currency', 'Валюта'), $text('status', 'Производство')->formatStateUsing(fn ($state) => CrmForms::orderStatuses()[$state] ?? $state)->badge()->color(fn ($state) => CrmTableUi::color($state)), TextColumn::make('payment_status')->label('Оплата')->badge()->color(fn ($state) => CrmTableUi::color($state)), TextColumn::make('due_at')->label('Срок')->date('d.m.Y')->sortable()],
            Task::class => [$text('title', 'Задача'), $text('manager.name', 'Ответственный'), TextColumn::make('due_at')->label('Срок')->dateTime('d.m.Y H:i')->sortable()->color(fn ($record) => $record->due_at->isPast() && ! in_array($record->status, ['done', 'cancelled']) ? 'danger' : null), $text('status', 'Статус')->formatStateUsing(fn ($state) => CrmForms::taskStatuses()[$state] ?? $state)->badge()->color(fn ($state) => CrmTableUi::color($state))],
            Payment::class => [$text('order.number', 'Заказ'), TextColumn::make('paid_at')->label('Дата оплаты')->date('d.m.Y')->sortable(), $money('amount_minor', 'Оплачено SFP'), TextColumn::make('method')->label('Способ оплаты')->searchable(), $text('currency', 'Валюта'), $text('manager.name', 'Менеджер'), TextColumn::make('calculation_status')->label('Расчёт')->formatStateUsing(fn ($state) => $state === 'calculated' ? 'Начислено' : 'Требует уточнения')->badge()->color(fn ($state) => CrmTableUi::color($state)), TextColumn::make('reversal_of')->label('Сторно оплаты №')],
            Accrual::class => [TextColumn::make('id')->label('№'), $text('payment.order.number', 'Заказ'), TextColumn::make('payment.paid_at')->label('Дата оплаты')->date('d.m.Y')->sortable(), $text('beneficiary.name', 'Получатель'), $text('kind', 'Начисление')->formatStateUsing(fn ($state) => CrmForms::kindLabels()[$state] ?? $state), $money('amount_minor', 'Начислено'), $money('outstanding_minor', 'Остаток'), $text('currency', 'Валюта')],
            Payout::class => [TextColumn::make('id')->label('№'), TextColumn::make('paid_at')->label('Дата')->date('d.m.Y')->sortable(), $text('payer', 'Плательщик'), $text('recipient.name', 'Фактический получатель'), $money('amount_minor', 'Выплачено'), $text('currency', 'Валюта'), TextColumn::make('reversal_of')->label('Сторно выплаты №')],
            Expense::class => [TextColumn::make('paid_at')->label('Дата')->date('d.m.Y')->sortable(), $text('category', 'Категория'), $text('payer', 'Плательщик'), $money('amount_minor', 'Расход'), $text('currency', 'Валюта'), $text('order.number', 'Заказ')],
            User::class => [$text('name', 'Сотрудник'), $text('email', 'Email'), $text('role', 'Роль')->formatStateUsing(fn ($state) => User::ROLE_LABELS[$state] ?? $state), TextColumn::make('active')->label('Доступ')->badge()->color(fn ($state) => $state ? 'success' : 'gray')->formatStateUsing(fn ($state) => $state ? 'Разрешён' : 'Отключён')],
            Activity::class => [TextColumn::make('created_at')->label('Дата')->dateTime('d.m.Y H:i:s')->sortable(), $text('actor.name', 'Пользователь'), $text('subject_type', 'Объект'), TextColumn::make('subject_id')->label('№ записи'), $text('event', 'Действие')],
        };
        $actions = [
            Action::make('details')->label('Подробнее')->modalHeading('Карточка записи')->modalSubmitAction(false)
                ->modalContent(function (Model $record) {
                    Gate::authorize('view', $record);

                    return view('filament.record', ['record' => $record, 'details' => RecordDetails::get($record)]);
                }),
            EditAction::make()->visible(fn (Model $record) => static::canEdit($record))
                ->mutateRecordDataUsing(fn (array $data, Model $record) => CrmForms::editData($data, $record))
                ->using(fn (Model $record, array $data) => app(CrmWriter::class)->save(static::getModel(), $data, $record)),
        ];
        if ($model === Deal::class) {
            $actions[] = Action::make('convert')->label('В заказ')->visible(fn ($record) => $record->status === 'won')->requiresConfirmation()->action(function (Deal $record) {
                app(CrmWriter::class)->convert($record);
                Notification::make()->title('Заказ создан или уже существует')->success()->send();
            });
        }
        if ($model === Client::class) {
            $actions[] = Action::make('communication')->label('Общение')->schema([Textarea::make('message')->label('Заметка о звонке или встрече')->required()->maxLength(5000)])->action(function (Client $record, array $data) {
                Gate::authorize('update', $record);
                $record->recordActivity('communication', ['message' => $data['message']]);
            });
        }
        if (in_array($model, [Payment::class, Payout::class])) {
            $actions[] = Action::make('reverse')->label('Сторно')->color('danger')->visible(fn ($record) => auth()->user()->role === 'owner' && ! $record->reversal_of)
                ->schema([Textarea::make('reason')->label('Причина корректировки')->required()->minLength(5)])->requiresConfirmation()->action(function (Model $record, array $data) {
                    if ($record instanceof Payment) {
                        app(Ledger::class)->reversePayment($record, $data['reason']);
                    } else {
                        app(Ledger::class)->reversePayout($record, $data['reason']);
                    }
                });
        }
        $filters = [];
        if (in_array($model, [Deal::class, Order::class, Task::class])) {
            $filters[] = SelectFilter::make('status')->label('Статус')->options(match ($model) {
                Deal::class => CrmForms::dealStatuses(),Order::class => CrmForms::orderStatuses(),Task::class => CrmForms::taskStatuses()
            });
        }
        if (in_array($model, [Client::class, Deal::class, Order::class, Task::class, Payment::class])) {
            $filters[] = SelectFilter::make('manager_id')->label('Менеджер')->options(fn () => CrmAccess::query(User::class)->pluck('name', 'id'));
        }
        $dateColumn = match ($model) {
            Client::class, Deal::class, Order::class, User::class, Activity::class => 'created_at',
            Task::class => 'due_at',
            default => 'paid_at',
        };
        $filters[] = CrmTableUi::period($dateColumn, $model === Task::class ? 'Срок задачи' : ($dateColumn === 'created_at' ? 'Дата создания' : 'Дата операции'), $model === Accrual::class);
        if ($model === Task::class) {
            $filters[] = SelectFilter::make('deadline')->label('Срок выполнения')
                ->options(['overdue' => 'Просрочено', 'today' => 'Сегодня', 'upcoming' => 'Предстоит'])
                ->query(function (Builder $query, array $data): Builder {
                    if (! ($data['value'] ?? null)) {
                        return $query;
                    }
                    $query->whereNotIn('status', ['done', 'cancelled']);

                    return match ($data['value']) {
                        'overdue' => $query->where('due_at', '<', now()),
                        'today' => $query->whereDate('due_at', today()),
                        'upcoming' => $query->where('due_at', '>', now()),
                        default => $query,
                    };
                });
        }
        if ($model === Payment::class) {
            $filters[] = SelectFilter::make('calculation_status')->label('Расчёт комиссии')->options(['calculated' => 'Начислено', 'needs_clarification' => 'Требует уточнения']);
            $filters[] = SelectFilter::make('method')->label('Способ оплаты')->options(['Перечисление' => 'Перечисление', 'Наличные через кассира' => 'Наличные через кассира']);
        }
        if ($model === Expense::class) {
            $filters[] = SelectFilter::make('payer')->label('Плательщик')->options(['SFP' => 'SFP', 'Promolux' => 'Promolux']);
        }
        if ($model === User::class) {
            $filters[] = SelectFilter::make('role')->label('Роль')->options(fn () => auth()->user()->role === 'owner' ? User::ROLE_LABELS : ['manager' => 'Менеджер']);
            $filters[] = SelectFilter::make('active')->label('Доступ')->options([1 => 'Разрешён', 0 => 'Отключён']);
        }

        $table->columns($columns)->filters($filters)->recordActions($actions)->defaultSort('id', 'desc')->recordUrl(null);

        return CrmTableUi::configure($table, $model, static::createAction(), fn (): bool => static::canCreate());
    }

    public static function getNavigationSort(): ?int
    {
        return match (static::getModel()) {
            Client::class, Payment::class, User::class => 10,
            Deal::class, Activity::class => 20,
            Order::class, Accrual::class => 30,
            Task::class, Payout::class => 40,
            Expense::class => 50,
        };
    }
}
