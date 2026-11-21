<?php

namespace App\Policies;

/** FR-I18N-008 ترجمات الواجهة — translations.manage (إعداد عام، نطاق G فقط). */
class UiTranslationPolicy extends GlobalSettingPolicy
{
    protected function permission(): string
    {
        return 'translations.manage';
    }

    /** بيانات مرجعية تعرضها الواجهة لكل مستخدم. */
    protected function publicRead(): bool
    {
        return true;
    }
}
