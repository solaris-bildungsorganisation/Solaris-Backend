<?php

namespace Tests\Feature\Api\V1\Security;

use App\Models\AccessScope;
use App\Models\User;
use App\Services\Auth\StepUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Gate::before (super_admin في النطاق العام فقط + تسجيل الاستخدام) وحارس MFA (القرار D8).
 */
class GateAndMfaTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function activity(string $logName, string $event): Collection
    {
        return DB::table('activity_log')->where('log_name', $logName)->where('event', $event)->get();
    }

    public function test_super_admin_bypass_in_global_scope_is_logged_once_per_ability(): void
    {
        $super = $this->actAs($this->userWithRole('super_admin'));

        $this->assertTrue($super->can('audit_log.view_restricted'));
        $this->assertTrue($super->can('audit_log.view_restricted'));

        $rows = $this->activity('rbac', 'super_admin_bypass');
        $this->assertCount(1, $rows, 'تسجيل واحد لكل صلاحية خلال الطلب');
        $this->assertSame($super->id, (int) $rows[0]->causer_id);
        $this->assertSame('restricted', $rows[0]->sensitivity);
        $this->assertSame('audit_log.view_restricted', json_decode($rows[0]->properties, true)['ability']);
    }

    public function test_super_admin_bypass_does_not_apply_outside_global_scope(): void
    {
        $org = $this->makeOrganization();
        $scope = AccessScope::forOrganization($org);
        $super = $this->userWithRole('super_admin');
        $this->grantRole($super, 'organization_admin', $scope);

        $this->actAs($super, $scope)->can('users.view_any');

        $this->assertCount(0, $this->activity('rbac', 'super_admin_bypass'));
    }

    public function test_super_admin_does_not_bypass_hard_policy_rules(): void
    {
        $super = $this->actAs($this->userWithRole('super_admin'));

        $this->assertTrue($super->cannot('updateStatus', $super), 'لا تغيير لحالة حسابك حتى للمدير الأعلى');
        $this->assertTrue($super->can('updateStatus', User::factory()->create()));
    }

    public function test_mfa_warn_mode_allows_and_logs_warning(): void
    {
        config(['solaris.mfa.mode' => 'warn']);
        $admin = $this->actAs($this->userWithRole('platform_admin'));

        $this->assertTrue($admin->can('users.update_status'));
        $this->assertTrue($admin->can('users.view_any'), 'صلاحية بلا MFA لا تُسجَّل');

        $rows = $this->activity('security', 'mfa_required');
        $this->assertCount(1, $rows);
        $this->assertSame('allowed_with_warning', json_decode($rows[0]->properties, true)['decision']);
    }

    public function test_mfa_enforce_mode_denies_until_mfa_is_confirmed(): void
    {
        config(['solaris.mfa.mode' => 'enforce']);
        $admin = $this->actAs($this->userWithRole('platform_admin'));

        $this->assertFalse($admin->can('users.update_status'));
        $this->assertTrue($admin->can('users.view_any'));

        $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->flushAccess();
        $this->assertFalse($admin->can('users.update_status'), 'K2: 2FA مفعّل وحده لا يكفي — يلزم تحقق حديث للجلسة');

        app(StepUpService::class)->mark($admin);
        $this->flushAccess();
        $this->assertTrue($admin->can('users.update_status'));
    }

    public function test_mfa_guard_applies_to_super_admin_too(): void
    {
        config(['solaris.mfa.mode' => 'enforce']);
        $super = $this->actAs($this->userWithRole('super_admin'));

        $this->assertFalse($super->can('roles.assign'));
    }

    public function test_mfa_enforced_on_http_admin_endpoint(): void
    {
        config(['solaris.mfa.mode' => 'enforce']);
        $admin = $this->userWithRole('platform_admin');
        $target = User::factory()->create();

        $this->withToken($this->loginAs($admin))
            ->deleteJson("/api/v1/admin/users/{$target->uuid}/sessions", ['reason' => 'security incident'])
            ->assertForbidden();

        config(['solaris.mfa.mode' => 'warn']);
        $this->withToken($this->loginAs($admin))
            ->deleteJson("/api/v1/admin/users/{$target->uuid}/sessions", ['reason' => 'security incident'])
            ->assertNoContent();
    }
}
