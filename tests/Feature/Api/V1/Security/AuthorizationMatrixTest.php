<?php

namespace Tests\Feature\Api\V1\Security;

use App\Models\AccessScope;
use App\Models\Country;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use App\Policies\ScopeGuard;
use Database\Seeders\Rbac\RbacCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * يقرأ مصفوفة التفويض المعتمدة (tests/fixtures/authorization_matrix.csv المصدّرة من
 * requerments/Solaris_Authorization_Matrix_Wave1.xlsx) ويولّد اختبار سماح ورفض لكل خلية:
 *   Own / S / G / O → مسموح للدور في نطاقه الأساسي ورفض لأي "—".
 *   G              → مرفوض إن أُسند الدور نفسه بنطاق دولة/منطقة/مؤسسة.
 *   S / O / Own    → تبقى مسموحة بالنطاق الأضيق.
 * ثم اختبارات "الوصول للبيانات" عبر HTTP و ScopeGuard (إخفاء الزر لا يكفي).
 */
class AuthorizationMatrixTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private const FIXTURE = __DIR__.'/../../../../fixtures/authorization_matrix.csv';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /** @return array<string, array{0: string, 1: array<string, string>}> */
    public static function matrixByRole(): array
    {
        $rows = array_map(
            fn (string $line) => str_getcsv($line, ',', '"', ''),
            file(self::FIXTURE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
        $header = array_shift($rows);
        $roles = array_slice($header, 2);

        $cases = [];
        foreach ($roles as $i => $role) {
            $cells = [];
            foreach ($rows as $row) {
                $cells[$row[0]] = $row[$i + 2];
            }
            $cases[$role] = [$role, $cells];
        }

        return $cases;
    }

    public function test_fixture_matches_catalog_shape(): void
    {
        $cases = self::matrixByRole();

        $this->assertCount(14, $cases, 'المصفوفة: 14 دورًا');
        $this->assertCount(55, reset($cases)[1], 'المصفوفة: 55 صلاحية');
        $this->assertEqualsCanonicalizing(array_keys(RbacCatalog::roles()), array_keys($cases));
        $this->assertEqualsCanonicalizing(RbacCatalog::permissionNames(), array_keys(reset($cases)[1]));
    }

    /** خلية خلية: سماح ورفض للدور في نطاقه الأساسي (العام إن سُمح، وإلا المؤسسة). */
    #[DataProvider('matrixByRole')]
    public function test_every_cell_in_home_scope(string $role, array $cells): void
    {
        $roleModel = Role::query()->where('name', $role)->firstOrFail();
        $scope = $roleModel->allowsScope('global')
            ? $this->globalScope()
            : AccessScope::forOrganization($this->makeOrganization());

        $user = $this->actAs($this->userWithRole($role, $scope), $scope);

        foreach ($cells as $permission => $cell) {
            $expected = $cell !== '-';
            $this->assertSame(
                $expected,
                $user->can($permission),
                sprintf('[%s × %s = %s] متوقع %s في نطاق %s', $role, $permission, $cell, $expected ? 'سماح' : 'رفض', $scope->type),
            );
        }
    }

    /** خلية G تُرفض، وخلايا S/O/Own تبقى، عند إسناد الدور بأضيق نطاق غير عام يسمح به. */
    #[DataProvider('matrixByRole')]
    public function test_every_cell_in_narrow_scope(string $role, array $cells): void
    {
        $roleModel = Role::query()->where('name', $role)->firstOrFail();
        $type = collect(['organization', 'region', 'country'])->first(fn ($t) => $roleModel->allowsScope($t));

        if ($type === null) {
            $this->assertSame(['global'], $roleModel->allowed_scope_types, "{$role}: نطاق عام فقط");

            return;
        }

        $country = $this->makeCountry('SY');
        $scope = match ($type) {
            'organization' => AccessScope::forOrganization($this->makeOrganization($country)),
            'region' => AccessScope::forRegion($this->makeRegion($country)),
            'country' => AccessScope::forCountry($country),
        };

        $user = $this->actAs($this->userWithRole($role, $scope), $scope);

        foreach ($cells as $permission => $cell) {
            $expected = ! in_array($cell, ['-', 'G'], true);
            $this->assertSame(
                $expected,
                $user->can($permission),
                sprintf('[%s × %s = %s] متوقع %s بنطاق %s', $role, $permission, $cell, $expected ? 'سماح' : 'رفض', $type),
            );
        }
    }

    // ───────────── الوصول للبيانات (HTTP) ─────────────

    public function test_learner_is_denied_admin_endpoint_with_403(): void
    {
        $learner = $this->userWithRole('learner');
        $target = User::factory()->create();

        $this->withToken($this->loginAs($learner))
            ->getJson("/api/v1/admin/users/{$target->uuid}/sessions")
            ->assertForbidden();
    }

    public function test_country_admin_sees_own_country_and_gets_404_outside(): void
    {
        $sy = $this->makeCountry('SY');
        $jo = $this->makeCountry('JO');
        $scope = AccessScope::forCountry($sy);
        $admin = $this->userWithRole('platform_admin', $scope, ['country_id' => $sy->id]);

        $inside = User::factory()->create(['country_id' => $sy->id]);
        $outside = User::factory()->create(['country_id' => $jo->id]);
        $token = $this->loginAs($admin, $scope, 'administration');

        $this->withToken($token)->getJson("/api/v1/admin/users/{$inside->uuid}/sessions")->assertOk();
        $this->withToken($token)->getJson("/api/v1/admin/users/{$outside->uuid}/sessions")->assertNotFound();
    }

    public function test_global_admin_sees_every_country(): void
    {
        $jo = $this->makeCountry('JO');
        $admin = $this->userWithRole('platform_admin');
        $outside = User::factory()->create(['country_id' => $jo->id]);

        $this->withToken($this->loginAs($admin, null, 'administration'))
            ->getJson("/api/v1/admin/users/{$outside->uuid}/sessions")
            ->assertOk();
    }

    // ───────────── ScopeGuard: covers + visibleTo ─────────────

    public function test_organization_admin_invites_into_own_org_but_not_another(): void
    {
        $orgA = $this->makeOrganization();
        $orgB = $this->makeOrganization();
        $scopeA = AccessScope::forOrganization($orgA);
        $admin = $this->actAs($this->userWithRole('organization_admin', $scopeA), $scopeA);

        $this->assertTrue(Gate::forUser($admin)->allows('create', [OrganizationInvitation::class, $orgA]));

        $decision = Gate::forUser($admin)->inspect('create', [OrganizationInvitation::class, $orgB]);
        $this->assertTrue($decision->denied());
        $this->assertSame(404, $decision->status(), 'مؤسسة خارج النطاق = 404 (لا نكشف وجودها)');
    }

    public function test_visible_to_filters_users_by_scope_type(): void
    {
        $sy = $this->makeCountry('SY');
        $jo = $this->makeCountry('JO');
        $damascus = $this->makeRegion($sy);
        $district = $this->makeRegion($sy, $damascus);
        $aleppo = $this->makeRegion($sy);
        $org = $this->makeOrganization($sy);

        $inDistrict = User::factory()->create(['country_id' => $sy->id, 'region_id' => $district->id]);
        $inAleppo = User::factory()->create(['country_id' => $sy->id, 'region_id' => $aleppo->id]);
        $inJordan = User::factory()->create(['country_id' => $jo->id]);
        $this->addMember($inAleppo, $org);

        $countryScope = AccessScope::forCountry($sy);
        $regionScope = AccessScope::forRegion($damascus);
        $orgScope = AccessScope::forOrganization($org);

        $countryAdmin = $this->actAs($this->userWithRole('platform_admin', $countryScope), $countryScope);
        $ids = User::query()->visibleTo($countryAdmin)->pluck('id');
        $this->assertTrue($ids->contains($inDistrict->id) && $ids->contains($inAleppo->id));
        $this->assertFalse($ids->contains($inJordan->id));

        $regionAdmin = $this->actAs($this->userWithRole('platform_admin', $regionScope), $regionScope);
        $ids = User::query()->visibleTo($regionAdmin)->pluck('id');
        $this->assertTrue($ids->contains($inDistrict->id), 'المنطقة تشمل ما تحتها');
        $this->assertFalse($ids->contains($inAleppo->id));

        $orgAdmin = $this->actAs($this->userWithRole('organization_admin', $orgScope), $orgScope);
        $this->assertSame([$inAleppo->id], User::query()->visibleTo($orgAdmin)->pluck('id')->all(), 'أعضاء المؤسسة النشطون فقط');

        $this->assertTrue(ScopeGuard::covers($regionAdmin, $inDistrict, 'users.view'));
        $this->assertFalse(ScopeGuard::covers($regionAdmin, $inJordan, 'users.view'));
    }

    public function test_user_without_permission_sees_nothing(): void
    {
        User::factory()->count(2)->create();
        $learner = $this->actAs($this->userWithRole('learner'));

        $this->assertSame(0, User::query()->visibleTo($learner)->count());
    }

    // ───────────── الحالة والانتهاء ─────────────

    public function test_inactive_user_has_no_permissions(): void
    {
        $admin = $this->userWithRole('platform_admin');
        $admin->forceFill(['status' => 'suspended'])->save();
        $admin = $this->actAs($admin);

        $this->assertFalse($admin->can('profile.view_own'));
        $this->assertSame([], $admin->effectiveAccess()->permissions());
    }

    public function test_suspended_organization_removes_org_scoped_permissions(): void
    {
        $org = $this->makeOrganization(status: 'suspended');
        $scope = AccessScope::forOrganization($org);
        $admin = $this->actAs($this->userWithRole('organization_admin', $scope), $scope);

        $this->assertFalse($admin->can('organizations.view'));
    }

    public function test_expired_role_is_not_counted(): void
    {
        $user = User::factory()->create();
        $this->grantRole($user, 'platform_admin', null, now()->subMinute());

        $this->assertFalse($this->actAs($user)->can('users.view_any'));
    }

    public function test_global_settings_require_global_assignment(): void
    {
        $country = AccessScope::forCountry(Country::query()->create(['iso2' => 'SY', 'name' => ['en' => 'SY']]));
        $regional = $this->actAs($this->userWithRole('platform_admin', $country), $country);
        $global = $this->actAs($this->userWithRole('platform_admin'));

        $this->assertTrue(Gate::forUser($regional)->denies('create', Country::class));
        $this->assertTrue(Gate::forUser($global)->allows('create', Country::class));
        $this->assertTrue(Gate::forUser($regional)->allows('viewAny', Country::class), 'القراءة المرجعية عامة');
    }
}
