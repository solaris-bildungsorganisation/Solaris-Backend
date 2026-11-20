<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Requests\Me\TwoFactor\ConfirmTwoFactorRequest;
use App\Http\Requests\Me\TwoFactor\EnableTwoFactorRequest;
use App\Http\Requests\Me\TwoFactor\TwoFactorSensitiveActionRequest;
use App\Http\Requests\Me\TwoFactor\VerifyTwoFactorRequest;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

/**
 * K1 — التحقق الثنائي TOTP: التفعيل والإدارة (NFR-SEC-002 · القرار D8).
 * المنطق كله في TwoFactorService؛ الـ Controller يتحقق من الصلاحية ويشكّل الاستجابة.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    #[OA\Get(
        path: '/v1/me/2fa',
        tags: ['Two-Factor Auth'],
        summary: 'حالة التحقق الثنائي لحسابي',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'نجاح',
                content: new OA\JsonContent(example: ['data' => [
                    'enabled' => true,
                    'pending' => false,
                    'confirmed_at' => '2026-11-20T10:00:00+00:00',
                    'recovery_codes_remaining' => 8,
                ]]),
            ),
            new OA\Response(response: 401, description: 'التوكن مفقود أو غير صالح'),
            new OA\Response(response: 403, description: 'لا صلاحية'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewTwoFactor', User::class);

        $user = $request->user();

        return response()->json(['data' => [
            'enabled' => $this->twoFactor->isEnabled($user),
            'pending' => $this->twoFactor->isPending($user),
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'recovery_codes_remaining' => $this->twoFactor->remainingRecoveryCodes($user),
        ]]);
    }

    #[OA\Post(
        path: '/v1/me/2fa/enable',
        tags: ['Two-Factor Auth'],
        summary: 'بدء التفعيل: يولّد secret ويعيد رمز QR (SVG) ورابط otpauth — لا يُفعَّل قبل التأكيد',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['current_password'],
            properties: [new OA\Property(property: 'current_password', type: 'string', format: 'password')],
        )),
        responses: [
            new OA\Response(
                response: 200,
                description: 'الإعداد معلّق حتى التأكيد. الـ secret يظهر هنا فقط (للإدخال اليدوي) ولا يظهر بعد التأكيد.',
                content: new OA\JsonContent(example: ['data' => [
                    'secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
                    'otpauth_url' => 'otpauth://totp/Solaris:user%40example.com?secret=...&issuer=Solaris&algorithm=SHA1&digits=6&period=30',
                    'qr_svg' => '<svg ...>...</svg>',
                ]]),
            ),
            new OA\Response(response: 409, description: 'التحقق الثنائي مفعّل بالفعل'),
            new OA\Response(response: 422, description: 'كلمة المرور الحالية غير صحيحة'),
        ],
    )]
    public function enable(EnableTwoFactorRequest $request): JsonResponse
    {
        Gate::authorize('manageTwoFactor', User::class);

        return response()->json(['data' => $this->twoFactor->start($request->user())]);
    }

    #[OA\Post(
        path: '/v1/me/2fa/confirm',
        tags: ['Two-Factor Auth'],
        summary: 'تأكيد التفعيل برمز من التطبيق → 8 رموز استرداد تُعرض مرة واحدة',
        description: 'ينهي باقي جلسات المستخدم عدا الحالية ويرسل إشعارًا أمنيًا. محدود بـ 5 محاولات في الدقيقة.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['code'],
            properties: [new OA\Property(property: 'code', type: 'string', example: '123456')],
        )),
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم التفعيل. احفظ رموز الاسترداد — لن تظهر مرة أخرى.',
                content: new OA\JsonContent(example: ['data' => [
                    'enabled' => true,
                    'recovery_codes' => ['a1b2c-3d4e5', 'f6a7b-8c9d0'],
                ]]),
            ),
            new OA\Response(response: 409, description: 'لا يوجد إعداد معلّق، أو التحقق مفعّل بالفعل'),
            new OA\Response(response: 422, description: 'رمز غير صحيح أو مستخدم مسبقًا'),
            new OA\Response(response: 429, description: 'محاولات كثيرة'),
        ],
    )]
    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        Gate::authorize('manageTwoFactor', User::class);

        $codes = $this->twoFactor->confirm($request->user(), $request->validated('code'));

        return response()->json(['data' => ['enabled' => true, 'recovery_codes' => $codes]]);
    }

    #[OA\Post(
        path: '/v1/me/2fa/recovery-codes',
        tags: ['Two-Factor Auth'],
        summary: 'إعادة توليد رموز الاسترداد — القديمة تبطل فورًا',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['current_password', 'code'],
            properties: [
                new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                new OA\Property(property: 'code', type: 'string', description: 'رمز التطبيق أو رمز استرداد', example: '123456'),
            ],
        )),
        responses: [
            new OA\Response(
                response: 200,
                description: 'رموز جديدة تُعرض مرة واحدة',
                content: new OA\JsonContent(example: ['data' => ['recovery_codes' => ['a1b2c-3d4e5', 'f6a7b-8c9d0']]]),
            ),
            new OA\Response(response: 409, description: 'التحقق الثنائي غير مفعّل'),
            new OA\Response(response: 422, description: 'كلمة المرور أو الرمز غير صحيح'),
            new OA\Response(response: 429, description: 'محاولات كثيرة'),
        ],
    )]
    public function regenerateRecoveryCodes(TwoFactorSensitiveActionRequest $request): JsonResponse
    {
        Gate::authorize('manageTwoFactor', User::class);

        $codes = $this->twoFactor->regenerateRecoveryCodes($request->user(), $request->validated('code'));

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    #[OA\Post(
        path: '/v1/me/2fa/verify',
        tags: ['Two-Factor Auth'],
        summary: 'تحقق إضافي (step-up): رمز حديث يفتح العمليات الحساسة (MFA) لهذه الجلسة 15 دقيقة',
        description: 'استدعه عندما يعيد أي مسار 403 بالرمز mfa_required. رمز الاسترداد مقبول ويُستهلك. '
            .'إن أعاد المسار mfa_setup_required فعلى المستخدم تفعيل 2FA أولًا. محدود بـ 5 محاولات في الدقيقة.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['code'],
            properties: [new OA\Property(property: 'code', type: 'string', description: 'رمز التطبيق أو رمز استرداد', example: '123456')],
        )),
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم التحقق',
                content: new OA\JsonContent(example: ['data' => [
                    'verified' => true,
                    'verified_until' => '2026-11-20T10:15:00+00:00',
                    'expires_in' => 900,
                ]]),
            ),
            new OA\Response(response: 401, description: 'التوكن مفقود أو غير صالح'),
            new OA\Response(response: 403, description: 'لا صلاحية'),
            new OA\Response(response: 409, description: 'التحقق الثنائي غير مفعّل'),
            new OA\Response(response: 422, description: 'رمز غير صحيح أو مستخدم مسبقًا'),
            new OA\Response(response: 429, description: 'محاولات كثيرة'),
        ],
    )]
    public function verify(VerifyTwoFactorRequest $request): JsonResponse
    {
        Gate::authorize('verifyTwoFactor', User::class);

        $until = $this->twoFactor->stepUp($request->user(), $request->validated('code'));

        return response()->json(['data' => [
            'verified' => true,
            'verified_until' => $until?->toIso8601String(),
            'expires_in' => $until ? max(0, $until->getTimestamp() - now()->getTimestamp()) : 0,
        ]]);
    }

    #[OA\Delete(
        path: '/v1/me/2fa',
        tags: ['Two-Factor Auth'],
        summary: 'تعطيل التحقق الثنائي ومسح الـ secret ورموز الاسترداد',
        description: 'ينهي باقي جلسات المستخدم عدا الحالية ويرسل إشعارًا أمنيًا.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['current_password', 'code'],
            properties: [
                new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                new OA\Property(property: 'code', type: 'string', description: 'رمز التطبيق أو رمز استرداد', example: '123456'),
            ],
        )),
        responses: [
            new OA\Response(response: 204, description: 'تم التعطيل'),
            new OA\Response(response: 409, description: 'التحقق الثنائي غير مفعّل'),
            new OA\Response(response: 422, description: 'كلمة المرور أو الرمز غير صحيح'),
            new OA\Response(response: 429, description: 'محاولات كثيرة'),
        ],
    )]
    public function destroy(TwoFactorSensitiveActionRequest $request): Response
    {
        Gate::authorize('manageTwoFactor', User::class);

        $this->twoFactor->disable($request->user(), $request->validated('code'));

        return response()->noContent();
    }
}
