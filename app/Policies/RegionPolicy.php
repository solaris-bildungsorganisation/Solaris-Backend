<?php

namespace App\Policies;

/** FR-AUTH-031 المناطق — regions.manage (إعداد عام، نطاق G فقط). */
class RegionPolicy extends GlobalSettingPolicy
{
    protected function permission(): string
    {
        return 'regions.manage';
    }

    /** بيانات مرجعية تعرضها الواجهة لكل مستخدم. */
    protected function publicRead(): bool
    {
        return true;
    }
}
