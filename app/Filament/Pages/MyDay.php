<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Task;
use App\Services\ClientDirectory;
use App\Services\CrmAccess;
use App\Services\CrmWriter;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class MyDay extends Page
{
    protected static ?string $title = 'Мой день';

    protected static string|\UnitEnum|null $navigationGroup = 'Главная';

    protected static ?int $navigationSort = -20;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected string $view = 'filament.pages.my-day';

    public static function canAccess(): bool
    {
        return About::canAccess() && TaskResource::canViewAny();
    }

    public function complete(int $id): void
    {
        abort_unless(static::canAccess() && ! session()->has('crm_preview'), 403);
        DB::transaction(function () use ($id) {
            $task = CrmAccess::query(Task::class)->where('manager_id', auth()->id())->lockForUpdate()->findOrFail($id);
            if (! in_array($task->status, ['done', 'cancelled'], true)) {
                app(CrmWriter::class)->save(Task::class, ['status' => 'done'], $task);
            }
        });
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $now = now('Asia/Tashkent');
        $tasks = CrmAccess::query(Task::class)->where('manager_id', auth()->id())
            ->whereNotIn('status', ['done', 'cancelled']);
        $rows = (clone $tasks)->select(['id', 'title', 'status', 'due_at', 'client_id', 'deal_id', 'order_id'])
            ->addSelect([
                'client_name' => CrmAccess::query(Client::class)->select('name')->whereColumn('clients.id', 'tasks.client_id'),
                'deal_title' => CrmAccess::query(Deal::class)->select('title')->whereColumn('deals.id', 'tasks.deal_id'),
                'visible_order' => CrmAccess::query(Order::class)->select('id')->whereColumn('orders.id', 'tasks.order_id'),
            ])->orderBy('due_at')->orderBy('id')->limit(8);
        $orders = CrmAccess::query(Order::class)->where('manager_id', auth()->id())
            ->whereNotIn('status', ['delivered', 'cancelled'])->select(['id', 'status', 'due_at'])
            ->addSelect([
                'client_name' => ClientDirectory::visible()->select('name')->whereColumn('clients.id', 'orders.client_id'),
                'product' => OrderItem::select('name')->whereColumn('order_id', 'orders.id')->orderBy('id')->limit(1),
            ])->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderBy('id')->limit(8)->get();
        $deals = null;
        if (DealResource::canViewAny()) {
            $action = (clone $tasks)->whereColumn('tasks.deal_id', 'deals.id')->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderBy('id')->limit(1);
            $deals = CrmAccess::query(Deal::class)->whereIn('id', (clone $tasks)->select('deal_id'))
                ->select(['id', 'title', 'status'])->addSelect([
                    'client_name' => ClientDirectory::visible()->select('name')->whereColumn('clients.id', 'deals.client_id'),
                    'action_title' => (clone $action)->select('title'),
                    'action_due' => (clone $action)->select('due_at'),
                ])->orderByRaw('action_due IS NULL')->orderBy('action_due')->orderBy('id')->limit(8)->get();
        }

        return [
            'today' => (clone $rows)->whereBetween('due_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->get(),
            'late' => (clone $rows)->where('due_at', '<', $now)->get(),
            'delegated' => auth()->user()->role === 'manager' && Task::supportsDelegation()
                ? CrmAccess::query(Task::class)->where('created_by', auth()->id())->where('manager_id', '!=', auth()->id())
                    ->with('manager:id,name')->latest('updated_at')->orderByDesc('id')->limit(8)->get()
                : null,
            'upcoming' => auth()->user()->role === 'designer'
                ? (clone $rows)->where('due_at', '>', $now->copy()->endOfDay())->get()
                : null,
            'orders' => $orders, 'deals' => $deals,
            'resources' => array_filter(['задачу' => TaskResource::class, 'клиента' => ClientResource::class, 'сделку' => DealResource::class, 'заказ' => OrderResource::class], fn ($resource) => $resource::canViewAny()),
        ];
    }
}
