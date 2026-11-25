<?php

use App\Http\Controllers\Api\V1\Admin\CountryController as AdminCountryController;
use App\Http\Controllers\Api\V1\Admin\RegionController as AdminRegionController;
use App\Http\Controllers\Api\V1\Admin\RegionAccessPolicyController;
use App\Http\Controllers\Api\V1\Meta\CountryController as MetaCountryController;
use App\Http\Controllers\Api\V1\Meta\LanguageController as MetaLanguageController;
use Illuminate\Support\Facades\Route;

Route::prefix('meta')->group(function (): void {
    Route::get('/countries', [MetaCountryController::class, 'index']);
    Route::get('/countries/{country}/regions', [MetaCountryController::class, 'regions']);
    Route::get('/languages', [MetaLanguageController::class, 'index']);
});

// كل عمليات الإدارة محمية بالمصادقة والتحقق من الجلسة.
Route::middleware(['auth:sanctum', 'session.valid'])->prefix('admin')->group(function (): void {
    Route::get('/countries', [AdminCountryController::class, 'index']);
    Route::post('/countries', [AdminCountryController::class, 'store']);
    Route::get('/countries/{country}', [AdminCountryController::class, 'show']);
    Route::patch('/countries/{country}', [AdminCountryController::class, 'update']);
    Route::delete('/countries/{country}', [AdminCountryController::class, 'destroy']);

    Route::get('/regions', [AdminRegionController::class, 'index']);
    Route::post('/regions', [AdminRegionController::class, 'store']);
    Route::get('/regions/{region}', [AdminRegionController::class, 'show']);
    Route::patch('/regions/{region}', [AdminRegionController::class, 'update']);
    Route::delete('/regions/{region}', [AdminRegionController::class, 'destroy']);

    Route::get('/region-policies', [RegionAccessPolicyController::class, 'index']);
    Route::post('/region-policies', [RegionAccessPolicyController::class, 'store']);
    Route::patch('/region-policies/{policy}', [RegionAccessPolicyController::class, 'update']);
    Route::delete('/region-policies/{policy}', [RegionAccessPolicyController::class, 'destroy']);
});
