<?php

return [

    /*
    | النطاق العام (access_scopes.id = 1) — يُنشأ في migration 000007.
    | الـ middleware يضبطه افتراضيًا: setPermissionsTeamId(config('solaris.global_scope_id'))
    */
    'global_scope_id' => 1,

    /*
    | أول Super Administrator — يُنشأ فقط عبر SuperAdminSeeder ومن متغيرات البيئة.
    | لا كلمات مرور في الكود أو في المستودع. يُجبر على تغيير كلمة المرور عند أول دخول.
    */
    'super_admin' => [
        'email' => env('SOLARIS_SUPER_ADMIN_EMAIL'),
        'password' => env('SOLARIS_SUPER_ADMIN_PASSWORD'),
        'first_name' => env('SOLARIS_SUPER_ADMIN_FIRST_NAME', 'Super'),
        'last_name' => env('SOLARIS_SUPER_ADMIN_LAST_NAME', 'Admin'),
    ],
    'session_lifetime_days' => 30,

    /*
    | حارس MFA للصلاحيات requires_mfa والـ middleware mfa.confirmed (NFR-SEC-002) — القرار D8 · K2.
    |   enforce             : false = يُسمح ويُسجَّل تحذير فقط (الوضع الحالي) · true = رفض 403
    |                         ما لم يكن للجلسة تحقق 2FA حديث (step-up).
    |   required_for_admins : true = يُفرض على أصحاب الأدوار الإدارية حتى لو enforce = false.
    |   mode                : (قديم، B4) warn|enforce — enforce يعادل enforce = true. يبقى للتوافق.
    |   step_up_minutes     : مدة صلاحية التحقق الإضافي POST /me/2fa/verify لكل جلسة.
    | القيم النهائية لـ enforce و required_for_admins تُحسم مع قتيبة ضمن القرار D8.
    */
    'mfa' => [
        'enforce' => (bool) env('SOLARIS_MFA_ENFORCE', false),
        'required_for_admins' => (bool) env('SOLARIS_MFA_REQUIRED_FOR_ADMINS', false),
        'mode' => env('SOLARIS_MFA_MODE', 'warn'),
        'step_up_minutes' => 15,
    ],

    /*
    | التحقق الثنائي TOTP (K1 · NFR-SEC-002 · القرار D8).
    |   issuer         : الاسم الظاهر في تطبيق المصادقة.
    |   window         : عدد الخطوات (30 ثانية) المقبولة قبل/بعد الوقت الحالي.
    |   recovery_codes : عدد رموز الاسترداد المولَّدة عند التأكيد أو إعادة التوليد.
    |   challenge_ttl_minutes / challenge_max_attempts : تحدّي الدخول (K2) — صالح 5 دقائق، يبطل بعد 5 رموز خاطئة.
    */
    'two_factor' => [
        'issuer' => env('SOLARIS_2FA_ISSUER', env('APP_NAME', 'Solaris')),
        'window' => 1,
        'recovery_codes' => 8,
        'challenge_ttl_minutes' => 5,
        'challenge_max_attempts' => 5,
    ],

];
