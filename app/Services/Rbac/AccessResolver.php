<?php

namespace App\Services\Rbac;

use App\Models\AccessScope;
use App\Models\User;
use App\Models\UserSession;
use Database\Seeders\Rbac\RbacCatalog;
use Illuminate\Support\Facades\DB;

/**
 * يحسب الصلاحيات الفعلية للمستخدم — المصدر الوحيد لقرار "هل يملك الصلاحية؟" في المنصة.
 *
 * قواعد الحساب (ورقة B4 — إلزامية):
 *  1. الصلاحيات الفعلية = أدوار النطاق العام ∪ أدوار النطاق الفعّال (user_sessions.active_scope_id).
 *     spatie مع teams يرى النطاق الحالي فقط؛ لذلك لا نعتمد على hasPermissionTo الأصلي.
 *  2. فلترة حسب السياق الفعّال (FR-AUTH-016/017): الدور يُحسب فقط إذا طابق context_id السياق الفعّال،
 *     عدا الصلاحيات الذاتية (RbacCatalog::selfService) التي تسري دائمًا.
 *  3. صلاحيات الإعداد العام (RbacCatalog::globalOnly) لا تُحسب من إسناد بنطاق دولة/منطقة/مؤسسة (خلية G).
 *  4. الدور الذي تجاوز expires_at لا يُحسب.
 *  5. الحالة: لا صلاحية لمستخدم غير active، ولا صلاحيات من نطاق مؤسسة معلّقة أو مؤرشفة.
 *
 * النتيجة مخزّنة لكل (مستخدم، توكن) خلال الطلب؛ ResolveAccessScope و RoleAssignmentService يستدعيان flush().
 */
class AccessResolver
{
    /** @var array<string, EffectiveAccess> */
    private array $cache = [];

    /** @var array<string, true> سجل ما تم تدوينه في activity_log خلال الطلب (منع التكرار) */
    private array $logged = [];

    /** @var array<int, list<string>> */
    private array $rolePermissionCache = [];

    public function for(User $user): EffectiveAccess
    {
        $session = $this->currentSession($user);
        $key = $user->getKey().':'.($session?->getKey() ?? 'none');

        return $this->cache[$key] ??= $this->build($user, $session);
    }

    /** أعلى رتبة مُسندة للمستخدم في أي نطاق (إسنادات غير منتهية) — لقاعدة "لا على رتبة أعلى". */
    public function highestAssignedRank(User $user): int
    {
        $t = config('permission.table_names');
        $morphKey = config('permission.column_names.model_morph_key');

        return (int) DB::table($t['model_has_roles'].' as mhr')
            ->join($t['roles'].' as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', $user->getMorphClass())
            ->where("mhr.{$morphKey}", $user->getKey())

            ->where(fn ($q) => $q->whereNull('mhr.expires_at')->orWhere('mhr.expires_at', '>', now()))
            ->max('r.rank');
    }

    public function flush(): void
    {
        $this->cache = [];
        $this->logged = [];
        $this->rolePermissionCache = [];
    }

    /** يعيد true مرة واحدة فقط لكل مفتاح خلال الطلب — لمنع إغراق activity_log بنفس الحدث. */
    public function firstTime(string $key): bool
    {
        if (isset($this->logged[$key])) {
            return false;
        }

        return $this->logged[$key] = true;
    }

    private function currentSession(User $user): ?UserSession
    {
        $token = $user->currentAccessToken();

        if (! $token || ! isset($token->id)) {
            return null;
        }

        return $user->currentSession();
    }

