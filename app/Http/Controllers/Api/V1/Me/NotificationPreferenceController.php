<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Requests\Me\UpdateNotificationPreferencesRequest;
use App\Models\NotificationPreference;
use App\Services\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

class NotificationPreferenceController extends Controller
{
    #[OA\Get(
        path: '/v1/me/notification-preferences',
        tags: ['Notification Preferences'],
        summary: 'مصفوفة تفضيلات الإشعارات (فئة × قناة)',
        description: 'تعيد لكل فئة قيمة locked، ولكل قناة قيمة is_enabled.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'تم جلب التفضيلات'),
            new OA\Response(response: 401, description: 'غير مصرح'),
            new OA\Response(response: 403, description: 'لا تملك الصلاحية'),
        ]
    )]
    public function index(Request $request, NotificationPreferenceService $service): JsonResponse
    {
        Gate::authorize('viewAny', NotificationPreference::class);

        return response()->json([
            'data' => $service->matrix($request->user()),
        ]);
    }

    #[OA\Put(
        path: '/v1/me/notification-preferences',
        tags: ['Notification Preferences'],
        summary: 'تحديث تفضيلات الإشعارات (جماعي)',
        description: 'لا يمكن تعطيل فئة إلزامية (locked).',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'تم التحديث'),
            new OA\Response(response: 401, description: 'غير مصرح'),
            new OA\Response(response: 403, description: 'لا تملك الصلاحية'),
            new OA\Response(response: 422, description: 'بيانات غير صالحة أو فئة إلزامية'),
        ]
    )]
    public function update(UpdateNotificationPreferencesRequest $request, NotificationPreferenceService $service): JsonResponse
    {
        Gate::authorize('update', NotificationPreference::class);

        $service->update($request->user(), $request->validated()['preferences']);

        return response()->json([
            'data' => $service->matrix($request->user()),
        ]);
    }
}
