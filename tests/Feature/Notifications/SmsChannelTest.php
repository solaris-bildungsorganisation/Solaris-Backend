<?php

namespace Tests\Feature\Notifications;

use App\Contracts\SmsProvider;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SmsChannelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sms_channel_sends_with_fake_provider(): void
    {
        $provider = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $provider);

        $user = $this->createUser('+963912345678');
        $notification = new SmsTestNotification('رمز التحقق هو 123456');

        app(SmsChannel::class)->send($user, $notification);

        $this->assertSame('+963912345678', $provider->phone);
        $this->assertSame('رمز التحقق هو 123456', $provider->message);
    }

    public function test_sms_channel_handles_missing_phone_safely(): void
    {
        $provider = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $provider);

        $user = $this->createUser(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a recipient phone number');

        app(SmsChannel::class)->send($user, new SmsTestNotification('اختبار'));
    }

    public function test_sent_listener_updates_notification_delivery(): void
    {
        $user = $this->createUser('+963912345678');
        $channel = $this->createSmsChannel();
        $notification = new SmsTestNotification('اختبار');
        $notification->id = (string) Str::uuid();

        $delivery = NotificationDelivery::create([
            'notification_uuid' => $notification->id,
            'user_id' => $user->id,
            'notification_type_id' => null,
            'channel_id' => $channel->id,
            'template_id' => null,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        Event::dispatch(new NotificationSent($user, $notification, 'sms', [
            'provider' => 'fake',
            'message_id' => 'provider-123',
        ]));

        $delivery->refresh();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('fake', $delivery->provider);
        $this->assertSame('provider-123', $delivery->provider_message_id);
        $this->assertNotNull($delivery->sent_at);
    }

    public function test_failed_listener_updates_notification_delivery_and_redacts_error(): void
    {
        $user = $this->createUser('+963912345678');
        $channel = $this->createSmsChannel();
        $notification = new SmsTestNotification('اختبار');
        $notification->id = (string) Str::uuid();

        $delivery = NotificationDelivery::create([
            'notification_uuid' => $notification->id,
            'user_id' => $user->id,
            'notification_type_id' => null,
            'channel_id' => $channel->id,
            'template_id' => null,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        Event::dispatch(new NotificationFailed(
            $user,
            $notification,
            'sms',
            ['exception' => new RuntimeException('password=secret 123456 failed')],
        ));

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->failed_at);
        $this->assertStringNotContainsString('secret', (string) $delivery->last_error);
        $this->assertStringNotContainsString('123456', (string) $delivery->last_error);
    }

    public function test_sent_listener_tracks_a_unique_mail_driver(): void
    {
        $user = $this->createUser('+963912345678');
        $channel = $this->createChannel('email', 'mail');
        $notification = new SmsTestNotification('اختبار');
        $notification->id = (string) Str::uuid();

        $delivery = NotificationDelivery::create([
            'notification_uuid' => $notification->id,
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        Event::dispatch(new NotificationSent($user, $notification, 'mail'));

        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_listener_does_not_guess_when_a_driver_has_multiple_channels(): void
    {
        $user = $this->createUser('+963912345678');
        $firstChannel = $this->createChannel('email_primary', 'mail');
        $this->createChannel('email_secondary', 'mail');
        $notification = new SmsTestNotification('اختبار');
        $notification->id = (string) Str::uuid();

        $delivery = NotificationDelivery::create([
            'notification_uuid' => $notification->id,
            'user_id' => $user->id,
            'channel_id' => $firstChannel->id,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        Event::dispatch(new NotificationSent($user, $notification, 'mail'));

        $this->assertSame('queued', $delivery->fresh()->status);
        $this->assertSame(0, $delivery->fresh()->attempts);
    }

    public function test_failed_attempts_increment_and_sent_delivery_is_not_overwritten(): void
    {
        $user = $this->createUser('+963912345678');
        $channel = $this->createSmsChannel();
        $notification = new SmsTestNotification('اختبار');
        $notification->id = (string) Str::uuid();

        $delivery = NotificationDelivery::create([
            'notification_uuid' => $notification->id,
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        $failure = new NotificationFailed($user, $notification, 'sms');
        Event::dispatch($failure);
        Event::dispatch($failure);

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);

        $delivery->forceFill(['status' => 'sent'])->save();
        Event::dispatch($failure);

        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);
    }

    private function createUser(?string $phone): User
    {
        return User::query()->create([
            'first_name' => 'SMS',
            'last_name' => 'Test',
            'email' => fake()->unique()->safeEmail(),
            'phone' => $phone,
            'password' => 'password-123',
        ]);
    }

    private function createSmsChannel(): NotificationChannel
    {
        return $this->createChannel('sms', 'sms');
    }

    private function createChannel(string $key, string $driver): NotificationChannel
    {
        return NotificationChannel::query()->create([
            'key' => $key,
            'name' => ['ar' => 'رسائل نصية', 'en' => 'SMS'],
            'driver' => $driver,
            'supports_sensitive' => true,
            'is_active' => true,
            'sort_order' => 3,
        ]);
    }
}

class SmsTestNotification extends Notification
{
    public function __construct(private readonly string $message) {}

    public function via(object $notifiable): array
    {
        return ['sms'];
    }

    public function toSms(object $notifiable): string
    {
        return $this->message;
    }
}

class FakeSmsProvider implements SmsProvider
{
    public ?string $phone = null;

    public ?string $message = null;

    public function send(string $phone, string $message): mixed
    {
        $this->phone = $phone;
        $this->message = $message;

        return [
            'provider' => 'fake',
            'message_id' => 'fake-123',
        ];
    }
}
