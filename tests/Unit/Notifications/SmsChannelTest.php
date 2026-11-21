<?php

namespace Tests\Unit\Notifications;

use App\Contracts\SmsProvider;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use Tests\TestCase;

class SmsChannelTest extends TestCase
{
    public function test_sms_channel_sends_with_fake_provider(): void
    {
        $provider = new UnitFakeSmsProvider;
        $this->app->instance(SmsProvider::class, $provider);

        $channel = app(SmsChannel::class);
        $user = new UnitSmsUser('+963912345678');

        $channel->send($user, new UnitSmsNotification('رمز التحقق هو 123456'));

        $this->assertSame('+963912345678', $provider->phone);
        $this->assertSame('رمز التحقق هو 123456', $provider->message);
    }

    public function test_sms_channel_handles_missing_phone_safely(): void
    {
        $provider = new UnitFakeSmsProvider;
        $this->app->instance(SmsProvider::class, $provider);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('recipient phone number');

        app(SmsChannel::class)->send(
            new UnitSmsUser(null),
            new UnitSmsNotification('اختبار'),
        );
    }
}

class UnitSmsUser
{
    public function __construct(public ?string $phone) {}

    public function routeNotificationFor(string $channel): ?string
    {
        return $channel === 'sms' ? $this->phone : null;
    }
}

class UnitSmsNotification extends Notification
{
    public function __construct(private readonly string $message) {}

    public function toSms(object $notifiable): string
    {
        return $this->message;
    }
}

class UnitFakeSmsProvider implements SmsProvider
{
    public ?string $phone = null;

    public ?string $message = null;

    public function send(string $phone, string $message): mixed
    {
        $this->phone = $phone;
        $this->message = $message;

        return ['provider' => 'fake', 'message_id' => 'fake-123'];
    }
}
