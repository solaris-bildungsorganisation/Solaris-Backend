<?php
namespace App\Http\Controllers\Api\V1\Auth;
use App\Http\Controllers\Controller;
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
 $request->user()->currentAccessToken()?->delete();
 return response()->json([
 'message' => '.تم تسجيل الخروج وإلغاء الرمز الحالي'
 ]);
 }
}
