<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AccountTypeRequestController extends Controller
{
    #[OA\Get(
        path: '/v1/me/account-type-requests',
        tags: ['Profile - Account Type Requests'],
        summary: 'عرض طلبات المستخدم الحالية وحالتها',
        description: 'يعرض قائمة بطلبات أنواع الحسابات الخاصة بالمستخدم الحالي مع حالتها.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم جلب الطلبات بنجاح'
            ),
            new OA\Response(response: 401, description: 'غير مصرح'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('account_type_requests.create_own')) {
            abort(403, 'Unauthorized');
        }
        $requests = $request->user()->accountTypeRequests()->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    #[OA\Delete(
        path: '/v1/me/account-type-requests/{uuid}',
        tags: ['Profile - Account Type Requests'],
        summary: 'إلغاء طلب ما زال pending فقط',
        description: 'إلغاء طلب نوع الحساب بشرط أن يكون ما زال في حالة pending.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                description: 'UUID الخاص بالطلب',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم إلغاء الطلب بنجاح'
            ),
            new OA\Response(response: 422, description: 'لا يمكن إلغاء الطلب لأنه لم يعد في حالة pending'),
            new OA\Response(response: 404, description: 'الطلب غير موجود أو لا يخص المستخدم'),
            new OA\Response(response: 401, description: 'غير مصرح'),
        ]
    )]
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $accountRequest = $request->user()
            ->accountTypeRequests()
            ->where('uuid', $uuid)
            ->firstOrFail();

        // التحقق من أن الطلب ما زال في حالة pending فقط
        if ($accountRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel a request that is no longer pending.',
            ], 422);
        }

        $accountRequest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Account type request cancelled successfully.',
        ]);
    }
}
