<?php

use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// B4 — انتهاء الأدوار: الإسناد الذي تجاوز expires_at لا يُحسب أصلًا (AccessResolver)،
// وهذه المهمة تحذفه فعليًا وتسجّل ذلك في activity_log (log_name = rbac).
Artisan::command('rbac:prune-expired-roles', function (RoleAssignmentService $service) {
    $this->info('Expired role assignments removed: '.$service->pruneExpired());
})->purpose('Remove expired role assignments and log them');

Schedule::command('rbac:prune-expired-roles')->hourly()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
