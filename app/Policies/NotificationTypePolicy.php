<?php

namespace App\Policies;

/** FR-COM-006, 009 أنواع الإشعارات — notification_types.manage (إعداد عام، نطاق G فقط). */
class NotificationTypePolicy extends GlobalSettingPolicy
{
    protected function permission(): string
    {
        return 'notification_types.manage';
    }
}
