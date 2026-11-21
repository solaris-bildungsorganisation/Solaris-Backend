<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

class NotificationController extends Controller
{

#[OA\Get(
        path: '/v1/me/notifications',
        tags: ['Notifications'],
        summary: 'قائمة إشعاراتي',
        description: 'الأحدث أولاً، مع فلتر unread وcategory.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'unread', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'category', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'تم جلب الإشعارات'),
            new OA\Response(response: 401, description: 'غير مصرح'),
            new OA\Response(response: 403, description: 'لا تملك الصلاحية'),
            new OA\Response(response: 422, description: 'بيانات غير صالحة'),
        ]
    )]




    public function index(Request $request, NotificationService $service)
    {
        Gate::authorize('viewAny', DatabaseNotification::class);

        $data = $request->validate([
            'unread' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $notifications = $service->list(
            $request->user(),
            (bool) ($data['unread'] ?? false),
            $data['category'] ?? null,
            $data['per_page'] ?? 15,
        );

        return NotificationResource::collection($notifications);
    }



#[OA\Get(
        path: '/v1/me/notifications/unread-count',
        tags: ['Notifications'],
        summary: 'عدد الإشعارات غير المقروءة',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'تم جلب العدد'),
            new OA\Response(response: 401, description: 'غير مصرح'),
            new OA\Response(response: 403, description: 'لا تملك الصلاحية'),
        ]
    )]




    public function unreadCount(Request $request, NotificationService $service)
    {
        Gate::authorize('viewAny', DatabaseNotification::class);

        return response()->json([
            'data' => ['count' => $service->unreadCount($request->user())],
        ]);
    }



#[OA\Patch(
        path: '/v1/me/notifications/{id}/read',
        tags: ['Notifications'],
        summary: 'تعليم إشعار كمقروء',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'UUID الإشعار', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'تم تعليم الإشعار كمقروء'),
            new OA\Response(response: 401, description: 'غير مصرح'),
            new OA\Response(response: 403, description: 'لا تملك الصلاحية'),
            new OA\Response(response: 404, description: 'الإشعار غير موجود أو لا يخص المستخدم'),
        ]
    )]








    public function markRead(Request $request, string $id, NotificationService $service)
    {
        Gate::authorize('viewAny', DatabaseNotification::class);

        $notification = $service->find($request->user(), $id);

        return new NotificationResource($service->markRead($notification));
    }








#[OA\Post(
        path: '/v1/me/notifications/read-all',
        tags: ['Notifications'],
        summary: 'تعليم كل الإشعارات كمقروءة',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 204, description: 'تم تعليم الكل كمقروء'),
            new OA\Response(response: 401, description: 'غير مصرح'),
            new OA\Response(response: 403, description: 'لا تملك الصلاحية'),
        ]
    )]








    public function readAll(Request $request, NotificationService $service)
    {
        Gate::authorize('viewAny', DatabaseNotification::class);

        $service->markAllRead($request->user());

        return response()->noContent();
    }








#[OA\Delete(
        path: '/v1/me/notifications/{id}',
        tags: ['Notifications'],
        summary: 'حذف إشعار من قائمتي',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'UUID الإشعار', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'تم حذف الإشعار'),
            new OA\Response(response: 401, description: 'غير مصرح'),
            new OA\Response(response: 403, description: 'لا تملك الصلاحية'),
            new OA\Response(response: 404, description: 'الإشعار غير موجود أو لا يخص المستخدم'),
        ]
    )]





    public function destroy(Request $request, string $id, NotificationService $service)
    {
        Gate::authorize('viewAny', DatabaseNotification::class);

        $notification = $service->find($request->user(), $id);
        $service->delete($notification);

        return response()->noContent();
    }
}
