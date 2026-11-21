<?php

use App\Http\Controllers\Api\V1\Me\NotificationPreferenceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.valid'])->prefix('me/notification-preferences')->group(function () {
    Route::get('/', [NotificationPreferenceController::class, 'index']);
    Route::put('/', [NotificationPreferenceController::class, 'update']);
});
