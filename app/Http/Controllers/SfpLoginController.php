<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SfpLoginController
{
    public function show(Request $request)
    {
        if ($user = $request->user()) {
            abort_unless($user->role === 'sfp_screen', 403);
            if ($user->active) {
                return redirect()->route('sfp.screen');
            }
            $this->endSession($request);
        }

        return response()->view('sfp.login')->header('Cache-Control', 'no-store, private');
    }

    public function login(Request $request)
    {
        abort_if($request->user() !== null, 403);
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        if (! Auth::guard('web')->attempt([
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'sfp_screen',
            'active' => true,
        ], false)) {
            throw ValidationException::withMessages(['email' => 'Неверные данные входа или доступ отключён.']);
        }

        $request->session()->regenerate();
        $request->session()->put('sfp_monitor_session', true);

        return redirect()->route('sfp.screen');
    }

    public function logout(Request $request)
    {
        abort_unless($request->user()?->role === 'sfp_screen', 403);
        $this->endSession($request);

        return redirect()->route('sfp.login');
    }

    private function endSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
