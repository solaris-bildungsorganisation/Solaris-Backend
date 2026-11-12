<?php

namespace Database\Seeders\Notifications;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** FR-COM-001..009 — قنوات الإرسال وكتالوج أنواع الإشعارات. */
class NotificationCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $n = fn (string $ar, string $en) => json_encode(compact('ar', 'en'), JSON_UNESCAPED_UNICODE);

        // SMS/Push معطّلة حتى يُعتمد المزوّد
        $channels = [
            ['in_app', 'داخل المنصة', 'In-app', 'database', true, true],
            ['email', 'البريد الإلكتروني', 'Email', 'mail', false, true],
            ['sms', 'رسالة نصية', 'SMS', 'sms', false, false],
            ['push', 'إشعار فوري', 'Push', 'push', false, false],
        ];
        $rows = [];
        foreach ($channels as $i => [$key, $ar, $en, $driver, $sensitive, $active]) {
            $rows[] = ['key' => $key, 'name' => $n($ar, $en), 'driver' => $driver, 'supports_sensitive' => $sensitive,
                'is_active' => $active, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('notification_channels')->upsert($rows, ['key'], ['name', 'driver', 'supports_sensitive', 'sort_order', 'updated_at']);

        // [key, category, ar, en, sensitivity, mandatory, default_channels]
        $types = [
            ['account.verification_code', 'account', 'رمز التحقق', 'Verification code', 'personal', true, ['email', 'sms']],
            ['account.password_reset', 'security', 'استعادة كلمة المرور', 'Password reset', 'personal', true, ['email', 'sms']],
            ['account.status_changed', 'account', 'تغيير حالة الحساب', 'Account status changed', 'personal', true, ['in_app', 'email']],
            ['account.type_request_decided', 'account', 'قرار طلب نوع الحساب', 'Account type request decided', 'personal', false, ['in_app', 'email']],
            ['security.new_login', 'security', 'تسجيل دخول جديد', 'New sign-in', 'personal', true, ['in_app', 'email']],
            ['security.role_changed', 'security', 'تغيير الأدوار', 'Roles changed', 'personal', true, ['in_app', 'email']],
            ['org.invitation', 'admin', 'دعوة للانضمام لمؤسسة', 'Organization invitation', 'personal', false, ['email', 'in_app']],
            ['enrollment.status_changed', 'enrollment', 'تحديث التسجيل', 'Enrollment update', 'personal', false, ['in_app', 'email']],
            ['session.reminder', 'session', 'تذكير بالجلسة', 'Session reminder', 'personal', false, ['in_app', 'email']],
            ['result.published', 'result', 'صدور نتيجة', 'Result published', 'personal', false, ['in_app']],
            ['certificate.issued', 'certificate', 'إصدار شهادة', 'Certificate issued', 'personal', false, ['in_app', 'email']],
            ['job.application_status', 'job', 'حالة طلب التوظيف', 'Application status', 'personal', false, ['in_app', 'email']],
            ['finance.transaction_status', 'finance', 'حالة عملية مالية', 'Transaction status', 'sensitive', true, ['in_app']],
            ['admin.announcement', 'admin', 'إعلان إداري', 'Admin announcement', 'public', false, ['in_app']],
        ];
        $rows = [];
        foreach ($types as [$key, $cat, $ar, $en, $sens, $mandatory, $ch]) {
            $rows[] = ['key' => $key, 'category' => $cat, 'name' => $n($ar, $en), 'sensitivity' => $sens, 'is_mandatory' => $mandatory,
                'default_channels' => json_encode($ch), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('notification_types')->upsert($rows, ['key'],
            ['category', 'name', 'sensitivity', 'is_mandatory', 'default_channels', 'updated_at']);

        $this->command?->info('Notification channels: ' . count($channels) . ', types: ' . count($types));
    }
}
