<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * أعضاء المؤسسة — FR-ORG-002, 006. فلتر النطاق: عبر مؤسسة العضوية.
 * لا تعديل ولا إزالة لعضويتك أنت (ورقة الصلاحيات: "لا على النفس").
 */
class OrganizationMembershipPolicy
{
    public function viewAny(User $user, Organization $organization): Response
    {
        return ScopeGuard::authorize($user, 'organization_members.view', $organization);
    }

    public function view(User $user, OrganizationMembership $membership): Response
    {
        if ($membership->user_id === $user->getKey()) {
            return Response::allow();
        }

        return ScopeGuard::authorize($user, 'organization_members.view', $membership);
    }

    /** إضافة عضو تتم عبر الدعوة */
    public function create(User $user, Organization $organization): Response
    {
        return ScopeGuard::authorize($user, 'organization_members.invite', $organization, 'organization_members.view');
    }

    public function update(User $user, OrganizationMembership $membership): Response
    {
        if ($membership->user_id === $user->getKey()) {
            return Response::deny('لا يمكنك تعديل عضويتك.');
        }

        return ScopeGuard::authorize($user, 'organization_members.update', $membership, 'organization_members.view');
    }

    public function delete(User $user, OrganizationMembership $membership): Response
    {
        if ($membership->user_id === $user->getKey()) {
            return Response::deny('لا يمكنك إزالة نفسك من المؤسسة بهذه العملية.');
        }

        return ScopeGuard::authorize($user, 'organization_members.remove', $membership, 'organization_members.view');
    }

    public function restore(User $user, OrganizationMembership $membership): Response
    {
        return $this->update($user, $membership);
    }

    public function forceDelete(User $user, OrganizationMembership $membership): Response
    {
        return Response::deny();
    }
}
