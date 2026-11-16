<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\CashReceipts\CashReceiptResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class Help extends Page
{
    protected static ?string $title = 'Помощь и обучение';

    protected static string|\UnitEnum|null $navigationGroup = 'Справка';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected string $view = 'filament.pages.help';

    public const TOPICS = [
        'my-day' => MyDay::class, 'clients' => ClientResource::class, 'deals' => DealResource::class,
        'orders' => OrderResource::class, 'tasks' => TaskResource::class, 'payments' => PaymentResource::class,
        'cash' => CashReceiptResource::class, 'debts' => ClientDebts::class, 'history' => ActivityResource::class,
        'team' => UserResource::class,
    ];

    public static function canAccess(): bool
    {
        return About::canAccess();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $query = request()->string('q')->trim()->limit(160, '')->toString();
        $articles = [];
        foreach (self::TOPICS as $key => $destination) {
            if (! $destination::canAccess()) {
                continue;
            }
            [$heading,$steps] = config('help.'.$key);
            if ($key === 'cash') {
                $steps[] = auth()->user()->role === 'cashier'
                    ? 'Получите наличные от сотрудника, сверьте сумму и заказ. Нажмите «Подтвердить приём» и укажите дату фактической передачи.'
                    : 'Нажмите «Записать наличные от клиента». Выберите заказ, укажите сумму и дату. Передайте деньги кассиру и проверьте статус записи.';
            }
            if ($key === 'payments' && PaymentResource::canCreate()) {
                $steps[] = 'Для безналичной оплаты нажмите «Записать перечисление в SFP», выберите заказ и укажите фактическую сумму и дату.';
            }
            if ($key === 'team' && auth()->user()->role === 'rop') {
                $steps[] = 'РОП управляет учётными записями менеджеров.';
            }
            if ($query === '' || Str::contains($heading.' '.implode(' ', $steps), $query, ignoreCase: true)) {
                $articles[$key] = ['title' => $heading, 'steps' => $steps, 'url' => $destination::getUrl()];
            }
        }

        return compact('query', 'articles');
    }
}
