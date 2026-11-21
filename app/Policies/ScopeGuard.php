<?php

namespace App\Policies;

use App\Models\AccessScope;
use App\Models\AccountTypeRequest;
use App\Models\MetricSnapshot;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\Region;
use App\Models\RegionAccessPolicy;
use App\Models\ReportExport;
use App\Models\SuccessStory;
use App\Models\User;
use App\Models\UserLanguage;
use App\Models\UserRegionChange;
use App\Models\UserSession;
use App\Services\Rbac\AccessResolver;
use App\Services\Rbac\EffectiveAccess;
use App\Services\Rbac\MfaGuard;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ScopeGuard — يطبّق ورقة "قواعد النطاق" من مصفوفة التفويض (FR-AUTH-028).
 *
 * الصلاحية وحدها لا تكفي: يجب أن يقع السجل داخل نطاق إسناد الدور الذي يمنح الصلاحية.
 *   G عام      → كل السجلات
 *   C دولة     → سجلات الدولة
 *   R منطقة    → سجلات المنطقة وما تحتها (شجرة regions.parent_id)
 *   O مؤسسة    → سجلات المؤسسة فقط
 *
 * الاستخدام:
 *   ScopeGuard::covers($user, $model, 'users.view')            // bool — للسجل الواحد
 *   User::query()->visibleTo($user)                             // فلتر القوائم (trait VisibleWithinScope)
 *   ScopeGuard::authorize($user, 'users.update', $target, 'users.view')  // Response للـ Policy
 *
 * الرفض: 404 لسجل خارج النطاق، و403 لسجل مرئي بلا صلاحية (ورقة B4).
 */
class ScopeGuard
{
    /** الصلاحية الافتراضية حين لا تُمرَّر صلاحية إلى covers()/visibleTo(). */
    private const DEFAULT_PERMISSION = [
        User::class => 'users.view_any',
        Organization::class => 'organizations.view_any',
        AccountTypeRequest::class => 'account_type_requests.view_any',
        OrganizationMembership::class => 'organization_members.view',
        OrganizationInvitation::class => 'organization_members.view',
        UserSession::class => 'sessions.view_any',
        NotificationDelivery::class => 'notification_deliveries.view',
        UserRegionChange::class => 'users.change_region',
        SuccessStory::class => 'success_stories.manage',
        ReportExport::class => 'reports.view',
        MetricSnapshot::class => 'metrics.view',
        RegionAccessPolicy::class => 'region_policies.view',
        UserLanguage::class => 'users.view',
    ];

    // ───────────── واجهة الاستخدام ─────────────

    /** هل يقع السجل داخل نطاق أي إسناد يمنح المستخدم هذه الصلاحية؟ */
    public static function covers(User $user, Model $model, ?string $permission = null): bool
    {
        $permission ??= self::defaultPermission($model);

        foreach (self::access($user)->scopesFor($permission) as $scope) {
            if (self::scopeCovers($scope, $model)) {
                return true;
            }
        }

        return false;
    }

    /** يقصر الاستعلام على السجلات الواقعة في نطاق الصلاحية. لا صلاحية = لا نتائج. */
    public static function visibleTo(Builder $query, User $user, ?string $permission = null): Builder
    {
        $model = $query->getModel();
        $permission ??= self::defaultPermission($model);
        $scopes = self::access($user)->scopesFor($permission);

        if ($scopes === []) {
            return $query->whereRaw('1 = 0');
        }

        foreach ($scopes as $scope) {
            if ($scope->type === AccessScope::TYPE_GLOBAL) {
                return $query;
            }
        }

        return $query->where(function (Builder $q) use ($scopes, $model) {
            foreach ($scopes as $scope) {
                $q->orWhere(fn (Builder $inner) => self::constrain($inner, $scope, $model));
            }
        });
    }

