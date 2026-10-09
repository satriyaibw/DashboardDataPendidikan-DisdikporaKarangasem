<?php

use App\Http\Controllers\PublicController;
use App\Http\Controllers\VipDashboardController;
use App\Http\Controllers\VipLoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicController::class, 'index'])
    ->name('public.dashboard')
    ->middleware('metabase.csp');

Route::get('/login', [VipLoginController::class, 'show'])->name('login');
Route::post('/vip/login', [VipLoginController::class, 'login'])->name('vip.login');
Route::post('/vip/logout', [VipLoginController::class, 'logout'])->name('vip.logout');

Route::middleware(['auth', 'role:vip', 'check.vip', 'metabase.csp'])->group(function () {
    Route::get('/vip/dashboard', [VipDashboardController::class, 'index'])->name('vip.dashboard');
    Route::get('/vip/embed-url', [VipDashboardController::class, 'embedUrl'])->name('vip.embed-url');
});
