<?php

namespace Tests\Feature\Api\V1\Security;

use App\Models\AccessScope;
use App\Models\User;
use Database\Seeders\Rbac\RbacCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * تبديل السياق والنطاق (FR-AUTH-016/017 · BR-AUTH-001):
 *  - السياق ليس صلاحية: الدور يُحسب فقط إذا طابق السياق الفعّال؛ الصلاحيات الذاتية تسري دائمًا.
 *  - الصلاحيات الفعلية = أدوار النطاق العام ∪ أدوار النطاق الفعّال — ولا شيء من نطاق آخر.
 */
class ContextIsolationTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_user_with_two_roles_does_not_see_admin_screens_in_learning_context(): void
    {
        $user = $this->userWithRole('learner');
        $this->grantRole($user, 'platform_admin');
        $target = User::factory()->create();

        $learning = $this->loginAs($user, null, 'learning');
        $this->withToken($learning)->getJson("/api/v1/admin/users/{$target->uuid}/sessions")->assertForbidden();
        $this->withToken($learning)->getJson('/api/v1/me/permissions')
            ->assertOk()
            ->assertJsonPath('data.context.key', 'learning')
            ->assertJsonCount(count(RbacCatalog::selfService()), 'data.permissions') // الذاتية فقط
            ->assertJsonFragment(['profile.view_own']);

        $admin = $this->loginAs($user, null, 'administration');
        $this->withToken($admin)->getJson("/api/v1/admin/users/{$target->uuid}/sessions")->assertOk();
    }

    public function test_program_officer_dashboard_follows_active_context(): void
    {
        $user = $this->userWithRole('learner');
        $this->grantRole($user, 'program_officer');

        $this->assertFalse($this->actAs($user, null, 'learning')->can('dashboard.view'));
        $this->assertTrue($this->actAs($user, null, 'learning')->can('region_changes.create_own'), 'الذاتية تسري دائمًا');
        $this->assertTrue($this->actAs($user, null, 'program_management')->can('dashboard.view'));
    }

    public function test_context_without_role_grants_nothing(): void
    {
        // BR-AUTH-001: امتلاك سياق إداري بلا دور لا يمنح صلاحيات
        $user = $this->userWithRole('learner');
        $user->userContexts()->create([
            'context_id' => $this->contextId('administration'), 'status' => 'active', 'is_default' => false,
        ]);

        $this->assertFalse($this->actAs($user, null, 'administration')->can('users.view_any'));
    }

    public function test_default_context_is_used_when_session_has_none(): void
    {
        $admin = $this->userWithRole('platform_admin'); // أول سياق = افتراضي (administration)

        $this->withToken($this->loginAs($admin))
            ->getJson('/api/v1/me/permissions')
            ->assertOk()
            ->assertJsonPath('data.context.key', 'administration')
            ->assertJsonFragment(['users.view_any']);
    }

    public function test_organization_permissions_apply_only_when_its_scope_is_active(): void
    {
        $org = $this->makeOrganization();
        $orgScope = AccessScope::forOrganization($org);
        $user = $this->userWithRole('learner');
        $this->grantRole($user, 'organization_admin', $orgScope);

        $inGlobal = $this->actAs($user, null, 'organization');
        $this->assertFalse($inGlobal->can('organization_members.invite'), 'النطاق العام فعّال: لا صلاحيات المؤسسة');
        $this->assertTrue($inGlobal->can('profile.view_own'));

        $inOrg = $this->actAs($user, $orgScope, 'organization');
        $this->assertTrue($inOrg->can('organization_members.invite'), 'مسؤول المؤسسة يحتفظ بصلاحياته داخل مؤسسته');
        $this->assertTrue($inOrg->can('profile.view_own'), 'أدوار النطاق العام ∪ أدوار النطاق الفعّال');
    }

    public function test_active_org_scope_does_not_leak_into_another_org(): void
    {
        $orgA = $this->makeOrganization();
        $orgB = $this->makeOrganization();
        $scopeA = AccessScope::forOrganization($orgA);
        $scopeB = AccessScope::forOrganization($orgB);

        $admin = $this->userWithRole('organization_admin', $scopeA);
        $this->grantRole($admin, 'organization_admin', $scopeB);

        $memberOfB = User::factory()->create();
        $this->addMember($memberOfB, $orgB);

        $inA = $this->actAs($admin, $scopeA, 'organization');
        $decision = Gate::forUser($inA)->inspect('view', $memberOfB);
        $this->assertTrue($decision->denied());
        $this->assertSame(404, $decision->status(), 'عضو مؤسسة أخرى = خارج النطاق الفعّال');
        $this->assertSame(0, User::query()->visibleTo($inA)->whereKey($memberOfB->id)->count());

        $inB = $this->actAs($admin, $scopeB, 'organization');
        $this->assertTrue($inB->can('view', $memberOfB));
    }

    public function test_switching_scope_through_api_changes_permissions(): void
    {
        $org = $this->makeOrganization();
        $other = $this->makeOrganization();
        $orgScope = AccessScope::forOrganization($org);
        $admin = $this->userWithRole('learner');                    // السياق الافتراضي = learning
        $this->grantRole($admin, 'organization_admin', $orgScope);
        $token = $this->loginAs($admin);

        $this->withToken($token)->getJson('/api/v1/me/scopes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'global')
            ->assertJsonPath('data.0.active', true)
            ->assertJsonPath('data.1.organization.uuid', $org->uuid)
            ->assertJsonCount(2, 'meta.contexts');

        // النطاق وحده لا يكفي: السياق learning لا يطابق دور organization_admin
        $this->withToken($token)->postJson('/api/v1/me/scope', ['scope_id' => $orgScope->id])
            ->assertOk()
            ->assertJsonMissing(['organization_members.invite']);

        $this->withToken($token)->postJson('/api/v1/me/scope', ['scope_id' => $orgScope->id, 'context' => 'organization'])
            ->assertOk()
            ->assertJsonPath('data.scope.id', $orgScope->id)
            ->assertJsonPath('data.context.key', 'organization')
            ->assertJsonFragment(['organization_members.invite']);

        $this->withToken($token)->postJson('/api/v1/me/scope', ['scope_id' => $orgScope->id, 'context' => 'administration'])
            ->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me/permissions')
            ->assertJsonPath('data.scope.type', 'organization');

        // نطاق لا أملك فيه دورًا
        $this->withToken($token)->postJson('/api/v1/me/scope', ['scope_id' => AccessScope::forOrganization($other)->id])
            ->assertUnprocessable();
    }

    public function test_me_permissions_reports_active_scope(): void
    {
        $org = $this->makeOrganization();
        $orgScope = AccessScope::forOrganization($org);
        $admin = $this->userWithRole('organization_admin', $orgScope);

        $this->withToken($this->loginAs($admin, $orgScope, 'organization'))
            ->getJson('/api/v1/me/permissions')
            ->assertOk()
            ->assertJsonPath('data.scope.type', 'organization')
            ->assertJsonPath('data.scope.organization_uuid', $org->uuid)
            ->assertJsonFragment(['roles.assign'])
            ->assertJsonPath('data.mfa_required', ['reports.export', 'roles.assign', 'roles.revoke']);
    }
}
