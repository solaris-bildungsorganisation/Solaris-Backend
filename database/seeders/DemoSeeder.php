<?php

namespace Database\Seeders;

use App\Models\AccessScope;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserContext;
use App\Services\Rbac\AccessResolver;
use Database\Seeders\Rbac\ContextSeeder;
use Database\Seeders\Rbac\PermissionSeeder;
use Database\Seeders\Rbac\RoleSeeder;
use Database\Seeders\Reference\CountryRegionSeeder;
use Database\Seeders\Reference\LanguageSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * بيانات تجريبية لاختبار الصلاحيات يدويًا (Postman: docs/postman/).
 * للتطوير المحلي فقط — يرفض العمل في الإنتاج. آمن للتشغيل المتكرر.
 *
 *   php artisan db:seed --class="Database\Seeders\DemoSeeder"
 *
 * كل الحسابات بكلمة المرور: Solaris@Demo2026
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Solaris@Demo2026';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder لا يعمل في الإنتاج.');

            return;
        }

        $this->call([LanguageSeeder::class, CountryRegionSeeder::class, ContextSeeder::class, PermissionSeeder::class, RoleSeeder::class]);

        $sy = (int) DB::table('countries')->where('iso2', 'SY')->value('id');
        $de = (int) DB::table('countries')->where('iso2', 'DE')->value('id');
        $damascus = (int) DB::table('regions')->where('code', 'SY-DI')->value('id');
        $aleppo = (int) DB::table('regions')->where('code', 'SY-HL')->value('id');

        $org = Organization::query()->where('slug', 'demo-partner-org')->first() ?? new Organization([
            'name' => ['ar' => 'مؤسسة الشريك التجريبية', 'en' => 'Demo Partner Org'],
            'slug' => 'demo-partner-org', 'type' => 'partner', 'country_id' => $sy, 'region_id' => $damascus,
        ]);
        $org->forceFill(['status' => 'active'])->save();

        $otherOrg = Organization::query()->where('slug', 'demo-other-org')->first() ?? new Organization([
            'name' => ['ar' => 'مؤسسة أخرى', 'en' => 'Demo Other Org'],
            'slug' => 'demo-other-org', 'type' => 'ngo', 'country_id' => $sy, 'region_id' => $aleppo,
        ]);
        $otherOrg->forceFill(['status' => 'active'])->save();

        $global = AccessScope::global();
        $syScope = AccessScope::query()->firstOrCreate(['type' => 'country', 'country_id' => $sy]);
        $damascusScope = AccessScope::query()->firstOrCreate(['type' => 'region', 'country_id' => $sy, 'region_id' => $damascus]);
        $orgScope = AccessScope::forOrganization($org);
        AccessScope::forOrganization($otherOrg);

        // [email, الاسم, الدولة, المنطقة, [[الدور, النطاق], ...]] — أول دور يحدد السياق الافتراضي
        $accounts = [
            ['super@demo.test', 'Super Admin', $sy, $damascus, [['super_admin', $global]]],
            ['admin@demo.test', 'Platform Admin (Global)', $sy, $damascus, [['platform_admin', $global]]],
            ['admin.sy@demo.test', 'Platform Admin (Syria)', $sy, $damascus, [['platform_admin', $syScope]]],
            ['admin.damascus@demo.test', 'Platform Admin (Damascus)', $sy, $damascus, [['platform_admin', $damascusScope]]],
            ['orgadmin@demo.test', 'Org Admin', $sy, $damascus, [['learner', $global], ['organization_admin', $orgScope]]],
            ['officer@demo.test', 'Program Officer', $sy, $aleppo, [['program_officer', $global]]],
            ['dual@demo.test', 'Learner + Admin', $sy, $damascus, [['learner', $global], ['platform_admin', $global]]],
            ['learner@demo.test', 'Learner', $sy, $damascus, [['learner', $global]]],
            ['member@demo.test', 'Org Member', $sy, $damascus, [['learner', $global]]],
            ['aleppo@demo.test', 'Aleppo User', $sy, $aleppo, [['learner', $global]]],
            ['germany@demo.test', 'Germany User', $de, null, [['learner', $global]]],
            ['suspended@demo.test', 'Suspended User', $sy, $damascus, [['learner', $global]], 'suspended'],
        ];

        foreach ($accounts as $a) {
            [$email, $name, $country, $region, $roles] = $a;
            [$first, $last] = array_pad(explode(' ', $name, 2), 2, 'Demo');

            $user = User::withTrashed()->where('email', $email)->first() ?? new User;
            $user->fill([
                'first_name' => $first, 'last_name' => $last, 'display_name' => $name, 'email' => $email,
                'password' => self::PASSWORD, 'country_id' => $country, 'region_id' => $region,
            ]);
            $user->forceFill([
                'status' => $a[5] ?? 'active', 'email_verified_at' => now(),
                'failed_login_count' => 0, 'locked_until' => null, 'deleted_at' => null,
            ])->save();

            foreach ($roles as [$role, $scope]) {
                $this->grant($user, $role, $scope);
            }
        }

        foreach (['member@demo.test', 'orgadmin@demo.test'] as $email) {
            OrganizationMembership::query()->updateOrCreate(
                ['organization_id' => $org->id, 'user_id' => User::where('email', $email)->value('id')],
                ['membership_type' => 'member', 'status' => 'active', 'joined_at' => now()],
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(AccessResolver::class)->flush();

        $this->command?->info('Demo accounts ready — password: '.self::PASSWORD);
        $this->command?->table(['email', 'scope ids'], collect($accounts)->map(fn ($a) => [
            $a[0], collect($a[4])->map(fn ($r) => $r[0].'@'.$r[1]->id)->implode(', '),
        ])->all());
    }

    private function grant(User $user, string $role, AccessScope $scope): void
    {
        $roleModel = Role::query()->where('name', $role)->whereNull('scope_id')->firstOrFail();

        DB::table('model_has_roles')->updateOrInsert(
            ['role_id' => $roleModel->id, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id, 'scope_id' => $scope->id],
            ['grant_reason' => 'DemoSeeder', 'created_at' => now()],
        );

        if (! UserContext::query()->where(['user_id' => $user->id, 'context_id' => $roleModel->context_id])->exists()) {
            UserContext::query()->create([
                'user_id' => $user->id, 'context_id' => $roleModel->context_id, 'status' => 'active',
                'is_default' => ! UserContext::query()->where('user_id', $user->id)->where('is_default', true)->exists(),
                'activated_at' => now(),
            ]);
        }
    }
}
