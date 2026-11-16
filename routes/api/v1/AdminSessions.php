<?php

use App\Http\Controllers\Api\V1\Admin\UserSessionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.valid'])
    ->prefix('admin/users/{user}/sessions')
    ->group(function () {
        Route::get('/', [UserSessionController::class, 'index']);
        Route::delete('/', [UserSessionController::class, 'destroy'])
            ->middleware('mfa.confirmed');
    });
