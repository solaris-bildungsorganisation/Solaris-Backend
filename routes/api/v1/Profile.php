<?php

use App\Http\Controllers\Api\V1\Me\AccountTypeRequestController;
use App\Http\Controllers\Api\V1\Me\LanguageController;
use App\Http\Controllers\Api\V1\Me\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me', [ProfileController::class, 'show']);
    Route::patch('/me', [ProfileController::class, 'update']);

    Route::post('/me/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::delete('/me/avatar', [ProfileController::class, 'deleteAvatar']);

    Route::get('/me/languages', [LanguageController::class, 'index']);
    Route::post('/me/languages', [LanguageController::class, 'store']);
    Route::patch('/me/languages/{userLanguage}', [LanguageController::class, 'update']);
    Route::delete('/me/languages/{userLanguage}', [LanguageController::class, 'destroy']);

    Route::get('/me/account-type-requests', [ProfileController::class, 'accountTypeRequests']);
    Route::delete('/me/account-type-requests/{uuid}', [ProfileController::class, 'cancelAccountTypeRequest']);

    Route::get('/me/account-type-requests', [AccountTypeRequestController::class, 'index']);
    Route::delete('/me/account-type-requests/{uuid}', [AccountTypeRequestController::class, 'destroy']);
});
