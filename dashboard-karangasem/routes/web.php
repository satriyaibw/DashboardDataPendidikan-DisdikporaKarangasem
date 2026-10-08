<?php

use App\Http\Controllers\VipLoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', [VipLoginController::class, 'show'])->name('login');
Route::post('/vip/login', [VipLoginController::class, 'login'])->name('vip.login')->middleware('throttle:6,1');
Route::post('/vip/logout', [VipLoginController::class, 'logout'])->name('vip.logout');

Route::middleware(['auth', 'role:vip', 'check.vip'])->group(function () {
    Route::get('/vip/dashboard', fn () => view('vip.dashboard'))->name('vip.dashboard');
});
