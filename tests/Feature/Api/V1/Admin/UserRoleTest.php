<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * السجل (ورقة الاختبارات): تغييرات الأدوار تُسجَّل في activity_log (log_name = rbac, sensitivity = restricted)،
 * ويُكتب granted_by و grant_reason و expires_at في model_has_roles.
 */
class UserRoleTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private RoleAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->service = app(RoleAssignmentService::class);
    }

    public function test_assignment_is_recorded_with_grant_metadata_and_audit_row(): void
    {
        $admin = $this->actAs($this->userWithRole('platform_admin'));
        $target = User::factory()->create();

        $this->service->assign($admin, $target, 'program_officer', $this->globalScope(), 'M&E lead', now()->addMonth());

        $row = DB::table('model_has_roles')->where('model_id', $target->id)->first();
        $this->assertSame($admin->id, (int) $row->granted_by);
        $this->assertSame('M&E lead', $row->grant_reason);
        $this->assertNotNull($row->expires_at);

        $log = DB::table('activity_log')->where(['log_name' => 'rbac', 'event' => 'role_assigned'])->first();
        $this->assertNotNull($log);
        $this->assertSame('restricted', $log->sensitivity);
        $this->assertSame($admin->id, (int) $log->causer_id);
        $this->assertSame($target->id, (int) $log->subject_id);
        $this->assertSame('program_officer', json_decode($log->properties, true)['role']);
    }

    public function test_assignment_activates_role_context_and_takes_effect_immediately(): void
    {
        $admin = $this->actAs($this->userWithRole('platform_admin'));
        $target = User::factory()->create();

        $this->service->assign($admin, $target, 'program_officer', $this->globalScope(), 'M&E');

        $this->assertTrue($target->userContexts()->where('context_id', $this->contextId('program_management'))
            ->where('status', 'active')->where('is_default', true)->exists());
        $this->assertTrue($this->actAs($target)->can('dashboard.view'), 'كاش الصلاحيات يُمسح بعد الإسناد');
    }

    public function test_revocation_is_logged_and_removes_permissions(): void
    {
        $admin = $this->actAs($this->userWithRole('platform_admin'));
        $mentor = $this->userWithRole('mentor');

        $this->service->revoke($admin, $mentor, 'mentor', $this->globalScope(), 'program ended');

        $this->assertSame(1, DB::table('activity_log')->where(['log_name' => 'rbac', 'event' => 'role_revoked'])->count());
        $this->assertFalse($this->actAs($mentor)->can('profile.view_own'));
    }

    public function test_system_assignment_has_no_granter(): void
    {
        $user = User::factory()->create();

        $this->service->assignBySystem($user, 'learner', null, 'self registration');

        $this->assertNull(DB::table('model_has_roles')->where('model_id', $user->id)->value('granted_by'));
        $this->assertTrue($this->actAs($user)->can('profile.view_own'));
    }

    public function test_expired_assignments_are_pruned_and_logged(): void
    {
        $user = User::factory()->create();
        $this->grantRole($user, 'mentor', null, now()->subHour());

        Artisan::call('rbac:prune-expired-roles');

        $this->assertFalse(DB::table('model_has_roles')->where('model_id', $user->id)->exists());
        $this->assertSame(1, DB::table('activity_log')->where(['log_name' => 'rbac', 'event' => 'role_expired'])->count());
    }
}
