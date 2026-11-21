<?php

namespace Tests\Feature\Notifications;

use App\Models\Language;
use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\PlatformNotification;
use Database\Seeders\Notifications\NotificationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class NotificationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Language $arabic;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => [
                    'host' => '127.0.0.1',
                    'port' => 8080,
                    'scheme' => 'http',
                    'useTLS' => false,
                ],
            ],
        ]);
        require base_path('routes/channels.php');

        $this->seed(NotificationCatalogSeeder::class);
        $this->arabic = Language::query()->firstOrCreate(
            ['code' => 'ar'],
            [
                'name' => ['ar' => 'العربية', 'en' => 'Arabic'],
                'native_name' => 'العربية',
                'direction' => 'rtl',
                'is_interface' => true,
                'is_active' => true,
            ],
        );
    }

    public function test_broadcast_auth_allows_owner(): void
    {
        /** @var User $owner */
        $owner = User::factory()->create();

        Sanctum::actingAs($owner);
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-users.' . $owner->uuid,
            'socket_id' => '1234.5678',
        ])->assertOk();
    }

    public function test_broadcast_auth_rejects_other_user(): void
    {
        /** @var User $owner */
        $owner = User::factory()->create();
        /** @var User $other */
        $other = User::factory()->create();

        Sanctum::actingAs($other);
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-users.' . $owner->uuid,
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }

    public function test_in_app_notification_is_broadcast_with_resource_payload_and_unread_count(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $user = User::factory()->create(['interface_language_id' => $this->arabic->id]);
        $type = NotificationType::query()->where('key', 'result.published')->firstOrFail();
        $inApp = NotificationChannel::query()->where('key', 'in_app')->firstOrFail();

        NotificationTemplate::factory()->create([
            'notification_type_id' => $type->id,
            'channel_id' => $inApp->id,
            'language_id' => $this->arabic->id,
            'subject' => 'نتيجة {{ title }}',
            'body' => 'تم نشر النتيجة',
        ]);

        $uuid = app(NotificationService::class)->send(
            $user,
            'result.published',
            ['title' => 'البرمجة'],
        );

        $this->assertDatabaseHas('notifications', [
            'id' => $uuid,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'type' => 'App\Services\Notifications\PlatformNotification',
        ]);

        Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $event) use ($user): bool {
            $channels = $event->broadcastOn();
            $channelNames = array_map(
                static fn($channel): string => $channel->name,
                $channels,
            );

            if (!in_array('private-users.' . $user->uuid, $channelNames, true)) {
                return false;
            }

            if ($event->broadcastAs() !== 'notification.created') {
                return false;
            }

            $payload = $event->data;

            foreach (['id', 'category', 'type', 'title', 'body', 'action_url', 'read_at', 'created_at', 'unread_count'] as $key) {
                if (!array_key_exists($key, $payload)) {
                    return false;
                }
            }

            if ($payload['unread_count'] !== 1) {
                return false;
            }

            return !array_key_exists('secret', $payload)
                && !array_key_exists('secret', $payload['data'] ?? []);
        });
    }

    public function test_broadcast_payload_never_contains_secret_options(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $user = User::factory()->create(['interface_language_id' => $this->arabic->id]);
        $type = NotificationType::query()->where('key', 'admin.announcement')->firstOrFail();
        $inApp = NotificationChannel::query()->where('key', 'in_app')->firstOrFail();

        NotificationTemplate::factory()->create([
            'notification_type_id' => $type->id,
            'channel_id' => $inApp->id,
            'language_id' => $this->arabic->id,
            'body' => 'إعلان إداري',
        ]);

        $notification = new PlatformNotification(
            notificationUuid: (string) Str::uuid(),
            typeKey: 'admin.announcement',
            channels: ['database', 'broadcast'],
            renderedTemplates: [
                'database' => [
                    'subject' => 'إعلان إداري',
                    'body' => 'إعلان إداري',
                ],
            ],
            data: ['token' => 'data-token', 'secret' => 'data-secret'],
            options: ['secret' => 'option-secret'],
            recipientUuid: (string) $user->uuid,
        );

        $user->notify($notification);

        Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $event): bool {
            $json = json_encode($event->data, JSON_UNESCAPED_UNICODE);

            return !str_contains((string) $json, 'option-secret')
                && !str_contains((string) $json, 'data-secret')
                && !str_contains((string) $json, 'data-token')
                && !array_key_exists('secret', $event->data)
                && !array_key_exists('secret', $event->data['data'] ?? [])
                && !array_key_exists('token', $event->data['data'] ?? []);
        });
    }

    public function test_safe_broadcast_failure_does_not_remove_database_notification_or_fail_send(): void
    {
        Log::spy();

        $user = User::factory()->create(['interface_language_id' => $this->arabic->id]);
        $type = NotificationType::query()->where('key', 'session.reminder')->firstOrFail();
        $inApp = NotificationChannel::query()->where('key', 'in_app')->firstOrFail();

        NotificationTemplate::factory()->create([
            'notification_type_id' => $type->id,
            'channel_id' => $inApp->id,
            'language_id' => $this->arabic->id,
            'body' => 'تذكير بالجلسة',
        ]);

        $uuid = app(NotificationService::class)->send($user, 'session.reminder', ['title' => 'البرمجة']);

        $this->assertNotSame('', $uuid);
        $this->assertDatabaseHas('notifications', [
            'id' => $uuid,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_uuid' => $uuid,
            'user_id' => $user->id,
            'channel_id' => $inApp->id,
        ]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Real-time notification broadcast failed', \Mockery::on(
                static fn(array $context): bool => ($context['notification_class'] ?? null) === PlatformNotification::class
                    && isset($context['exception'])
            ));
    }
}


