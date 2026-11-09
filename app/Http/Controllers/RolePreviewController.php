<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RolePreviewController extends Controller
{
    public function start(Request $request): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($owner?->active && $owner->role === 'owner', 403);
        $choice = $request->validate(['view_as' => 'required|string|max:80'])['view_as'];
        $employee = null;
        if (str_starts_with($choice, 'user:')) {
            $employee = User::where('active', true)->where('role', '!=', 'owner')->findOrFail(substr($choice, 5));
        }
        $role = $employee?->role ?? $choice;
        abort_unless(isset(User::ROLE_LABELS[$role]) && $role !== 'owner', 422);
        $request->session()->put('crm_preview', ['owner' => $owner->id, 'role' => $role, 'employee' => $employee?->id]);
        $request->session()->regenerateToken();

        return redirect()->route('filament.admin.home');
    }

    public function stop(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->active && $request->user()->role === 'owner', 403);
        $request->session()->forget('crm_preview');
        $request->session()->regenerateToken();

        return redirect()->route('filament.admin.home');
    }
}
