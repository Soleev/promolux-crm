<?php

namespace App\Filament;

use App\Models\Accrual;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Task;
use App\Models\User;
use App\Services\CrmAccess;
use App\Services\DealStages;
use App\Services\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CrmForms
{
    public static function money(string $name = 'amount', string $label = 'Сумма'): TextInput
    {
        return TextInput::make($name)->label($label)->suffix('сум')->required()->inputMode('decimal')->rules(['regex:/^\\d{1,12}([.,]\\d{1,2})?$/D'])->helperText('До 2 знаков после запятой');
    }

    public static function manager(bool $required = false): Select
    {
        return Select::make('manager_id')->label('Ответственный менеджер')
            ->options(fn (?Model $record) => CrmAccess::salesAssignees(auth()->user(), $record)->orderBy('name')->pluck('name', 'id'))
            ->default(fn () => auth()->user()->handlesOwnOrders() ? auth()->id() : null)
            ->required($required)->disabled(fn () => auth()->user()->handlesOwnOrders())->dehydrated()->searchable()
            ->helperText('Если заказ ведёт сам владелец или ответственный не выбран, распределение комиссии требует уточнения.');
    }

    public static function relation(string $field, string $model, string $label, string $title = 'name', bool $required = false): Select
    {
        return Select::make($field)->label($label)->options(fn () => CrmAccess::query($model)->orderByDesc('id')->limit(500)->pluck($title, 'id'))->searchable()->required($required);
    }

    public static function items(): Repeater
    {
        return Repeater::make('items')->label('Позиции')->schema([
            TextInput::make('name')->label('Наименование')->required()->maxLength(255),
            TextInput::make('quantity')->label('Количество')->integer()->minValue(1)->maxValue(1000000)->default(1)->required(),
            self::money('price', 'Цена за единицу'),
        ])->columns(3)->minItems(1)->defaultItems(1)->columnSpanFull();
    }

    public static function upload(string $name): FileUpload
    {
        return FileUpload::make($name)->label('Документ')->disk('local')->directory('documents/'.auth()->id())->visibility('private')->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)->preventFilePathTampering()->previewable(false)->helperText('PDF, JPG или PNG, до 10 МБ. Приватное хранение.');
    }

    public static function fields(string $model): array
    {
        $notes = fn () => Textarea::make('notes')->label('Примечания')->maxLength(10000)->columnSpanFull();

        return match ($model) {
            Client::class => [
                TextInput::make('name')->label('Компания / клиент')->required()->maxLength(255), self::manager(),
                TextInput::make('phone')->label('Телефон')->maxLength(100), TextInput::make('email')->label('Email')->email(),
                TextInput::make('source')->label('Источник обращения')->maxLength(255),
                Repeater::make('contacts')->label('Контактные лица')->schema([TextInput::make('name')->label('Имя')->required(), TextInput::make('phone')->label('Телефон'), TextInput::make('email')->label('Email')->email()])->columns(3)->defaultItems(0)->columnSpanFull(), $notes(),
            ],
            Deal::class => [
                TextInput::make('title')->label('Название сделки')->required(), self::relation('client_id', Client::class, 'Клиент', 'name', true), self::manager(),
                Select::make('status')->label('Этап')->options(fn (?Deal $record) => $record
                    ? array_intersect_key(self::dealStatuses(), array_flip([$record->status, ...DealStages::targets($record)]))
                    : self::dealStatuses())->default('new')->required()->live()
                    ->helperText('Закрытую сделку можно вернуть в «Уточнение». После создания заказа этап зафиксирован.'),
                Textarea::make('lost_reason')->label('Причина проигрыша')->required(fn (Get $get) => $get('status') === 'lost'),
                Textarea::make('description')->label('Описание')->columnSpanFull(), self::items(),
            ],
            Order::class => [
                TextInput::make('number')->label('Номер заказа')->default(fn () => 'PL-'.strtoupper((string) Str::ulid()))->required(),
                self::relation('client_id', Client::class, 'Клиент', 'name', true), self::manager(),
                Select::make('status')->label('Производственный статус')->options(self::orderStatuses())->default('new')->required(),
                DatePicker::make('due_at')->native(false)->displayFormat('d.m.Y')->label('Срок'), Textarea::make('description')->label('Описание'), self::upload('attachment'), self::items(),
            ],
            Task::class => [
                TextInput::make('title')->label('Задача')->required(),
                Select::make('manager_id')->label('Ответственный')->options(fn () => User::where('active', true)->when(auth()->user()->handlesOwnOrders(), fn ($q) => $q->whereKey(auth()->id()))->pluck('name', 'id'))->default(fn () => auth()->id())->required(),
                DateTimePicker::make('due_at')->native(false)->displayFormat('d.m.Y H:i')->label('Срок')->seconds(false)->required(),
                Select::make('status')->label('Статус')->options(self::taskStatuses())->default('open')->required(),
                self::relation('client_id', Client::class, 'Клиент'), self::relation('deal_id', Deal::class, 'Сделка', 'title'), self::relation('order_id', Order::class, 'Заказ', 'number'), $notes(),
            ],
            Payment::class => [
                Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
                self::relation('order_id', Order::class, 'Заказ', 'number', true),
                DatePicker::make('paid_at')->native(false)->displayFormat('d.m.Y')->label('Дата оплаты в SFP')->default(today())->maxDate(today())->required(),
                self::money(),
                Select::make('owner_id')->hidden(fn () => auth()->user()->role === 'designer')->label('Получатель доли владельца')->options(fn () => User::where('role', 'owner')->where('active', true)->pluck('name', 'id'))->default(fn () => auth()->id()),
                Select::make('rop_id')->hidden(fn () => auth()->user()->role === 'designer')->label('Получатель доли РОП')->options(fn () => User::where('role', 'rop')->where('active', true)->pluck('name', 'id'))->helperText('Для заказа с менеджером обязательны оба получателя.'),
                self::upload('document'), $notes(),
            ],
            Payout::class => [
                Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
                Select::make('payer')->label('Плательщик')->options(fn () => ['SFP' => 'SFP'] + Accrual::where('payer', '!=', 'SFP')->distinct()->pluck('payer', 'payer')->all())->required()->default('SFP'),
                self::relation('recipient_id', User::class, 'Фактический получатель', 'name', true),
                DatePicker::make('paid_at')->native(false)->displayFormat('d.m.Y')->label('Дата выплаты')->default(today())->maxDate(today())->required(), self::money(),
                Repeater::make('allocations')->label('Распределение по начислениям')->schema([
                    Select::make('accrual_id')->label('Начисление')->options(fn () => Accrual::with(['beneficiary', 'payment.order'])->where('amount_minor', '>', 0)->get()->filter(fn ($a) => $a->outstanding_minor > 0 && ! Payment::where('reversal_of', $a->payment_id)->exists())->mapWithKeys(fn ($a) => [$a->id => '#'.$a->id.' · '.$a->payment->order->number.' · '.$a->beneficiary->name.' · '.self::kindLabels()[$a->kind].' · остаток '.Money::display($a->outstanding_minor)]))->searchable()->required(),
                    self::money(),
                ])->minItems(1)->defaultItems(1)->columnSpanFull(), $notes(),
            ],
            Expense::class => [
                DatePicker::make('paid_at')->native(false)->displayFormat('d.m.Y')->label('Дата')->default(today())->required(),
                TextInput::make('category')->label('Категория')->required(), self::money(),
                Select::make('payer')->label('Плательщик')->options(['SFP' => 'SFP', 'Promolux' => 'Promolux'])->default('SFP')->required(),
                self::relation('order_id', Order::class, 'Заказ', 'number'), $notes(),
            ],
            User::class => [
                TextInput::make('name')->label('Имя')->required(), TextInput::make('email')->label('Email')->email()->required(),
                TextInput::make('password')->label('Пароль')->password()->minLength(12)->autocomplete('new-password')->helperText('При изменении оставьте пустым, чтобы сохранить пароль.'),
                Select::make('role')->label('Роль')->options(fn () => auth()->user()->role === 'owner' ? User::ROLE_LABELS : ['manager' => 'Менеджер'])->default('manager')->required(),
                Toggle::make('active')->label('Доступ разрешён')->default(true),
            ],
            default => [],
        };
    }

    public static function dealStatuses(): array
    {
        return ['new' => 'Новый контакт', 'qualifying' => 'Уточнение', 'estimate' => 'Расчёт', 'proposal' => 'Предложение', 'won' => 'Согласовано', 'lost' => 'Проиграно'];
    }

    public static function orderStatuses(): array
    {
        return ['new' => 'Новый', 'design' => 'Макет', 'production' => 'Производство', 'ready' => 'Готов', 'delivered' => 'Передан', 'cancelled' => 'Отменён'];
    }

    public static function taskStatuses(): array
    {
        return ['open' => 'Открыта', 'in_progress' => 'В работе', 'done' => 'Выполнена', 'cancelled' => 'Отменена'];
    }

    public static function kindLabels(): array
    {
        return ['owner_gross' => 'Владельцу от SFP · 5%', 'rop_gross' => 'РОП от SFP · 5%', 'manager_owner' => 'Менеджеру из доли владельца · 2,5%', 'manager_rop' => 'Менеджеру из доли РОП · 2,5%'];
    }

    public static function editData(array $data, Model $record): array
    {
        unset($data['password']);
        if ($record instanceof Order) {
            $data['items'] = $record->items->map(fn ($i) => ['name' => $i->name, 'quantity' => $i->quantity, 'price' => Money::format($i->price_minor)])->all();
        }
        if ($record instanceof Deal) {
            $data['items'] = array_map(fn ($i) => ['name' => $i['name'], 'quantity' => $i['quantity'], 'price' => Money::format($i['price_minor'])], $record->items ?? []);
        }

        return $data;
    }
}
