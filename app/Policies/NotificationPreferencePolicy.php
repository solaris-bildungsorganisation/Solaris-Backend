<?php

namespace App\Policies;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * تفضيلاتي — notifications.preferences_own
 */
class NotificationPreferencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('notifications.preferences_own');
    }

    public function update(User $user): bool
    {
        return $user->can('notifications.preferences_own');
    }
}
