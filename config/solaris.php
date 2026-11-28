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
    | حارس MFA للصلاحيات requires_mfa (NFR-SEC-002) — القرار D8.
    |   warn    : (الموجة 1، حتى تنفيذ FA2) يُسمح بالعملية ويُسجَّل تحذير في activity_log.
    |   enforce : تُرفض العملية (403) ما لم يكن لدى المستخدم MFA مؤكَّد.
    */
    'mfa' => [
        'mode' => env('SOLARIS_MFA_MODE', 'warn'),
    ],

    'region_policy_keys' => [
        'courses.free_access' => ['type' => 'boolean'],
    ],

    'rate_limits' => [
        'login_per_minute' => (int) env('RATE_LIMIT_LOGIN', 5),
        'code_per_minute' => (int) env('RATE_LIMIT_CODE', 5),
        'register_per_minute' => (int) env('RATE_LIMIT_REGISTER', 5),
        'api_per_minute' => (int) env('RATE_LIMIT_API', 60),
    ],

    'geo_reference_cache_seconds' => 3600,

];
