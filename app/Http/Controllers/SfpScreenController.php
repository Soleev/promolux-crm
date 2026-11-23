<?php

namespace App\Http\Controllers;

use App\Filament\CrmForms;
use App\Models\Order;
use App\Services\CrmAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SfpScreenController
{
    public function __invoke(Request $request)
    {
        try {
            return $this->screen($request);
        } catch (ModelNotFoundException $exception) {
            return response('Заказ недоступен. Вернитесь к очереди производства.', 404)->header('Cache-Control', 'no-store, private');
        } catch (HttpExceptionInterface $exception) {
            return response('Просмотр недоступен.', $exception->getStatusCode())->header('Cache-Control', 'no-store, private');
        } catch (\Throwable $exception) {
            report($exception);

            return response('Экран временно недоступен. Повторите открытие позже.', 503)->header('Cache-Control', 'no-store, private');
        }
    }

    private function screen(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->active && in_array($user->role, ['owner', 'rop', 'manager', 'designer'], true), 403);
        $today = now('Asia/Tashkent')->toDateString();
        $base = CrmAccess::query(Order::class, $user)->whereIn('status', ['new', 'design', 'production']);
        $ordered = fn () => (clone $base)->with(['client:id,name', 'items'])->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderBy('id');
        $mode = $request->query('view') === 'queue' ? 'queue' : 'home';
        $detail = null;
        if ($request->filled('order')) {
            $detail = $ordered()->findOrFail($request->integer('order'));
            $mode = 'detail';
        }
        $data = [
            'mode' => $mode, 'detail' => $detail, 'today' => $today,
            'total' => (clone $base)->count(),
            'working' => (clone $base)->where('status', 'production')->count(),
            'dueToday' => (clone $base)->whereDate('due_at', $today)->count(),
            'overdue' => (clone $base)->whereDate('due_at', '<', $today)->count(),
            'queue' => $mode === 'queue' ? $ordered()->paginate(20)->appends(['view' => 'queue']) : $ordered()->limit(3)->get(),
            'production' => $ordered()->where('status', 'production')->limit(3)->get(),
            'urgent' => $ordered()->whereDate('due_at', '<=', $today)->limit(3)->get(),
            'statuses' => CrmForms::orderStatuses(),
        ];

        return response()->view($request->boolean('fragment') ? 'sfp.content' : 'sfp.screen', $data)
            ->header('Cache-Control', 'no-store, private');
    }
}
