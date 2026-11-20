<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\AuthEvent;
use App\Models\User;
use App\Services\Rbac\MfaGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithRbac;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * K2 — تحدّي 2FA عند الدخول + التحقق الإضافي (step-up) + mfa.confirmed الحقيقي
 * (NFR-SEC MFA · FR-AUTH-009, 025, 028 · D8). الوقت مثبَّت — لا اعتماد على الساعة.
 */
class TwoFactorChallengeTest extends TestCase
{
    use InteractsWithRbac, InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->travelTo(now()->startOfMinute());
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function login(User $user, string $password = self::PASSWORD): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => $password]);
    }

    private function challengeToken(User $user): string
    {
        return $this->login($user)->assertOk()->json('challenge_token');
    }

    // ───────────── POST /auth/login ─────────────

    public function test_user_with_2fa_gets_no_token_from_login(): void
    {
        $user = $this->userWithRole('learner');
        $this->enableTwoFactor($user);

        $this->login($user)
            ->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonPath('expires_in', 300)
            ->assertJsonMissingPath('access_token')
            ->assertJsonStructure(['challenge_token']);

        $this->assertSame(0, $user->tokens()->count(), 'لا توكن قبل نجاح التحدّي');
        $this->assertSame(0, $user->sessions()->count());
    }

    public function test_user_without_2fa_logs_in_as_before(): void
    {
        $user = $this->userWithRole('learner');

        $this->login($user)
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'data' => ['uuid', 'email']])
            ->assertJsonMissingPath('two_factor_required');

        $this->assertSame(1, $user->sessions()->count());
    }

    public function test_wrong_password_still_rejected_before_any_challenge(): void
    {
        $user = $this->userWithRole('learner');
        $this->enableTwoFactor($user);

        $this->login($user, 'wrong')->assertUnauthorized()->assertJsonMissingPath('challenge_token');
    }

    // ───────────── POST /auth/2fa/challenge ─────────────

    public function test_challenge_with_correct_code_gives_token_and_session(): void
    {
        $user = $this->userWithRole('learner');
        [$secret] = $this->enableTwoFactor($user);

        $response = $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $this->challengeToken($user),
            'code' => $this->totp($secret),
            'device_name' => 'Test device',
        ])->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('data.uuid', $user->uuid)
            ->assertJsonPath('two_factor.method', 'totp');

        $this->assertSame(1, $user->sessions()->count());
        $this->assertSame('Test device', $user->sessions()->first()->device_name);
        $this->assertDatabaseHas('auth_events', ['user_id' => $user->id, 'event' => 'mfa_challenge_passed']);

        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('access_token'))->getJson('/api/v1/me/2fa')
            ->assertOk()->assertJsonPath('data.enabled', true);
    }

    public function test_challenge_token_is_single_use(): void
    {
        $user = $this->userWithRole('learner');
        [$secret] = $this->enableTwoFactor($user);
        $challenge = $this->challengeToken($user);

        $this->postJson('/api/v1/auth/2fa/challenge', ['challenge_token' => $challenge, 'code' => $this->totp($secret)])
            ->assertOk();

        $this->travel(30)->seconds();
        $this->postJson('/api/v1/auth/2fa/challenge', ['challenge_token' => $challenge, 'code' => $this->totp($secret)])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'challenge_invalid');
    }

    public function test_expired_challenge_is_rejected(): void
    {
        $user = $this->userWithRole('learner');
        [$secret] = $this->enableTwoFactor($user);
        $challenge = $this->challengeToken($user);

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();

        $this->postJson('/api/v1/auth/2fa/challenge', ['challenge_token' => $challenge, 'code' => $this->totp($secret)])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'challenge_invalid');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_unknown_challenge_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/2fa/challenge', ['challenge_token' => str_repeat('x', 64), 'code' => '123456'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'challenge_invalid');
    }

    public function test_challenge_requires_code_or_recovery_code(): void
    {
        $this->postJson('/api/v1/auth/2fa/challenge', ['challenge_token' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'recovery_code']);
    }

    public function test_recovery_code_works_once_in_challenge(): void
    {
        $user = $this->userWithRole('learner');
        [, $codes] = $this->enableTwoFactor($user);

        $this->postJson('/api/v1/auth/2fa/challenge', ['challenge_token' => $this->challengeToken($user), 'recovery_code' => $codes[0]])
            ->assertOk()
            ->assertJsonPath('two_factor.method', 'recovery')
            ->assertJsonPath('two_factor.recovery_codes_remaining', 7);

        $this->postJson('/api/v1/auth/2fa/challenge', ['challenge_token' => $this->challengeToken($user), 'recovery_code' => $codes[0]])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_code');

        $this->assertDatabaseHas('auth_events', ['user_id' => $user->id, 'event' => 'mfa_recovery_code_used']);
    }

    public function test_wrong_codes_count_towards_lockout_and_revoke_challenge_after_five(): void
    {
        $user = $this->userWithRole('learner');
        [$secret] = $this->enableTwoFactor($user);
        $challenge = $this->challengeToken($user);

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/auth/2fa/challenge', ['challenge_token' => $challenge, 'code' => '000000'])
                ->assertUnauthorized()
                ->assertJsonPath('code', 'invalid_code');
        }
        $this->assertSame(4, $user->refresh()->failed_login_count);

        $this->postJson('/api/v1/auth/2fa/challenge', ['challenge_token' => $challenge, 'code' => '000000'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'challenge_invalid');

        $user->refresh();
        $this->assertTrue($user->locked_until->isFuture(), 'الرموز الخاطئة ضمن محاولات قفل الحساب');
        $this->assertSame(5, AuthEvent::query()->where('user_id', $user->id)->where('event', 'mfa_challenge_failed')->count());

        // التحدّي أُبطل، والحساب مقفل حتى مع كلمة مرور صحيحة.
        $this->postJson('/api/v1/auth/2fa/challenge', ['challenge_token' => $challenge, 'code' => $this->totp($secret)])
            ->assertUnauthorized();
        $this->login($user)->assertStatus(423);
    }

    public function test_inactive_user_cannot_complete_challenge(): void
    {
        $user = $this->userWithRole('learner');
        [$secret] = $this->enableTwoFactor($user);
        $challenge = $this->challengeToken($user);

        $user->forceFill(['status' => 'suspended'])->save();

        $this->postJson('/api/v1/auth/2fa/challenge', ['challenge_token' => $challenge, 'code' => $this->totp($secret)])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_unavailable');
    }

    public function test_challenge_shares_login_throttle(): void
    {
        $this->withMiddleware(ThrottleRequests::class);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/2fa/challenge', ['challenge_token' => 'x', 'code' => '123456'])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/2fa/challenge', ['challenge_token' => 'x', 'code' => '123456'])->assertStatus(429);
    }

    // ───────────── mfa.confirmed + step-up ─────────────

    public function test_enforce_blocks_session_revocation_until_step_up_then_allows(): void
    {
        config(['solaris.mfa.enforce' => true]);
        $admin = $this->userWithRole('platform_admin');
        [$secret] = $this->enableTwoFactor($admin);
        $target = User::factory()->create();
        $token = $this->loginAs($admin);

        $this->withToken($token)
            ->deleteJson("/api/v1/admin/users/{$target->uuid}/sessions", ['reason' => 'security incident'])
            ->assertForbidden()
            ->assertJsonPath('code', 'mfa_required');

        $this->withToken($token)->postJson('/api/v1/me/2fa/verify', ['code' => $this->totp($secret)])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushAccess();
        $this->withToken($token)
            ->deleteJson("/api/v1/admin/users/{$target->uuid}/sessions", ['reason' => 'security incident'])
            ->assertNoContent();
    }

    public function test_step_up_expires_after_15_minutes(): void
    {
        config(['solaris.mfa.enforce' => true]);
        $admin = $this->userWithRole('platform_admin');
        [$secret] = $this->enableTwoFactor($admin);
        $target = User::factory()->create();
        $token = $this->loginAs($admin);

        $this->withToken($token)->postJson('/api/v1/me/2fa/verify', ['code' => $this->totp($secret)])->assertOk();

        $this->travel(15)->minutes();
        $this->travel(1)->seconds();
        $this->app['auth']->forgetGuards();
        $this->flushAccess();

        $this->withToken($token)
            ->deleteJson("/api/v1/admin/users/{$target->uuid}/sessions", ['reason' => 'security incident'])
            ->assertForbidden()
            ->assertJsonPath('code', 'mfa_required');
    }

    public function test_step_up_is_per_session(): void
    {
        config(['solaris.mfa.enforce' => true]);
        $admin = $this->userWithRole('platform_admin');
        [$secret] = $this->enableTwoFactor($admin);
        $target = User::factory()->create();
        $verified = $this->loginAs($admin);
        $other = $this->loginAs($admin);

        $this->withToken($verified)->postJson('/api/v1/me/2fa/verify', ['code' => $this->totp($secret)])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushAccess();
        $this->withToken($other)
            ->deleteJson("/api/v1/admin/users/{$target->uuid}/sessions", ['reason' => 'security incident'])
            ->assertForbidden();
    }

    public function test_enforce_without_2fa_returns_setup_required(): void
    {
        config(['solaris.mfa.enforce' => true]);
        $admin = $this->userWithRole('platform_admin');
        $target = User::factory()->create();

        $this->withToken($this->loginAs($admin))
            ->deleteJson("/api/v1/admin/users/{$target->uuid}/sessions", ['reason' => 'security incident'])
            ->assertForbidden()
            ->assertJsonPath('code', 'mfa_setup_required');
    }

    public function test_warn_mode_allows_without_step_up(): void
    {
        config(['solaris.mfa.enforce' => false, 'solaris.mfa.required_for_admins' => false]);
        $admin = $this->userWithRole('platform_admin');
        $target = User::factory()->create();

        $this->withToken($this->loginAs($admin))
            ->deleteJson("/api/v1/admin/users/{$target->uuid}/sessions", ['reason' => 'security incident'])
            ->assertNoContent();
    }

    public function test_required_for_admins_enforces_on_administrative_roles_only(): void
    {
        config(['solaris.mfa.enforce' => false, 'solaris.mfa.required_for_admins' => true]);
        $admin = $this->userWithRole('platform_admin');
        $target = User::factory()->create();

        $this->withToken($this->loginAs($admin))
            ->deleteJson("/api/v1/admin/users/{$target->uuid}/sessions", ['reason' => 'security incident'])
            ->assertForbidden()
            ->assertJsonPath('code', 'mfa_setup_required');

        $guard = app(MfaGuard::class);
        $this->assertTrue($guard->enforcedFor($admin));
        $this->assertFalse($guard->enforcedFor($this->userWithRole('learner')));
    }

    public function test_login_via_challenge_counts_as_fresh_step_up(): void
    {
        config(['solaris.mfa.enforce' => true]);
        $admin = $this->userWithRole('platform_admin');
        [$secret] = $this->enableTwoFactor($admin);
        $target = User::factory()->create();

        $token = $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $this->challengeToken($admin),
            'code' => $this->totp($secret),
        ])->assertOk()->json('access_token');

        $this->app['auth']->forgetGuards();
        $this->flushAccess();
        $this->withToken($token)
            ->deleteJson("/api/v1/admin/users/{$target->uuid}/sessions", ['reason' => 'security incident'])
            ->assertNoContent();
    }

    public function test_permissions_endpoint_reports_step_up_state(): void
    {
        $user = $this->userWithRole('learner');
        [$secret] = $this->enableTwoFactor($user);
        $token = $this->loginAs($user);

        $this->withToken($token)->getJson('/api/v1/me/permissions')
            ->assertOk()
            ->assertJsonPath('data.mfa.two_factor_enabled', true)
            ->assertJsonPath('data.mfa.confirmed', false);

        $this->withToken($token)->postJson('/api/v1/me/2fa/verify', ['code' => $this->totp($secret)])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushAccess();
        $this->withToken($token)->getJson('/api/v1/me/permissions')
            ->assertOk()
            ->assertJsonPath('data.mfa.confirmed', true);
    }
}
