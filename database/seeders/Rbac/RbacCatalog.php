<?php

namespace Database\Seeders\Rbac;

/**
 * كتالوج التفويض لمنصة زولاريس — المصدر الوحيد للسياقات والأدوار والصلاحيات.
 *
 * - الأدوار الـ 14 والسياقات مأخوذة من §3 "نموذج الأدوار والصلاحيات" في وثيقة التسليم والتنفيذ.
 * - الصلاحيات تغطي موارد الموجة 1 (AUTH · I18N · ORG · ADMIN · COM) بصيغة resource.action.
 * - ربط الأدوار بالصلاحيات هنا = مصفوفة التفويض "مسودة 2" (requerments/Solaris_Authorization_Matrix_Wave1.xlsx)
 *   ونسختها المصدّرة tests/fixtures/authorization_matrix.csv التي يتحقق منها AuthorizationMatrixTest خلية خلية.
 *   أي تعديل هنا يجب أن يطابق المصفوفة المعتمدة، ثم يُعاد تشغيل RoleSeeder (idempotent: يزامن ولا يكرر).
 *
 * إضافة صلاحية: أضفها في permissions() ثم اربطها بالأدوار في roles() — لا تُنشأ صلاحيات من الكود أو من لوحة التحكم.
 */
final class RbacCatalog
{
    /**
     * الحارس الذي تُسجَّل عليه الأدوار. Sanctum يصادق المستخدم عبر provider الحارس web،
     * وspatie يستنتج guard المستخدم = 'web'؛ لذلك نسجّل على 'web' وليس 'sanctum'.
     */
    public const GUARD = 'web';

    public const SUPER_ADMIN = 'super_admin';

    /** key => [ar, en] */
    public static function contexts(): array
    {
        return [
            'learning' => ['التعلم', 'Learning'],
            'teaching' => ['التدريس والتدريب', 'Teaching & training'],
            'review' => ['المراجعة العلمية/المهنية', 'Expert review'],
            'content' => ['إنشاء المحتوى', 'Content creation'],
            'mentoring' => ['الإرشاد', 'Mentoring'],
            'pss' => ['الدعم النفسي والاجتماعي', 'Psychosocial support'],
            'program_management' => ['إدارة البرامج والجودة والرصد', 'Programs, quality & M&E'],
            'recruitment' => ['التوظيف', 'Recruitment'],
            'staff' => ['موظفو زولاريس', 'Solaris staff'],
            'organization' => ['المؤسسة/الشريك', 'Organization / partner'],
            'administration' => ['إدارة المنصة', 'Platform administration'],
        ];
    }