    /**
     * قرار الـ Policy الموحّد:
     *  - السجل داخل نطاق الصلاحية                    → سماح
     *  - السجل مرئي (viewPermission) لكن بلا الصلاحية → 403
     *  - يملك الصلاحية لكن السجل خارج نطاقه          → 404 (لا نكشف وجوده)
     *  - لا يملك الصلاحية إطلاقًا                    → 403
     */
    public static function authorize(User $user, string $permission, ?Model $model = null, ?string $viewPermission = null): Response
    {
        $access = self::access($user);

        if ($model === null) {
            return $access->has($permission) ? self::mfa($user, $permission) : Response::deny();
        }

        if (self::covers($user, $model, $permission)) {
            return self::mfa($user, $permission);
        }

        if ($viewPermission !== null && self::covers($user, $model, $viewPermission)) {
            return Response::deny();
        }

        if ($access->has($permission) || ($viewPermission !== null && $access->has($viewPermission))) {
            return Response::denyAsNotFound();
        }

        return Response::deny();
    }

    /** هل نطاق الإسناد $inner محتوى داخل نطاق $outer؟ (قاعدة الإسناد 3: نطاق الإسناد ⊆ نطاق المُسنِد) */
    public static function scopeWithin(AccessScope $inner, AccessScope $outer): bool
    {
        return match ($outer->type) {
            AccessScope::TYPE_GLOBAL => true,
            AccessScope::TYPE_COUNTRY => match ($inner->type) {
                AccessScope::TYPE_COUNTRY, AccessScope::TYPE_REGION => (int) $inner->country_id === (int) $outer->country_id,
                AccessScope::TYPE_ORGANIZATION => (int) Organization::withTrashed()->whereKey($inner->organization_id)->value('country_id') === (int) $outer->country_id,
                default => false,
            },
            AccessScope::TYPE_REGION => match ($inner->type) {
                AccessScope::TYPE_REGION => in_array((int) $inner->region_id, self::regionSubtree((int) $outer->region_id), true),
                AccessScope::TYPE_ORGANIZATION => in_array(
                    (int) Organization::withTrashed()->whereKey($inner->organization_id)->value('region_id'),
                    self::regionSubtree((int) $outer->region_id),
                    true,
                ),
                default => false,
            },
            AccessScope::TYPE_ORGANIZATION => $inner->type === AccessScope::TYPE_ORGANIZATION
                && (int) $inner->organization_id === (int) $outer->organization_id,
            default => false,
        };
    }

    /** هل يغطي نطاق واحد هذا السجل؟ (القاعدة الصافية لورقة "قواعد النطاق") */
    public static function scopeCovers(AccessScope $scope, Model $model): bool
    {
        if ($scope->type === AccessScope::TYPE_GLOBAL) {
            return true;
        }

        return match (true) {
            $model instanceof User => self::coversUser($scope, $model),
            $model instanceof Organization => self::coversOrganization($scope, $model),
            $model instanceof AccountTypeRequest => self::coversAccountTypeRequest($scope, $model),
            $model instanceof OrganizationMembership,
            $model instanceof OrganizationInvitation => ($org = Organization::withTrashed()->find($model->organization_id)) !== null
                && self::coversOrganization($scope, $org),
            $model instanceof UserSession,
            $model instanceof NotificationDelivery,
            $model instanceof UserLanguage => ($u = User::withTrashed()->find($model->user_id)) !== null
                && self::coversUser($scope, $u),
            $model instanceof UserRegionChange => self::coversRegionChange($scope, $model),
            $model instanceof SuccessStory,
            $model instanceof MetricSnapshot => self::coversByColumns($scope, $model->country_id, $model->region_id, $model->organization_id),
            $model instanceof ReportExport => self::coversReportExport($scope, $model),
            $model instanceof RegionAccessPolicy => self::coversRegionPolicy($scope, $model),
            default => false, // إعدادات عامة وغيرها: النطاق العام فقط
        };
    }

    /** المنطقة وكل ما تحتها (محافظة ← منطقة ← مدينة). @return list<int> */
    public static function regionSubtree(int $regionId): array
    {
        $all = [$regionId];
        $frontier = [$regionId];

        while ($frontier !== []) {
            $frontier = Region::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $frontier = array_values(array_diff($frontier, $all));
            $all = array_merge($all, $frontier);
        }

        return $all;
    }

    // ───────────── قواعد السجل الواحد ─────────────

