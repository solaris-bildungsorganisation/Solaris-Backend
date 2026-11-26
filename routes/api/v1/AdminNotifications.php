<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\NotificationTemplateController;
use App\Http\Controllers\Api\V1\Admin\NotificationTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.valid'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/notification-types', [NotificationTypeController::class, 'index']);
        Route::patch('/notification-types/{type}', [NotificationTypeController::class, 'update']);

        Route::get('/notification-templates', [NotificationTemplateController::class, 'index']);
        Route::post('/notification-templates', [NotificationTemplateController::class, 'store']);
        Route::patch('/notification-templates/{template}', [NotificationTemplateController::class, 'update']);
        Route::post('/notification-templates/{template}/preview', [NotificationTemplateController::class, 'preview']);
    });
