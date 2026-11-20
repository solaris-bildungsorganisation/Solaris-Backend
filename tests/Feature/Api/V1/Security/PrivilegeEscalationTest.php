<?php

namespace Tests\Feature\Api\V1\Security;

use App\Models\AccessScope;
use App\Models\AccountTypeRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\StepUpService;
use App\Services\Rbac\RoleAssignmentException;
use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * منع تصعيد الصلاحيات — ورقة "إسناد الأدوار" (FR-AUTH-005, 014, 022 · BR-AUTH-004).
 * لا يمنح المستخدم نفسه دورًا، ولا دورًا برتبة مساوية أو أعلى، ولا خارج نطاقه.
 */
class PrivilegeEscalationTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private RoleAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->service = app(RoleAssignmentService::class);
    }

    private function assertRejected(string $reason, callable $action, int $status = 403): void
    {
        try {
            $action();
            $this->fail("كان يجب رفض الإسناد ({$reason})");
        } catch (RoleAssignmentException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($status, $e->status);
        }
    }

    public function test_platform_admin_cannot_assign_platform_admin(): void
    {
        $admin = $this->actAs($this->userWithRole('platform_admin'));
        $target = User::factory()->create();

        $this->assertRejected(RoleAssignmentException::ROLE_NOT_ASSIGNABLE,
            fn () => $this->service->assign($admin, $target, 'platform_admin', $this->globalScope(), 'promotion'));
        $this->assertFalse($target->fresh()->roles()->exists());
    }

    public function test_nobody_assigns_a_role_to_themselves_not_even_super_admin(): void
    {
        $super = $this->actAs($this->userWithRole('super_admin'));

        $this->assertRejected(RoleAssignmentException::SELF_ASSIGNMENT,
            fn () => $this->service->assign($super, $super, 'platform_admin', $this->globalScope(), 'self'));
    }

    public function test_super_admin_is_never_assigned_through_the_service(): void
    {
        $super = $this->actAs($this->userWithRole('super_admin'));

        $this->assertRejected(RoleAssignmentException::SUPER_ADMIN_SEEDER_ONLY,
            fn () => $this->service->assign($super, User::factory()->create(), 'super_admin', $this->globalScope(), 'x'));
    }

    public function test_database_check_blocks_self_grant_as_last_line_of_defense(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('model_has_roles')->insert([
            'role_id' => Role::query()->where('name', 'platform_admin')->value('id'),
            'model_type' => $user->getMorphClass(), 'model_id' => $user->id,
            'scope_id' => 1, 'granted_by' => $user->id,
        ]);
    }

    public function test_rank_must_be_strictly_lower(): void
    {
        $sy = $this->makeCountry('SY');
        $org = $this->makeOrganization($sy);
        $orgScope = AccessScope::forOrganization($org);
        $admin = $this->actAs($this->userWithRole('platform_admin'));
        $target = User::factory()->create();
        $this->addMember($target, $org);

        // 60 < 90 → مسموح
        $this->service->assign($admin, $target, 'organization_admin', $orgScope, 'manages partner');
        $this->assertTrue(DB::table('model_has_roles')->where(['model_id' => $target->id, 'scope_id' => $orgScope->id, 'granted_by' => $admin->id])->exists());
    }

    public function test_assignee_without_roles_assign_permission_is_rejected(): void
    {
        $learner = $this->actAs($this->userWithRole('learner'));

        $this->assertRejected(RoleAssignmentException::MISSING_PERMISSION,
            fn () => $this->service->assign($learner, User::factory()->create(), 'mentor', $this->globalScope()));
    }

    public function test_country_admin_cannot_assign_outside_country_or_globally(): void
    {
        $sy = $this->makeCountry('SY');
        $jo = $this->makeCountry('JO');
        $syScope = AccessScope::forCountry($sy);
        $admin = $this->actAs($this->userWithRole('platform_admin', $syScope), $syScope);
        $target = User::factory()->create();

        $this->assertRejected(RoleAssignmentException::SCOPE_OUTSIDE_ASSIGNER,
            fn () => $this->service->assign($admin, $target, 'program_officer', AccessScope::forCountry($jo), 'x'));
        $this->assertRejected(RoleAssignmentException::SCOPE_OUTSIDE_ASSIGNER,
            fn () => $this->service->assign($admin, $target, 'program_officer', $this->globalScope(), 'x'));

        // داخل دولته وما تحتها → مسموح
        $region = AccessScope::forRegion($this->makeRegion($sy));
        $this->service->assign($admin, $target, 'program_officer', $region, 'regional M&E');
        $this->assertTrue(DB::table('model_has_roles')->where(['model_id' => $target->id, 'scope_id' => $region->id])->exists());
    }

    public function test_scope_type_must_be_allowed_for_the_role(): void
    {
        $admin = $this->actAs($this->userWithRole('platform_admin'));
        $sy = $this->makeCountry('SY');

        // solaris_employee: نطاق عام فقط
        $this->assertRejected(RoleAssignmentException::SCOPE_TYPE_NOT_ALLOWED,
            fn () => $this->service->assign($admin, User::factory()->create(), 'solaris_employee', AccessScope::forCountry($sy)),
            422);
    }

    public function test_organization_admin_assigns_only_educational_roles_inside_own_org(): void
    {
        $orgA = $this->makeOrganization();
        $orgB = $this->makeOrganization();
        $scopeA = AccessScope::forOrganization($orgA);
        $admin = $this->actAs($this->userWithRole('organization_admin', $scopeA), $scopeA, 'organization');

        $member = User::factory()->create();
        $this->addMember($member, $orgA);
        $outsider = User::factory()->create();

        $this->service->assign($admin, $member, 'instructor', $scopeA);
        $this->assertTrue(DB::table('model_has_roles')->where(['model_id' => $member->id, 'scope_id' => $scopeA->id])->exists());

        $this->assertRejected(RoleAssignmentException::ROLE_NOT_ASSIGNABLE,
            fn () => $this->service->assign($admin, $member, 'program_officer', $scopeA, 'x'));
        $this->assertRejected(RoleAssignmentException::TARGET_NOT_MEMBER,
            fn () => $this->service->assign($admin, $outsider, 'mentor', $scopeA), 422);
        $this->assertRejected(RoleAssignmentException::SCOPE_OUTSIDE_ASSIGNER,
            fn () => $this->service->assign($admin, $member, 'mentor', AccessScope::forOrganization($orgB)));
    }

    public function test_administrative_roles_require_a_reason(): void
    {
        $admin = $this->actAs($this->userWithRole('platform_admin'));

        $this->assertRejected(RoleAssignmentException::REASON_REQUIRED,
            fn () => $this->service->assign($admin, User::factory()->create(), 'program_officer', $this->globalScope()),
            422);
    }

    public function test_mfa_enforce_mode_blocks_role_assignment(): void
    {
        config(['solaris.mfa.mode' => 'enforce']);
        $admin = $this->actAs($this->userWithRole('platform_admin'));

        $this->assertRejected(RoleAssignmentException::MFA_REQUIRED,
            fn () => $this->service->assign($admin, User::factory()->create(), 'mentor', $this->globalScope()));

        $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
        app(StepUpService::class)->mark($admin); // K2: تحقق حديث للجلسة
        $this->flushAccess();
        $this->service->assign($admin, $target = User::factory()->create(), 'mentor', $this->globalScope());
        $this->assertTrue(DB::table('model_has_roles')->where('model_id', $target->id)->exists());
    }

    public function test_status_change_is_blocked_on_self_and_on_higher_rank(): void
    {
        $admin = $this->actAs($this->userWithRole('platform_admin'));
        $super = $this->userWithRole('super_admin');
        $learner = $this->userWithRole('learner');

        $this->assertTrue(Gate::forUser($admin)->denies('updateStatus', $admin), 'لا على النفس');
        $this->assertTrue(Gate::forUser($admin)->denies('updateStatus', $super), 'لا على رتبة أعلى');
        $this->assertTrue(Gate::forUser($admin)->allows('updateStatus', $learner));
    }

    public function test_no_self_review_of_account_type_request(): void
    {
        $admin = $this->actAs($this->userWithRole('platform_admin'));
        $own = $this->accountTypeRequest($admin, 'mentor');
        $other = $this->accountTypeRequest(User::factory()->create(), 'mentor');

        $this->assertTrue(Gate::forUser($admin)->denies('review', $own));
        $this->assertTrue(Gate::forUser($admin)->allows('review', $other));
    }

    public function test_organization_admin_reviews_only_assignable_roles(): void
    {
        $org = $this->makeOrganization();
        $scope = AccessScope::forOrganization($org);
        $admin = $this->actAs($this->userWithRole('organization_admin', $scope), $scope, 'organization');

        $instructor = $this->accountTypeRequest(User::factory()->create(), 'instructor', $org->id);
        $officer = $this->accountTypeRequest(User::factory()->create(), 'program_officer', $org->id);

        $this->assertTrue(Gate::forUser($admin)->allows('review', $instructor));
        $this->assertTrue(Gate::forUser($admin)->denies('review', $officer), 'D1');
    }

    public function test_revoke_follows_the_same_rules(): void
    {
        $admin = $this->actAs($this->userWithRole('platform_admin'));
        $peer = $this->userWithRole('platform_admin');
        $mentor = $this->userWithRole('mentor');

        $this->assertRejected(RoleAssignmentException::ROLE_NOT_ASSIGNABLE,
            fn () => $this->service->revoke($admin, $peer, 'platform_admin', $this->globalScope()));

        $this->service->revoke($admin, $mentor, 'mentor', $this->globalScope(), 'left program');
        $this->assertFalse(DB::table('model_has_roles')->where('model_id', $mentor->id)->exists());

        $this->assertRejected(RoleAssignmentException::NOT_ASSIGNED,
            fn () => $this->service->revoke($admin, $mentor, 'mentor', $this->globalScope()), 404);
    }

    private function accountTypeRequest(User $owner, string $role, ?int $organizationId = null): AccountTypeRequest
    {
        return AccountTypeRequest::query()->create([
            'user_id' => $owner->id,
            'requested_role_id' => Role::query()->where('name', $role)->value('id'),
            'organization_id' => $organizationId,
            'purpose' => 'test',
        ]);
    }
}
