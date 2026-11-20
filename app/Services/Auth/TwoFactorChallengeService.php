<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserSession;
use App\Services\SessionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * تحدّي 2FA عند الدخول — K2 · NFR-SEC (MFA) · FR-AUTH-009, 025, 028 · القرار D8.
 *
 *  - POST /auth/login لمستخدم 2FA مفعّل لا يعطي توكنًا، بل challenge_token عشوائي.
 *  - challenge_token: يُخزَّن hash (sha256) فقط في الـ cache، صالح 5 دقائق، يُستخدم مرة واحدة،
 *    ويبطل بعد 5 رموز خاطئة. الرموز الخاطئة تُحسب ضمن محاولات قفل الحساب (failed_login_count).
 *  - التوكن والجلسة يُنشآن بعد نجاح التحدّي فقط، عبر SessionService::start() نفسها التي يستخدمها الدخول.
 *  - نجاح التحدّي أو فشله أو استخدام رمز استرداد يُسجَّل في auth_events.
 */
class TwoFactorChallengeService
{
    public const LOCK_THRESHOLD = 5;

    public const LOCK_MINUTES = 15;

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly SessionService $sessions,
        private readonly StepUpService $stepUp,
    ) {}

    /** يصدر challenge_token جديدًا (النص الصريح يُعاد مرة واحدة ولا يُخزَّن). */
    public function issue(User $user, Request $request): string
    {
        $token = Str::random(64);
        $expiresAt = now()->addMinutes($this->ttlMinutes());

        Cache::put($this->key($token), [
            'user_id' => $user->getKey(),
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
            'device_name' => $request->input('device_name'),
            'platform' => $request->input('platform'),
        ], $expiresAt);

        return $token;
    }

    /**
     * يتحقق من التحدّي وينشئ التوكن والجلسة.
     *
     * @return array{user: User, token: string, session: UserSession, method: string}
     *
     * @throws TwoFactorChallengeException 401 (تحدٍّ غير صالح/منتهٍ/خاطئ) · 403 (حساب غير نشط) · 423 (حساب مقفل)
     */
    public function complete(string $challengeToken, ?string $code, ?string $recoveryCode, Request $request): array
    {
        $key = $this->key($challengeToken);
        $payload = Cache::get($key);
        $user = $payload ? User::query()->find($payload['user_id']) : null;

        if (! $payload || ! $user) {
            $this->abort(401, 'challenge_invalid', 'رمز التحدّي غير صالح أو منتهي الصلاحية. سجّل الدخول من جديد.');
        }

        if ($user->locked_until?->isFuture()) {
            Cache::forget($key);
            $this->abort(423, 'account_locked', 'الحساب مقفل مؤقتاً بسبب محاولات دخول فاشلة.');
        }

        if ($user->status !== 'active' || ! $this->twoFactor->isEnabled($user)) {
            Cache::forget($key);
            $this->abort(403, 'account_unavailable', 'الحساب غير مفعل أو غير متاح لتسجيل الدخول.');
        }

        $method = $this->twoFactor->checkCode($user, $code, $recoveryCode);

        if ($method === null) {
            $this->failed($user, $key, $payload);
        }

        // استخدام واحد: إزالة التحدّي قبل إصدار التوكن (pull ذري قدر الإمكان).
        if (Cache::pull($key) === null) {
            $this->abort(401, 'challenge_invalid', 'رمز التحدّي غير صالح أو منتهي الصلاحية. سجّل الدخول من جديد.');
        }

        // الجهاز/المنصة من طلب الدخول الأصلي ما لم يُرسَلا مع التحدّي.
        $request->merge([
            'device_name' => $request->input('device_name', $payload['device_name']),
            'platform' => $request->input('platform', $payload['platform']),
        ]);

        [$plain, $session] = DB::transaction(function () use ($user, $request) {
            $user->forceFill([
                'failed_login_count' => 0,
                'locked_until' => null,
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ])->save();

            $newToken = $user->createToken('api');
            $session = $this->sessions->start($user, $newToken, $request);

            return [$newToken->plainTextToken, $session];
        });

        // الرمز أُدخل للتوّ → الجلسة الجديدة تُعدّ متحقَّقًا منها (step-up) لمدة 15 دقيقة.
        $this->stepUp->markSession($session);

        $this->twoFactor->recordEvent($user, 'mfa_challenge_passed', ['method' => $method], $session->getKey());

        return ['user' => $user, 'token' => $plain, 'session' => $session, 'method' => $method];
    }

    public function ttlMinutes(): int
    {
        return (int) config('solaris.two_factor.challenge_ttl_minutes', 5);
    }

    private function failed(User $user, string $key, array $payload): never
    {
        $attempts = $payload['attempts'] + 1;
        $max = (int) config('solaris.two_factor.challenge_max_attempts', 5);

        // الرموز الخاطئة ضمن محاولات قفل الحساب (نفس قاعدة /auth/login).
        $user->failed_login_count = (int) $user->failed_login_count + 1;
        if ($user->failed_login_count >= self::LOCK_THRESHOLD) {
            $user->locked_until = now()->addMinutes(self::LOCK_MINUTES);
        }
        $user->save();

        $exhausted = $attempts >= $max || $user->locked_until?->isFuture();

        if ($exhausted) {
            Cache::forget($key);
        } else {
            // نحتفظ بوقت الانتهاء الأصلي (لا تمديد لصلاحية التحدّي مع كل محاولة).
            Cache::put($key, ['attempts' => $attempts] + $payload, Carbon::createFromTimestamp($payload['expires_at']));
        }

        $this->twoFactor->recordEvent($user, 'mfa_challenge_failed', [
            'attempts' => $attempts,
            'challenge_revoked' => $exhausted,
        ]);

        $this->abort(401, $exhausted ? 'challenge_invalid' : 'invalid_code', $exhausted
            ? 'رمز التحقق غير صحيح. أُبطل التحدّي — سجّل الدخول من جديد.'
            : 'رمز التحقق غير صحيح أو مستخدم مسبقًا.');
    }

    private function key(string $token): string
    {
        return '2fa:challenge:'.hash('sha256', $token);
    }

    private function abort(int $status, string $code, string $message): never
    {
        throw new TwoFactorChallengeException($status, $code, $message);
    }
}
