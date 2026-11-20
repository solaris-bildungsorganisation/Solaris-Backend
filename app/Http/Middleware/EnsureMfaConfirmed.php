<?php

namespace App\Http\Middleware;

use App\Services\Rbac\MfaGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * mfa.confirmed — K2 (يحلّ محل النسخة المؤقتة) · NFR-SEC (MFA) · القرار D8.
 *
 * يقبل الطلب إن كان للجلسة الحالية تحقق 2FA خلال آخر 15 دقيقة (POST /me/2fa/verify).
 * وإلا:
 *   - مفروض (solaris.mfa.enforce أو required_for_admins لمسؤول): 403 بالرمز
 *     mfa_required (2FA مفعّل: أدخل رمزًا حديثًا) أو mfa_setup_required (فعّل 2FA أولًا).
 *   - غير مفروض: يسمح ويسجّل تحذيرًا فقط (كما في الموجة 1).
 */
class EnsureMfaConfirmed
{
    public function __construct(private readonly MfaGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $this->guard->satisfied($user)) {
            return $next($request);
        }

        $reason = $this->guard->reason($user);

        if (! $this->guard->enforcedFor($user)) {
            Log::warning('MFA step-up missing; allowed because MFA is not enforced (D8)', [
                'user_id' => $user->getKey(),
                'path' => $request->path(),
                'reason' => $reason,
            ]);

            return $next($request);
        }

        return response()->json([
            'message' => $reason === MfaGuard::REASON_SETUP_REQUIRED
                ? 'هذه العملية تتطلب تفعيل التحقق الثنائي أولًا.'
                : 'هذه العملية تتطلب إدخال رمز التحقق الثنائي (POST /api/v1/me/2fa/verify).',
            'code' => $reason,
        ], 403);
    }
}
