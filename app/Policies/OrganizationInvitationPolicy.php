<?php

namespace App\Policies;

use App\Models\AccessScope;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Auth\Access\Response;

/**
 * دعوات المؤسسة — FR-ORG-002. فلتر النطاق: organization_id = المؤسسة (عبر ScopeGuard).
 * الدعوة بدور مقترح (role_id) تخضع لورقة "إسناد الأدوار" (القاعدة 8).
 */
class OrganizationInvitationPolicy
{
    public function viewAny(User $user, Organization $organization): Response
    {
        return ScopeGuard::authorize($user, 'organization_members.view', $organization);
    }

    public function view(User $user, OrganizationInvitation $invitation): Response
    {
        return ScopeGuard::authorize($user, 'organization_members.view', $invitation);
    }

    /** $role: الدور المقترح في الدعوة (اختياري) — يجب أن يحق للداعي إسناده داخل المؤسسة. */
    public function create(User $user, Organization $organization, ?Role $role = null): Response
    {
        $decision = ScopeGuard::authorize($user, 'organization_members.invite', $organization, 'organization_members.view');
        if ($decision->denied() || $role === null) {
            return $decision;
        }

        $scope = new AccessScope(['type' => AccessScope::TYPE_ORGANIZATION, 'organization_id' => $organization->getKey()]);

        return app(RoleAssignmentService::class)->canGrant($user, $role, $scope)
            ? Response::allow()
            : Response::deny('لا يحق لك دعوة عضو بهذا الدور.');
    }

    /** إعادة الإرسال/التعديل */
    public function update(User $user, OrganizationInvitation $invitation): Response
    {
        return ScopeGuard::authorize($user, 'organization_members.invite', $invitation, 'organization_members.view');
    }

    /** إلغاء الدعوة */
    public function delete(User $user, OrganizationInvitation $invitation): Response
    {
        return $this->update($user, $invitation);
    }

    public function restore(User $user, OrganizationInvitation $invitation): Response
    {
        return Response::deny();
    }

    public function forceDelete(User $user, OrganizationInvitation $invitation): Response
    {
        return Response::deny();
    }
}
