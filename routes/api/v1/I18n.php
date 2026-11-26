<?php

use App\Http\Controllers\Api\V1\Admin\LanguageController as AdminLanguageController;
use App\Http\Controllers\Api\V1\Admin\TranslationController;
use App\Http\Controllers\Api\V1\Meta\LanguageController as MetaLanguageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.valid'])
    ->prefix('admin')
    ->group(function () {
        Route::get('languages', [AdminLanguageController::class, 'index'])
            ->name('admin.languages.index');
        Route::post('languages', [AdminLanguageController::class, 'store'])
            ->name('admin.languages.store');

        Route::patch('languages/{language}', [AdminLanguageController::class, 'update'])
            ->name('admin.languages.update');

        Route::get('translations', [TranslationController::class, 'index'])
            ->name('admin.translations.index');

        Route::put('translations', [TranslationController::class, 'update'])
            ->name('admin.translations.update');

        Route::delete('translations/{translation}', [TranslationController::class, 'destroy'])
            ->name('admin.translations.destroy');
    });
Route::get('meta/translations/{lang}', [MetaLanguageController::class, 'show'])
    ->name('meta.translations.show');
