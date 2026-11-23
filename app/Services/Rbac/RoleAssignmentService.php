<?php

namespace App\Services\Rbac;
use App\Events\UserRolesChanged;
use App\Models\AccessScope;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserContext;
use App\Policies\ScopeGuard;
use Carbon\CarbonInterface;
use Database\Seeders\Rbac\RbacCatalog;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * RoleAssignmentService — يطبّق ورقة "إسناد الأدوار" (FR-AUTH-005, 014, 022 · BR-AUTH-004).
 * المكان الوحيد الذي يكتب في model_has_roles. يستخدمه C2 (UserRoleController) و C3 (مراجعة طلبات نوع الحساب).
 *
 * قواعد الإسناد (كلها تُفحص هنا، ولا يتجاوزها super_admin):
 *  1. rank(الدور المُسنَد) < أعلى رتبة للمُسنِد في النطاق الذي يمنحه roles.assign — المساواة ممنوعة.
 *  2. نوع النطاق ضمن allowed_scope_types للدور المُسنَد.
 *  3. نطاق الإسناد ⊆ نطاق المُسنِد.
 *  4. لا إسناد للنفس (يُفرض أيضًا بقيد CHECK في القاعدة).
 *  5. granted_by و grant_reason إلزاميان للأدوار الإدارية؛ expires_at اختياري.
 *  6. roles.assign / roles.revoke تتطلب MFA (MfaGuard) وتُسجَّل في activity_log (rbac, restricted).
 *  7. super_admin لا يُسنَد عبر الـ API (D3 — SuperAdminSeeder فقط).
 *  + الدور يجب أن يكون في قائمة الأدوار التي يحق لدور المُسنِد إسنادها (RbacCatalog::assignableRoles).
 *  + إسناد بنطاق مؤسسة يتطلب أن يكون المستخدم عضوًا نشطًا فيها.
 *
 * بعد كل إسناد أو سحب: مسح كاش spatie وكاش AccessResolver.
 */
