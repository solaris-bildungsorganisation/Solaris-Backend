<?php

namespace Database\Factories;

use App\Models\Language;
use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    public function definition(): array
    {
        return [
            'notification_type_id' => fn() => NotificationType::firstOrCreate(
                ['key' => 'test.notification'],
                [
                    'category' => 'account',
                    'name' => ['ar' => 'إشعار تجريبي', 'en' => 'Test Notification'],
                    'default_channels' => ['in_app', 'email'],
                ]
            )->id,
            'channel_id' => fn() => NotificationChannel::firstOrCreate(
                ['key' => 'in_app'],
                [
                    'name' => ['ar' => 'داخل المنصة', 'en' => 'In-app'],
                    'driver' => 'database',
                    'supports_sensitive' => true,
                ]
            )->id,
            'language_id' => fn() => Language::firstOrCreate(
                ['code' => 'ar'],
                [
                    'name' => ['ar' => 'العربية', 'en' => 'Arabic'],
                    'native_name' => 'العربية',
                    'direction' => 'rtl',
                ]
            )->id,

            'subject' => $this->faker->sentence(4),
            'body' => 'مرحباً {{ name }}، لديك إشعار جديد.',
            'version' => 1,
            'is_active' => true,
            'updated_by' => null,
        ];
    }
}
