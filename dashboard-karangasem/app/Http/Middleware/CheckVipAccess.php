<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckVipAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Silakan login terlebih dahulu.']);
        }

        if (! $user->hasRole('vip')) {
            return $this->logoutAndRedirect($request, 'Akun Anda tidak memiliki akses VIP.');
        }

        if ($user->is_active !== true) {
            return $this->logoutAndRedirect($request, 'Akun Anda telah dinonaktifkan.');
        }

        if ($user->expires_at !== null && $user->expires_at->isPast()) {
            return $this->logoutAndRedirect($request, 'Masa aktif akun VIP Anda telah berakhir.');
        }

        return $next($request);
    }

    protected function logoutAndRedirect(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
