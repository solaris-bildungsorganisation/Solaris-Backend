<?php

namespace Database\Seeders;

use Database\Seeders\Notifications\NotificationCatalogSeeder;
use Database\Seeders\Rbac\ContextSeeder;
use Database\Seeders\Rbac\PermissionSeeder;
use Database\Seeders\Rbac\RoleSeeder;
use Database\Seeders\Rbac\SuperAdminSeeder;
use Database\Seeders\Reference\CountryRegionSeeder;
use Database\Seeders\Reference\LanguageSeeder;
use Illuminate\Database\Seeder;

/**
 * بيانات النظام المرجعية فقط — لا بيانات تجريبية. كل Seeder هنا idempotent وآمن في الإنتاج.
 * البيانات التجريبية (مستخدمون/مؤسسات وهمية) تُكتب لاحقًا في DemoSeeder منفصل ولا تُستدعى من هنا.
 *
 *   php artisan db:seed                                  # الكل
 *   php artisan db:seed --class="Database\Seeders\Rbac\RoleSeeder"   # بعد تعديل RbacCatalog
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LanguageSeeder::class,
            CountryRegionSeeder::class,
            ContextSeeder::class,
            PermissionSeeder::class,
            RoleSeeder::class,
            NotificationCatalogSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
