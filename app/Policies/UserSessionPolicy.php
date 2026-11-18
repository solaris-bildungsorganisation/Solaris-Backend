<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Auth\Access\Response;

/**
 * الجلسات — FR-AUTH-024, 025.
 *  - جلساتي: sessions.view_own / sessions.revoke_own (جلسة مستخدم آخر = 404).
 *  - جلسات المستخدمين: sessions.view_any / sessions.revoke_any (MFA) — فلتر النطاق: مستخدمو النطاق (ScopeGuard).
 */
class UserSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sessions.view_own');
    }

    public function revoke(User $user, UserSession $session): Response
    {
        if ($session->user_id != $user->id) {
            return Response::denyAsNotFound();
        }

        return $user->can('sessions.revoke_own')
            ? Response::allow()
            : Response::deny();
    }

    public function revokeOthers(User $user): bool
    {
        return $user->can('sessions.revoke_own');
    }

    public function viewForUser(User $admin, User $target): Response
    {
        return ScopeGuard::authorize($admin, 'sessions.view_any', $target);
    }

    public function revokeForUser(User $admin, User $target): Response
    {
        return ScopeGuard::authorize($admin, 'sessions.revoke_any', $target, 'sessions.view_any');
    }
}
