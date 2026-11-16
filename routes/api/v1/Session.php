<?php

use App\Http\Controllers\Api\V1\Me\SessionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.valid'])->prefix('me/sessions')->group(function () {
    Route::get('/', [SessionController::class, 'index']);
    Route::delete('/others', [SessionController::class, 'destroyOthers']);
    Route::delete('/{session}', [SessionController::class, 'destroy']);
});
