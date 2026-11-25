<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', RegisterController::class)
        ->middleware('throttle:auth-register');

    Route::post('/login', LoginController::class)
        ->middleware('throttle:login-auth');

    Route::post('/password/forgot', PasswordResetController::class)
        ->middleware('throttle:code-auth');

    Route::post('/password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:code-auth');
    Route::post('/logout', LogoutController::class)
        ->middleware('auth:sanctum');
});
