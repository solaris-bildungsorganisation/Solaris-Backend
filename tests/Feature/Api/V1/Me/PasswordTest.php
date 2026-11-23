<?php

namespace Tests\Feature\Api\V1\Me;

use App\Models\PasswordHistory;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Notifications\NotificationService;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_change_password(): void
    {
        $user = $this->makeUser('current-password-123');
        $notificationService = Mockery::mock(NotificationService::class);

$notificationService->shouldReceive('send')
    ->once()
    ->withArgs(function ($notifiable, $type) use ($user) {
        return $notifiable instanceof User
            && $notifiable->is($user)
            && $type === 'security.password_changed';
    })
    ->andReturn('notification-id');

$this->app->instance(NotificationService::class, $notificationService);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/me/password', [
            'current_password' => 'current-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'تم تغيير كلمة المرور بنجاح.']);
        $updatedUser = $user->fresh();
        $this->assertTrue(Hash::check('new-password-123', $updatedUser->getRawOriginal('password')));
        $this->assertNotNull($updatedUser->password_changed_at);
        $this->assertFalse($updatedUser->must_change_password);
        $this->assertTrue(Hash::check('current-password-123', PasswordHistory::query()->latest('id')->first()->password_hash));
        $this->assertDatabaseHas('auth_events', [
            'user_id' => $user->getKey(),
            'event' => 'password_changed',
        ]);
    }
public function test_password_change_succeeds_when_notification_fails(): void
{
    $user = $this->makeUser('current-password-123');

    $notificationService = Mockery::mock(NotificationService::class);

    $notificationService->shouldReceive('send')
        ->once()
        ->withArgs(function ($notifiable, $type) use ($user) {
            return $notifiable instanceof User
                && $notifiable->is($user)
                && $type === 'security.password_changed';
        })
        ->andThrow(new \RuntimeException('Notification failed'));

    $this->app->instance(NotificationService::class, $notificationService);

    Sanctum::actingAs($user);

    $response = $this->putJson('/api/v1/me/password', [
        'current_password' => 'current-password-123',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertOk();

    $this->assertTrue(
        Hash::check('new-password-123', $user->fresh()->getRawOriginal('password'))
    );
}
    public function test_it_requires_authentication_to_change_password(): void
    {
        $response = $this->putJson('/api/v1/me/password', [
            'current_password' => 'current-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertUnauthorized();
    }

    public function test_it_rejects_an_incorrect_current_password(): void
    {
        $user = $this->makeUser('current-password-123');
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/me/password', [
            'current_password' => 'wrong-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(422);
        $this->assertTrue(Hash::check('current-password-123', $user->fresh()->getRawOriginal('password')));
    }

    public function test_it_rejects_a_password_from_recent_history(): void
    {
        $user = $this->makeUser('current-password-123');
        PasswordHistory::query()->create([
            'user_id' => $user->getKey(),
            'password_hash' => Hash::make('old-password-123'),
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/me/password', [
            'current_password' => 'current-password-123',
            'password' => 'old-password-123',
            'password_confirmation' => 'old-password-123',
        ]);

        $response->assertStatus(422);
        $this->assertTrue(Hash::check('current-password-123', $user->fresh()->getRawOriginal('password')));
    }

    public function test_it_keeps_the_current_session_and_revokes_other_sessions(): void
    {
        $user = $this->makeUser('current-password-123');
        $currentToken = $user->createToken('current');
        $otherToken = $user->createToken('other');
        $currentSession = UserSession::query()->create([
            'user_id' => $user->getKey(),
            'personal_access_token_id' => $currentToken->accessToken->id,
        ]);
        $otherSession = UserSession::query()->create([
            'user_id' => $user->getKey(),
            'personal_access_token_id' => $otherToken->accessToken->id,
        ]);

        $response = $this->withToken($currentToken->plainTextToken)->putJson('/api/v1/me/password', [
            'current_password' => 'current-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertOk();
        $this->assertNull($currentSession->fresh()->revoked_at);
        $this->assertNotNull($otherSession->fresh()->revoked_at);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
    }

    private function makeUser(string $password): User
    {
        return User::query()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => fake()->unique()->safeEmail(),
            'password' => $password,
        ]);
    }
}
