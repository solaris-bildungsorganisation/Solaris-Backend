<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Auth\Access\Response;

class UserSessionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
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

public function revokeOthers(User $user):bool{
    return $user->can('sessions.revoke_own');
}


public function viewForUser(User $admin, User $target): Response
{
    if (! $admin->can('sessions.view_any')) {
        return Response::deny();
    }

    return $admin->country_id === $target->country_id
        ? Response::allow()
        : Response::denyAsNotFound();
}

public function revokeForUser(User $admin, User $target): Response
{
    if (! $admin->can('sessions.revoke_any')) {
        return Response::deny();
    }

    return $admin->country_id === $target->country_id
        ? Response::allow()
        : Response::denyAsNotFound();
}



}

