<?php

namespace App\Policies;

use App\Models\AccessScope;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * المؤسسات — FR-ORG-001, 004, 005 · FR-ADMIN-009.
 * فلتر النطاق: organizations.country_id / region_id ضمن الشجرة / organizations.id (ScopeGuard).
 */
class OrganizationPolicy
{
    /** أنواع المؤسسات التي يرى مسؤولها تقارير المانحين داخل مؤسسته (D5). */
    private const DONOR_TYPES = ['donor', 'partner'];

    public function viewAny(User $user): Response
    {
        return ScopeGuard::authorize($user, 'organizations.view_any');
    }

    public function view(User $user, Organization $organization): Response
    {
        return ScopeGuard::authorize($user, 'organizations.view', $organization);
    }

    public function create(User $user): Response
    {
        return ScopeGuard::authorize($user, 'organizations.create');
    }

    public function update(User $user, Organization $organization): Response
    {
        return ScopeGuard::authorize($user, 'organizations.update', $organization, 'organizations.view');
    }

    /** تعليق/أرشفة/تفعيل */
    public function updateStatus(User $user, Organization $organization): Response
    {
        return ScopeGuard::authorize($user, 'organizations.update_status', $organization, 'organizations.view');
    }

    public function verify(User $user, Organization $organization): Response
    {
        return ScopeGuard::authorize($user, 'organizations.verify', $organization, 'organizations.view');
    }

    /** الحذف = أرشفة (soft delete) */
    public function delete(User $user, Organization $organization): Response
    {
        return $this->updateStatus($user, $organization);
    }

    public function restore(User $user, Organization $organization): Response
    {
        return $this->updateStatus($user, $organization);
    }

    public function forceDelete(User $user, Organization $organization): Response
    {
        return Response::deny('الحذف النهائي للمؤسسات غير مسموح.');
    }

    public function viewStats(User $user, Organization $organization): Response
    {
        return ScopeGuard::authorize($user, 'organization_reports.view', $organization, 'organizations.view');
    }

    /** D5: مسؤول المؤسسة يرى تقارير المانحين فقط إن كانت مؤسسته من نوع donor/partner. */
    public function viewDonorReports(User $user, Organization $organization): Response
    {
        $decision = ScopeGuard::authorize($user, 'reports.donor_view', $organization, 'organizations.view');
        if ($decision->denied()) {
            return $decision;
        }

        foreach ($user->effectiveAccess()->scopesFor('reports.donor_view') as $scope) {
            if ($scope->type !== AccessScope::TYPE_ORGANIZATION && ScopeGuard::scopeCovers($scope, $organization)) {
                return Response::allow();
            }
        }

        return in_array($organization->type, self::DONOR_TYPES, true)
            ? Response::allow()
            : Response::deny('تقارير المانحين متاحة لمؤسسات المانحين والشركاء فقط.');
    }
}
