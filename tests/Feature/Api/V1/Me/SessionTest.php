<?php

namespace Tests\Feature\Me;

use App\Models\User;
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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




}

