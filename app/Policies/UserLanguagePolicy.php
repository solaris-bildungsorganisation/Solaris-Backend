<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserLanguage;
use Illuminate\Auth\Access\Response;

/**
 * لغات المستخدم (جزء من الملف الشخصي) — FR-AUTH-018 · FR-I18N.
 * صاحبها يديرها (profile.update_own)، والمسؤول يراها ضمن users.view ونطاقه.
 */
class UserLanguagePolicy
{
    public function viewAny(User $user): Response
    {
        return ScopeGuard::authorize($user, 'profile.view_own');
    }

    public function view(User $user, UserLanguage $language): Response
    {
        if ($language->user_id === $user->getKey()) {
            return ScopeGuard::authorize($user, 'profile.view_own');
        }

        return ScopeGuard::authorize($user, 'users.view', $language);
    }

    public function create(User $user): Response
    {
        return ScopeGuard::authorize($user, 'profile.update_own');
    }

    public function update(User $user, UserLanguage $language): Response
    {
        return $language->user_id === $user->getKey()
            ? ScopeGuard::authorize($user, 'profile.update_own')
            : Response::denyAsNotFound();
    }

    public function delete(User $user, UserLanguage $language): Response
    {
        return $this->update($user, $language);
    }

    public function restore(User $user, UserLanguage $language): Response
    {
        return $this->update($user, $language);
    }

    public function forceDelete(User $user, UserLanguage $language): Response
    {
        return $this->update($user, $language);
    }
}