    /**
     * resource => [action => [sensitivity, requires_mfa, label_ar]]
     * sensitivity: public | internal | personal | sensitive | restricted
     */
    public static function permissions(): array
    {
        return [
            'profile' => [
                'view_own' => ['personal', false, 'عرض ملفي'],
                'update_own' => ['personal', false, 'تعديل ملفي'],
            ],
            'sessions' => [
                'view_own' => ['personal', false, 'عرض جلساتي'],
                'revoke_own' => ['personal', false, 'إنهاء جلساتي'],
                'view_any' => ['sensitive', false, 'عرض جلسات المستخدمين'],
                'revoke_any' => ['sensitive', true, 'إنهاء جلسات المستخدمين'],
            ],
            'users' => [
                'view_any' => ['personal', false, 'عرض قائمة المستخدمين'],
                'view' => ['personal', false, 'عرض مستخدم'],
                'create' => ['personal', false, 'إنشاء مستخدم'],
                'update' => ['personal', false, 'تعديل مستخدم'],
                'update_status' => ['sensitive', true, 'تفعيل/تعطيل/حظر حساب'],
                'restore' => ['sensitive', true, 'استعادة حساب'],
                'change_region' => ['personal', false, 'تغيير منطقة مستخدم'],
                'export' => ['sensitive', true, 'تصدير بيانات المستخدمين'],
            ],
            'roles' => [
                'view' => ['internal', false, 'عرض الأدوار وإسناداتها'],
                'assign' => ['restricted', true, 'إسناد دور'],
                'revoke' => ['restricted', true, 'سحب دور'],
            ],
            'account_type_requests' => [
                'create_own' => ['personal', false, 'طلب نوع حساب'],
                'view_any' => ['personal', false, 'عرض طلبات نوع الحساب'],
                'review' => ['sensitive', false, 'مراجعة طلبات نوع الحساب'],
            ],
            'auth_events' => [
                'view' => ['sensitive', false, 'عرض سجل الدخول والأمان'],
            ],
            'countries' => ['manage' => ['internal', false, 'إدارة الدول']],
            'regions' => ['manage' => ['internal', false, 'إدارة المناطق']],
            'region_policies' => [
                'view' => ['internal', false, 'عرض سياسات المناطق'],
                'manage' => ['restricted', true, 'إدارة سياسات المناطق'],
            ],
            'languages' => ['manage' => ['internal', false, 'إدارة اللغات']],
            'translations' => ['manage' => ['internal', false, 'إدارة الترجمات']],
            'organizations' => [
                'create' => ['internal', false, 'إنشاء مؤسسة'],
                'view_any' => ['internal', false, 'عرض المؤسسات'],
                'view' => ['internal', false, 'عرض مؤسسة'],
                'update' => ['internal', false, 'تعديل مؤسسة'],
                'update_status' => ['sensitive', true, 'تعليق/أرشفة مؤسسة'],
                'verify' => ['sensitive', false, 'توثيق مؤسسة'],
            ],
            'organization_members' => [
                'view' => ['personal', false, 'عرض أعضاء المؤسسة'],
                'invite' => ['personal', false, 'دعوة أعضاء'],
                'update' => ['personal', false, 'تعديل عضوية'],
                'remove' => ['personal', false, 'إزالة عضو'],
            ],
            'organization_reports' => ['view' => ['internal', false, 'تقارير المؤسسة']],
            'dashboard' => ['view' => ['internal', false, 'لوحة المتابعة']],
            'metrics' => [
                'view' => ['internal', false, 'عرض المؤشرات'],
                'view_financial' => ['sensitive', false, 'عرض المؤشرات المالية'],
            ],
            'reports' => [
                'view' => ['internal', false, 'عرض التقارير'],
                'export' => ['sensitive', true, 'تصدير التقارير'], // الفروقات #9: MFA (D4/D8)
                'donor_view' => ['internal', false, 'تقارير المانحين والشركاء'],
            ],
            'audit_log' => [
                'view' => ['sensitive', false, 'عرض سجل التدقيق'],
                'view_restricted' => ['restricted', true, 'عرض السجلات المقيّدة'],
            ],
            'success_stories' => [
                'view' => ['public', false, 'عرض قصص النجاح'],
                'manage' => ['personal', false, 'إدارة قصص النجاح'],
                'publish' => ['internal', false, 'نشر قصص النجاح'],
            ],
            // الفروقات #1: طلب تغيير المنطقة من الحساب (FR-AUTH-032, 036)
            'region_changes' => [
                'create_own' => ['personal', false, 'طلب تغيير منطقتي'],
            ],
            'notifications' => [
                'view_own' => ['personal', false, 'عرض إشعاراتي'],
                'preferences_own' => ['personal', false, 'تفضيلات إشعاراتي'],
            ],
            'notification_types' => ['manage' => ['internal', false, 'إدارة أنواع الإشعارات']],
            'notification_templates' => ['manage' => ['internal', false, 'إدارة قوالب الإشعارات']],
            'notification_deliveries' => ['view' => ['personal', false, 'متابعة إرسال الإشعارات']],
        ];
    }

    /**
     * صلاحيات ذاتية يملكها كل مستخدم نشط عبر أي دور.
     * تسري دائمًا مهما كان السياق الفعّال (FR-AUTH-016/017).
     */
    public static function selfService(): array
    {
        return [
            'profile.view_own', 'profile.update_own',
            'sessions.view_own', 'sessions.revoke_own',
            'account_type_requests.create_own',
            'region_changes.create_own',
            'notifications.view_own', 'notifications.preferences_own',
            'success_stories.view',
        ];
    }

    /**
     * صلاحيات الإعداد العام = خلية "G" في المصفوفة لكل دور يملكها.
     * لا تُحسب إلا إذا كان الدور مُسنَدًا في النطاق العام (scope_id = 1) — الفروقات #7 و#8 (D7).
     */
    public static function globalOnly(): array
    {
        return [
            'users.export',
            'countries.manage', 'regions.manage', 'region_policies.manage',
            'languages.manage', 'translations.manage',
            'notification_types.manage', 'notification_templates.manage',
        ];
    }

