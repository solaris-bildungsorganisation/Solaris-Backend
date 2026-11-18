<?php

namespace App\Services\Rbac;

use App\Models\AccessScope;

/**
 * لقطة الصلاحيات الفعلية لمستخدم في طلب واحد (يبنيها AccessResolver).
 *
 * grants: permission => [scope_id, ...] — كل نطاق يمنح هذه الصلاحية (العام و/أو الفعّال).
 * roles : الإسنادات التي دخلت الحساب، مع counted=false للأدوار التي استُبعدت بسبب السياق.
 */
final class EffectiveAccess
{
    /**
     * @param  array<string, list<int>>  $grants
     * @param  array<int, AccessScope>  $scopes  النطاقات المشار إليها في grants (مفهرسة بالـ id)
     * @param  list<array<string, mixed>>  $roles
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $active,
        public readonly AccessScope $activeScope,
        public readonly ?int $activeContextId,
        public readonly ?string $activeContextKey,
        public readonly bool $superAdmin,
        public readonly array $grants,
        public readonly array $scopes,
        public readonly array $roles,
    ) {}

    public function has(string $permission): bool
    {
        return isset($this->grants[$permission]);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $names = array_keys($this->grants);
        sort($names);

        return $names;
    }

    /** النطاقات التي تمنح الصلاحية — فارغة إن لم تكن ممنوحة. @return list<AccessScope> */
    public function scopesFor(string $permission): array
    {
        return array_values(array_map(
            fn (int $id) => $this->scopes[$id],
            $this->grants[$permission] ?? [],
        ));
    }

    /** هل الصلاحية ممنوحة عبر إسناد في النطاق العام؟ */
    public function hasGlobally(string $permission): bool
    {
        return in_array((int) config('solaris.global_scope_id'), $this->grants[$permission] ?? [], true);
    }

    /** أعلى رتبة لدور محسوب يمنح الصلاحية داخل نطاق معيّن. */
    public function highestRankIn(int $scopeId, ?string $permission = null): int
    {
        $rank = 0;
        foreach ($this->roles as $role) {
            if (! $role['counted'] || $role['scope_id'] !== $scopeId) {
                continue;
            }
            if ($permission !== null && ! in_array($permission, $role['permissions'], true)) {
                continue;
            }
            $rank = max($rank, $role['rank']);
        }

        return $rank;
    }

    /** أسماء الأدوار المحسوبة في نطاق معيّن. @return list<string> */
    public function roleNamesIn(int $scopeId): array
    {
        return array_values(array_unique(array_map(
            fn (array $r) => $r['name'],
            array_filter($this->roles, fn (array $r) => $r['counted'] && $r['scope_id'] === $scopeId),
        )));
    }
}
