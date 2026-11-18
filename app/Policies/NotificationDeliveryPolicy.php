<?php

namespace App\Policies;

use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * متابعة إرسال الإشعارات — FR-COM-008, 009. فلتر النطاق: مستخدمو النطاق (عبر user_id).
 * السجلات يكتبها النظام فقط؛ لا إنشاء أو تعديل من الـ API.
 */
class NotificationDeliveryPolicy
{
    public function viewAny(User $user): Response
    {
        return ScopeGuard::authorize($user, 'notification_deliveries.view');
    }

    public function view(User $user, NotificationDelivery $delivery): Response
    {
        return ScopeGuard::authorize($user, 'notification_deliveries.view', $delivery);
    }

    public function create(User $user): Response
    {
        return Response::deny();
    }

    public function update(User $user, NotificationDelivery $delivery): Response
    {
        return Response::deny();
    }

    public function delete(User $user, NotificationDelivery $delivery): Response
    {
        return Response::deny();
    }

    public function restore(User $user, NotificationDelivery $delivery): Response
    {
        return Response::deny();
    }

    public function forceDelete(User $user, NotificationDelivery $delivery): Response
    {
        return Response::deny();
    }
}
