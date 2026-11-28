<?php

namespace App\Policies;

use App\Models\SuccessStory;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Policy للإجراءات الإدارية في مهمة ADMIN-05.
 * API العام منفصل ويعرض القصص المنشورة فقط.
 */
class SuccessStoryPolicy
{
    private const PUBLISHABLE_CONSENT = ['granted', 'not_required'];

    /** قائمة الإدارة؛ يطبّق Controller ScopeGuard::visibleTo() على النتائج. */
    public function viewAny(User $user): Response
    {
        return ScopeGuard::authorize(
            $user,
            'success_stories.manage',
        );
    }

    /** عرض سجل إداري داخل نطاق المستخدم. */
    public function view(User $user, SuccessStory $story): Response
    {
        return ScopeGuard::authorize(
            $user,
            'success_stories.manage',
            $story,
        );
    }

    /** فحص صلاحية الإنشاء؛ تفحص Service نطاق السجل المقترح قبل حفظه. */
    public function create(User $user): Response
    {
        return ScopeGuard::authorize(
            $user,
            'success_stories.manage',
        );
    }

    /** تفحص Service السجل الحالي والنطاق الجديد المقترح قبل الحفظ. */
    public function update(User $user, SuccessStory $story): Response
    {
        return ScopeGuard::authorize(
            $user,
            'success_stories.manage',
            $story,
        );
    }

    public function delete(User $user, SuccessStory $story): Response
    {
        return $this->update($user, $story);
    }

    public function recordConsent(User $user, SuccessStory $story): Response
    {
        return ScopeGuard::authorize(
            $user,
            'success_stories.manage',
            $story,
        );
    }

    public function submit(User $user, SuccessStory $story): Response
    {
        return ScopeGuard::authorize(
            $user,
            'success_stories.manage',
            $story,
        );
    }

    /** النشر يتطلب الصلاحية والنطاق وموافقة صالحة، ويمنع الكاتب من نشر قصته. */
    public function publish(User $user, SuccessStory $story): Response
    {
        $decision = ScopeGuard::authorize(
            $user,
            'success_stories.publish',
            $story,
            'success_stories.manage',
        );

        if ($decision->denied()) {
            return $decision;
        }

        if ((int) $story->created_by === (int) $user->getKey()) {
            return Response::deny(
                'لا يجوز لمنشئ القصة نشر قصته بنفسه.'
            );
        }

        if (! in_array(
            $story->consent_status,
            self::PUBLISHABLE_CONSENT,
            true,
        )) {
            return Response::deny(
                'لا يمكن النشر قبل تسجيل موافقة صالحة.'
            );
        }

        if (
            $story->consent_status === 'not_required'
            && $story->subject_user_id !== null
        ) {
            return Response::deny(
                'لا يمكن اختيار not_required مع وجود صاحب للقصة.'
            );
        }

        return $decision;
    }

    /**
     * هنا الأرشفة تتطلب صلاحية النشر؛ لا يظهر في الصلاحيات التي فُحصت
     * إذن أرشفة مستقل.
     */
    public function archive(User $user, SuccessStory $story): Response
    {
        return ScopeGuard::authorize(
            $user,
            'success_stories.publish',
            $story,
            'success_stories.manage',
        );
    }

    /** أبقيتها لأنها موجودة في كودك الحالي. */
    public function restore(User $user, SuccessStory $story): Response
    {
        return $this->update($user, $story);
    }

    /** الحذف النهائي ممنوع؛ استخدم الحذف المنطقي عبر delete(). */
    public function forceDelete(User $user, SuccessStory $story): Response
    {
        return Response::deny();
    }
}
