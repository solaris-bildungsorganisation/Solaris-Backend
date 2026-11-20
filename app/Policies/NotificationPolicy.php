<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Notifications\DatabaseNotification;

/**
 * إشعاراتي — notifications.view_own (إشعار مستخدم آخر = 404).
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('notifications.view_own');
    }

    public function update(User $user, DatabaseNotification $notification): Response
    {
        if ($notification->notifiable_id != $user->id) {
            return Response::denyAsNotFound();
        }

        return $user->can('notifications.view_own')
            ? Response::allow()
            : Response::deny();
    }

    public function delete(User $user, DatabaseNotification $notification): Response
    {
        return $this->update($user, $notification);
    }
}
