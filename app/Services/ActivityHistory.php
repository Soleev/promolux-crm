<?php

namespace App\Services;

use App\Filament\CrmForms;
use App\Filament\Resources\Accruals\AccrualResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Payouts\PayoutResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Accrual;
use App\Models\Activity;
use App\Models\Allocation;
use App\Models\CashReceipt;
use App\Models\Client;
use App\Models\CommissionSettlement;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\RefundAllocation;
use App\Models\RefundCommissionReversal;
use App\Models\RefundExecutionIssue;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class ActivityHistory
{
    public const TYPES = [
        Client::class => 'Клиент', Deal::class => 'Сделка', Order::class => 'Заказ', Task::class => 'Задача',
        Payment::class => 'Оплата', CashReceipt::class => 'Приём наличных', Expense::class => 'Расход',
        Payout::class => 'Выплата', Accrual::class => 'Начисление', Allocation::class => 'Распределение выплаты',
        Refund::class => 'Возврат', RefundAllocation::class => 'Распределение возврата',
        RefundCommissionReversal::class => 'Сторно комиссии', RefundExecutionIssue::class => 'Ошибка возврата',
        CommissionSettlement::class => 'Расчёт по комиссии',
        User::class => 'Сотрудник', OrderItem::class => 'Позиция заказа',
    ];

    public const EVENTS = [
        'created' => 'Создание', 'updated' => 'Изменение', 'communication' => 'Общение',
        'converted' => 'Преобразование', 'items_replaced' => 'Состав заказа', 'account_saved' => 'Учётная запись',
        'order_cancelled' => 'Отмена заказа', 'order_restored' => 'Восстановление заказа',
    ];

    private const RESOURCES = [
        Client::class => ClientResource::class, Deal::class => DealResource::class, Order::class => OrderResource::class,
        Task::class => TaskResource::class, User::class => UserResource::class, Payment::class => PaymentResource::class,
        Expense::class => ExpenseResource::class, Payout::class => PayoutResource::class, Accrual::class => AccrualResource::class,
        Refund::class => RefundResource::class,
    ];

    private const REFERENCES = [
        'created_by' => User::class, 'manager_id' => User::class, 'beneficiary_id' => User::class,
        'client_id' => Client::class, 'order_id' => Order::class, 'deal_id' => Deal::class,
        'payout_id' => Payout::class, 'refund_id' => Refund::class, 'payment_id' => Payment::class,
        'accrual_id' => Accrual::class,
    ];

    public static function forRecord(Model $record): array
    {
        Gate::authorize('view', $record);
        if (auth()->user()->role !== 'owner' && ! in_array($record::class, [Client::class, Deal::class, Order::class, Task::class], true)) {
            return [];
        }
        $events = Activity::where('subject_type', $record::class)->where('subject_id', $record->id)
            ->whereIn('subject_id', CrmAccess::query($record::class)->select('id'))
            ->with('actor:id,name')->latest('created_at')->latest('id')->limit(50)->get();

        return self::present($events, [$record::class => collect([$record->id => $record])], false);
    }

    public static function journal(Collection $events): array
    {
        abort_unless(auth()->user()?->active && auth()->user()->role === 'owner', 403);
        $subjects = [];
        foreach ($events->groupBy('subject_type') as $type => $group) {
            if (isset(self::TYPES[$type])) {
                $subjects[$type] = self::objects($type, $group->pluck('subject_id')->all());
            }
        }

        return self::present($events, $subjects, true);
    }

    private static function objects(string $type, array $ids): Collection
    {
        $columns = match ($type) {
            User::class, Client::class => ['id', 'name'],
            OrderItem::class => ['id', 'name', 'order_id'],
            CashReceipt::class => ['id', 'order_id'],
            Allocation::class => ['id', 'payout_id'],
            Refund::class => ['id', 'currency'],
            RefundAllocation::class, RefundCommissionReversal::class, RefundExecutionIssue::class => ['id', 'refund_id'],
            Task::class, Deal::class => ['id', 'title'],
            default => ['id'],
        };

        return CrmAccess::query($type)->whereKey(array_unique($ids))->get($columns)->keyBy('id');
    }

    private static function snapshots(Activity $event): array
    {
        $data = is_array($event->changes) ? $event->changes : [];
        if ($event->event === 'items_replaced') {
            $parts = [];
            foreach (['before', 'after'] as $side) {
                if (isset($data[$side]) && is_array($data[$side])) {
                    $parts[$side] = ['items' => $data[$side]];
                }
            }

            return [$parts['before'] ?? [], $parts['after'] ?? []];
        }

        return [
            is_array($data['before'] ?? null) ? $data['before'] : [],
            is_array($data['after'] ?? null) ? $data['after'] : $data,
        ];
    }

    private static function present(Collection $events, array $subjects, bool $links): array
    {
        $ids = [];
        $snapshots = [];
        foreach ($events as $event) {
            $subject = $subjects[$event->subject_type][$event->subject_id] ?? null;
            $snapshots[$event->id] = $subject ? self::snapshots($event) : [[], []];
            if ($subject instanceof CashReceipt || $subject instanceof OrderItem) {
                $ids['order_id'][] = $subject->order_id;
            } elseif ($subject instanceof Allocation) {
                $ids['payout_id'][] = $subject->payout_id;
            } elseif ($subject instanceof RefundAllocation || $subject instanceof RefundCommissionReversal || $subject instanceof RefundExecutionIssue) {
                $ids['refund_id'][] = $subject->refund_id;
            }
            foreach ($snapshots[$event->id] as $snapshot) {
                foreach (array_intersect_key($snapshot, self::REFERENCES) as $key => $id) {
                    if (is_numeric($id)) {
                        $ids[$key][] = (int) $id;
                    }
                }
            }
        }
        $references = [];
        foreach (self::REFERENCES as $key => $type) {
            $references[$key] = empty($ids[$key]) ? collect() : self::objects($type, $ids[$key]);
        }

        $rows = [];
        foreach ($events as $event) {
            $type = $event->subject_type;
            $subject = $subjects[$type][$event->subject_id] ?? null;
            [$before, $after] = $snapshots[$event->id];
            $fields = [];
            if ($subject && isset(self::EVENTS[$event->event])) {
                foreach (self::fields($type) as $key => $label) {
                    $field = ['label' => $label];
                    foreach (['before' => $before, 'after' => $after] as $side => $snapshot) {
                        if (array_key_exists($key, $snapshot)) {
                            $currency = $snapshot['currency'] ?? $subject->currency
                                ?? ($references['refund_id'][$subject->refund_id] ?? null)?->currency;
                            $field[$side] = self::value($type, $key, $snapshot[$key], $references, $currency);
                        }
                    }
                    if (count($field) > 1) {
                        $fields[] = $field;
                    }
                }
            }
            $target = $subject;
            if ($subject instanceof CashReceipt || $subject instanceof OrderItem) {
                $target = $references['order_id'][$subject->order_id] ?? null;
            } elseif ($subject instanceof Allocation) {
                $target = $references['payout_id'][$subject->payout_id] ?? null;
            } elseif ($subject instanceof RefundAllocation || $subject instanceof RefundCommissionReversal || $subject instanceof RefundExecutionIssue) {
                $target = $references['refund_id'][$subject->refund_id] ?? null;
            }
            $resource = $target ? (self::RESOURCES[$target::class] ?? null) : null;
            $caption = $subject ? self::TYPES[$type].' № '.$subject->id : 'Объект недоступен';
            if ($name = $subject?->name ?? $subject?->title) {
                $caption .= ' · '.$name;
            }
            $rows[] = [
                'id' => $event->id,
                'title' => $subject ? self::title($event, $before, $after) : 'Событие истории',
                'category' => self::EVENTS[$event->event] ?? 'Событие',
                'subject' => $caption,
                'actor' => $event->actor?->name ?? ($event->actor_id ? 'Сотрудник недоступен' : 'Автор не указан'),
                'date' => $event->created_at?->timezone('Asia/Tashkent')->format('d.m.Y H:i') ?? 'Не указано',
                'url' => $links && $subject && $resource ? $resource::getUrl('index', [
                    'tableAction' => $target instanceof Refund ? 'refundDetails' : 'details', 'tableActionRecord' => $target->id,
                ]) : null,
                'linkLabel' => match (true) {
                    $target === $subject => 'Открыть карточку',
                    $target instanceof Order => 'Открыть заказ',
                    $target instanceof Refund => 'Открыть возврат',
                    default => 'Открыть выплату',
                },
                'fields' => $fields,
            ];
        }

        return $rows;
    }

    private static function fields(string $type): array
    {
        $manager = ['manager_id' => 'Ответственный менеджер'];
        $customer = ['client_id' => 'Клиент'];
        $related = ['order_id' => 'Заказ', 'deal_id' => 'Сделка'];
        $production = ['status' => 'Статус производства', 'due_at' => 'Срок заказа', 'items' => 'Позиции'];
        $money = ['amount_minor' => 'Сумма', 'paid_at' => 'Дата операции'];

        return match ($type) {
            Client::class => ['name' => 'Название клиента', 'phone' => 'Телефон', 'email' => 'Электронная почта', 'contacts' => 'Контактные лица', 'notes' => 'Примечание', 'message' => 'Заметка', 'source' => 'Источник'] + $manager,
            Deal::class => ['title' => 'Название сделки', 'status' => 'Этап сделки', 'lost_reason' => 'Причина отказа', 'description' => 'Описание', 'items' => 'Позиции'] + $manager + $customer + ['order_id' => 'Заказ'],
            Order::class => $production + $manager + $customer + ['description' => 'Описание', 'deal_id' => 'Сделка', 'reason' => 'Причина отмены'],
            Task::class => ['created_by' => 'Постановщик', 'manager_id' => 'Исполнитель', 'title' => 'Название задачи', 'status' => 'Статус задачи', 'due_at' => 'Срок выполнения', 'notes' => 'Примечание'] + $manager + $customer + $related,
            OrderItem::class => ['name' => 'Продукция', 'quantity' => 'Количество', 'price_minor' => 'Цена', 'order_id' => 'Заказ'],
            CashReceipt::class => ['amount_minor' => 'Сумма', 'status' => 'Состояние наличных', 'collected_at' => 'Получено от клиента', 'handed_at' => 'Принято кассиром', 'reason' => 'Причина', 'order_id' => 'Заказ'],
            Payment::class => $money + ['notes' => 'Примечание', 'method' => 'Способ оплаты', 'order_id' => 'Заказ'],
            Expense::class => $money + ['category' => 'Категория', 'order_id' => 'Заказ'],
            Payout::class => $money + ['notes' => 'Примечание'],
            Accrual::class => $money, Allocation::class => $money + ['payout_id' => 'Выплата'],
            Refund::class => $money + $customer + [
                'order_id' => 'Заказ', 'number' => 'Номер возврата', 'status' => 'Статус возврата',
                'reason' => 'Причина возврата', 'method' => 'Способ возврата', 'source' => 'Источник выдачи',
                'approved_at' => 'Согласован', 'executed_at' => 'Фактически выполнен', 'close_reason' => 'Причина закрытия',
            ],
            RefundAllocation::class => $money + ['refund_id' => 'Возврат', 'payment_id' => 'Исходная оплата'],
            RefundCommissionReversal::class => $money + ['refund_id' => 'Возврат', 'accrual_id' => 'Исходное начисление', 'recorded_at' => 'Дата сторно'],
            RefundExecutionIssue::class => ['refund_id' => 'Возврат', 'reason' => 'Описание ошибки'],
            CommissionSettlement::class => $money + [
                'beneficiary_id' => 'Получатель начисления', 'payer' => 'Исходный плательщик', 'kind' => 'Операция',
                'settled_on' => 'Дата расчёта', 'method' => 'Способ погашения', 'reason' => 'Причина',
            ],
            User::class => ['name' => 'Имя сотрудника', 'role' => 'Роль'],
            default => [],
        };
    }

    private static function title(Activity $event, array $before, array $after): string
    {
        $type = $event->subject_type;
        $status = $after['status'] ?? null;
        if ($event->event === 'created') {
            if (in_array($type, [Payment::class, Payout::class], true) && ! empty($after['reversal_of'])) {
                return 'Проведено сторно';
            }

            return match ($type) {
                Client::class => 'Создан клиент', Deal::class => 'Создана сделка', Order::class => 'Создан заказ',
                Task::class => 'Создана задача', CashReceipt::class => 'Зарегистрировано получение наличных',
                Payment::class => 'Зарегистрирована оплата', Payout::class => 'Зарегистрирована выплата',
                Expense::class => 'Зарегистрирован расход', Accrual::class => 'Зарегистрировано начисление',
                Allocation::class => 'Записано распределение выплаты', OrderItem::class => 'Добавлена позиция заказа',
                Refund::class => 'Создана заявка на возврат', RefundAllocation::class => 'Записано распределение возврата',
                RefundCommissionReversal::class => 'Проведено сторно комиссии', RefundExecutionIssue::class => 'Зафиксирована ошибка возврата',
                CommissionSettlement::class => ($after['kind'] ?? null) === CommissionSettlement::WRITE_OFF
                    ? 'Списана задолженность сотрудника' : 'Погашена задолженность по комиссии',
                default => 'Создана запись',
            };
        }
        if ($event->event === 'items_replaced') {
            $old = self::itemTotal($before['items'] ?? null);
            $new = self::itemTotal($after['items'] ?? null);

            return $old !== null && $new !== null && $old !== $new ? 'Изменена стоимость заказа' : 'Изменены позиции заказа';
        }
        if ($event->event !== 'updated') {
            return match ($event->event) {
                'communication' => 'Добавлена заметка о звонке или встрече', 'converted' => 'Из сделки создан заказ',
                'account_saved' => 'Сохранена учётная запись', 'order_cancelled' => 'Заказ отменён',
                'order_restored' => 'Заказ восстановлен', default => 'Событие истории',
            };
        }

        return match (true) {
            $type === CashReceipt::class && $status === 'accepted' => 'Подтверждена передача наличных в SFP',
            $type === CashReceipt::class && $status === 'reversed' => 'Проведено сторно',
            $type === Deal::class && $status === 'won' => 'Сделка согласована',
            $type === Deal::class && $status === 'lost' => 'Сделка признана проигранной',
            $type === Deal::class && isset($status) => 'Изменён этап сделки',
            $type === Order::class && isset($status) => 'Изменён статус заказа',
            $type === Order::class && array_key_exists('due_at', $after) => 'Изменён срок заказа',
            $type === Task::class && $status === 'done' => 'Задача выполнена',
            $type === Refund::class && $status === Refund::APPROVED => 'Возврат согласован',
            $type === Refund::class && $status === Refund::COMPLETED => 'Возврат выполнен',
            $type === Refund::class && $status === Refund::REJECTED => 'Возврат отклонён',
            $type === Refund::class && $status === Refund::CANCELLED => 'Заявка на возврат отменена',
            array_key_exists('manager_id', $after) => 'Изменён ответственный',
            $type === Client::class && array_intersect(['contacts', 'phone', 'email'], array_keys($after)) !== [] => 'Изменены контактные данные клиента',
            default => 'Изменена запись',
        };
    }

    private static function itemTotal(mixed $items): ?int
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return null;
        }
        $total = 0;
        foreach ($items as $item) {
            if (! is_array($item) || ! is_numeric($item['quantity'] ?? null) || ! is_numeric($item['price_minor'] ?? null) || ! ctype_digit((string) $item['quantity']) || ! ctype_digit((string) $item['price_minor'])) {
                return null;
            }
            $total += (int) $item['quantity'] * (int) $item['price_minor'];
            if (! is_int($total)) {
                return null;
            }
        }

        return $total;
    }

    public static function formatList(string $key, mixed $value): string
    {
        $list = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($list) || ! array_is_list($list)) {
            return 'Подробности недоступны';
        }
        $lines = [];
        foreach ($list as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $parts = [];
            foreach ($key === 'contacts' ? ['name', 'phone', 'email'] : ['name', 'quantity', 'price_minor'] as $part) {
                $v = $entry[$part] ?? null;
                if (! is_scalar($v) || $v === '') {
                    continue;
                }
                $parts[] = match ($part) {
                    'quantity' => $v.' шт.', 'price_minor' => is_numeric($v) ? Money::display((int) $v) : 'Цена не указана',
                    default => (string) $v,
                };
            }
            if ($parts) {
                $lines[] = implode(' · ', $parts);
            }
        }
        if ($key === 'items' && ($total = self::itemTotal($list)) !== null) {
            $lines[] = 'Стоимость: '.Money::display($total);
        }

        return $lines ? implode("\n", $lines) : 'Подробности недоступны';
    }

    private static function value(string $type, string $key, mixed $value, array $references, mixed $currency): string
    {
        if ($value === null || $value === '' || $value === []) {
            return 'Не указано';
        }
        if (in_array($key, ['contacts', 'items'], true)) {
            return self::formatList($key, $value);
        }
        if (! is_scalar($value)) {
            return 'Подробности недоступны';
        }
        if (isset($references[$key])) {
            $ref = $references[$key][$value] ?? null;

            return $ref ? ($ref->name ?? $ref->title ?? self::TYPES[$ref::class].' № '.$ref->id) : 'Объект недоступен';
        }
        if ($key === 'status') {
            $labels = match ($type) {
                Deal::class => CrmForms::dealStatuses(), Task::class => CrmForms::taskStatuses(),
                Order::class => CrmForms::orderStatuses(), CashReceipt::class => ['pending' => 'У сотрудника', 'accepted' => 'Принято кассиром', 'cancelled' => 'Отменено', 'reversed' => 'Сторнировано'],
                Refund::class => RefundResource::STATUSES,
                default => [],
            };

            return $labels[$value] ?? 'Статус не распознан';
        }
        if ($key === 'role') {
            return User::ROLE_LABELS[$value] ?? 'Роль не указана';
        }
        if ($key === 'method' && in_array($type, [Refund::class, CommissionSettlement::class], true)) {
            return ['cash' => 'Наличные', 'bank_transfer' => 'Безналичный перевод'][$value] ?? 'Способ не указан';
        }
        if ($key === 'kind' && $type === CommissionSettlement::class) {
            return $value === CommissionSettlement::WRITE_OFF ? 'Списание' : 'Погашение';
        }
        if (str_ends_with($key, '_minor')) {
            if (! is_numeric($value)) {
                return 'Сумма не указана';
            }

            return is_string($currency) && $currency !== 'UZS'
                ? Money::format((int) $value).' '.$currency : Money::display((int) $value);
        }
        if (str_ends_with($key, '_at') || $key === 'settled_on') {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:$|[T ])/D', $value)) {
                return 'Дата не распознана';
            }
            try {
                return CarbonImmutable::parse($value, 'Asia/Tashkent')->timezone('Asia/Tashkent')->format($type === Task::class ? 'd.m.Y H:i' : 'd.m.Y');
            } catch (\Exception) {
                return 'Дата не распознана';
            }
        }

        return (string) $value;
    }
}
