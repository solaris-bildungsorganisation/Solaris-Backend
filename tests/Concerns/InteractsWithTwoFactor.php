<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use PragmaRX\Google2FA\Google2FA;

/**
 * أدوات اختبارات K1/K2: رموز TOTP محسوبة من وقت مثبَّت (travelTo) — لا اعتماد على الساعة.
 */
trait InteractsWithTwoFactor
{
    protected const PASSWORD = 'password';

    /** رمز TOTP للخطوة الحالية (+ إزاحة بالخطوات). */
    protected function totp(string $secret, int $offsetSteps = 0): string
    {
        return app(Google2FA::class)->oathTotp($secret, intdiv(now()->getTimestamp(), 30) + $offsetSteps);
    }

    /** يفعّل 2FA مباشرة عبر الخدمة (تجهيز) ويعيد [secret, recovery codes]. */
    protected function enableTwoFactor(User $user): array
    {
        $service = app(TwoFactorService::class);
        $secret = $service->start($user)['secret'];
        $codes = $service->confirm($user->refresh(), $this->totp($secret));

        // الرمز المستخدم في التأكيد محجوز؛ ننتقل للخطوة التالية حتى تكون رموز الاختبار جديدة.
        $this->travel(30)->seconds();

        return [$secret, $codes];
    }
}
