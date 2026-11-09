<?php

namespace Database\Seeders\Rbac;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

/**
 * ينشئ أول Super Administrator من متغيرات البيئة ويُسند له الدور في النطاق العام.
 * - يتخطّى بهدوء إذا لم تُضبط المتغيرات (لا يفشل الـ deploy).
 * - لا يغيّر كلمة مرور حساب موجود (لا يعيد تعيين كلمة مرور مدير فعلي عند كل تشغيل).
 * - granted_by = NULL (النظام) — لا أحد "منح نفسه" الدور.
 */
class SuperAdminSeeder extends Seeder
{
    private const MIN_PASSWORD_LENGTH = 12;

    public function run(): void
    {
        $cfg = config('solaris.super_admin');

        if (empty($cfg['email']) || empty($cfg['password'])) {
            $this->command?->warn('SuperAdminSeeder: SOLARIS_SUPER_ADMIN_EMAIL / _PASSWORD غير مضبوطة — تم التخطي.');

            return;
        }

        if (mb_strlen($cfg['password']) < self::MIN_PASSWORD_LENGTH) {
            throw new \RuntimeException('SOLARIS_SUPER_ADMIN_PASSWORD يجب أن تكون ' . self::MIN_PASSWORD_LENGTH . ' محرفًا على الأقل.');
        }

        $userModel = config('auth.providers.users.model');
        if (! in_array(HasRoles::class, class_uses_recursive($userModel), true)) {
            throw new \RuntimeException("{$userModel} يجب أن يستخدم Spatie\\Permission\\Traits\\HasRoles.");
        }

        DB::transaction(function () use ($cfg, $userModel) {
            $email = mb_strtolower(trim($cfg['email']));
            $user = $userModel::query()->where('email', $email)->first();

            if (! $user) {
                $hash = Hash::make($cfg['password']);
                $user = new $userModel;
                $user->forceFill([
                    'uuid' => (string) Str::uuid(),
                    'first_name' => $cfg['first_name'],
                    'last_name' => $cfg['last_name'],
                    'email' => $email,
                    'password' => $hash,
                    'status' => 'active',
                    'status_changed_at' => now(),
                    'email_verified_at' => now(),
                    'password_changed_at' => now(),
                    'must_change_password' => true,
                ])->save();

                DB::table('password_histories')->insert(['user_id' => $user->getKey(), 'password_hash' => $hash, 'created_at' => now()]);
                DB::table('account_status_histories')->insert([
                    'user_id' => $user->getKey(), 'from_status' => null, 'to_status' => 'active',
                    'reason' => 'Initial super administrator (SuperAdminSeeder)', 'changed_by' => null, 'created_at' => now(),
                ]);
                $this->command?->info("Super admin created: {$email}");
            } else {
                $this->command?->info("Super admin exists: {$email} (password unchanged)");
            }

            // الإسناد في النطاق العام
            $registrar = app(PermissionRegistrar::class);
            $previousTeam = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId(config('solaris.global_scope_id'));

            if (! $user->hasRole(RbacCatalog::SUPER_ADMIN)) {
                $user->assignRole(RbacCatalog::SUPER_ADMIN);
            }

            $registrar->setPermissionsTeamId($previousTeam);

            // سياق الإدارة كسياق افتراضي
            $contextId = DB::table('contexts')->where('key', 'administration')->value('id');
            $hasContext = DB::table('user_contexts')
                ->where(['user_id' => $user->getKey(), 'context_id' => $contextId])->exists();

            if (! $hasContext) {
                $hasDefault = DB::table('user_contexts')->where(['user_id' => $user->getKey(), 'is_default' => true])->exists();
                DB::table('user_contexts')->insert([
                    'user_id' => $user->getKey(), 'context_id' => $contextId, 'status' => 'active',
                    'is_default' => ! $hasDefault, 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}
