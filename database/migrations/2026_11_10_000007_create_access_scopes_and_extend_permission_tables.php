<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC — نطاقات الوصول + توسيع جداول spatie/laravel-permission.
 *
 * جداول spatie الخمسة (roles, permissions, model_has_roles, model_has_permissions, role_has_permissions)
 * تُنشأ من migration الحزمة نفسها (000005) لأن شكلها يتبع config/permission.php.
 * هذا الملف لا ينشئها؛ يضيف عليها ما تحتاجه المنصة فقط.
 *
 * النطاق: نستخدم ميزة teams في spatie، لكن "الفريق" عندنا هو نطاق وصول (access_scopes):
 *   global | country | region | organization
 * وعمود الفريق في جداول spatie اسمه scope_id (config: permission.column_names.team_foreign_key).
 * هكذا يستطيع المستخدم أن يملك نفس الدور في أكثر من مؤسسة/منطقة (FR-AUTH-014/015).
 *
 * FR-AUTH-003/004/005/014/022 · BR-AUTH-003/004 · NFR-SEC-003
 */
return new class extends Migration
{
    /** النطاق العام — ثابت ويُنشأ هنا لأن كل إسناد عام يعتمد عليه. */
    private const GLOBAL_SCOPE_ID = 1;

    public function up(): void
    {
        $this->assertSpatieTablesReady();

        $t = config('permission.table_names');
        $scope = config('permission.column_names.team_foreign_key');

        // 1) نطاقات الوصول
        Schema::create('access_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->comment('global | country | region | organization');
            $table->foreignId('country_id')->nullable()->constrained('countries')->restrictOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('regions')->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->timestamps();

            // صف واحد فقط لكل هدف (MySQL: مفتاح مولّد بدل NULLS NOT DISTINCT)
            $table->string('target_key', 80)->virtualAs(
                "CONCAT_WS(':', type, IFNULL(country_id,0), IFNULL(region_id,0), IFNULL(organization_id,0))"
            );
            $table->unique('target_key', 'access_scopes_target_unique');
            $table->comment('FR-AUTH-014 — نطاقات الوصول (فريق spatie)');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access_scopes ADD CONSTRAINT access_scopes_shape_check CHECK (
                (type = 'global'       AND country_id IS NULL AND region_id IS NULL AND organization_id IS NULL) OR
                (type = 'country'      AND country_id IS NOT NULL AND region_id IS NULL AND organization_id IS NULL) OR
                (type = 'region'       AND region_id IS NOT NULL AND organization_id IS NULL) OR
                (type = 'organization' AND organization_id IS NOT NULL)
            )
        SQL);

        DB::table('access_scopes')->insert([
            'id' => self::GLOBAL_SCOPE_ID, 'type' => 'global', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 2) roles: خصائص الدور في المنصة
        Schema::table($t['roles'], function (Blueprint $table) use ($scope) {
            $table->foreignId('context_id')->nullable()->after('guard_name')->constrained('contexts')->restrictOnDelete();
            $table->json('display_name')->nullable()->after('context_id')->comment('{"ar":"…","en":"…"}');
            $table->json('description')->nullable()->after('display_name');
            $table->boolean('is_administrative')->default(false)->after('description')->comment('دور إداري — لا يُمنح ذاتيًا (FR-AUTH-005)');
            $table->boolean('is_self_registrable')->default(false)->after('is_administrative')->comment('يُسند تلقائيًا عند التسجيل — FR-AUTH-003');
            $table->boolean('requires_approval')->default(true)->after('is_self_registrable')->comment('يحتاج طلب نوع حساب ومراجعة — FR-AUTH-004');
            $table->json('allowed_scope_types')->nullable()->after('requires_approval')->comment('["global","country","region","organization"]');
            $table->unsignedSmallInteger('rank')->default(0)->after('allowed_scope_types')->comment('منع التصعيد: لا يُسند دور rank أعلى من رتبة المُسنِد');
            $table->boolean('is_system')->default(false)->after('rank')->comment('أدوار النظام لا تُحذف ولا يُغيَّر اسمها');
            $table->boolean('is_active')->default(true)->after('is_system');

            // الدور العام scope_id = NULL. دور خاص بمؤسسة (ميزة spatie) يشير إلى نطاقها.
            $table->foreign($scope, 'roles_scope_id_foreign')->references('id')->on('access_scopes')->restrictOnDelete();
        });

        // 3) permissions: Resource × Action × Sensitivity
        Schema::table($t['permissions'], function (Blueprint $table) {
            $table->string('resource', 50)->nullable()->after('guard_name');
            $table->string('action', 50)->nullable()->after('resource');
            $table->json('display_name')->nullable()->after('action');
            $table->string('sensitivity', 20)->default('internal')->after('display_name')
                ->comment('public | internal | personal | sensitive | restricted');
            $table->boolean('requires_mfa')->default(false)->after('sensitivity')->comment('NFR-SEC-002');
            $table->index(['resource', 'action'], 'permissions_resource_action_index');
        });
        DB::statement("ALTER TABLE `{$t['permissions']}` ADD CONSTRAINT permissions_sensitivity_check CHECK (sensitivity IN ('public','internal','personal','sensitive','restricted'))");

        // 4) model_has_roles: من منح الدور، ومتى ينتهي (FR-AUTH-022/023)
        Schema::table($t['model_has_roles'], function (Blueprint $table) use ($scope) {
            $table->foreign($scope, 'model_has_roles_scope_id_foreign')->references('id')->on('access_scopes')->restrictOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->restrictOnDelete()
                ->comment('null = النظام. RESTRICT لأن MySQL يمنع CHECK على عمود FK بـ SET NULL');
            $table->string('grant_reason')->nullable();
            $table->timestamp('expires_at')->nullable()->comment('Job مجدول يزيل الإسنادات المنتهية');
            $table->timestamp('created_at')->useCurrent();
            $table->index('expires_at', 'model_has_roles_expires_at_index');
        });
        // FR-AUTH-005 / BR-AUTH-004: لا أحد يمنح نفسه دورًا — خط دفاع أخير بعد الـ Policy
        DB::statement("ALTER TABLE `{$t['model_has_roles']}` ADD CONSTRAINT model_has_roles_no_self_grant CHECK (granted_by IS NULL OR granted_by <> model_id)");

        // 5) model_has_permissions: الصلاحيات المباشرة غير مستخدمة بالسياسة (كل شيء عبر الأدوار)، نربط النطاق فقط
        Schema::table($t['model_has_permissions'], function (Blueprint $table) use ($scope) {
            $table->foreign($scope, 'model_has_permissions_scope_id_foreign')->references('id')->on('access_scopes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $t = config('permission.table_names');

        Schema::table($t['model_has_permissions'], fn (Blueprint $table) => $table->dropForeign('model_has_permissions_scope_id_foreign'));

        DB::statement("ALTER TABLE `{$t['model_has_roles']}` DROP CHECK model_has_roles_no_self_grant");
        Schema::table($t['model_has_roles'], function (Blueprint $table) {
            $table->dropForeign('model_has_roles_scope_id_foreign');
            $table->dropForeign(['granted_by']);
            $table->dropIndex('model_has_roles_expires_at_index');
            $table->dropColumn(['granted_by', 'grant_reason', 'expires_at', 'created_at']);
        });

        DB::statement("ALTER TABLE `{$t['permissions']}` DROP CHECK permissions_sensitivity_check");
        Schema::table($t['permissions'], function (Blueprint $table) {
            $table->dropIndex('permissions_resource_action_index');
            $table->dropColumn(['resource', 'action', 'display_name', 'sensitivity', 'requires_mfa']);
        });

        Schema::table($t['roles'], function (Blueprint $table) {
            $table->dropForeign('roles_scope_id_foreign');
            $table->dropForeign(['context_id']);
            $table->dropColumn(['context_id', 'display_name', 'description', 'is_administrative', 'is_self_registrable',
                'requires_approval', 'allowed_scope_types', 'rank', 'is_system', 'is_active']);
        });

        Schema::dropIfExists('access_scopes');
    }

    /** يفشل برسالة واضحة إذا لم تُنشر migration الحزمة أو لم تُضبط الإعدادات قبل التشغيل. */
    private function assertSpatieTablesReady(): void
    {
        $t = config('permission.table_names');

        throw_if(empty($t), RuntimeException::class,
            'config/permission.php غير موجود. شغّل: php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"');

        throw_unless(config('permission.teams') === true && config('permission.column_names.team_foreign_key') === 'scope_id',
            RuntimeException::class,
            "config/permission.php: يجب أن تكون 'teams' => true و 'team_foreign_key' => 'scope_id' قبل تشغيل migration الحزمة.");

        foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $key) {
            throw_unless(Schema::hasTable($t[$key]), RuntimeException::class,
                "الجدول {$t[$key]} غير موجود. انشر migration الحزمة وأعد تسميتها إلى 2026_11_10_000005_create_permission_tables.php (راجع README).");
        }

        throw_unless(Schema::hasColumn($t['model_has_roles'], 'scope_id'), RuntimeException::class,
            "{$t['model_has_roles']}.scope_id غير موجود: migration الحزمة شُغّلت قبل تفعيل teams. نفّذ migrate:fresh بعد ضبط config/permission.php.");
    }
};
