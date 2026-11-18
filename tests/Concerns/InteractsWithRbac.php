<?php

namespace Tests\Concerns;

use App\Models\AccessScope;
use App\Models\Context;
use App\Models\Country;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Models\UserContext;
use App\Services\Rbac\AccessResolver;
use Carbon\CarbonInterface;
use Database\Seeders\Rbac\ContextSeeder;
use Database\Seeders\Rbac\PermissionSeeder;
use Database\Seeders\Rbac\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * أدوات مشتركة لاختبارات B4: بذر الكتالوج، إنشاء نطاقات، إسناد أدوار كتجهيز (fixture) مباشر في القاعدة،
 * وتسجيل الدخول بجلسة لها سياق ونطاق فعّالان.
 */
trait InteractsWithRbac
{
    protected function seedRbac(): void
    {
        $this->seed([ContextSeeder::class, PermissionSeeder::class, RoleSeeder::class]);
        $this->flushAccess();
    }

    protected function flushAccess(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(AccessResolver::class)->flush();
    }

    protected function globalScope(): AccessScope
    {
        return AccessScope::global();
    }

    protected function makeCountry(string $iso2): Country
    {
        return Country::query()->create(['iso2' => $iso2, 'name' => ['ar' => $iso2, 'en' => $iso2]]);
    }

    protected function makeRegion(Country $country, ?Region $parent = null, ?string $code = null): Region
    {
        return Region::query()->create([
            'country_id' => $country->getKey(),
            'parent_id' => $parent?->getKey(),
            'type' => $parent ? 'district' : 'governorate',
            'code' => $code ?? Str::upper(Str::random(6)),
            'name' => ['ar' => 'منطقة', 'en' => 'Region'],
        ]);
    }

    protected function makeOrganization(?Country $country = null, ?Region $region = null, string $type = 'ngo', string $status = 'active'): Organization
    {
        $org = new Organization([
            'name' => ['ar' => 'مؤسسة', 'en' => 'Organization'],
            'slug' => 'org-'.Str::lower(Str::random(10)),
            'type' => $type,
            'country_id' => $country?->getKey(),
            'region_id' => $region?->getKey(),
        ]);
        $org->forceFill(['status' => $status])->save();

        return $org;
    }

    protected function addMember(User $user, Organization $org, string $status = 'active'): OrganizationMembership
    {
        return OrganizationMembership::query()->create([
            'organization_id' => $org->getKey(),
            'user_id' => $user->getKey(),
            'membership_type' => 'member',
            'status' => $status,
            'joined_at' => now(),
        ]);
    }

    /**
     * تجهيز: إسناد دور مباشرة في القاعدة (granted_by = NULL = النظام) + سياق الدور للمستخدم.
     * الاختبارات التي تفحص قواعد الإسناد نفسها تستخدم RoleAssignmentService.
     */
    protected function grantRole(User $user, string $role, ?AccessScope $scope = null, ?CarbonInterface $expiresAt = null): void
    {
        $scope ??= $this->globalScope();
        $roleModel = Role::query()->where('name', $role)->whereNull('scope_id')->firstOrFail();

        DB::table('model_has_roles')->insert([
            'role_id' => $roleModel->getKey(),
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->getKey(),
            'scope_id' => $scope->getKey(),
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);

        if (! UserContext::query()->where(['user_id' => $user->getKey(), 'context_id' => $roleModel->context_id])->exists()) {
            UserContext::query()->create([
                'user_id' => $user->getKey(),
                'context_id' => $roleModel->context_id,
                'status' => 'active',
                'is_default' => ! UserContext::query()->where('user_id', $user->getKey())->where('is_default', true)->exists(),
                'activated_at' => now(),
            ]);
        }

        $this->flushAccess();
    }

    protected function userWithRole(string $role, ?AccessScope $scope = null, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $this->grantRole($user, $role, $scope);

        return $user;
    }

    protected function contextId(string $key): int
    {
        return (int) Context::query()->where('key', $key)->value('id');
    }

    /**
     * يسجّل الدخول بتوكن حقيقي + جلسة user_sessions بسياق/نطاق فعّالين، ويعيد التوكن النصي.
     * يفرّغ الحراس حتى لا يُعاد استخدام مستخدم طلب سابق في نفس الاختبار.
     */
    protected function loginAs(User $user, ?AccessScope $scope = null, ?string $context = null): string
    {
        $token = $user->createToken('test');

        $user->sessions()->create([
            'personal_access_token_id' => $token->accessToken->id,
            'active_scope_id' => $scope?->getKey(),
            'active_context_id' => $context ? $this->contextId($context) : null,
            'last_activity_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->app['auth']->forgetGuards();
        $this->flushAccess();

        return $token->plainTextToken;
    }

    /** يحاكي حالة الطلب داخل الكود (بدون HTTP): يضبط التوكن الحالي ليقرأ AccessResolver جلسته. */
    protected function actAs(User $user, ?AccessScope $scope = null, ?string $context = null): User
    {
        $token = $user->createToken('test');
        $user->sessions()->create([
            'personal_access_token_id' => $token->accessToken->id,
            'active_scope_id' => $scope?->getKey(),
            'active_context_id' => $context ? $this->contextId($context) : null,
            'last_activity_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $user->withAccessToken($token->accessToken);
        $this->flushAccess();

        return $user;
    }
}
