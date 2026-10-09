<?php

use App\Http\Controllers\PublicController;
use App\Http\Controllers\VipDashboardController;
use App\Http\Controllers\VipLoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicController::class, 'index'])
    ->middleware(['throttle:60,1', 'metabase.csp'])
    ->name('public.dashboard');

Route::get('/login', [VipLoginController::class, 'show'])->name('login');
Route::post('/vip/login', [VipLoginController::class, 'login'])->name('vip.login');
Route::post('/vip/logout', [VipLoginController::class, 'logout'])->name('vip.logout');

Route::middleware(['auth', 'role:vip', 'check.vip', 'metabase.csp'])->group(function () {
    Route::get('/vip/dashboard', [VipDashboardController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('vip.dashboard');

    Route::get('/vip/embed-url', [VipDashboardController::class, 'embedUrl'])
        ->middleware('throttle:10,1')
        ->name('vip.embed-url');
});