    private static function coversUser(AccessScope $scope, User $user): bool
    {
        return match ($scope->type) {
            AccessScope::TYPE_COUNTRY => $user->country_id !== null && (int) $user->country_id === (int) $scope->country_id,
            AccessScope::TYPE_REGION => $user->region_id !== null && in_array((int) $user->region_id, self::regionSubtree((int) $scope->region_id), true),
            AccessScope::TYPE_ORGANIZATION => OrganizationMembership::query()
                ->where('organization_id', $scope->organization_id)
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->exists(),
            default => false,
        };
    }

    private static function coversOrganization(AccessScope $scope, Organization $org): bool
    {
        return self::coversByColumns($scope, $org->country_id, $org->region_id, $org->getKey());
    }

    private static function coversAccountTypeRequest(AccessScope $scope, AccountTypeRequest $request): bool
    {
        $owner = User::withTrashed()->find($request->user_id);

        return match ($scope->type) {
            AccessScope::TYPE_COUNTRY => $owner !== null && (int) $owner->country_id === (int) $scope->country_id,
            AccessScope::TYPE_REGION => array_intersect(
                array_filter([(int) $request->region_id, (int) $owner?->region_id]),
                self::regionSubtree((int) $scope->region_id),
            ) !== [],
            AccessScope::TYPE_ORGANIZATION => $request->organization_id !== null && (int) $request->organization_id === (int) $scope->organization_id,
            default => false,
        };
    }

    private static function coversRegionChange(AccessScope $scope, UserRegionChange $change): bool
    {
        return match ($scope->type) {
            AccessScope::TYPE_COUNTRY => in_array((int) $scope->country_id, [(int) $change->old_country_id, (int) $change->new_country_id], true),
            AccessScope::TYPE_REGION => array_intersect(
                array_filter([(int) $change->old_region_id, (int) $change->new_region_id]),
                self::regionSubtree((int) $scope->region_id),
            ) !== [],
            default => false, // O: غير مسموح
        };
    }

    private static function coversReportExport(AccessScope $scope, ReportExport $export): bool
    {
        $filters = $export->filters ?? [];

        return match ($scope->type) {
            AccessScope::TYPE_COUNTRY => (int) ($filters['country_id'] ?? 0) === (int) $scope->country_id,
            AccessScope::TYPE_REGION => isset($filters['region_id'])
                && in_array((int) $filters['region_id'], self::regionSubtree((int) $scope->region_id), true),
            AccessScope::TYPE_ORGANIZATION => (int) $export->organization_id === (int) $scope->organization_id,
            default => false,
        };
    }

    private static function coversRegionPolicy(AccessScope $scope, RegionAccessPolicy $policy): bool
    {
        if ($policy->country_id === null && $policy->region_id === null) {
            return true; // السياسة العامة الافتراضية مرئية لكل من يملك region_policies.view
        }

        return match ($scope->type) {
            AccessScope::TYPE_COUNTRY => (int) $policy->country_id === (int) $scope->country_id,
            AccessScope::TYPE_REGION => $policy->region_id !== null
                ? in_array((int) $policy->region_id, self::regionSubtree((int) $scope->region_id), true)
                : (int) $policy->country_id === (int) $scope->country_id,
            default => false,
        };
    }

    private static function coversByColumns(AccessScope $scope, mixed $countryId, mixed $regionId, mixed $organizationId): bool
    {
        return match ($scope->type) {
            AccessScope::TYPE_COUNTRY => $countryId !== null && (int) $countryId === (int) $scope->country_id,
            AccessScope::TYPE_REGION => $regionId !== null && in_array((int) $regionId, self::regionSubtree((int) $scope->region_id), true),
            AccessScope::TYPE_ORGANIZATION => $organizationId !== null && (int) $organizationId === (int) $scope->organization_id,
            default => false,
        };
    }

    // ───────────── قواعد الاستعلام (visibleTo) ─────────────