    /**
     * ورقة "إسناد الأدوار": أي دور يحق لكل مُسنِد أن يُسنده.
     * ['*', 'except' => [...]] = كل الأدوار عدا المذكورة. الأدوار غير المذكورة هنا لا تُسنِد شيئًا.
     * super_admin لا يُسنَد عبر الـ API إطلاقًا (D3 — SuperAdminSeeder فقط).
     */
    public static function assignableRoles(): array
    {
        return [
            self::SUPER_ADMIN => ['*', 'except' => [self::SUPER_ADMIN]],
            'platform_admin' => ['*', 'except' => ['platform_admin', self::SUPER_ADMIN]],
            // D2: خمسة أدوار تعليمية داخل مؤسسته فقط
            'organization_admin' => ['academic_student', 'vocational_trainee', 'instructor', 'content_creator', 'mentor'],
        ];
    }

    /** [sensitivity, requires_mfa] لصلاحية، أو null إن لم تكن في الكتالوج. */
    public static function permissionMeta(string $name): ?array
    {
        static $index = null;

        if ($index === null) {
            $index = [];
            foreach (self::permissions() as $resource => $actions) {
                foreach ($actions as $action => [$sensitivity, $mfa]) {
                    $index["{$resource}.{$action}"] = [$sensitivity, $mfa];
                }
            }
        }

        return $index[$name] ?? null;
    }

    public static function isPermission(string $name): bool
    {
        return self::permissionMeta($name) !== null;
    }

    public static function requiresMfa(string $name): bool
    {
        return (bool) (self::permissionMeta($name)[1] ?? false);
    }

    public static function sensitivity(string $name): string
    {
        return self::permissionMeta($name)[0] ?? 'internal';
    }

