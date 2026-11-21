<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\AuthEvent;
use App\Models\PasswordHistory;
use App\Models\User;
use App\Models\UserSession;
use App\Models\VerificationCode;
use App\Notifications\PasswordResetCodeNotification;
use App\Services\VerificationCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_uniform_response_and_creates_a_code_for_an_existing_email(): void
    {
        Notification::fake();

        $user = User::query()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ]);

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'identifier' => $user->email,
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'message' => 'إذا كانت البيانات صحيحة، سيتم إرسال رمز استعادة كلمة المرور.',
            ])
            ->assertJsonMissing(['code']);

        Notification::assertSentTo(
            $user,
            PasswordResetCodeNotification::class,
            fn (PasswordResetCodeNotification $notification, array $channels): bool => $channels === ['mail'],
        );

        $this->assertDatabaseHas('verification_codes', [
            'user_id' => $user->getKey(),
            'channel' => 'email',
            'destination' => $user->email,
            'purpose' => 'password_reset',
            'max_attempts' => 5,
        ]);
        $this->assertDatabaseHas('auth_events', [
            'user_id' => $user->getKey(),
            'event' => 'password_reset_requested',
        ]);
    }

    public function test_it_returns_the_same_response_without_creating_a_code_for_an_unknown_identifier(): void
    {
        Notification::fake();

        $existingUser = User::query()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ]);

        $existingResponse = $this->postJson('/api/v1/auth/password/forgot', [
            'identifier' => $existingUser->email,
        ]);

        $this->assertSame(1, $this->getCodeCount());
        $this->assertSame(1, AuthEvent::query()->where('event', 'password_reset_requested')->count());

        $unknownResponse = $this->postJson('/api/v1/auth/password/forgot', [
            'identifier' => 'unknown@example.com',
        ]);

        $unknownResponse->assertStatus(202)
            ->assertJsonMissing(['code']);
        $this->assertSame($existingResponse->json(), $unknownResponse->json());
        $this->assertSame(1, $this->getCodeCount());
        $this->assertSame(1, AuthEvent::query()->where('event', 'password_reset_requested')->count());
        Notification::assertSentTo($existingUser, PasswordResetCodeNotification::class);
        Notification::assertCount(1);
    }

    public function test_it_issues_and_verifies_a_password_reset_code(): void
    {
        $result = app(VerificationCodeService::class)->issue('email', 'user@example.com');

        $this->assertSame('password_reset', $result['verificationCode']->purpose);
        $this->assertSame(5, $result['verificationCode']->max_attempts);
        $this->assertTrue(Hash::check($result['code'], $result['verificationCode']->code_hash));
        $this->assertNotSame($result['code'], $result['verificationCode']->code_hash);
        $this->assertTrue(app(VerificationCodeService::class)->verify($result['verificationCode'], $result['code']));
        $this->assertNotNull($result['verificationCode']->fresh()->consumed_at);
    }

    public function test_it_increments_attempts_for_an_incorrect_code(): void
    {
        $result = app(VerificationCodeService::class)->issue('email', 'user@example.com');

        $this->assertFalse(app(VerificationCodeService::class)->verify($result['verificationCode'], '000000'));
        $this->assertSame(1, $result['verificationCode']->fresh()->attempts);
    }

    public function test_it_rejects_an_expired_code(): void
    {
        $result = app(VerificationCodeService::class)->issue('email', 'user@example.com');
        $result['verificationCode']->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertFalse(app(VerificationCodeService::class)->verify($result['verificationCode'], $result['code']));
        $this->assertSame(0, $result['verificationCode']->fresh()->attempts);
    }

    public function test_it_rejects_the_correct_code_after_five_incorrect_attempts(): void
    {
        $result = app(VerificationCodeService::class)->issue('email', 'user@example.com');
        $service = app(VerificationCodeService::class);

        foreach (range(1, 5) as $attempt) {
            $this->assertFalse($service->verify($result['verificationCode'], '000000'));
        }

        $verificationCode = $result['verificationCode']->fresh();

        $this->assertSame(5, $verificationCode->attempts);
        $this->assertNotNull($verificationCode->consumed_at);
        $this->assertFalse($service->verify($verificationCode, $result['code']));
    }

    public function test_it_resets_password_and_records_the_previous_password(): void
    {
        $oldPassword = 'old-password-123';
        $newPassword = 'new-password-123';
        $user = User::query()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'reset@example.com',
            'password' => $oldPassword,
        ]);
        $user->forceFill([
            'must_change_password' => true,
            'failed_login_count' => 3,
            'locked_until' => now()->addMinutes(10),
        ])->save();
        $result = app(VerificationCodeService::class)->issue('email', $user->email, $user->getKey());

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => $user->email,
            'code' => $result['code'],
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'تمت إعادة تعيين كلمة المرور بنجاح.']);

        $user = $user->fresh();
        $this->assertTrue(Hash::check($newPassword, $user->getRawOriginal('password')));
        $this->assertNotNull($user->password_changed_at);
        $this->assertFalse($user->must_change_password);
        $this->assertSame(0, $user->failed_login_count);
        $this->assertNull($user->locked_until);
        $this->assertTrue(Hash::check($oldPassword, PasswordHistory::query()->latest('id')->first()->password_hash));
        $this->assertDatabaseHas('auth_events', [
            'user_id' => $user->getKey(),
            'event' => 'password_reset_completed',
        ]);
    }

    public function test_it_rejects_an_invalid_reset_code_without_changing_the_password(): void
    {
        $user = User::query()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'invalid-reset@example.com',
            'password' => 'old-password-123',
        ]);
        app(VerificationCodeService::class)->issue('email', $user->email, $user->getKey());

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => $user->email,
            'code' => '000000',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'تعذر إعادة تعيين كلمة المرور بالبيانات المقدمة.']);
        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->getRawOriginal('password')));
        $this->assertDatabaseMissing('auth_events', [
            'user_id' => $user->getKey(),
            'event' => 'password_reset_completed',
        ]);
    }

    public function test_it_rejects_reuse_of_a_recent_password(): void
    {
        $reusedPassword = 'reused-password-123';
        $user = User::query()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'history-reset@example.com',
            'password' => 'current-password-123',
        ]);
        PasswordHistory::query()->create([
            'user_id' => $user->getKey(),
            'password_hash' => Hash::make($reusedPassword),
        ]);
        $result = app(VerificationCodeService::class)->issue('email', $user->email, $user->getKey());

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => $user->email,
            'code' => $result['code'],
            'password' => $reusedPassword,
            'password_confirmation' => $reusedPassword,
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'تعذر إعادة تعيين كلمة المرور بالبيانات المقدمة.']);
        $this->assertTrue(Hash::check('current-password-123', $user->fresh()->getRawOriginal('password')));
        $this->assertNull($result['verificationCode']->fresh()->consumed_at);
    }

    public function test_it_resets_password_successfully_for_a_phone_identifier(): void
    {
        $newPassword = 'new-phone-password-123';
        $user = User::query()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'phone' => '+15551234567',
            'password' => 'old-phone-password-123',
        ]);
        $result = app(VerificationCodeService::class)->issue('sms', $user->phone, $user->getKey());

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => $user->phone,
            'code' => $result['code'],
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'تمت إعادة تعيين كلمة المرور بنجاح.']);
        $this->assertDatabaseHas('verification_codes', [
            'user_id' => $user->getKey(),
            'channel' => 'sms',
            'destination' => $user->phone,
        ]);
        $this->assertTrue(Hash::check($newPassword, $user->fresh()->getRawOriginal('password')));
    }

    public function test_it_revokes_all_sessions_and_tokens_after_password_reset(): void
    {
        $user = User::query()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'session-reset@example.com',
            'password' => 'old-password-123',
        ]);
        $firstToken = $user->createToken('first');
        $secondToken = $user->createToken('second');
        $firstSession = UserSession::query()->create([
            'user_id' => $user->getKey(),
            'personal_access_token_id' => $firstToken->accessToken->id,
        ]);
        $secondSession = UserSession::query()->create([
            'user_id' => $user->getKey(),
            'personal_access_token_id' => $secondToken->accessToken->id,
        ]);
        $result = app(VerificationCodeService::class)->issue('email', $user->email, $user->getKey());

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => $user->email,
            'code' => $result['code'],
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertOk();
        $this->assertNotNull($firstSession->fresh()->revoked_at);
        $this->assertNotNull($secondSession->fresh()->revoked_at);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $firstToken->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $secondToken->accessToken->id]);
    }

    private function getCodeCount(): int
    {
        return VerificationCode::query()->count();
    }
}
