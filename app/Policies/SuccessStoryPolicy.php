<?php

namespace App\Policies;

use App\Models\SuccessStory;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * قصص النجاح — FR-ADMIN-007.
 *  - العرض العام: المنشورة فقط (success_stories.view).
 *  - الإدارة/النشر: داخل النطاق (country_id / region_id / organization_id)، والنشر يتطلب الموافقة (consent).
 */
class SuccessStoryPolicy
{
    private const PUBLISHABLE_CONSENT = ['granted', 'not_required'];

    public function viewAny(User $user): Response
    {
        return ScopeGuard::authorize($user, 'success_stories.view');
    }

    public function view(User $user, SuccessStory $story): Response
    {
        if ($story->status === 'published' && $user->can('success_stories.view')) {
            return Response::allow();
        }

        return ScopeGuard::authorize($user, 'success_stories.manage', $story);
    }

    public function create(User $user): Response
    {
        return ScopeGuard::authorize($user, 'success_stories.manage');
    }

    public function update(User $user, SuccessStory $story): Response
    {
        return ScopeGuard::authorize($user, 'success_stories.manage', $story);
    }

    public function delete(User $user, SuccessStory $story): Response
    {
        return $this->update($user, $story);
    }

    public function publish(User $user, SuccessStory $story): Response
    {
        $decision = ScopeGuard::authorize($user, 'success_stories.publish', $story, 'success_stories.manage');
        if ($decision->denied()) {
            return $decision;
        }

        return in_array($story->consent_status, self::PUBLISHABLE_CONSENT, true)
            ? Response::allow()
            : Response::deny('لا يمكن النشر قبل تسجيل موافقة صاحب القصة.');
    }

    public function restore(User $user, SuccessStory $story): Response
    {
        return $this->update($user, $story);
    }

    public function forceDelete(User $user, SuccessStory $story): Response
    {
        return Response::deny();
    }
}
