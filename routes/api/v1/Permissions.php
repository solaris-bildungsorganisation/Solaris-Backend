<?php

use App\Http\Controllers\Api\V1\Me\PermissionController;
use App\Http\Controllers\Api\V1\Me\ScopeController;
use Illuminate\Support\Facades\Route;

// B4 — الصلاحيات الفعلية واختيار النطاق الفعّال
Route::middleware(['auth:sanctum', 'session.valid'])->prefix('me')->group(function () {
    Route::get('permissions', PermissionController::class)->name('me.permissions');
    Route::get('scopes', [ScopeController::class, 'index'])->name('me.scopes');
    Route::post('scope', [ScopeController::class, 'store'])->name('me.scope.switch');
});
