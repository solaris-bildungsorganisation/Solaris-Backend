<?php

namespace App\Policies;

/** FR-I18N-001, 007 اللغات — languages.manage (إعداد عام، نطاق G فقط). */
class LanguagePolicy extends GlobalSettingPolicy
{
    protected function permission(): string
    {
        return 'languages.manage';
    }

    /** بيانات مرجعية تعرضها الواجهة لكل مستخدم. */
    protected function publicRead(): bool
    {
        return true;
    }
}
