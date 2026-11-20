<?php

use App\Http\Controllers\Api\V1\Admin\NotificationDeliveryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.valid'])
    ->prefix('admin/notification-deliveries')
    ->group(function () {
        Route::get('/', [NotificationDeliveryController::class, 'index'])
            ->name('admin.notification-deliveries.index');

        Route::get('/{delivery}', [NotificationDeliveryController::class, 'show'])
            ->name('admin.notification-deliveries.show');
    });
