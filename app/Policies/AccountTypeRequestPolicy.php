<?php

namespace App\Policies;

use App\Models\AccessScope;
use App\Models\AccountTypeRequest;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Auth\Access\Response;

/**
 * طلبات نوع الحساب — FR-AUTH-002, 004 · BR-AUTH-004.
 * فلتر النطاق: دولة صاحب الطلب / region_id للطلب أو منطقة صاحبه / organization_id للطلب (ScopeGuard).
 * المراجعة "مثل الإسناد؛ لا مراجعة ذاتية" (D1: مسؤول المؤسسة يراجع فقط الأدوار التي يحق له إسنادها).
 */
class AccountTypeRequestPolicy
{
    public function viewAny(User $user): Response
    {
        return ScopeGuard::authorize($user, 'account_type_requests.view_any');
    }

    public function view(User $user, AccountTypeRequest $request): Response
    {
        if ($request->user_id === $user->getKey()) {
            return Response::allow();
        }

        return ScopeGuard::authorize($user, 'account_type_requests.view_any', $request);
    }

    public function create(User $user): Response
    {
        return ScopeGuard::authorize($user, 'account_type_requests.create_own');
    }

    /** قبول/رفض الطلب */
    public function review(User $user, AccountTypeRequest $request): Response
    {
        if ($request->user_id === $user->getKey()) {
            return Response::deny('لا يمكنك مراجعة طلبك.');
        }

        $decision = ScopeGuard::authorize($user, 'account_type_requests.review', $request, 'account_type_requests.view_any');
        if ($decision->denied()) {
            return $decision;
        }

        $role = $request->requestedRole;

        return $role !== null && app(RoleAssignmentService::class)->canGrant($user, $role, $this->targetScope($request))
            ? Response::allow()
            : Response::deny('لا يحق لك اعتماد هذا الدور في هذا النطاق.');
    }

    public function update(User $user, AccountTypeRequest $request): Response
    {
        return $this->review($user, $request);
    }

    /** إلغاء الطلب من صاحبه ما دام مفتوحًا */
    public function delete(User $user, AccountTypeRequest $request): Response
    {
        return $request->user_id === $user->getKey()
            && in_array($request->status, ['pending', 'under_review'], true)
            && $user->can('account_type_requests.create_own')
            ? Response::allow()
            : Response::deny();
    }

    public function restore(User $user, AccountTypeRequest $request): Response
    {
        return Response::deny();
    }

    public function forceDelete(User $user, AccountTypeRequest $request): Response
    {
        return Response::deny();
    }

    /** النطاق الذي سيُسنَد فيه الدور عند القبول (غير محفوظ — للفحص فقط). */
    private function targetScope(AccountTypeRequest $request): AccessScope
    {
        return match (true) {
            $request->organization_id !== null => new AccessScope(['type' => AccessScope::TYPE_ORGANIZATION, 'organization_id' => $request->organization_id]),
            $request->region_id !== null => new AccessScope([
                'type' => AccessScope::TYPE_REGION,
                'region_id' => $request->region_id,
                'country_id' => $request->region?->country_id,
            ]),
            default => new AccessScope(['type' => AccessScope::TYPE_GLOBAL]),
        };
    }
}
