<?php

namespace Tests\Feature\Me;

use App\Models\AccessScope;
use App\Models\Country;
use App\Models\User;
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(User $user, array $attrs = []): array
    {
        $token = $user->createToken('test');
        $session = $user->sessions()->create(array_merge([
            'personal_access_token_id' => $token->accessToken->id,
            'last_activity_at' => now(),
            'expires_at' => now()->addDay(),
        ], $attrs));

        return [$token->plainTextToken, $session];
    }

    public function test_revoked_token_returns_401(): void
    {
        $user = User::factory()->create();
        [$plain, $session] = $this->loginAs($user);

        app(SessionService::class)->revoke($session, $user, 'logout');

        $this->withToken($plain)->getJson('/api/v1/me/sessions')
            ->assertUnauthorized();
    }

    public function test_expired_session_is_rejected(): void
    {
        $user = User::factory()->create();
        [$plain] = $this->loginAs($user, ['expires_at' => now()->subMinute()]);

        $this->withToken($plain)->getJson('/api/v1/me/sessions')
            ->assertUnauthorized();
    }

    public function test_other_users_session_returns_404(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        [$plain] = $this->loginAs($user);
        [, $otherSession] = $this->loginAs($other);

        $this->withToken($plain)
            ->deleteJson('/api/v1/me/sessions/'.$otherSession->id)
            ->assertNotFound();
    }

    public function test_admin_cannot_view_sessions_of_user_from_another_country(): void
    {
        $permission = Permission::create([
            'name' => 'sessions.view_any',
            'guard_name' => 'web',
        ]);

        $syria = Country::create(['iso2' => 'SY', 'name' => ['ar' => 'سوريا', 'en' => 'Syria']]);
        $jordan = Country::create(['iso2' => 'JO', 'name' => ['ar' => 'الأردن', 'en' => 'Jordan']]);

        // B4: النطاق يأتي من إسناد الصلاحية (ورقة "قواعد النطاق")، لا من دولة المسؤول نفسه.
        // إسناد بنطاق دولة سوريا + جلسة نطاقها الفعّال سوريا → يرى مستخدمي سوريا فقط.
        $syriaScope = AccessScope::forCountry($syria);
        setPermissionsTeamId($syriaScope->id);

        $admin = User::factory()->create(['country_id' => $syria->id]);
        $admin->givePermissionTo($permission);

        $sameCountry = User::factory()->create(['country_id' => $syria->id]);
        $otherCountry = User::factory()->create(['country_id' => $jordan->id]);

        [$plain] = $this->loginAs($admin, ['active_scope_id' => $syriaScope->id]);

        $this->withToken($plain)
            ->getJson('/api/v1/admin/users/'.$sameCountry->uuid.'/sessions')
            ->assertOk();

        $this->withToken($plain)
            ->getJson('/api/v1/admin/users/'.$otherCountry->uuid.'/sessions')
            ->assertNotFound();
    }
}