    private static function constrain(Builder $q, AccessScope $scope, Model $model): void
    {
        match (true) {
            $model instanceof User => self::constrainUsers($q, $scope),
            $model instanceof Organization => self::constrainByColumns($q, $scope, 'country_id', 'region_id', $model->getKeyName()),
            $model instanceof AccountTypeRequest => self::constrainAccountTypeRequests($q, $scope),
            $model instanceof OrganizationMembership,
            $model instanceof OrganizationInvitation => $q->whereHas('organization',
                fn (Builder $o) => self::constrainByColumns($o, $scope, 'country_id', 'region_id', 'id')),
            $model instanceof UserSession,
            $model instanceof NotificationDelivery,
            $model instanceof UserLanguage => $q->whereHas('user', fn (Builder $u) => self::constrainUsers($u, $scope)),
            $model instanceof UserRegionChange => self::constrainRegionChanges($q, $scope),
            $model instanceof SuccessStory,
            $model instanceof MetricSnapshot => self::constrainByColumns($q, $scope, 'country_id', 'region_id', 'organization_id'),
            $model instanceof ReportExport => self::constrainByColumns($q, $scope, 'filters->country_id', 'filters->region_id', 'organization_id'),
            default => $q->whereRaw('1 = 0'),
        };
    }

    private static function constrainUsers(Builder $q, AccessScope $scope): void
    {
        match ($scope->type) {
            AccessScope::TYPE_COUNTRY => $q->where($q->qualifyColumn('country_id'), $scope->country_id),
            AccessScope::TYPE_REGION => $q->whereIn($q->qualifyColumn('region_id'), self::regionSubtree((int) $scope->region_id)),
            AccessScope::TYPE_ORGANIZATION => $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('organization_memberships as om')
                ->whereColumn('om.user_id', $q->qualifyColumn('id'))
                ->where('om.organization_id', $scope->organization_id)
                ->where('om.status', 'active')),
            default => $q->whereRaw('1 = 0'),
        };
    }

    private static function constrainAccountTypeRequests(Builder $q, AccessScope $scope): void
    {
        match ($scope->type) {
            AccessScope::TYPE_COUNTRY => $q->whereHas('user', fn (Builder $u) => $u->where('country_id', $scope->country_id)),
            AccessScope::TYPE_REGION => $q->where(function (Builder $w) use ($scope) {
                $tree = self::regionSubtree((int) $scope->region_id);
                $w->whereIn($w->qualifyColumn('region_id'), $tree)
                    ->orWhereHas('user', fn (Builder $u) => $u->whereIn('region_id', $tree));
            }),
            AccessScope::TYPE_ORGANIZATION => $q->where($q->qualifyColumn('organization_id'), $scope->organization_id),
            default => $q->whereRaw('1 = 0'),
        };
    }

    private static function constrainRegionChanges(Builder $q, AccessScope $scope): void
    {
        match ($scope->type) {
            AccessScope::TYPE_COUNTRY => $q->where(fn (Builder $w) => $w->where('old_country_id', $scope->country_id)
                ->orWhere('new_country_id', $scope->country_id)),
            AccessScope::TYPE_REGION => $q->where(function (Builder $w) use ($scope) {
                $tree = self::regionSubtree((int) $scope->region_id);
                $w->whereIn('old_region_id', $tree)->orWhereIn('new_region_id', $tree);
            }),
            default => $q->whereRaw('1 = 0'),
        };
    }

    private static function constrainByColumns(Builder $q, AccessScope $scope, string $country, string $region, string $organization): void
    {
        match ($scope->type) {
            AccessScope::TYPE_COUNTRY => $q->where($q->qualifyColumn($country), $scope->country_id),
            AccessScope::TYPE_REGION => $q->whereIn($q->qualifyColumn($region), self::regionSubtree((int) $scope->region_id)),
            AccessScope::TYPE_ORGANIZATION => $q->where($q->qualifyColumn($organization), $scope->organization_id),
            default => $q->whereRaw('1 = 0'),
        };
    }

    // ───────────── مساعدات ─────────────

    /** حارس MFA (D8) على القرار النهائي بالسماح — يُطبَّق على الـ Policies كما على Gate::before. */
    private static function mfa(User $user, string $permission): Response
    {
        return app(MfaGuard::class)->allows($user, $permission)
            ? Response::allow()
            : Response::deny('هذه العملية تتطلب التحقق بخطوتين.');
    }

    private static function access(User $user): EffectiveAccess
    {
        return app(AccessResolver::class)->for($user);
    }

    private static function defaultPermission(Model $model): string
    {
        return self::DEFAULT_PERMISSION[$model::class]
            ?? throw new \InvalidArgumentException('ScopeGuard: مرّر اسم الصلاحية صراحةً للمودل '.$model::class);
    }
}
