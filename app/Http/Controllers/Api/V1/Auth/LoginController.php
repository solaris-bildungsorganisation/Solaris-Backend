<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
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
            new OA\Response(response: 200, description: 'نجاح؛ تشمل الاستجابة access_token'),
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
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            if ($user) {
                $user->failed_login_count++;
                if ($user->failed_login_count >= 5) {
                    $user->locked_until = now()->addMinutes(15);
                }
                $user->save();
            }

            return response()->json([
                'message' => '.بيانات الدخول غير صحيحة',
            ], 401);
        }
        if ($user->status !== 'active') {
            return response()->json([
                'message' => '.الحساب غير  مفعل أو غير متاح لتسجيل الدخول',
            ], 403);
        }
        if ($user->locked_until?->isFuture()) {
            return response()->json([
                'message' => '.الحساب مقفل مؤقتا ببسب محاولات دخول فاشلة',
            ], 423);
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
