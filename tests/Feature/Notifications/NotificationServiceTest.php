<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Language;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\PlatformNotification;
use Database\Seeders\Notifications\NotificationCatalogSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private NotificationService $service;
    private Language $arabic;
    private Language $english;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationCatalogSeeder::class);
        $this->service = app(NotificationService::class);

        $this->arabic = Language::firstOrCreate(
            ['code' => 'ar'],
            ['name' => ['ar' => 'العربية', 'en' => 'Arabic'], 'native_name' => 'العربية', 'direction' => 'rtl']
        );

        $this->english = Language::firstOrCreate(
            ['code' => 'en'],
            ['name' => ['ar' => 'الإنجليزية', 'en' => 'English'], 'native_name' => 'English', 'direction' => 'ltr']
        );
    }

    public function test_skips_channel_when_disabled_in_user_preferences(): void
    {
        Notification::fake();

        $user = User::factory()->create(['interface_language_id' => $this->arabic->id]);
        $type = NotificationType::where('key', 'session.reminder')->firstOrFail();
        $emailChannel = NotificationChannel::where('key', 'email')->firstOrFail();
        $inAppChannel = NotificationChannel::where('key', 'in_app')->firstOrFail();

        NotificationPreference::create([
            'user_id' => $user->id,
            'category' => $type->category,
            'channel_id' => $emailChannel->id,
            'is_enabled' => false,
        ]);

        NotificationTemplate::factory()->create([
            'notification_type_id' => $type->id,
            'channel_id' => $inAppChannel->id,
            'language_id' => $this->arabic->id,
            'body' => 'تذكير بالجلسة {{ title }}',
        ]);

        $this->service->send($user, 'session.reminder', ['title' => 'البرمجة']);

        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'notification_type_id' => $type->id,
            'channel_id' => $emailChannel->id,
            'status' => 'skipped_preference',
        ]);

        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'notification_type_id' => $type->id,
            'channel_id' => $inAppChannel->id,
            'status' => 'queued',
        ]);
    }

    public function test_ignores_user_preferences_when_notification_is_mandatory(): void
    {
        Notification::fake();

        $user = User::factory()->create(['interface_language_id' => $this->arabic->id]);
        $type = NotificationType::where('key', 'security.new_login')->firstOrFail();
        $emailChannel = NotificationChannel::where('key', 'email')->firstOrFail();
        $inAppChannel = NotificationChannel::where('key', 'in_app')->firstOrFail();

        NotificationPreference::create([
            'user_id' => $user->id,
            'category' => $type->category,
            'channel_id' => $emailChannel->id,
            'is_enabled' => false,
        ]);

        NotificationTemplate::factory()->create([
            'notification_type_id' => $type->id,
            'channel_id' => $emailChannel->id,
            'language_id' => $this->arabic->id,
            'subject' => 'تنبيه أمني',
            'body' => 'تسجيل دخول جديد',
        ]);

        NotificationTemplate::factory()->create([
            'notification_type_id' => $type->id,
            'channel_id' => $inAppChannel->id,
            'language_id' => $this->arabic->id,
            'body' => 'تسجيل دخول جديد',
        ]);

        $this->service->send($user, 'security.new_login');

        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'channel_id' => $emailChannel->id,
            'status' => 'queued',
        ]);
    }

    public function test_skips_sensitive_notification_on_channels_not_supporting_sensitive(): void
    {
        Notification::fake();

        $user = User::factory()->create(['interface_language_id' => $this->arabic->id]);
        $type = NotificationType::where('key', 'finance.transaction_status')->firstOrFail();
        $type->update(['default_channels' => ['in_app', 'email']]);

        $emailChannel = NotificationChannel::where('key', 'email')->firstOrFail();
        $inAppChannel = NotificationChannel::where('key', 'in_app')->firstOrFail();

        NotificationTemplate::factory()->create([
            'notification_type_id' => $type->id,
            'channel_id' => $inAppChannel->id,
            'language_id' => $this->arabic->id,
            'body' => 'تحديث مالي',
        ]);

        $this->service->send($user, 'finance.transaction_status');

        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'channel_id' => $emailChannel->id,
            'status' => 'skipped_sensitivity',
        ]);

        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'channel_id' => $inAppChannel->id,
            'status' => 'queued',
        ]);
    }

    public function test_falls_back_to_arabic_template_when_user_language_template_is_missing(): void
    {
        Notification::fake();

        $user = User::factory()->create(['interface_language_id' => $this->english->id]);
        $type = NotificationType::where('key', 'admin.announcement')->firstOrFail();
        $inAppChannel = NotificationChannel::where('key', 'in_app')->firstOrFail();

        $arabicTemplate = NotificationTemplate::factory()->create([
            'notification_type_id' => $type->id,
            'channel_id' => $inAppChannel->id,
            'language_id' => $this->arabic->id,
            'body' => 'إعلان هام باللغة العربية',
        ]);

        $this->service->send($user, 'admin.announcement');

        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'template_id' => $arabicTemplate->id,
            'status' => 'queued',
        ]);
    }

    public function test_creates_delivery_per_channel_and_keeps_secret_out_of_database(): void
    {
        $user = User::factory()->create(['interface_language_id' => $this->arabic->id]);
        $type = NotificationType::where('key', 'account.verification_code')->firstOrFail();
        $emailChannel = NotificationChannel::where('key', 'email')->firstOrFail();

        NotificationTemplate::factory()->create([
            'notification_type_id' => $type->id,
            'channel_id' => $emailChannel->id,
            'language_id' => $this->arabic->id,
            'subject' => 'رمز التحقق',
            'body' => 'استخدم الرمز التالي لتأكيد حسابك:',
        ]);

        $this->service->send(
            $user,
            'account.verification_code',
            ['user_name' => 'فراس'],
            ['secret' => '987654']
        );

        $delivery = NotificationDelivery::where('user_id', $user->id)
            ->where('channel_id', $emailChannel->id)
            ->first();

        $this->assertNotNull($delivery);
        $this->assertSame('queued', $delivery->status);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $user->id]);

        $notification = new PlatformNotification(
            notificationUuid: $delivery->notification_uuid,
            typeKey: $type->key,
            channels: ['mail'],
            renderedTemplates: ['mail' => ['subject' => 'رمز التحقق', 'body' => 'استخدم الرمز التالي:']],
            data: ['user_name' => 'فراس', 'secret' => '987654'],
            options: ['secret' => '987654'],
        );

        /** @var MailMessage $mail */
        $mail = $notification->toMail($user);
        $this->assertContains('987654', $mail->introLines);

        $dbPayload = $notification->toDatabase($user);
        $this->assertArrayNotHasKey('secret', $dbPayload['data']);
    }
}
