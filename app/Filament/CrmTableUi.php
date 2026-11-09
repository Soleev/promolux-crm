<?php

namespace App\Filament;

use App\Models\Accrual;
use App\Models\Activity;
use App\Models\CashReceipt;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CrmTableUi
{
    public static function labels(string $model): array
    {
        return match ($model) {
            Client::class => ['Клиентов пока нет', 'Добавьте первого клиента, чтобы начать работу.', 'Добавить клиента'],
            Deal::class => ['Сделок пока нет', 'Сделка — обсуждение будущего заказа. Создайте первую сделку.', 'Создать сделку'],
            Order::class => ['Заказов пока нет', 'Заказ — согласованная работа с составом, сроком и этапом производства.', 'Создать заказ'],
            Task::class => ['Задач пока нет', 'Запланируйте звонок, встречу или другую работу и укажите срок.', 'Создать задачу'],
            Payment::class => ['Оплат пока нет', 'Здесь появляются подтверждённые поступления в SFP. Наличные до приёма кассиром смотрите в разделе «Передача наличных».', 'Записать перечисление в SFP'],
            CashReceipt::class => ['Наличных пока нет', 'Сотрудник записывает деньги от клиента, а кассир подтверждает фактический приём в SFP.', 'Записать наличные от клиента'],
            Accrual::class => ['Начислений пока нет', 'Начисления появляются после подтверждённой оплаты в SFP, если правила распределения определены.', ''],
            Payout::class => ['Выплат пока нет', 'Выплата погашает ранее начисленное вознаграждение. Начисление само по себе не означает выплату.', 'Записать выплату'],
            Expense::class => ['Расходов пока нет', 'Укажите расход и его плательщика: SFP или Promolux.', 'Добавить расход'],
            User::class => ['Сотрудников в этом списке нет', 'Здесь отображаются сотрудники, доступные по вашей роли.', 'Добавить сотрудника'],
            Activity::class => ['Событий пока нет', 'История появится после работы с записями CRM.', ''],
        };
    }

    public static function hint(string $model): string
    {
        return match ($model) {
            Deal::class => 'Сделка — обсуждение продажи. После согласования её можно один раз преобразовать в заказ.',
            Order::class => 'Заказ — согласованная работа. Производство и оплата имеют отдельные статусы. Оплата заказа включает наличные у сотрудника; поступление в SFP — только после кассира.',
            Task::class => 'Просрочена задача, срок которой прошёл, а статус ещё не «Выполнена» или «Отменена». Время — Ташкент.',
            Payment::class => 'Подтверждённая оплата — поступление в SFP. «Требует уточнения» означает, что оплата учтена, но распределение комиссии ещё не определено.',
            Accrual::class => 'Начислено — обязательство по вознаграждению. Остаток — непогашенная часть. Фактические перечисления отражены в разделе «Выплаты».',
            Payout::class => 'Выплата — фактически переданные деньги, распределённые по начислениям. Частичная выплата оставляет непогашенный остаток.',
            CashReceipt::class => 'Кассир представляет SFP. До подтверждения наличные числятся у сотрудника; приём кассиром создаёт поступление в SFP один раз.',
            Expense::class => 'Расходы SFP и Promolux учитываются отдельно. Расходы SFP не уменьшают начисленные комиссии.',
            Client::class => 'Клиент объединяет контакты, сделки, заказы и задачи. В «Подробнее» доступны связанные записи и история.',
            User::class => 'Роль определяет доступ к CRM. Отключение доступа сохраняет историю сотрудника.',
            Activity::class => 'Журнал сохраняет автора и историю изменений. Исходные события не редактируются.',
        };
    }

    public static function color(?string $state): string
    {
        return match ($state) {
            'won', 'ready', 'delivered', 'done', 'calculated', 'accepted', 'Оплачен' => 'success',
            'lost', 'cancelled', 'reversed' => 'gray',
            'production', 'in_progress', 'proposal', 'Частично оплачен' => 'info',
            'needs_clarification', 'pending', 'Не оплачен', 'Переплата' => 'warning',
            default => 'primary',
        };
    }

    public static function configure(Table $table, string $model, ?Action $create, Closure $canCreate): Table
    {
        [$heading, $description, $label] = self::labels($model);
        $filtered = fn (): bool => $table->hasSearch() || $table->isFiltered();
        $actions = [Action::make('clearSelection')->label('Сбросить фильтры и поиск')->color('gray')
            ->visible($filtered)->action(function () use ($table): void {
                $page = $table->getLivewire();
                $page->removeTableFilters();
                $page->resetTableSearch();
                $page->resetTableColumnSearches();
            })];
        if ($create && $label !== '') {
            $actions[] = $create->name('createFromEmpty')->label($label)->authorize($canCreate)
                ->visible(fn (): bool => ! $filtered() && ! request()->attributes->get('preview_applied') && $canCreate());
        }

        return $table->identifier(fn (): string => auth()->id().':'.auth()->user()->role)
            ->description(self::hint($model))->striped()->paginated([10, 25, 50])
            ->searchPlaceholder('Поиск по списку')->persistFiltersInSession()->persistSearchInSession()->persistSortInSession()
            ->emptyStateIcon('heroicon-o-inbox')
            ->emptyStateHeading(fn (): string => $filtered() ? 'По вашему запросу ничего не найдено' : $heading)
            ->emptyStateDescription(fn (): string => $filtered() ? 'Измените условия или сбросьте фильтры и поиск.' : $description)
            ->emptyStateActions($actions);
    }

    public static function period(string $column = 'paid_at', string $label = 'Период', bool $paymentRelation = false): Filter
    {
        return Filter::make('period')->label($label)->schema([
            DatePicker::make('from')->label('С даты')->native(false)->displayFormat('d.m.Y'),
            DatePicker::make('until')->label('По дату')->native(false)->displayFormat('d.m.Y')->afterOrEqual('from'),
        ])->query(function (Builder $query, array $data) use ($column, $paymentRelation): Builder {
            $apply = fn (Builder $q): Builder => $q
                ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate($column, '>=', $v))
                ->when($data['until'] ?? null, fn ($q, $v) => $q->whereDate($column, '<=', $v));

            return $paymentRelation ? $query->whereHas('payment', $apply) : $apply($query);
        })->indicateUsing(function (array $data) use ($label): array {
            $indicators = [];
            foreach (['from' => 'с', 'until' => 'по'] as $key => $word) {
                if (! empty($data[$key])) {
                    $indicators[] = $label.' '.$word.' '.CarbonImmutable::parse($data[$key])->format('d.m.Y');
                }
            }

            return $indicators;
        });
    }
}
