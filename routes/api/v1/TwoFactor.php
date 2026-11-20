<?php

use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\Me\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
| K1 — التحقق الثنائي (TOTP): التفعيل والإدارة · NFR-SEC-002 · D8
| K2 — تحدّي الدخول + التحقق الإضافي (step-up) · FR-AUTH-009, 025, 028
| المسارات التي تتحقق من كلمة مرور أو رمز محدودة بـ 5 محاولات في الدقيقة.
*/
Route::middleware(['auth:sanctum', 'session.valid'])->prefix('me/2fa')->group(function () {
    Route::get('/', [TwoFactorController::class, 'show']);

    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/enable', [TwoFactorController::class, 'enable']);
        Route::post('/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        Route::post('/verify', [TwoFactorController::class, 'verify']);
        Route::delete('/', [TwoFactorController::class, 'destroy']);
    });
});

// K2: مسار عام (بلا توكن) يكمل الدخول بعد /auth/login عندما يكون 2FA مفعّلًا.
Route::post('auth/2fa/challenge', TwoFactorChallengeController::class)
    ->middleware('throttle:5,1');
