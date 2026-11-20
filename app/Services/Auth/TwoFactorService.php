<?php

namespace App\Services\Auth;

use App\Models\AuthEvent;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\SessionService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * التحقق الثنائي TOTP — K1 · NFR-SEC-002 · القرار D8.
 *
 * كل منطق 2FA هنا، ويستخدمه K2 (تحدّي الدخول و step-up) عبر verify() و useRecoveryCode().
 *
 * قواعد ملزمة:
 *  - الـ secret يُخزَّن مشفّرًا (cast encrypted في User) ولا يظهر في أي استجابة بعد التأكيد ولا في السجلات.
 *  - التحقق بنافذة ±1 خطوة (30 ثانية)، ولا يُقبل نفس الرمز مرتين: آخر timestep مستخدم يُحفظ في الـ cache.
 *  - رموز الاسترداد تُخزَّن hash (sha256) داخل الحقل المشفّر، تُقارن بـ hash_equals، وكل رمز يُستخدم مرة واحدة.
 *  - الوقت يُقرأ من now() (Carbon) حتى تُثبَّت الاختبارات دون الاعتماد على الساعة.
 */
class TwoFactorService
{
    private const STEP_SECONDS = 30;

    private const NOTIFICATION_TYPE = 'security.two_factor_changed';

    public function __construct(
        private readonly Google2FA $engine,
        private readonly SessionService $sessions,
        private readonly NotificationService $notifications,
        private readonly StepUpService $stepUp,
    ) {}

    // ───────────── الحالة ─────────────

