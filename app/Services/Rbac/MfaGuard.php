<?php

namespace App\Services\Rbac;

use App\Models\User;
use Database\Seeders\Rbac\RbacCatalog;

/**
 * حارس MFA للصلاحيات requires_mfa (NFR-SEC-002) — القرار D8.
 *
 * يُستدعى تلقائيًا من Gate::before (AppServiceProvider) لكل فحص صلاحية، لذلك يغطي
 * $user->can('roles.assign') و Gate::authorize و Policies دون تعديل أي Controller.
 *
 *   الوضع warn    (الموجة 1، حتى تنفيذ FA2): يسمح ويسجّل تحذيرًا في activity_log (log_name = security).
 *   الوضع enforce (بعد FA2): يرفض (403) ما لم يكن لدى المستخدم MFA مؤكَّد.
 *
 * التبديل: SOLARIS_MFA_MODE=enforce في .env — بلا تغيير كود.
 */
class MfaGuard
{
    public const MODE_WARN = 'warn';

    public const MODE_ENFORCE = 'enforce';

    public function __construct(private readonly AccessResolver $resolver) {}

    /** هل يُسمح بالمتابعة لهذه الصلاحية من ناحية MFA؟ (الصلاحيات التي لا تتطلب MFA تمر دائمًا) */
    public function allows(User $user, string $permission): bool
    {
        if (! RbacCatalog::requiresMfa($permission) || $this->satisfied($user)) {
            return true;
        }

        $enforce = $this->mode() === self::MODE_ENFORCE;

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
                    'decision_ref' => 'D8',
                ])
                ->event('mfa_required')
                ->log($enforce
                    ? 'MFA required: permission denied until MFA is confirmed'
                    : 'MFA not enforced yet (D8): permission allowed with warning');
        }

        return ! $enforce;
    }

    /**
     * MFA مؤكَّد للمستخدم. حتى تنفيذ FA2 لا يوجد تحقق MFA على مستوى الجلسة،
     * فنعتمد two_factor_confirmed_at؛ FA2 يستبدل هذا بفحص الجلسة.
     */
    public function satisfied(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null;
    }

    public function mode(): string
    {
        return config('solaris.mfa.mode') === self::MODE_ENFORCE ? self::MODE_ENFORCE : self::MODE_WARN;
    }
}
