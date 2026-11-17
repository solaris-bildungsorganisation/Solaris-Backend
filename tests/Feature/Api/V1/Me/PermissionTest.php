<?php

namespace Tests\Feature\Api\V1\Me;

use App\Models\User;
use Database\Seeders\Rbac\RbacCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/** GET /api/v1/me/permissions — صلاحيات السياق والنطاق الفعّالين للواجهة. */
class PermissionTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/me/permissions')->assertUnauthorized();
    }

    public function test_learner_gets_self_service_permissions_only(): void
    {
        $learner = $this->userWithRole('learner');

        $response = $this->withToken($this->loginAs($learner))->getJson('/api/v1/me/permissions')->assertOk();

        $this->assertEqualsCanonicalizing(RbacCatalog::selfService(), $response->json('data.permissions'));
        $response->assertJsonPath('data.scope.type', 'global')
            ->assertJsonPath('data.context.key', 'learning')
            ->assertJsonPath('data.is_super_admin', false)
            ->assertJsonPath('data.mfa_required', [])
            ->assertJsonPath('data.mfa.mode', 'warn')
            ->assertJsonPath('data.roles.0.name', 'learner');
    }

    public function test_platform_admin_gets_matrix_permissions_and_mfa_list(): void
    {
        $admin = $this->userWithRole('platform_admin');

        $response = $this->withToken($this->loginAs($admin, null, 'administration'))
            ->getJson('/api/v1/me/permissions')
            ->assertOk()
            ->assertJsonCount(53, 'data.permissions');

        $this->assertNotContains('audit_log.view_restricted', $response->json('data.permissions'));
        $this->assertContains('roles.assign', $response->json('data.mfa_required'));
    }

    public function test_super_admin_gets_all_permissions(): void
    {
        $super = $this->userWithRole('super_admin');

        $this->withToken($this->loginAs($super, null, 'administration'))
            ->getJson('/api/v1/me/permissions')
            ->assertOk()
            ->assertJsonPath('data.is_super_admin', true)
            ->assertJsonCount(55, 'data.permissions');
    }

    public function test_user_without_roles_gets_empty_list(): void
    {
        $this->withToken($this->loginAs(User::factory()->create()))
            ->getJson('/api/v1/me/permissions')
            ->assertOk()
            ->assertJsonPath('data.permissions', [])
            ->assertJsonPath('data.context', null);
    }
}
