<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_device_notification_is_sent_only_once_for_same_device(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);

        $notificationService = Mockery::mock(NotificationService::class);

        $notificationService->shouldReceive('send')
            ->once()
            ->withArgs(fn ($notifiable, $type) =>
                $notifiable->id === $user->id
                && $type === 'security.new_login'
            );

        $this->app->instance(NotificationService::class, $notificationService);

        $headers = [
            'User-Agent' => 'Solaris-Test-Device',
        ];

        // أول تسجيل دخول من الجهاز: يجب إرسال الإشعار.
        $this->withHeaders($headers)
            ->postJson('/api/v1/auth/login', [
                'identifier' => 'test@example.com',
                'password' => 'password123',
            ])
            ->assertOk()
            ->assertJsonStructure(['access_token']);

        // تسجيل دخول ثانٍ من الجهاز نفسه: لا يُرسل إشعار جديد.
        $this->withHeaders($headers)
            ->postJson('/api/v1/auth/login', [
                'identifier' => 'test@example.com',
                'password' => 'password123',
            ])
            ->assertOk()
            ->assertJsonStructure(['access_token']);
    }
    public function test_login_succeeds_when_new_login_notification_fails(): void
{
$user = User::factory()->create([

    'email' => 'failure-test@example.com',

    'password' => bcrypt('password123'),

    'status' => 'active',

]);

$notificationService = Mockery::mock(NotificationService::class);

$notificationService->shouldReceive('send')

    ->once()

    ->andThrow(new \RuntimeException('Notification service unavailable'));

$this->app->instance(NotificationService::class, $notificationService);

$this->withHeaders([

    'User-Agent' => 'Solaris-New-Failure-Test-Device',

])->postJson('/api/v1/auth/login', [

    'identifier' => 'failure-test@example.com',

    'password' => 'password123',

])

    ->assertOk()

    ->assertJsonStructure(['access_token']);
}
}
