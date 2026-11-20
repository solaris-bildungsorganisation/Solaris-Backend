<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserSession;
use App\Services\SessionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * التحقق الإضافي (step-up) — K2 · NFR-SEC (MFA) · القرار D8.
 *
 * بعد إدخال رمز 2FA صحيح (POST /me/2fa/verify أو نجاح تحدّي الدخول) تُعلَّم الجلسة الحالية
 * بأنها "متحقَّق منها" لمدة solaris.mfa.step_up_minutes (15 دقيقة). يُحفظ في الـ cache بمعرّف الجلسة،
 * فلا ينتقل التحقق لجلسة/جهاز آخر، وإنهاء الجلسة يُسقطه عمليًا (التوكن يُحذف).
 */
class StepUpService
{
    public function __construct(private readonly SessionService $sessions) {}

    /** يعلّم جلسة المستخدم الحالية كمتحقَّق منها؛ يعيد وقت انتهاء الصلاحية (null إن لم توجد جلسة). */
    public function mark(User $user): ?CarbonImmutable
    {
        $session = $this->sessions->current($user);

        return $session ? $this->markSession($session) : null;
    }

    public function markSession(UserSession $session): CarbonImmutable
    {
        $until = now()->toImmutable()->addMinutes($this->minutes());

        Cache::put($this->key($session), $until->getTimestamp(), $until);

        return $until;
    }

    /** وقت انتهاء التحقق الحديث للجلسة الحالية، أو null إن لم يوجد/انتهى. */
    public function verifiedUntil(User $user): ?CarbonImmutable
    {
        $session = $this->sessions->current($user);

        if (! $session) {
            return null;
        }

        $timestamp = Cache::get($this->key($session));

        if (! $timestamp || $timestamp <= now()->getTimestamp()) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($timestamp);
    }

    public function isFresh(User $user): bool
    {
        return $this->verifiedUntil($user) !== null;
    }

    public function forget(User $user): void
    {
        if ($session = $this->sessions->current($user)) {
            Cache::forget($this->key($session));
        }
    }

    public function minutes(): int
    {
        return (int) config('solaris.mfa.step_up_minutes', 15);
    }

    private function key(UserSession $session): string
    {
        return "2fa:step_up:session:{$session->getKey()}";
    }
}
