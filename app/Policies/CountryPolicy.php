<?php

namespace App\Policies;

/** FR-AUTH-031 الدول — countries.manage (إعداد عام، نطاق G فقط). */
class CountryPolicy extends GlobalSettingPolicy
{
    protected function permission(): string
    {
        return 'countries.manage';
    }

    /** بيانات مرجعية تعرضها الواجهة لكل مستخدم. */
    protected function publicRead(): bool
    {
        return true;
    }
}
