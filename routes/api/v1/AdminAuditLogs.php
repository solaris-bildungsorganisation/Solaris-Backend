<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
Route::middleware(['auth:sanctum','can:audit_log.view'])->group(function () {


    Route::get('/admin/audit-logs',[AuditLogController::class, 'index']);
    Route::get('/admin/audit-logs/{activity}',[AuditLogController::class, 'show']);
});