class RoleAssignmentService
{
    public function __construct(
        private readonly AccessResolver $resolver,
        private readonly MfaGuard $mfa,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /** @throws RoleAssignmentException */
    public function assign(
        User $actor,
        User $target,
        Role|string $role,
        AccessScope $scope,
        ?string $reason = null,
        ?CarbonInterface $expiresAt = null,
    ): void {
        $role = $this->role($role);

        $this->authorize($actor, $target, $role, $scope, 'roles.assign');

        if ($role->is_administrative && blank($reason)) {
            throw new RoleAssignmentException(RoleAssignmentException::REASON_REQUIRED, 422);
        }

        DB::transaction(function () use ($actor, $target, $role, $scope, $reason, $expiresAt) {
            $this->write($target, $role, $scope, $actor->getKey(), $reason, $expiresAt);
            $this->audit($actor, $target, $role, $scope, 'role_assigned', $reason, $expiresAt);
        });

        $this->flush($target);
event(new UserRolesChanged($target->fresh(), 'assigned', $role->name, (int) $scope->getKey(), $actor->getKey()));
    }

    /** @throws RoleAssignmentException */
    public function revoke(User $actor, User $target, Role|string $role, AccessScope $scope, ?string $reason = null): void
    {
        $role = $this->role($role);

        $this->authorize($actor, $target, $role, $scope, 'roles.revoke');

        DB::transaction(function () use ($actor, $target, $role, $scope, $reason) {
            if ($this->assignmentQuery($target, $role, $scope)->delete() === 0) {
                throw new RoleAssignmentException(RoleAssignmentException::NOT_ASSIGNED, 404);
            }
            $this->audit($actor, $target, $role, $scope, 'role_revoked', $reason);
        });

        $this->flush($target);
        event(new UserRolesChanged($target->fresh(), 'revoked', $role->name, (int) $scope->getKey(), $actor->getKey()));
    }

    /** نفس فحوص assign دون كتابة — لـ FormRequest::authorize() أو لإظهار الأزرار. */
    public function canAssign(User $actor, User $target, Role|string $role, AccessScope $scope, string $permission = 'roles.assign'): bool
    {
        try {
            $this->authorize($actor, $target, $this->role($role), $scope, $permission);

            return true;
        } catch (RoleAssignmentException) {
            return false;
        }
    }

    /**
     * إسناد من النظام (granted_by = NULL): مثل learner عند التسجيل (FR-AUTH-003)، أو الدور المعتمد بعد
     * مراجعة طلب نوع حساب عندما يكون المراجع قد فُحص مسبقًا. لا يمر بقواعد المُسنِد، لكنه يحترم D3 ونوع النطاق.
     */
    public function assignBySystem(User $target, Role|string $role, ?AccessScope $scope = null, ?string $reason = null): void
    {
        $role = $this->role($role);
        $scope ??= AccessScope::global();

        if ($role->name === RbacCatalog::SUPER_ADMIN) {
            throw new RoleAssignmentException(RoleAssignmentException::SUPER_ADMIN_SEEDER_ONLY);
        }
        if (! $role->allowsScope($scope->type)) {
            throw new RoleAssignmentException(RoleAssignmentException::SCOPE_TYPE_NOT_ALLOWED, 422);
        }

        DB::transaction(function () use ($target, $role, $scope, $reason) {
            $this->write($target, $role, $scope, null, $reason, null);
            $this->audit(null, $target, $role, $scope, 'role_assigned', $reason);
        });

        $this->flush($target);
        event(new UserRolesChanged($target->fresh(), 'assigned', $role->name, (int) $scope->getKey(), null));
    }

    /** يسحب الإسنادات المنتهية (expires_at) ويسجّل ذلك — مجدول يوميًا في routes/console.php. */
    public function pruneExpired(): int
    {
        $t = config('permission.table_names');
        $morphKey = config('permission.column_names.model_morph_key');

        $expired = DB::table($t['model_has_roles'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where('model_type', (new User)->getMorphClass())
            ->get();

        foreach ($expired as $row) {
            DB::transaction(function () use ($row, $t, $morphKey) {
                DB::table($t['model_has_roles'])
                    ->where('role_id', $row->role_id)
                    ->where('model_type', $row->model_type)
                    ->where($morphKey, $row->{$morphKey})
                    ->where('scope_id', $row->scope_id)
                    ->delete();

                $target = User::withTrashed()->find($row->{$morphKey});
                $role = Role::query()->find($row->role_id);
                $scope = AccessScope::query()->find($row->scope_id);

                if ($target && $role && $scope) {
                    $this->audit(null, $target, $role, $scope, 'role_expired', 'expires_at reached');
                }
            });
        }

        if ($expired->isNotEmpty()) {
            $this->registrar->forgetCachedPermissions();
            $this->resolver->flush();
        }

        return $expired->count();
    }

    // ───────────── القواعد ─────────────

    /**
     * قواعد المُسنِد فقط (بدون مستخدم مستهدف): للدعوة إلى مؤسسة بدور مقترح، ولمراجعة طلب نوع حساب (D1).
     * النطاق قد يكون غير محفوظ (new AccessScope([...])) — الفحص على الصفات فقط.
     */
    public function canGrant(User $actor, Role|string $role, AccessScope $scope, string $permission = 'roles.assign'): bool
    {
        try {
            $this->authorizeGrant($actor, $this->role($role), $scope, $permission);

            return true;
        } catch (RoleAssignmentException) {
            return false;
        }
    }

    /** @throws RoleAssignmentException */
    private function authorize(User $actor, User $target, Role $role, AccessScope $scope, string $permission): void
    {
        // 4. لا إسناد/سحب للنفس — حتى لـ super_admin
        if ($actor->is($target)) {
            throw new RoleAssignmentException(RoleAssignmentException::SELF_ASSIGNMENT);
        }

        $this->authorizeGrant($actor, $role, $scope, $permission);

        // إسناد داخل مؤسسة لعضو نشط فيها فقط
        if ($permission === 'roles.assign' && $scope->type === AccessScope::TYPE_ORGANIZATION && ! OrganizationMembership::query()
            ->where('organization_id', $scope->organization_id)
            ->where('user_id', $target->getKey())
            ->where('status', 'active')
            ->exists()) {
            throw new RoleAssignmentException(RoleAssignmentException::TARGET_NOT_MEMBER, 422);
        }

        // 6. MFA (D8: تحذير الآن، رفض بعد FA2)
        if (! $this->mfa->allows($actor, $permission)) {
            throw new RoleAssignmentException(RoleAssignmentException::MFA_REQUIRED);
        }
    }

    /** @throws RoleAssignmentException */
    private function authorizeGrant(User $actor, Role $role, AccessScope $scope, string $permission): void
    {
        // 7. D3
        if ($role->name === RbacCatalog::SUPER_ADMIN) {
            throw new RoleAssignmentException(RoleAssignmentException::SUPER_ADMIN_SEEDER_ONLY);
        }

        if ($permission === 'roles.assign' && ! $role->is_active) {
            throw new RoleAssignmentException(RoleAssignmentException::ROLE_INACTIVE, 422);
        }

        // 2. نوع النطاق
        if ($permission === 'roles.assign' && ! $role->allowsScope($scope->type)) {
            throw new RoleAssignmentException(RoleAssignmentException::SCOPE_TYPE_NOT_ALLOWED, 422);
        }

        $access = $this->resolver->for($actor);

        if (! $access->has($permission)) {
            throw new RoleAssignmentException(RoleAssignmentException::MISSING_PERMISSION);
        }

        // 3. نطاق الإسناد ⊆ نطاق المُسنِد — نجمع أدوار المُسنِد التي تمنح الصلاحية في نطاق يحتوي نطاق الإسناد
        $granting = array_filter($access->roles, fn (array $r) => $r['counted']
            && in_array($permission, $r['permissions'], true)
            && ScopeGuard::scopeWithin($scope, $access->scopes[$r['scope_id']]));

        if ($granting === []) {
            throw new RoleAssignmentException(RoleAssignmentException::SCOPE_OUTSIDE_ASSIGNER);
        }

        // ورقة الإسناد: هل يحق لدور المُسنِد إسناد هذا الدور؟
        $assignable = array_filter($granting, fn (array $r) => $this->mayAssign($r['name'], $role->name));
        if ($assignable === []) {
            throw new RoleAssignmentException(RoleAssignmentException::ROLE_NOT_ASSIGNABLE);
        }

        // 1. الرتبة: أقل تمامًا من أعلى رتبة للمُسنِد في هذا النطاق
        $actorRank = max(array_map(fn (array $r) => $r['rank'], $assignable));
        if ($role->rank >= $actorRank) {
            throw new RoleAssignmentException(RoleAssignmentException::RANK_TOO_HIGH);
        }
    }

    private function mayAssign(string $assignerRole, string $role): bool
    {
        $spec = RbacCatalog::assignableRoles()[$assignerRole] ?? [];

        if (($spec[0] ?? null) === '*') {
            return ! in_array($role, $spec['except'] ?? [], true);
        }

        return in_array($role, $spec, true);
    }

    // ───────────── الكتابة والسجل ─────────────

    private function write(User $target, Role $role, AccessScope $scope, ?int $grantedBy, ?string $reason, ?CarbonInterface $expiresAt): void
    {
        $t = config('permission.table_names');
        $morphKey = config('permission.column_names.model_morph_key');

        DB::table($t['model_has_roles'])->updateOrInsert(
            [
                'role_id' => $role->getKey(),
                'model_type' => $target->getMorphClass(),
                $morphKey => $target->getKey(),
                'scope_id' => $scope->getKey(),
            ],
            [
                'granted_by' => $grantedBy,
                'grant_reason' => $reason,
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ],
        );

        // السياق صفة للدور: المستخدم يحتاج سياق الدور ليتمكن من تفعيله (FR-AUTH-017)
        if ($role->context_id !== null) {
            $context = UserContext::query()->firstOrNew(['user_id' => $target->getKey(), 'context_id' => $role->context_id]);

            if (! $context->exists) {
                $context->is_default = ! UserContext::query()->where('user_id', $target->getKey())->where('is_default', true)->exists();
                $context->granted_by = $grantedBy;
            }

            if (! $context->exists || $context->status !== 'active') {
                $context->status = 'active';
                $context->activated_at = now();
                $context->deactivated_at = null;
                $context->save();
            }
        }
    }

    private function assignmentQuery(User $target, Role $role, AccessScope $scope)
    {
        $t = config('permission.table_names');
        $morphKey = config('permission.column_names.model_morph_key');

        return DB::table($t['model_has_roles'])
            ->where('role_id', $role->getKey())
            ->where('model_type', $target->getMorphClass())
            ->where($morphKey, $target->getKey())
            ->where('scope_id', $scope->getKey());
    }

    private function audit(?User $actor, User $target, Role $role, AccessScope $scope, string $event, ?string $reason, ?CarbonInterface $expiresAt = null): void
    {
        $logger = activity('rbac')
            ->performedOn($target)
            ->tap(function ($activity) use ($scope, $actor) {
                $activity->sensitivity = 'restricted';
                $activity->organization_id = $scope->organization_id;
                $activity->context_id = $actor ? $this->resolver->for($actor)->activeContextId : null;
                $activity->ip_address = request()?->ip();
                $activity->user_agent = request()?->userAgent();
            })
            ->withProperties([
                'role' => $role->name,
                'role_rank' => $role->rank,
                'scope_id' => $scope->getKey(),
                'scope_type' => $scope->type,
                'reason' => $reason,
                'expires_at' => $expiresAt?->toIso8601String(),
            ])
            ->event($event);

        if ($actor) {
            $logger->causedBy($actor);
        }

        $logger->log($event);
    }

    private function role(Role|string $role): Role
    {
        return $role instanceof Role
            ? $role
            : Role::query()->where('name', $role)->where('guard_name', RbacCatalog::GUARD)->whereNull('scope_id')->firstOrFail();
    }

    private function flush(User $target): void
    {
        $this->registrar->forgetCachedPermissions();
        $this->resolver->flush();
        $target->unsetRelation('roles')->unsetRelation('permissions');
    }
}
