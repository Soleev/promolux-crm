<?php

namespace App\Providers;

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
use App\Policies\CrmPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Client::class, Deal::class, Order::class, Task::class, Payment::class, Accrual::class, Payout::class, Expense::class, Activity::class, User::class] as $model) {
            Gate::policy($model, CrmPolicy::class);
        }
    }
}
