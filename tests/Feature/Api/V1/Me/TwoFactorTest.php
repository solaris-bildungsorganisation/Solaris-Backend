<?php

namespace Tests\Feature\Api\V1\Me;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithRbac;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * K1 — التحقق الثنائي TOTP: التفعيل والإدارة (NFR-SEC MFA · D8) + K2 step-up (POST /me/2fa/verify).
 * الوقت مثبَّت (travelTo) والرموز محسوبة منه — لا اعتماد على الساعة.
 */
class TwoFactorTest extends TestCase
{
    use InteractsWithRbac, InteractsWithTwoFactor, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->travelTo(now()->startOfMinute());
        // throttle:5,1 يُختبر منفصلًا في test_sensitive_endpoints_are_throttled.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->user = $this->userWithRole('learner');
    }

    private function as(User $user): static
    {
        return $this->withToken($this->loginAs($user));
    }

    /** enable ثم confirm عبر HTTP ويعيد [secret, recovery codes]. */
    private function enableViaApi(): array
    {
        $secret = $this->as($this->user)
            ->postJson('/api/v1/me/2fa/enable', ['current_password' => self::PASSWORD])
            ->assertOk()
            ->json('data.secret');

        $codes = $this->as($this->user)
            ->postJson('/api/v1/me/2fa/confirm', ['code' => $this->totp($secret)])
            ->assertOk()
            ->json('data.recovery_codes');

        $this->travel(30)->seconds();

        return [$secret, $codes];
    }

    // ───────────── GET /me/2fa ─────────────

    public function test_status_is_returned_for_owner(): void
    {
        $this->as($this->user)->getJson('/api/v1/me/2fa')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.pending', false)
            ->assertJsonPath('data.recovery_codes_remaining', 0);
    }

    public function test_status_is_denied_without_permission_or_token(): void
    {
        $this->getJson('/api/v1/me/2fa')->assertUnauthorized();

        $this->as(User::factory()->create())->getJson('/api/v1/me/2fa')->assertForbidden();
    }

    // ───────────── POST /me/2fa/enable ─────────────

    public function test_enable_returns_secret_qr_and_otpauth_but_does_not_enable(): void
    {
        $this->as($this->user)
            ->postJson('/api/v1/me/2fa/enable', ['current_password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'otpauth_url', 'qr_svg']])
            ->assertJsonPath('data.qr_svg', fn ($svg) => str_starts_with($svg, '<svg'))
            ->assertJsonPath('data.otpauth_url', fn ($url) => str_starts_with($url, 'otpauth://totp/'));

        $this->user->refresh();
        $this->assertNull($this->user->two_factor_confirmed_at, 'لا يُفعَّل قبل التأكيد');
        $this->assertNotNull($this->user->two_factor_secret);
    }

    public function test_enable_rejects_wrong_password_and_users_without_permission(): void
    {
        $this->as($this->user)
            ->postJson('/api/v1/me/2fa/enable', ['current_password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->as(User::factory()->create())
            ->postJson('/api/v1/me/2fa/enable', ['current_password' => self::PASSWORD])
            ->assertForbidden();
    }

    public function test_enable_is_rejected_when_already_enabled(): void
    {
        $this->enableViaApi();

        $this->as($this->user)
            ->postJson('/api/v1/me/2fa/enable', ['current_password' => self::PASSWORD])
            ->assertStatus(409);
    }

    // ───────────── POST /me/2fa/confirm ─────────────

    public function test_correct_code_enables_and_returns_recovery_codes_once(): void
    {
        [, $codes] = $this->enableViaApi();

        $this->assertCount(8, $codes);
        $this->user->refresh();
        $this->assertNotNull($this->user->two_factor_confirmed_at);

        $this->as($this->user)->getJson('/api/v1/me/2fa')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.recovery_codes_remaining', 8)
            ->assertJsonMissingPath('data.secret')
            ->assertJsonMissingPath('data.recovery_codes');

        $this->assertDatabaseHas('auth_events', ['user_id' => $this->user->id, 'event' => 'mfa_enabled']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'event' => 'two_factor_enabled', 'causer_id' => $this->user->id]);
    }

    public function test_wrong_code_is_rejected_and_logged(): void
    {
        $this->as($this->user)->postJson('/api/v1/me/2fa/enable', ['current_password' => self::PASSWORD])->assertOk();

        $this->as($this->user)
            ->postJson('/api/v1/me/2fa/confirm', ['code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertNull($this->user->refresh()->two_factor_confirmed_at);
        $this->assertDatabaseHas('auth_events', ['user_id' => $this->user->id, 'event' => 'mfa_failed']);
    }

    public function test_code_from_adjacent_step_is_accepted_but_not_two_steps_away(): void
    {
        $secret = $this->as($this->user)
            ->postJson('/api/v1/me/2fa/enable', ['current_password' => self::PASSWORD])->json('data.secret');

        $this->as($this->user)->postJson('/api/v1/me/2fa/confirm', ['code' => $this->totp($secret, -2)])
            ->assertUnprocessable();

        $this->as($this->user)->postJson('/api/v1/me/2fa/confirm', ['code' => $this->totp($secret, -1)])
            ->assertOk();
    }

    public function test_same_code_cannot_be_used_twice(): void
    {
        [$secret] = $this->enableViaApi();
        $code = $this->totp($secret);

        $this->as($this->user)->postJson('/api/v1/me/2fa/verify', ['code' => $code])->assertOk();
        $this->as($this->user)->postJson('/api/v1/me/2fa/verify', ['code' => $code])->assertUnprocessable();
    }

    public function test_confirm_without_pending_setup_returns_409(): void
    {
        $this->as($this->user)->postJson('/api/v1/me/2fa/confirm', ['code' => '123456'])->assertStatus(409);
    }

    public function test_confirm_is_denied_without_permission(): void
    {
        $this->as(User::factory()->create())->postJson('/api/v1/me/2fa/confirm', ['code' => '123456'])->assertForbidden();
    }

    public function test_enabling_revokes_other_sessions_but_keeps_current(): void
    {
        $other = $this->loginAs($this->user);
        $current = $this->loginAs($this->user);

        $secret = $this->withToken($current)
            ->postJson('/api/v1/me/2fa/enable', ['current_password' => self::PASSWORD])->json('data.secret');
        $this->withToken($current)->postJson('/api/v1/me/2fa/confirm', ['code' => $this->totp($secret)])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/me/2fa')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/v1/me/2fa')->assertOk();

        $this->assertSame(1, $this->user->sessions()->where('revoke_reason', 'security')->count());
    }

    // ───────────── السرية ─────────────

    public function test_secret_and_recovery_codes_are_encrypted_at_rest(): void
    {
        [$secret, $codes] = $this->enableViaApi();

        $row = DB::table('users')->where('id', $this->user->id)->first();
        $this->assertStringNotContainsString($secret, (string) $row->two_factor_secret);
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, (string) $row->two_factor_recovery_codes);
            $this->assertNotContains($code, $this->user->refresh()->two_factor_recovery_codes, 'تُخزَّن hash فقط');
        }

        $this->as($this->user)->getJson('/api/v1/me/2fa')->assertDontSee($secret);
        $this->as($this->user)->getJson('/api/v1/me')->assertDontSee($secret);
    }

    // ───────────── رموز الاسترداد ─────────────

    public function test_recovery_code_works_once(): void
    {
        [, $codes] = $this->enableViaApi();

        $this->as($this->user)->postJson('/api/v1/me/2fa/verify', ['code' => $codes[0]])->assertOk();
        $this->as($this->user)->postJson('/api/v1/me/2fa/verify', ['code' => $codes[0]])->assertUnprocessable();

        $this->as($this->user)->getJson('/api/v1/me/2fa')->assertJsonPath('data.recovery_codes_remaining', 7);
        $this->assertDatabaseHas('auth_events', ['user_id' => $this->user->id, 'event' => 'mfa_recovery_code_used']);
    }

    public function test_regenerate_recovery_codes_invalidates_old_ones(): void
    {
        [$secret, $old] = $this->enableViaApi();

        $new = $this->as($this->user)
            ->postJson('/api/v1/me/2fa/recovery-codes', ['current_password' => self::PASSWORD, 'code' => $this->totp($secret)])
            ->assertOk()
            ->json('data.recovery_codes');

        $this->assertCount(8, $new);
        $this->assertEmpty(array_intersect($old, $new));

        $this->as($this->user)->postJson('/api/v1/me/2fa/verify', ['code' => $old[1]])->assertUnprocessable();
        $this->as($this->user)->postJson('/api/v1/me/2fa/verify', ['code' => $new[1]])->assertOk();
        $this->assertDatabaseHas('auth_events', ['user_id' => $this->user->id, 'event' => 'mfa_recovery_codes_regenerated']);
    }

    public function test_regenerate_requires_password_code_and_permission(): void
    {
        [$secret] = $this->enableViaApi();

        $this->as($this->user)
            ->postJson('/api/v1/me/2fa/recovery-codes', ['current_password' => 'wrong', 'code' => $this->totp($secret)])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->as($this->user)
            ->postJson('/api/v1/me/2fa/recovery-codes', ['current_password' => self::PASSWORD, 'code' => '000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->as(User::factory()->create())
            ->postJson('/api/v1/me/2fa/recovery-codes', ['current_password' => self::PASSWORD, 'code' => '123456'])
            ->assertForbidden();
    }

    // ───────────── DELETE /me/2fa ─────────────

    public function test_disable_clears_all_three_fields(): void
    {
        [$secret] = $this->enableViaApi();

        $this->as($this->user)
            ->deleteJson('/api/v1/me/2fa', ['current_password' => self::PASSWORD, 'code' => $this->totp($secret)])
            ->assertNoContent();

        $this->user->refresh();
        $this->assertNull($this->user->two_factor_secret);
        $this->assertNull($this->user->two_factor_recovery_codes);
        $this->assertNull($this->user->two_factor_confirmed_at);
        $this->assertDatabaseHas('auth_events', ['user_id' => $this->user->id, 'event' => 'mfa_disabled']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'event' => 'two_factor_disabled']);
    }

    public function test_disable_without_password_is_rejected(): void
    {
        [$secret] = $this->enableViaApi();

        $this->as($this->user)
            ->deleteJson('/api/v1/me/2fa', ['code' => $this->totp($secret)])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->as($this->user)
            ->deleteJson('/api/v1/me/2fa', ['current_password' => 'wrong', 'code' => $this->totp($secret)])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertNotNull($this->user->refresh()->two_factor_confirmed_at);
    }

    public function test_disable_is_denied_without_permission_and_409_when_not_enabled(): void
    {
        $this->as(User::factory()->create())
            ->deleteJson('/api/v1/me/2fa', ['current_password' => self::PASSWORD, 'code' => '123456'])
            ->assertForbidden();

        $this->as($this->user)
            ->deleteJson('/api/v1/me/2fa', ['current_password' => self::PASSWORD, 'code' => '123456'])
            ->assertStatus(409);
    }

    // ───────────── POST /me/2fa/verify (K2 step-up) ─────────────

    public function test_verify_returns_step_up_window_of_15_minutes(): void
    {
        [$secret] = $this->enableViaApi();

        $this->as($this->user)->postJson('/api/v1/me/2fa/verify', ['code' => $this->totp($secret)])
            ->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.expires_in', 900);

        $this->assertDatabaseHas('auth_events', ['user_id' => $this->user->id, 'event' => 'mfa_step_up_verified']);
    }

    public function test_verify_rejects_wrong_code_and_users_without_2fa_or_permission(): void
    {
        $this->as($this->user)->postJson('/api/v1/me/2fa/verify', ['code' => '123456'])->assertStatus(409);

        $this->enableViaApi();
        $this->as($this->user)->postJson('/api/v1/me/2fa/verify', ['code' => '000000'])->assertUnprocessable();

        $this->as(User::factory()->create())->postJson('/api/v1/me/2fa/verify', ['code' => '123456'])->assertForbidden();
    }

    // ───────────── throttle ─────────────

    public function test_sensitive_endpoints_are_throttled(): void
    {
        $this->withMiddleware(ThrottleRequests::class);
        $token = $this->loginAs($this->user);

        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)->postJson('/api/v1/me/2fa/confirm', ['code' => '000000'])->assertStatus(409);
        }

        $this->withToken($token)->postJson('/api/v1/me/2fa/confirm', ['code' => '000000'])->assertStatus(429);
    }
}
