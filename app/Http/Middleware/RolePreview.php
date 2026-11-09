<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RolePreview
{
    public function handle(Request $request, Closure $next): Response
    {
        $preview = $request->session()->get('crm_preview');
        if (! $preview || $request->attributes->get('preview_applied') || $request->routeIs('crm.preview.stop', 'filament.admin.auth.logout')) {
            return $next($request);
        }

        $owner = $request->user();
        if (! $owner?->active || $owner->role !== 'owner' || $owner->id !== $preview['owner']) {
            $request->session()->forget('crm_preview');

            return $next($request);
        }
        abort_unless($request->isMethodSafe(), 403, 'Просмотр роли доступен только для чтения.');
        $role = $preview['role'];
        if ($preview['employee']) {
            $viewer = User::where('active', true)->where('role', $role)->find($preview['employee']);
            if (! $viewer) {
                $request->session()->forget('crm_preview');

                return redirect()->route('filament.admin.home');
            }
        } else {
            // A role without an employee has no personally assigned records.
            $viewer = new User(['name' => User::ROLE_LABELS[$role], 'role' => $role, 'active' => true]);
            $viewer->id = -1;
        }
        $request->attributes->set('preview_applied', true);
        Auth::guard('web')->setUser($viewer);
        try {
            return $next($request);
        } finally {
            Auth::guard('web')->setUser($owner);
        }
    }
}
