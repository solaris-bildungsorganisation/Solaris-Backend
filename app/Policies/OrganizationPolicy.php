<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class OrganizationPolicy
{
    /**
     * Determine whether the user can view any models.
     */

    private function allows(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission, 'web');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
 public function viewAny(User $user): bool
    {
        return $this->allows($user, 'organizations.view_any');
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->allows($user, 'organizations.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'organizations.create');
    }

    public function update(User $user, Organization $organization): bool
    {
        return $organization->status !== 'archived'
            && $this->allows($user, 'organizations.update');
    }

    public function updateStatus(User $user, Organization $organization): bool
    {
        return $this->allows($user, 'organizations.update_status');
    }

    public function verify(User $user, Organization $organization): bool
    {
        return $this->allows($user, 'organizations.verify');
    }
}