    private function build(User $user, ?UserSession $session): EffectiveAccess
    {
        $globalId = (int) config('solaris.global_scope_id');
        $activeScope = ($session?->active_scope_id ? AccessScope::query()->find($session->active_scope_id) : null)
            ?? AccessScope::query()->findOrFail($globalId);

        [$contextId, $contextKey] = $this->activeContext($user, $session);

        if ($user->status !== 'active') {
            return new EffectiveAccess($user->getKey(), false, $activeScope, $contextId, $contextKey, false, [], [], []);
        }

        $scopeIds = array_values(array_unique([$globalId, (int) $activeScope->getKey()]));
        $scopes = AccessScope::query()->whereIn('id', $scopeIds)->get()->keyBy('id')->all();

        $selfService = RbacCatalog::selfService();
        $globalOnly = RbacCatalog::globalOnly();

        $grants = [];
        $roles = [];
        $superAdmin = false;

        foreach ($this->assignments($user, $scopeIds) as $row) {
            $scopeId = (int) $row->scope_id;
            $counted = $row->context_id === null || (int) $row->context_id === $contextId;
            $permissions = $this->rolePermissions((int) $row->role_id);

            $effective = array_values(array_filter($permissions, function (string $p) use ($counted, $selfService, $globalOnly, $scopeId, $globalId) {
                if (! $counted && ! in_array($p, $selfService, true)) {
                    return false;
                }

                return $scopeId === $globalId || ! in_array($p, $globalOnly, true);
            }));

            foreach ($effective as $p) {
                $grants[$p][] = $scopeId;
            }

            if ($counted && $scopeId === $globalId && $row->name === RbacCatalog::SUPER_ADMIN) {
                $superAdmin = true;
            }

            $roles[] = [
                'role_id' => (int) $row->role_id,
                'name' => $row->name,
                'rank' => (int) $row->rank,
                'context_id' => $row->context_id === null ? null : (int) $row->context_id,
                'scope_id' => $scopeId,
                'scope_type' => $scopes[$scopeId]->type,
                'expires_at' => $row->expires_at,
                'counted' => $counted,
                'permissions' => $effective,
            ];
        }

        // صلاحيات مباشرة (model_has_permissions) — غير مستخدمة بالسياسة لكنها تُحترم إن وُجدت.
        foreach ($this->directPermissions($user, $scopeIds) as $row) {
            if ((int) $row->scope_id === $globalId || ! in_array($row->name, $globalOnly, true)) {
                $grants[$row->name][] = (int) $row->scope_id;
            }
        }

        if ($superAdmin) {
            foreach (RbacCatalog::permissionNames() as $p) {
                $grants[$p][] = $globalId;
            }
        }

        $grants = array_map(fn (array $ids) => array_values(array_unique($ids)), $grants);

        return new EffectiveAccess(
            $user->getKey(), true, $activeScope, $contextId, $contextKey, $superAdmin, $grants, $scopes, $roles,
        );
    }

    /** السياق الفعّال: سياق الجلسة، وإلا السياق الافتراضي النشط للمستخدم. */
    private function activeContext(User $user, ?UserSession $session): array
    {
        $contextId = $session?->active_context_id;

        if ($contextId === null) {
            $contextId = DB::table('user_contexts')
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->where('is_default', true)
                ->value('context_id');
        }

        if ($contextId === null) {
            return [null, null];
        }

        return [(int) $contextId, DB::table('contexts')->where('id', $contextId)->value('key')];
    }

    private function assignments(User $user, array $scopeIds): array
    {
        $t = config('permission.table_names');
        $morphKey = config('permission.column_names.model_morph_key');

        return DB::table($t['model_has_roles'].' as mhr')
            ->join($t['roles'].' as r', 'r.id', '=', 'mhr.role_id')
            ->join('access_scopes as s', 's.id', '=', 'mhr.scope_id')
            ->leftJoin('organizations as o', 'o.id', '=', 's.organization_id')
            ->where('mhr.model_type', $user->getMorphClass())
            ->where("mhr.{$morphKey}", $user->getKey())
            ->whereIn('mhr.scope_id', $scopeIds)
            ->where(fn ($q) => $q->whereNull('mhr.expires_at')->orWhere('mhr.expires_at', '>', now()))
            ->where('r.is_active', true)
            ->where(fn ($q) => $q->whereNull('s.organization_id')
                ->orWhere(fn ($q) => $q->where('o.status', 'active')->whereNull('o.deleted_at')))
            ->orderByDesc('r.rank')
            ->get(['mhr.role_id', 'mhr.scope_id', 'mhr.expires_at', 'r.name', 'r.rank', 'r.context_id'])
            ->all();
    }

    /** @return list<string> */
    private function rolePermissions(int $roleId): array
    {
        if (! isset($this->rolePermissionCache[$roleId])) {
            $t = config('permission.table_names');
            $this->rolePermissionCache[$roleId] = DB::table($t['role_has_permissions'].' as rp')
                ->join($t['permissions'].' as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.role_id', $roleId)
                ->pluck('p.name')
                ->all();
        }

        return $this->rolePermissionCache[$roleId];
    }

    private function directPermissions(User $user, array $scopeIds): array
    {
        $t = config('permission.table_names');
        $morphKey = config('permission.column_names.model_morph_key');

        return DB::table($t['model_has_permissions'].' as mhp')
            ->join($t['permissions'].' as p', 'p.id', '=', 'mhp.permission_id')
            ->where('mhp.model_type', $user->getMorphClass())
            ->where("mhp.{$morphKey}", $user->getKey())
            ->whereIn('mhp.scope_id', $scopeIds)
            ->get(['p.name', 'mhp.scope_id'])
            ->all();
    }
}
