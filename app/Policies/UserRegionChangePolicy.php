<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserRegionChange;
use Illuminate\Auth\Access\Response;

/**
 * طلبات تغيير المنطقة — FR-AUTH-032, 036.
 *  - صاحب الطلب: region_changes.create_own (ذاتية).
 *  - المراجعة: users.change_region على المنطقة/الدولة القديمة أو الجديدة داخل النطاق؛ لا مراجعة ذاتية؛ لا نطاق مؤسسة.
 */
class UserRegionChangePolicy
{
    public function viewAny(User $user): Response
    {
        return ScopeGuard::authorize($user, 'users.change_region');
    }

    public function view(User $user, UserRegionChange $change): Response
    {
        if ($change->user_id === $user->getKey()) {
            return Response::allow();
        }

        return ScopeGuard::authorize($user, 'users.change_region', $change);
    }

    public function create(User $user): Response
    {
        return ScopeGuard::authorize($user, 'region_changes.create_own');
    }

    /** اعتماد/رفض/تطبيق */
    public function review(User $user, UserRegionChange $change): Response
    {
        if ($change->user_id === $user->getKey()) {
            return Response::deny('لا يمكنك مراجعة طلبك.');
        }

        return ScopeGuard::authorize($user, 'users.change_region', $change);
    }

    public function update(User $user, UserRegionChange $change): Response
    {
        return $this->review($user, $change);
    }

    /** سحب الطلب من صاحبه ما دام معلّقًا */
    public function delete(User $user, UserRegionChange $change): Response
    {
        return $change->user_id === $user->getKey() && $change->status === 'pending'
            ? Response::allow()
            : Response::deny();
    }

    public function restore(User $user, UserRegionChange $change): Response
    {
        return Response::deny();
    }

    public function forceDelete(User $user, UserRegionChange $change): Response
    {
        return Response::deny();
    }
}
