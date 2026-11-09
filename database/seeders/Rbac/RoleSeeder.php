<?php

namespace Database\Seeders\Rbac;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ينشئ أدوار النظام الـ 14 كأدوار عامة (scope_id = NULL) ويزامن صلاحياتها مع RbacCatalog.
 * يعتمد على PermissionSeeder و ContextSeeder. آمن للتشغيل المتكرر وفي الإنتاج.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $contexts = DB::table('contexts')->pluck('id', 'key');
        $scopeKey = config('permission.column_names.team_foreign_key');

        DB::transaction(function () use ($contexts, $scopeKey) {
            foreach (RbacCatalog::roles() as $key => $def) {
                if (! isset($contexts[$def['context']])) {
                    throw new \RuntimeException("Context [{$def['context']}] غير موجود — شغّل ContextSeeder أولًا.");
                }

                [$ar, $en] = $def['name'];

                $role = Role::query()->updateOrCreate(
                    ['name' => $key, 'guard_name' => RbacCatalog::GUARD, $scopeKey => null],
                    [
                        'context_id' => $contexts[$def['context']],
                        'display_name' => json_encode(['ar' => $ar, 'en' => $en], JSON_UNESCAPED_UNICODE),
                        'is_administrative' => $def['administrative'],
                        'is_self_registrable' => $def['self_registrable'],
                        'requires_approval' => $def['requires_approval'],
                        'allowed_scope_types' => json_encode($def['scopes']),
                        'rank' => $def['rank'],
                        'is_system' => true,
                        'is_active' => true,
                    ],
                );

                $role->syncPermissions(RbacCatalog::resolveRolePermissions($key, $def['permissions']));
            }
        });

        $registrar->forgetCachedPermissions();

        $unknown = Role::query()->where('guard_name', RbacCatalog::GUARD)->whereNull($scopeKey)
            ->whereNotIn('name', array_keys(RbacCatalog::roles()))->pluck('name')->all();

        $this->command?->info('Roles: ' . count(RbacCatalog::roles()));
        if ($unknown !== []) {
            $this->command?->warn('Global roles in DB but not in RbacCatalog (not deleted): ' . implode(', ', $unknown));
        }
    }
}
