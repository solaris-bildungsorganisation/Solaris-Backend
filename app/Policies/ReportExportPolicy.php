<?php

namespace App\Policies;

use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * تصدير التقارير — FR-ADMIN-010, 012.
 *  - reports.export (sensitive + MFA) لتقارير النطاق، و users.export (G فقط + MFA) لبيانات المستخدمين.
 *  - التصدير لا يتجاوز نطاق المُصدِّر (ScopeGuard يفحص organization_id / filters).
 */
class ReportExportPolicy
{
    public function viewAny(User $user): Response
    {
        return ScopeGuard::authorize($user, 'reports.view');
    }

    public function view(User $user, ReportExport $export): Response
    {
        if ($export->user_id === $user->getKey()) {
            return Response::allow();
        }

        return ScopeGuard::authorize($user, 'reports.view', $export);
    }

    /** $reportKey = 'users' يتطلب users.export، غيره reports.export. */
    public function create(User $user, ?string $reportKey = null): Response
    {
        return ScopeGuard::authorize($user, $reportKey === 'users' ? 'users.export' : 'reports.export');
    }

    public function update(User $user, ReportExport $export): Response
    {
        return Response::deny();
    }

    /** حذف/إلغاء التصدير من صاحبه فقط */
    public function delete(User $user, ReportExport $export): Response
    {
        return $export->user_id === $user->getKey() ? Response::allow() : Response::deny();
    }

    public function restore(User $user, ReportExport $export): Response
    {
        return Response::deny();
    }

    public function forceDelete(User $user, ReportExport $export): Response
    {
        return Response::deny();
    }
}
