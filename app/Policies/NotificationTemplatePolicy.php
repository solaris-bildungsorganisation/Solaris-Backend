<?php

namespace App\Policies;

/** FR-COM-008, 009 قوالب الإشعارات — notification_templates.manage (إعداد عام، نطاق G فقط). */
class NotificationTemplatePolicy extends GlobalSettingPolicy
{
    protected function permission(): string
    {
        return 'notification_templates.manage';
    }
}
