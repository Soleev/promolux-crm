<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RestrictSfpMonitor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // An existing monitor session must not become an administrative session after a role change.
        if ($request->session()->get('sfp_monitor_session') && $user?->role !== 'sfp_screen') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response('Сессия SFP завершена. Войдите заново.', 403)->header('Cache-Control', 'no-store, private');
        }

        if ($user?->role === 'sfp_screen' && ! $request->routeIs('sfp.screen', 'sfp.login', 'sfp.login.submit', 'sfp.logout')) {
            return response('Доступ разрешён только к экрану SFP.', 403)->header('Cache-Control', 'no-store, private');
        }

        return $next($request);
    }
}
