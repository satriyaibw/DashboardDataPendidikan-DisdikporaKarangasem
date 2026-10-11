<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class VipLoginController extends Controller
{
    public function show()
    {
        return view('vip.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan login. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                'email' => 'Kredensial tidak valid.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user = Auth::user();

        if (! $user->hasRole('vip') || $user->is_active !== true || ($user->expires_at !== null && $user->expires_at->isPast())) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'Akun Anda tidak memiliki akses VIP yang aktif.',
            ]);
        }

        return redirect()->to($this->intendedVipUrl($request));
    }

    /**
     * Tentukan tujuan setelah login VIP.
     *
     * `redirect()->intended()` memakai URL yang disimpan middleware `Auth`
     * saat pengguna ditolak. Nilai itu bisa berasal dari area mana pun — paling
     * sering `/admin`, panel Filament yang hanya untuk role `admin`. Akun VIP
     * yang dikirim ke sana berakhir di 403: panel menolak role-nya, dan
     * pengguna terjebak karena tombol logout panel tidak bisa dijangkau.
     *
     * Karena itu tujuan hanya dipakai bila benar-benar berada di area VIP.
     * Selebihnya dialihkan ke `/vip/dashboard`, satu-satunya halaman yang
     * memang boleh dibuka role `vip`.
     */
    protected function intendedVipUrl(Request $request): string
    {
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || $intended === '') {
            return route('vip.dashboard');
        }

        $path = parse_url($intended, PHP_URL_PATH);

        if (! is_string($path) || ! str_starts_with($path, '/vip/')) {
            return route('vip.dashboard');
        }

        // URL dari luar situs ditolak, bukan hanya diambil path-nya: nilai sesi
        // adalah input eksternal dan tetap harus divalidasi. `url.intended`
        // disimpan middleware Auth dari request sebenarnya, tapi tidak
        // diperlakukan sebagai data tepercaya.
        $parts = parse_url($intended);
        $host = $parts['host'] ?? null;
        $scheme = $parts['scheme'] ?? null;

        if ($host !== null && $host !== $request->getHost()) {
            return route('vip.dashboard');
        }

        $query = parse_url($intended, PHP_URL_QUERY);

        return $query ? $path.'?'.$query : $path;
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
