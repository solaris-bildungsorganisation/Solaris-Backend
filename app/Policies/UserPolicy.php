<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Rbac\AccessResolver;

use Illuminate\Auth\Access\Response;

/**
 * المستخدمون — FR-AUTH-001, 006, 007, 012, 013, 019..023, 030, 036 · FR-ADMIN-012.
 * فلتر النطاق: users.country_id / region_id ضمن الشجرة / عضوية active في المؤسسة (ScopeGuard).
 */
class UserPolicy
{
    public function viewAny(User $user): Response
    {
        return ScopeGuard::authorize($user, 'users.view_any');
    }

    public function view(User $user, User $target): Response
    {
        if ($user->is($target)) {
            return $user->can('profile.view_own') ? Response::allow() : Response::deny();
        }

        return ScopeGuard::authorize($user, 'users.view', $target);
    }

    public function create(User $user): Response
    {
        return ScopeGuard::authorize($user, 'users.create');
    }

    public function update(User $user, User $target): Response
    {
        return ScopeGuard::authorize($user, 'users.update', $target, 'users.view');
    }

    /** تفعيل/تعطيل/حظر — لا على النفس، ولا على رتبة مساوية أو أعلى. */
    public function updateStatus(User $user, User $target): Response
    {
        return $this->sensitiveAction($user, $target, 'users.update_status');
    }

    /** الحذف = تعطيل ناعم (FR-AUTH-007: دون حذف البيانات) — نفس قواعد تغيير الحالة. */
    public function delete(User $user, User $target): Response
    {
        return $this->sensitiveAction($user, $target, 'users.update_status');
    }

    public function restore(User $user, User $target): Response
    {
        return $this->sensitiveAction($user, $target, 'users.restore');
    }

    public function forceDelete(User $user, User $target): Response
    {
        return Response::deny('الحذف النهائي للحسابات غير مسموح.');
    }

    public function changeRegion(User $user, User $target): Response
    {
        if ($user->is($target)) {
            return Response::deny('تغيير منطقتك يتم عبر طلب تغيير منطقة.');
        }

        return ScopeGuard::authorize($user, 'users.change_region', $target, 'users.view');
    }

    public function export(User $user): Response
    {
        return ScopeGuard::authorize($user, 'users.export');
    }

    public function viewRoles(User $user, User $target): Response
    {
        if ($user->is($target)) {
            return Response::allow();
        }

        return ScopeGuard::authorize($user, 'roles.view', $target, 'users.view');
    }

    public function viewAuthEvents(User $user, User $target): Response
    {
        return ScopeGuard::authorize($user, 'auth_events.view', $target, 'users.view');
    }

    private function sensitiveAction(User $user, User $target, string $permission): Response
    {
        if ($user->is($target)) {
            return Response::deny('لا يمكن تنفيذ هذه العملية على حسابك.');
        }

        $decision = ScopeGuard::authorize($user, $permission, $target, 'users.view');
        if ($decision->denied()) {
            return $decision;
        }

        $resolver = app(AccessResolver::class);
        $actorRank = max([0, ...array_map(fn (array $r) => $r['rank'], array_filter(
            $resolver->for($user)->roles,
            fn (array $r) => $r['counted'] && in_array($permission, $r['permissions'], true),
        ))]);

        if ($resolver->for($user)->superAdmin) {
            $actorRank = PHP_INT_MAX;
        }

        return $resolver->highestAssignedRank($target) >= $actorRank
            ? Response::deny('لا يمكن تنفيذ هذه العملية على مستخدم برتبة مساوية لرتبتك أو أعلى.')
            : Response::allow();
    }
}
