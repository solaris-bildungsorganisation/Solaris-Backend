<?php

use App\Http\Controllers\Api\V1\Admin\OrganizationStatusController;
use App\Http\Controllers\Api\V1\Admin\OrganizationVerificationController;
use App\Http\Controllers\Api\V1\Org\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
    Route::patch('/organizations/{organization}', [OrganizationController::class, 'update']);
    Route::get('/me/organizations', [OrganizationController::class, 'mine']);

    Route::patch('/admin/organizations/{organization}/status', OrganizationStatusController::class);
    Route::post('/admin/organizations/{organization}/verify', OrganizationVerificationController::class);
});