    /**
     * الأدوار الـ 14 (§3 من وثيقة التسليم).
     * permissions: قائمة أسماء | '*' = الكل | ['*', 'except' => [...]]
     * صلاحيات الموجات 2–5 (الدورات، المحتوى، التوظيف، الدعم النفسي...) تُضاف لاحقًا لنفس الأدوار.
     */
    public static function roles(): array
    {
        $self = self::selfService();

        return [
            'learner' => [
                'name' => ['طالب/متعلم', 'Learner'], 'context' => 'learning',
                'administrative' => false, 'self_registrable' => true, 'requires_approval' => false,
                'scopes' => ['global'], 'rank' => 10, 'permissions' => $self,
            ],
            'academic_student' => [
                'name' => ['طالب أكاديمي', 'Academic student'], 'context' => 'learning',
                'administrative' => false, 'self_registrable' => true, 'requires_approval' => true,
                'scopes' => ['global', 'organization'], 'rank' => 10, 'permissions' => $self,
            ],
            'vocational_trainee' => [
                'name' => ['متدرب مهني', 'Vocational trainee'], 'context' => 'learning',
                'administrative' => false, 'self_registrable' => false, 'requires_approval' => false,
                'scopes' => ['global', 'organization'], 'rank' => 10, 'permissions' => $self,
            ],
            'instructor' => [
                'name' => ['معلم/مدرب', 'Instructor / Trainer'], 'context' => 'teaching',
                'administrative' => false, 'self_registrable' => false, 'requires_approval' => true,
                'scopes' => ['global', 'region', 'organization'], 'rank' => 20, 'permissions' => $self,
            ],
            'expert_reviewer' => [
                'name' => ['خبير/مراجع', 'Expert reviewer'], 'context' => 'review',
                'administrative' => false, 'self_registrable' => false, 'requires_approval' => true,
                'scopes' => ['global', 'organization'], 'rank' => 30, 'permissions' => $self,
            ],
            'content_creator' => [
                'name' => ['منشئ محتوى', 'Content creator'], 'context' => 'content',
                'administrative' => false, 'self_registrable' => false, 'requires_approval' => true,
                'scopes' => ['global', 'organization'], 'rank' => 20, 'permissions' => $self,
            ],
            'mentor' => [
                'name' => ['مرشد', 'Mentor'], 'context' => 'mentoring',
                'administrative' => false, 'self_registrable' => false, 'requires_approval' => true,
                'scopes' => ['global', 'region', 'organization'], 'rank' => 20, 'permissions' => $self,
            ],
            'pss_specialist' => [
                'name' => ['مختص نفسي/اجتماعي', 'PSS specialist'], 'context' => 'pss',
                'administrative' => false, 'self_registrable' => false, 'requires_approval' => true,
                'scopes' => ['global', 'region', 'organization'], 'rank' => 30, 'permissions' => $self,
            ],
            'program_officer' => [
                'name' => ['مسؤول برنامج/جودة/رصد', 'Program / Quality / M&E officer'], 'context' => 'program_management',
                'administrative' => true, 'self_registrable' => false, 'requires_approval' => true,
                'scopes' => ['global', 'country', 'region', 'organization'], 'rank' => 50,
                'permissions' => [...$self,
                    'dashboard.view', 'metrics.view', 'reports.view', 'reports.export', 'reports.donor_view',
                    'success_stories.manage', 'users.view_any', 'users.view',
                    'organizations.view_any', 'organizations.view', 'organization_reports.view',
                    'region_policies.view', // الفروقات #6
                ],
            ],
            'recruiter' => [
                'name' => ['مسؤول توظيف', 'Recruiter'], 'context' => 'recruitment',
                'administrative' => false, 'self_registrable' => false, 'requires_approval' => true,
                'scopes' => ['global', 'organization'], 'rank' => 40, 'permissions' => $self,
            ],
            'solaris_employee' => [
                'name' => ['موظف زولاريس', 'Solaris employee'], 'context' => 'staff',
                'administrative' => false, 'self_registrable' => false, 'requires_approval' => true,
                'scopes' => ['global'], 'rank' => 20, 'permissions' => $self,
            ],
            'organization_admin' => [
                'name' => ['مسؤول مؤسسة/شريك', 'Organization admin'], 'context' => 'organization',
                'administrative' => true, 'self_registrable' => false, 'requires_approval' => true,
                'scopes' => ['organization'], 'rank' => 60,
                'permissions' => [...$self,
                    'organizations.view', 'organizations.update',
                    'organization_members.view', 'organization_members.invite',
                    'organization_members.update', 'organization_members.remove',
                    'organization_reports.view', 'roles.view', 'users.view',
                    'dashboard.view', 'reports.view', 'reports.export',
                    'users.view_any',                                                   // الفروقات #2
                    'roles.assign', 'roles.revoke',                                     // الفروقات #3 (D2)
                    'account_type_requests.view_any', 'account_type_requests.review',   // الفروقات #4 (D1)
                    'reports.donor_view',                                               // الفروقات #5 (D5)
                ],
            ],
            'platform_admin' => [
                'name' => ['إدارة المنصة/التقنية', 'Platform administrator'], 'context' => 'administration',
                'administrative' => true, 'self_registrable' => false, 'requires_approval' => true,
                'scopes' => ['global', 'country', 'region'], 'rank' => 90,
                'permissions' => ['*', 'except' => ['audit_log.view_restricted', 'metrics.view_financial']],
            ],
            self::SUPER_ADMIN => [
                'name' => ['مدير أعلى', 'Super Administrator'], 'context' => 'administration',
                'administrative' => true, 'self_registrable' => false, 'requires_approval' => true,
                'scopes' => ['global'], 'rank' => 100, 'permissions' => '*',
            ],
        ];
    }

    /** كل أسماء الصلاحيات resource.action */
    public static function permissionNames(): array
    {
        $names = [];
        foreach (self::permissions() as $resource => $actions) {
            foreach (array_keys($actions) as $action) {
                $names[] = "{$resource}.{$action}";
            }
        }

        return $names;
    }

    /** يحوّل تعريف صلاحيات الدور إلى قائمة أسماء نهائية ويتحقق أن كل اسم موجود في الكتالوج. */
    public static function resolveRolePermissions(string $role, array|string $spec): array
    {
        $all = self::permissionNames();

        $names = match (true) {
            $spec === '*' => $all,
            is_array($spec) && ($spec[0] ?? null) === '*' => array_values(array_diff($all, $spec['except'] ?? [])),
            default => array_values(array_unique($spec)),
        };

        $unknown = array_diff($names, $all);
        if ($unknown !== []) {
            throw new \LogicException("RbacCatalog: الدور {$role} يشير إلى صلاحيات غير معرّفة: " . implode(', ', $unknown));
        }

        return $names;
    }
}
