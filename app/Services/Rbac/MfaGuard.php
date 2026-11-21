<?php

namespace App\Services\Rbac;

use App\Models\User;
use App\Services\Auth\StepUpService;
use Database\Seeders\Rbac\RbacCatalog;
use Illuminate\Support\Facades\DB;

/**
 * حارس MFA للصلاحيات requires_mfa (NFR-SEC-002) — القرار D8 · K2.
 *
 * يُستدعى تلقائيًا من Gate::before (AppServiceProvider) لكل فحص صلاحية، لذلك يغطي
 * $user->can('roles.assign') و Gate::authorize و Policies دون تعديل أي Controller.
 * الـ middleware mfa.confirmed يستخدم نفس القرار (satisfied / enforcedFor / reason).
 *
 *   غير مفروض (enforce = false): يسمح ويسجّل تحذيرًا في activity_log (log_name = security).
 *   مفروض: يرفض (403) ما لم تكن للجلسة الحالية تحقق 2FA حديث (step-up خلال 15 دقيقة).
 *
 * التبديل: SOLARIS_MFA_ENFORCE=true (أو SOLARIS_MFA_MODE=enforce القديم) و
 * SOLARIS_MFA_REQUIRED_FOR_ADMINS=true في .env — بلا تغيير كود.
 */
class MfaGuard
{
    public const MODE_WARN = 'warn';

    public const MODE_ENFORCE = 'enforce';

    public const REASON_REQUIRED = 'mfa_required';

    public const REASON_SETUP_REQUIRED = 'mfa_setup_required';

    /** @var array<int, bool> */
    private array $adminCache = [];

    public function __construct(
        private readonly AccessResolver $resolver,
        private readonly StepUpService $stepUp,
    ) {}

    /** هل يُسمح بالمتابعة لهذه الصلاحية من ناحية MFA؟ (الصلاحيات التي لا تتطلب MFA تمر دائمًا) */
    public function allows(User $user, string $permission): bool
    {
        if (! RbacCatalog::requiresMfa($permission) || $this->satisfied($user)) {
            return true;
        }

        $enforce = $this->enforcedFor($user);

        if ($this->resolver->firstTime("mfa:{$user->getKey()}:{$permission}")) {
            activity('security')
                ->causedBy($user)
                ->tap(function ($activity) use ($permission) {
                    $activity->sensitivity = RbacCatalog::sensitivity($permission) === 'restricted' ? 'restricted' : 'sensitive';
                    $activity->ip_address = request()?->ip();
                    $activity->user_agent = request()?->userAgent();
                })
                ->withProperties([
                    'permission' => $permission,
                    'mode' => $this->mode(),
                    'decision' => $enforce ? 'denied' : 'allowed_with_warning',
                    'reason' => $this->reason($user),
                    'decision_ref' => 'D8',
                ])
                ->event('mfa_required')
                ->log($enforce
                    ? 'MFA required: permission denied until a fresh MFA verification'
                    : 'MFA not enforced yet (D8): permission allowed with warning');
        }

        return ! $enforce;
    }

    /** للجلسة الحالية تحقق 2FA حديث (يتطلب 2FA مفعّلًا + step-up خلال آخر 15 دقيقة). */
    public function satisfied(User $user): bool
    {
        return $this->enabled($user) && $this->stepUp->isFresh($user);
    }

    public function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null;
    }

    /** سبب الرفض المعاد للواجهة: إعداد 2FA أولًا، أو إدخال رمز حديث. */
    public function reason(User $user): string
    {
        return $this->enabled($user) ? self::REASON_REQUIRED : self::REASON_SETUP_REQUIRED;
    }

    /** هل الرفض مفعّل لهذا المستخدم؟ (enforce عام، أو required_for_admins وله دور إداري) */
    public function enforcedFor(User $user): bool
    {
        if ($this->mode() === self::MODE_ENFORCE) {
            return true;
        }

        return (bool) config('solaris.mfa.required_for_admins') && $this->isAdministrator($user);
    }

    public function mode(): string
    {
        return config('solaris.mfa.enforce') === true || config('solaris.mfa.mode') === self::MODE_ENFORCE
            ? self::MODE_ENFORCE
            : self::MODE_WARN;
    }

    /** له دور إداري (roles.is_administrative) غير منتهٍ في أي نطاق. */
    public function isAdministrator(User $user): bool
    {
        $tables = config('permission.table_names');
        $morphKey = config('permission.column_names.model_morph_key');

        return $this->adminCache[$user->getKey()] ??= DB::table($tables['model_has_roles'].' as mhr')
            ->join($tables['roles'].' as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', $user->getMorphClass())
            ->where("mhr.{$morphKey}", $user->getKey())
            ->where('r.is_administrative', true)
            ->where(fn ($q) => $q->whereNull('mhr.expires_at')->orWhere('mhr.expires_at', '>', now()))
            ->exists();
    }
}
