<?php

use App\Http\Controllers\Api\V1\Admin\UserController;
use Illuminate\Support\Facades\Route;


Route::middleware(['auth:sanctum', 'session.valid'])
    ->prefix('admin/users')
    ->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('admin.users.index');
        Route::get('/{user}', [UserController::class, 'show'])->name('admin.users.show');
        Route::post('/', [UserController::class, 'store'])->name('admin.users.store');
        Route::patch('/{user}', [UserController::class, 'update'])->name('admin.users.update');
    });
