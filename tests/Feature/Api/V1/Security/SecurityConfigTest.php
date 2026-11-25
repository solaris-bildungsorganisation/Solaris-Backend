<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecurityConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login-auth');
    }

    public function test_security_headers_are_present_on_api_responses(): void
    {
        $response = $this->getJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_sixth_login_attempt_within_a_minute_returns_429(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_cors_blocks_origin_not_in_allowed_list(): void
    {
        config(['cors.allowed_origins' => ['http://allowed.test']]);

        $response = $this->withHeaders([
            'Origin' => 'http://evil.test',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/v1/auth/login');

        $this->assertNotSame('http://evil.test', $response->headers->get('Access-Control-Allow-Origin'));

    }

    public function test_changing_config_changes_the_rate_limit(): void
    {
        config(['solaris.rate_limits.login_per_minute' => 2]);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'other@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'other@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
