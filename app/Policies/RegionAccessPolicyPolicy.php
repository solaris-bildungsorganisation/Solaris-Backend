<?php

namespace App\Policies;

use App\Models\RegionAccessPolicy;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * سياسات المناطق — FR-AUTH-033, 034, 035.
 *  - العرض (region_policies.view): سياسات الدولة/المنطقة داخل النطاق + السياسات العامة.
 *  - الإدارة (region_policies.manage): restricted + MFA، ونطاق G فقط (D7).
 */
class RegionAccessPolicyPolicy
{
    public function viewAny(User $user): Response
    {
        return ScopeGuard::authorize($user, 'region_policies.view');
    }

    public function view(User $user, RegionAccessPolicy $policy): Response
    {
        return ScopeGuard::authorize($user, 'region_policies.view', $policy);
    }

    public function create(User $user): Response
    {
        return ScopeGuard::authorize($user, 'region_policies.manage');
    }

    public function update(User $user, RegionAccessPolicy $policy): Response
    {
        return ScopeGuard::authorize($user, 'region_policies.manage', $policy, 'region_policies.view');
    }

    public function delete(User $user, RegionAccessPolicy $policy): Response
    {
        return $this->update($user, $policy);
    }

    public function restore(User $user, RegionAccessPolicy $policy): Response
    {
        return $this->update($user, $policy);
    }

    public function forceDelete(User $user, RegionAccessPolicy $policy): Response
    {
        return Response::deny();
    }
}
