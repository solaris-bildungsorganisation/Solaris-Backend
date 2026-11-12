<?php

namespace Database\Seeders\Rbac;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * يزامن جدول permissions مع RbacCatalog::permissions().
 * لا يحذف صلاحيات غير موجودة في الكتالوج — يحذّر فقط (الحذف قرار يُتخذ عمدًا لأنه يسحبها من كل الأدوار).
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        DB::transaction(function () {
            foreach (RbacCatalog::permissions() as $resource => $actions) {
                foreach ($actions as $action => [$sensitivity, $requiresMfa, $labelAr]) {
                    Permission::query()->updateOrCreate(
                        ['name' => "{$resource}.{$action}", 'guard_name' => RbacCatalog::GUARD],
                        [
                            'resource' => $resource,
                            'action' => $action,
                            'display_name' => json_encode(['ar' => $labelAr], JSON_UNESCAPED_UNICODE),
                            'sensitivity' => $sensitivity,
                            'requires_mfa' => $requiresMfa,
                        ],
                    );
                }
            }
        });

        $registrar->forgetCachedPermissions();

        $catalog = RbacCatalog::permissionNames();
        $orphans = Permission::query()->where('guard_name', RbacCatalog::GUARD)
            ->whereNotIn('name', $catalog)->pluck('name')->all();

        $this->command?->info('Permissions: ' . count($catalog));
        if ($orphans !== []) {
            $this->command?->warn('Permissions in DB but not in RbacCatalog (not deleted): ' . implode(', ', $orphans));
        }
    }
}
