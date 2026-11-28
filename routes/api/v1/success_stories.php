<?php

use App\Http\Controllers\Api\V1\Admin\SuccessStoryController as AdminSuccessStoryController;
use App\Http\Controllers\Api\V1\Admin\SuccessStoryWorkflowController;
use App\Http\Controllers\Api\V1\Content\SuccessStoryController as PublicSuccessStoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')
    ->prefix('admin')
    ->group(function (): void {
        Route::apiResource('success-stories', AdminSuccessStoryController::class)
            ->parameters(['success-stories' => 'story']);

        Route::patch(
            'success-stories/{story}/consent',
            [SuccessStoryWorkflowController::class, 'consent'],
        );
        Route::post(
            'success-stories/{story}/submit',
            [SuccessStoryWorkflowController::class, 'submit'],
        );
        Route::post(
            'success-stories/{story}/publish',
            [SuccessStoryWorkflowController::class, 'publish'],
        );
        Route::post(
            'success-stories/{story}/archive',
            [SuccessStoryWorkflowController::class, 'archive'],
        );
    });

// مسارات عامة: لا تستخدم auth:sanctum ولا صلاحية الإدارة.
Route::get('success-stories', [PublicSuccessStoryController::class, 'index']);
Route::get('success-stories/{story}', [PublicSuccessStoryController::class, 'show']);
