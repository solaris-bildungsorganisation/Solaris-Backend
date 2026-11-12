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

];
