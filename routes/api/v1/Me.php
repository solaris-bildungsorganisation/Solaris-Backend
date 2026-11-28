<?php

use App\Http\Controllers\Api\V1\Me\PasswordController;
use Illuminate\Support\Facades\Route;

Route::prefix('me')->middleware('auth:sanctum')->group(function () {
    Route::put('/password', PasswordController::class);
});
