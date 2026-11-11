<?php

use App\HandoffApi;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->controller(HandoffApi::class)->group(function () {
    Route::middleware('throttle:12,1')->group(function () {
        Route::post('auth/register', 'register');
        Route::post('auth/login', 'login');
    });
    Route::middleware(['auth:sanctum','throttle:120,1'])->group(function () {
        Route::post('auth/logout', 'logout');
        Route::get('families', 'families');
        Route::post('families', 'createFamily');
        Route::post('invites/redeem', 'redeem');
        Route::prefix('families/{family}')->where(['family'=>'[0-9a-fA-F-]{36}'])->group(function () {
            Route::get('status', 'status');
            Route::post('billing/test-activate', 'testActivate');
            Route::post('invites', 'invite');
            Route::post('sync', 'sync');
            Route::get('handoffs/current', 'currentHandoff');
            Route::post('handoffs', 'sendHandoff');
            Route::post('handoffs/{handoff}/accept', 'acceptHandoff')->whereUuid('handoff');
        });
    });
});
