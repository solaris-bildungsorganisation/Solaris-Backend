<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\TwoFactorChallengeService;
use App\Services\Auth\TwoFactorService;
use App\Services\SessionService;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

class LoginController extends Controller
{
    #[OA\Post(
        path: '/v1/auth/login',
        tags: ['Auth'],
        summary: 'تسجيل الدخول وإصدار Sanctum token',
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        required: ['identifier', 'password'],
                        properties: [
                            new OA\Property(
                                property: 'identifier',
                                type: 'string',
                                description: 'البريد الإلكتروني أو رقم الهاتف',
                                example: 'user@example.com'
                            ),
                            new OA\Property(
                                property: 'password',
                                type: 'string',
                                format: 'password',
                                example: 'secret123'
                            ),
                        ]
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'نجاح. شكلان للاستجابة: (1) بدون 2FA: access_token مباشرة. (2) مع 2FA مفعّل (K2): two_factor_required + challenge_token (صالح 5 دقائق) بدل التوكن — أكمِل عبر POST /v1/auth/2fa/challenge.',
                content: new OA\JsonContent(examples: [
                    new OA\Examples(example: 'token', summary: 'بدون 2FA', value: [
                        'message' => '.تم تسجيل الدخول بنجاح', 'token_type' => 'Bearer', 'access_token' => '1|abc...',
                        'data' => ['uuid' => '9b1c...', 'first_name' => 'Ahmad', 'last_name' => 'Ali', 'display_name' => 'Ahmad Ali', 'email' => 'user@example.com', 'phone' => null],
                    ]),
                    new OA\Examples(example: 'two_factor', summary: '2FA مفعّل', value: [
                        'message' => 'أدخل رمز التحقق الثنائي لإكمال تسجيل الدخول.',
                        'two_factor_required' => true,
                        'challenge_token' => 'kQ3x...(64 حرفًا)',
                        'expires_in' => 300,
                    ]),
                ]),
            ),
            new OA\Response(response: 423, description: 'الحساب مقفل مؤقتًا (5 محاولات فاشلة: كلمة مرور أو رمز 2FA)'),
            new OA\Response(response: 401, description: 'بيانات الدخول غير صحيحة'),
            new OA\Response(response: 403, description: 'الحساب غير نشط'),
            new OA\Response(response: 422, description: 'فشل التحقق من المدخلات'),
        ]
    )]
    public function __invoke(LoginRequest $request)
    {
        $data = $request->validated();
        $identifier = $data['identifier'];
        $user = User::query()
            ->where(function ($query) use ($identifier) {
                $query->where('email', $identifier)
                    ->orWhere('phone', $identifier);
            })
            ->first();
        if (! $user) {
            return response()->json([
                'message' => 'بيانات الدخول غير صحيحة.',
            ], 401);
        }
        if ($user->locked_until?->isFuture()) {
            return response()->json([
                'message' => 'الحساب مقفل مؤقتاً بسبب محاولات دخول فاشلة.',
            ], 423);
        }
        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'الحساب غير مفعل أو غير متاح لتسجيل الدخول.',
            ], 403);
        }
        if (! Hash::check($data['password'], $user->password)) {
            $user->failed_login_count++;
            if ($user->failed_login_count >= 5) {
                $user->locked_until = now()->addMinutes(15);
            }
            $user->save();

            return response()->json([
                'message' => 'بيانات الدخول غير صحيحة.',
            ], 401);
        }
        // K2: 2FA مفعّل → لا توكن بكلمة المرور وحدها؛ challenge_token يُكمَل عبر POST /auth/2fa/challenge.
        // عدّاد المحاولات الفاشلة لا يُصفَّر هنا: الرموز الخاطئة في التحدّي تُحسب ضمن قفل الحساب.
        if (app(TwoFactorService::class)->isEnabled($user)) {
            $challenges = app(TwoFactorChallengeService::class);

            return response()->json([
                'message' => 'أدخل رمز التحقق الثنائي لإكمال تسجيل الدخول.',
                'two_factor_required' => true,
                'challenge_token' => $challenges->issue($user, $request),
                'expires_in' => $challenges->ttlMinutes() * 60,
            ]);
        }
        $user->failed_login_count = 0;
        $user->locked_until = null;
        $user->last_login_at = now();
        $user->last_login_ip = request()->ip();
        $user->save();
        $newToken = $user->createToken('api');
        app(SessionService::class)->start($user, $newToken, $request);
        $token = $newToken->plainTextToken;

        return response()->json([
            'message' => '.تم تسجيل الدخول بنجاح',
            'token_type' => 'Bearer',
            'access_token' => $token,
            'data' => [
                'uuid' => $user->uuid,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'display_name' => $user->display_name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
        ]);
    }
}
