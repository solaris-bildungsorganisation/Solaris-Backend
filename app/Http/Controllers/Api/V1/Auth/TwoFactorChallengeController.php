<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Services\Auth\TwoFactorChallengeService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * K2 — إكمال الدخول بتحدّي 2FA (FR-AUTH-009, 025, 028 · NFR-SEC MFA · D8).
 * مسار عام (بلا توكن) + throttle. المنطق كله في TwoFactorChallengeService.
 */
class TwoFactorChallengeController extends Controller
{
    #[OA\Post(
        path: '/v1/auth/2fa/challenge',
        tags: ['Two-Factor Auth'],
        summary: 'إكمال الدخول: challenge_token + رمز التطبيق أو رمز استرداد → التوكن والجلسة',
        description: 'challenge_token يأتي من POST /v1/auth/login عندما تكون الاستجابة two_factor_required = true. '
            .'صالح 5 دقائق، يُستخدم مرة واحدة، ويبطل بعد 5 رموز خاطئة. الرموز الخاطئة تُحسب ضمن قفل الحساب (5 محاولات → 15 دقيقة). '
            .'الجلسة الجديدة تُعدّ متحقَّقًا منها (step-up) لمدة 15 دقيقة. محدود بـ 5 طلبات في الدقيقة.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['challenge_token'],
            properties: [
                new OA\Property(property: 'challenge_token', type: 'string', example: 'kQ3x...'),
                new OA\Property(property: 'code', type: 'string', description: 'رمز التطبيق (6 أرقام) — مطلوب إن لم يُرسل recovery_code', example: '123456'),
                new OA\Property(property: 'recovery_code', type: 'string', description: 'رمز استرداد (يُستهلك) — بديل عن code', example: 'a1b2c-3d4e5'),
                new OA\Property(property: 'device_name', type: 'string', nullable: true, example: 'Chrome on Windows'),
                new OA\Property(property: 'platform', type: 'string', nullable: true, example: 'web'),
            ],
        )),
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم تسجيل الدخول — نفس شكل استجابة /auth/login بدون 2FA',
                content: new OA\JsonContent(example: [
                    'message' => '.تم تسجيل الدخول بنجاح',
                    'token_type' => 'Bearer',
                    'access_token' => '12|xyz...',
                    'data' => ['uuid' => '9b1c...', 'first_name' => 'Ahmad', 'last_name' => 'Ali', 'display_name' => 'Ahmad Ali', 'email' => 'user@example.com', 'phone' => null],
                    'two_factor' => ['method' => 'totp', 'recovery_codes_remaining' => 8],
                ]),
            ),
            new OA\Response(
                response: 401,
                description: 'code = invalid_code (رمز خاطئ، يمكن المحاولة مجددًا) أو challenge_invalid (تحدٍّ منتهٍ/مستخدم/أُبطل — سجّل الدخول من جديد)',
                content: new OA\JsonContent(example: ['message' => 'رمز التحقق غير صحيح أو مستخدم مسبقًا.', 'code' => 'invalid_code']),
            ),
            new OA\Response(response: 403, description: 'code = account_unavailable — الحساب غير نشط'),
            new OA\Response(response: 422, description: 'فشل التحقق من المدخلات'),
            new OA\Response(response: 423, description: 'code = account_locked — الحساب مقفل مؤقتًا'),
            new OA\Response(response: 429, description: 'محاولات كثيرة'),
        ],
    )]
    public function __invoke(TwoFactorChallengeRequest $request, TwoFactorChallengeService $challenges): JsonResponse
    {
        $result = $challenges->complete(
            $request->validated('challenge_token'),
            $request->validated('code'),
            $request->validated('recovery_code'),
            $request,
        );

        $user = $result['user'];

        return response()->json([
            'message' => '.تم تسجيل الدخول بنجاح',
            'token_type' => 'Bearer',
            'access_token' => $result['token'],
            'data' => [
                'uuid' => $user->uuid,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'display_name' => $user->display_name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'two_factor' => [
                'method' => $result['method'],
                'recovery_codes_remaining' => count($user->two_factor_recovery_codes ?? []),
            ],
        ]);
    }
}