    public function isEnabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null;
    }

    public function isPending(User $user): bool
    {
        return $user->two_factor_secret !== null && ! $this->isEnabled($user);
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return $this->isEnabled($user) ? count($user->two_factor_recovery_codes ?? []) : 0;
    }

    // ───────────── دورة الحياة (يستدعيها الـ Controller) ─────────────

    /**
     * بدء التفعيل: secret جديد غير مؤكَّد (يستبدل أي إعداد معلّق سابق).
     *
     * @return array{secret: string, otpauth_url: string, qr_svg: string}
     */
    public function start(User $user): array
    {
        if ($this->isEnabled($user)) {
            throw new ConflictHttpException('التحقق الثنائي مفعّل بالفعل. عطّله أولًا لإعادة الإعداد.');
        }

        $secret = $this->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        Cache::forget($this->lastStepKey($user));

        $url = $this->otpauthUrl($user, $secret);

        return ['secret' => $secret, 'otpauth_url' => $url, 'qr_svg' => $this->qrSvg($url)];
    }

    /**
     * تأكيد التفعيل برمز من التطبيق → two_factor_confirmed_at + رموز استرداد تُعرض مرة واحدة.
     *
     * @return list<string> رموز الاسترداد بنصّها الصريح (لا تُحفظ إلا hash)
     */
    public function confirm(User $user, string $code): array
    {
        if ($this->isEnabled($user)) {
            throw new ConflictHttpException('التحقق الثنائي مفعّل بالفعل.');
        }

        if (! $this->isPending($user)) {
            throw new ConflictHttpException('ابدأ التفعيل أولًا عبر POST /me/2fa/enable.');
        }

        $this->ensureTotp($user, $code);

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map($this->hashRecoveryCode(...), $codes),
        ])->save();

        $this->recordEvent($user, 'mfa_enabled');
        $this->logActivity($user, 'two_factor_enabled', 'Two-factor authentication enabled');
        $this->revokeOtherSessions($user);
        $this->notify($user, 'enabled');

        return $codes;
    }

    /**
     * إعادة توليد رموز الاسترداد؛ القديمة تبطل فورًا.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        $this->ensureEnabled($user);
        $this->ensureCode($user, $code);

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => array_map($this->hashRecoveryCode(...), $codes),
        ])->save();

        $this->recordEvent($user, 'mfa_recovery_codes_regenerated');
        $this->logActivity($user, 'two_factor_recovery_codes_regenerated', 'Two-factor recovery codes regenerated');

        return $codes;
    }

    /** تعطيل 2FA ومسح الحقول الثلاثة. يقبل رمز التطبيق أو رمز استرداد. */
    public function disable(User $user, string $code): void
    {
        $this->ensureEnabled($user);
        $this->ensureCode($user, $code);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        Cache::forget($this->lastStepKey($user));

        $this->recordEvent($user, 'mfa_disabled');
        $this->logActivity($user, 'two_factor_disabled', 'Two-factor authentication disabled');
        $this->revokeOtherSessions($user);
        $this->notify($user, 'disabled');
    }

    /**
     * K2 — التحقق الإضافي (step-up): رمز التطبيق أو رمز استرداد يفتح العمليات الحساسة
     * لهذه الجلسة مدة solaris.mfa.step_up_minutes (15 دقيقة).
     */
    public function stepUp(User $user, string $code): ?CarbonImmutable
    {
        $this->ensureEnabled($user);
        $this->ensureCode($user, $code);

        $until = $this->stepUp->mark($user);
        $this->recordEvent($user, 'mfa_step_up_verified');

        return $until;
    }

    /** رمز التطبيق أو رمز استرداد (دون رمي استثناء) — يستخدمه تحدّي الدخول. يعيد 'totp' | 'recovery' | null. */
    public function checkCode(User $user, ?string $code, ?string $recoveryCode): ?string
    {
        if ($code !== null && $code !== '' && $this->verify($user, $code)) {
            return 'totp';
        }

        if ($recoveryCode !== null && $recoveryCode !== '' && $this->useRecoveryCode($user, $recoveryCode)) {
            return 'recovery';
        }

        return null;
    }

    // ───────────── الأدوات الأساسية (يستخدمها K2 أيضًا) ─────────────

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    /**
     * يتحقق من رمز TOTP بنافذة ±window ويمنع إعادة استخدام نفس الرمز
     * (أي timestep ≤ آخر timestep مقبول يُرفض).
     */
    public function verify(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;
        $code = trim($code);

        if (! $secret || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $current = $this->currentTimestep();
        $window = (int) config('solaris.two_factor.window', 1);
        $lastUsed = Cache::get($this->lastStepKey($user));

        for ($step = $current - $window; $step <= $current + $window; $step++) {
            if ($lastUsed !== null && $step <= (int) $lastUsed) {
                continue;
            }

            if (hash_equals($this->engine->oathTotp($secret, $step), $code)) {
                // يكفي الاحتفاظ به طوال مدة صلاحية النافذة.
                Cache::put($this->lastStepKey($user), $step, now()->addSeconds(self::STEP_SECONDS * (2 * $window + 2)));

                return true;
            }
        }

        return false;
    }

    /** @return list<string> رموز استرداد بصيغة xxxxx-xxxxx (hex) */
    public function generateRecoveryCodes(): array
    {
        $count = (int) config('solaris.two_factor.recovery_codes', 8);

        return array_map(function () {
            $raw = bin2hex(random_bytes(5));

            return substr($raw, 0, 5).'-'.substr($raw, 5);
        }, range(1, $count));
    }

    /** يستهلك رمز استرداد صالحًا (مرة واحدة). القفل يمنع استخدام نفس الرمز في طلبين متزامنين. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        if (! $this->isEnabled($user) || trim($code) === '') {
            return false;
        }

        $hash = $this->hashRecoveryCode($code);

        $remaining = DB::transaction(function () use ($user, $hash) {
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            $codes = $fresh?->two_factor_recovery_codes ?? [];

            foreach ($codes as $index => $stored) {
                if (hash_equals((string) $stored, $hash)) {
                    unset($codes[$index]);
                    $codes = array_values($codes);
                    $fresh->forceFill(['two_factor_recovery_codes' => $codes])->save();

                    return $codes;
                }
            }

            return null;
        });

        if ($remaining === null) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => $remaining])->syncOriginal();
        $this->recordEvent($user, 'mfa_recovery_code_used', ['remaining' => count($remaining)]);

        return true;
    }

    public function currentTimestep(): int
    {
        return intdiv(now()->getTimestamp(), self::STEP_SECONDS);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            (string) config('solaris.two_factor.issuer'),
            (string) ($user->email ?? $user->phone ?? $user->uuid),
            $secret,
        );
    }

    public function qrSvg(string $otpauthUrl): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))
            ->writeString($otpauthUrl);

        // إزالة تصريح XML ليُضمَّن الـ SVG مباشرة في الواجهة.
        return trim(preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
    }

    // ───────────── داخلي ─────────────

    private function ensureEnabled(User $user): void
    {
        if (! $this->isEnabled($user)) {
            throw new ConflictHttpException('التحقق الثنائي غير مفعّل.');
        }
    }

    /** رمز التطبيق فقط (التأكيد). */
    private function ensureTotp(User $user, string $code): void
    {
        if (! $this->verify($user, $code)) {
            $this->failed($user);
        }
    }

    /** رمز التطبيق أو رمز استرداد (التعطيل وإعادة التوليد — مفيد عند فقدان الجهاز). */
    private function ensureCode(User $user, string $code): void
    {
        if (! $this->verify($user, $code) && ! $this->useRecoveryCode($user, $code)) {
            $this->failed($user);
        }
    }

    private function failed(User $user): never
    {
        $this->recordEvent($user, 'mfa_failed');

        throw ValidationException::withMessages([
            'code' => 'رمز التحقق غير صحيح أو مستخدم مسبقًا.',
        ]);
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtolower(str_replace(['-', ' '], '', trim($code))));
    }

    private function lastStepKey(User $user): string
    {
        return "2fa:last_step:{$user->getKey()}";
    }

    /** إنهاء باقي الجلسات عدا الحالية (السبب security). */
    private function revokeOtherSessions(User $user): void
    {
        $this->sessions->revokeAllExcept($user, $this->sessions->current($user), $user, 'security');
    }

    /** سجل auth_events (بدون أي secret أو رمز). $sessionId يُمرَّر عند إنشاء جلسة جديدة (تحدّي الدخول). */
    public function recordEvent(User $user, string $event, array $metadata = [], int|string|null $sessionId = null): void
    {
        $request = request();

        AuthEvent::query()->create([
            'user_id' => $user->getKey(),
            'session_id' => $sessionId ?? $this->sessions->current($user)?->getKey(),
            'event' => $event,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata ?: null,
        ]);
    }

    private function logActivity(User $user, string $event, string $description): void
    {
        $request = request();
        $sessionId = $this->sessions->current($user)?->getKey();

        activity('security')
            ->causedBy($user)
            ->performedOn($user)
            ->tap(function ($activity) use ($request, $sessionId) {
                $activity->sensitivity = 'sensitive';
                $activity->ip_address = $request?->ip();
                $activity->user_agent = $request?->userAgent();
                $activity->session_id = $sessionId;
            })
            ->event($event)
            ->log($description);
    }

    /** إشعار أمني — فشله يُسجَّل ولا يُفشل العملية (النوع يضيفه N5). */
    private function notify(User $user, string $action): void
    {
        try {
            $this->notifications->send($user, self::NOTIFICATION_TYPE, ['action' => $action]);
        } catch (Throwable $e) {
            Log::warning('Two-factor notification was not sent', [
                'user_id' => $user->getKey(),
                'type' => self::NOTIFICATION_TYPE,
                'error' => $e::class,
            ]);
        }
    }
}
