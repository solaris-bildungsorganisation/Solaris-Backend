<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\UserSession;
use App\Services\SessionService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class LogoutController extends Controller
{
    #[OA\Post(
        path: '/v1/auth/logout',
        summary: 'إلغاء التوكن الحالي وتسجيل الخروج',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم إلغاء التوكن'
            ),
            new OA\Response(
                response: 401,
                description: 'التوكن مفقود أو غير صالح'
            ),
        ]
    )]
    public function __invoke(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        $session = UserSession::where('personal_access_token_id', $token?->id)->first();
        if ($session) {
            app(SessionService::class)->revoke($session, $request->user(), 'logout');
        } else {
            $token?->delete();
        }

        return response()->json([
            'message' => '.تم تسجيل الخروج وإلغاء الرمز الحالي',
        ]);
    }
#[OA\Post(
    path: '/v1/auth/logout',
    summary: 'إلغاء التوكن الحالي وتسجيل الخروج',
    tags: ['Auth'],
    security: [['bearerAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'تم إلغاء التوكن'
        ),
        new OA\Response(
            response: 401,
            description: 'التوكن مفقود أو غير صالح'
        ),
    ]
)]
 public function __invoke(Request $request)
 {
  $user = $request->user();
    $token = $user?->currentAccessToken();

    if (! $user || ! $token || ! method_exists($token, 'delete')) {
        return response()->json([
            'message' => 'أرسل Bearer token صالحًا لتسجيل الخروج.',
        ], 401);
    }

    $token->delete();

    return response()->json([
        'message' => 'تم تسجيل الخروج وإلغاء الرمز الحالي.',
    ]);
 }
}
