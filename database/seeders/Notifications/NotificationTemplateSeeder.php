<?php
namespace Database\Seeders\Notifications;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
    $languages = DB::table('languages')->pluck('id', 'code');
    $channels = DB::table('notification_channels')->pluck('id', 'key');
    $types = DB::table('notification_types')->pluck('id', 'key');

    $translations = [
        'account.verification_code' => [
            'email' => [
                'ar' => ['رمز التحقق', 'رمز التحقق الخاص بك هو:'],
                'en' => ['Verification code', 'Your verification code is:'],
                'de' => ['Bestätigungscode', 'Ihr Bestätigungscode lautet:'],
            ],
            'sms' => [
                'ar' => [null, 'رمز التحقق الخاص بك:'],
                'en' => [null, 'Your verification code:'],
                'de' => [null, 'Ihr Bestätigungscode:'],
            ],
        ],

        'account.password_reset' => [
            'email' => [
                'ar' => ['استعادة كلمة المرور', 'رمز استعادة كلمة المرور الخاص بك هو:'],
                'en' => ['Password reset', 'Your password reset code is:'],
                'de' => ['Passwort zurücksetzen', 'Ihr Code zum Zurücksetzen des Passworts lautet:'],
            ],
            'sms' => [
                'ar' => [null, 'رمز استعادة كلمة المرور:'],
                'en' => [null, 'Password reset code:'],
                'de' => [null, 'Code zum Zurücksetzen des Passworts:'],
            ],
        ],

        'account.status_changed' => [
            'in_app' => [
                'ar' => ['تغيير حالة الحساب', 'تم تغيير حالة حسابك.'],
                'en' => ['Account status changed', 'Your account status has changed.'],
                'de' => ['Kontostatus geändert', 'Der Status Ihres Kontos wurde geändert.'],
            ],
            'email' => [
                'ar' => ['تغيير حالة الحساب', 'نود إعلامك بأنه تم تغيير حالة حسابك.'],
                'en' => ['Account status changed', 'Your account status has been changed.'],
                'de' => ['Kontostatus geändert', 'Der Status Ihres Kontos wurde geändert.'],
            ],
        ],

        'account.type_request_decided' => [
            'in_app' => [
                'ar' => ['قرار طلب نوع الحساب', 'تم اتخاذ قرار بشأن طلب تغيير نوع حسابك.'],
                'en' => ['Account type request decision', 'A decision has been made about your account type request.'],
                'de' => ['Entscheidung zum Kontotyp', 'Über Ihren Antrag auf Änderung des Kontotyps wurde entschieden.'],
            ],
            'email' => [
                'ar' => ['قرار طلب نوع الحساب', 'نود إعلامك بأنه تم اتخاذ قرار بشأن طلب تغيير نوع حسابك.'],
                'en' => ['Account type request decision', 'A decision has been made about your account type request.'],
                'de' => ['Entscheidung zum Kontotyp', 'Über Ihren Antrag auf Änderung des Kontotyps wurde entschieden.'],
            ],
        ],

        'security.new_login' => [
            'in_app' => [
                'ar' => ['تسجيل دخول جديد', 'تم تسجيل الدخول إلى حسابك من جهاز جديد.'],
                'en' => ['New sign-in', 'Your account was accessed from a new device.'],
                'de' => ['Neue Anmeldung', 'Ihr Konto wurde von einem neuen Gerät aus aufgerufen.'],
            ],
            'email' => [
                'ar' => ['تسجيل دخول جديد', 'تم تسجيل الدخول إلى حسابك من جهاز جديد. إذا لم تكن أنت، يرجى تأمين حسابك.'],
                'en' => ['New sign-in', 'Your account was accessed from a new device. If this was not you, please secure your account.'],
                'de' => ['Neue Anmeldung', 'Ihr Konto wurde von einem neuen Gerät aus aufgerufen. Falls Sie das nicht waren, sichern Sie bitte Ihr Konto.'],
            ],
        ],

        'security.role_changed' => [
            'in_app' => [
                'ar' => ['تغيير الأدوار', 'تم تغيير الأدوار أو الصلاحيات المرتبطة بحسابك.'],
                'en' => ['Roles changed', 'The roles or permissions associated with your account have changed.'],
                'de' => ['Rollen geändert', 'Die mit Ihrem Konto verbundenen Rollen oder Berechtigungen wurden geändert.'],
            ],
            'email' => [
                'ar' => ['تغيير الأدوار', 'نود إعلامك بأنه تم تغيير الأدوار أو الصلاحيات المرتبطة بحسابك.'],
                'en' => ['Roles changed', 'The roles or permissions associated with your account have changed.'],
                'de' => ['Rollen geändert', 'Die mit Ihrem Konto verbundenen Rollen oder Berechtigungen wurden geändert.'],
            ],
        ],

        'org.invitation' => [
            'email' => [
                'ar' => ['دعوة للانضمام إلى مؤسسة', 'لقد تلقيت دعوة للانضمام إلى مؤسسة.'],
                'en' => ['Organization invitation', 'You have received an invitation to join an organization.'],
                'de' => ['Organisationseinladung', 'Sie haben eine Einladung erhalten, einer Organisation beizutreten.'],
            ],
            'in_app' => [
                'ar' => ['دعوة للانضمام إلى مؤسسة', 'لديك دعوة جديدة للانضمام إلى مؤسسة.'],
                'en' => ['Organization invitation', 'You have a new invitation to join an organization.'],
                'de' => ['Organisationseinladung', 'Sie haben eine neue Einladung erhalten, einer Organisation beizutreten.'],
            ],
        ],

        'security.password_changed' => [
            'in_app' => [
                'ar' => ['تم تغيير كلمة المرور', 'تم تغيير كلمة المرور الخاصة بحسابك.'],
                'en' => ['Password changed', 'Your account password has been changed.'],
                'de' => ['Passwort geändert', 'Das Passwort Ihres Kontos wurde geändert.'],
            ],
            'email' => [
                'ar' => ['تم تغيير كلمة المرور', 'تم تغيير كلمة المرور الخاصة بحسابك. إذا لم تكن أنت، يرجى تأمين حسابك فورًا.'],
                'en' => ['Password changed', 'Your account password has been changed. If this was not you, please secure your account immediately.'],
                'de' => ['Passwort geändert', 'Das Passwort Ihres Kontos wurde geändert. Falls Sie das nicht waren, sichern Sie bitte sofort Ihr Konto.'],
            ],
        ],

        'security.sessions_terminated' => [
            'in_app' => [
                'ar' => ['إنهاء الجلسات', 'تم إنهاء جلسات تسجيل الدخول الخاصة بحسابك.'],
                'en' => ['Sessions terminated', 'Your account sessions have been terminated.'],
                'de' => ['Sitzungen beendet', 'Die Sitzungen Ihres Kontos wurden beendet.'],
            ],
            'email' => [
                'ar' => ['إنهاء الجلسات', 'تم إنهاء جلسات تسجيل الدخول الخاصة بحسابك.'],
                'en' => ['Sessions terminated', 'Your account sessions have been terminated.'],
                'de' => ['Sitzungen beendet', 'Die Sitzungen Ihres Kontos wurden beendet.'],
            ],
        ],
    ];

    $rows = [];

    foreach ($translations as $typeKey => $channelTemplates) {
        if (! $types->has($typeKey)) {
            throw new RuntimeException("Notification type not found: {$typeKey}");
        }

        foreach ($channelTemplates as $channelKey => $languageTemplates) {
            if (! $channels->has($channelKey)) {
                throw new RuntimeException("Notification channel not found: {$channelKey}");
            }

            foreach ($languageTemplates as $languageCode => [$subject, $body]) {
                if (! $languages->has($languageCode)) {
                    throw new RuntimeException("Language not found: {$languageCode}");
                }

                $rows[] = [
                    'notification_type_id' => $types[$typeKey],
                    'channel_id' => $channels[$channelKey],
                    'language_id' => $languages[$languageCode],
                    'subject' => $subject,
                    'body' => $body,
                    'version' => 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
    }

    DB::table('notification_templates')->upsert(
        $rows,
        ['notification_type_id', 'channel_id', 'language_id', 'version'],
        ['subject', 'body', 'is_active', 'updated_at']
    );

    $this->command?->info(
        'Notification templates seeded: ' . count($rows)
    );
}
}
