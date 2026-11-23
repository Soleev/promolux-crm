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
        // Pulse registers its own default gate during provider boot. Override
        // it after ALL providers boot, including in local and test environments.
        $this->app->booted(function (): void {
            Gate::define('viewPulse', fn (?User $user): bool => $user?->active === true && $user->role === 'owner');
        });

        foreach ([Client::class, Deal::class, Order::class, Task::class, Payment::class, Accrual::class, Payout::class, Expense::class, Activity::class, User::class] as $model) {
            Gate::policy($model, CrmPolicy::class);
        }
    }
}
