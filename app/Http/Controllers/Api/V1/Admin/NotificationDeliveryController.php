<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FilterNotificationDeliveriesRequest;
use App\Http\Resources\NotificationDeliveryResource;
use App\Models\NotificationDelivery;
use App\Services\NotificationDeliveryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

class NotificationDeliveryController extends Controller
{
    public function __construct(
        private readonly NotificationDeliveryService $service
    ) {}

    #[OA\Get(
        path: '/v1/admin/notification-deliveries',
        tags: ['Admin - Notification Deliveries'],
        summary: 'عرض سجلات إرسال الإشعارات',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'channel', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'تم جلب سجلات الإرسال بنجاح'),
            new OA\Response(response: 401, description: 'غير مصرح بالدخول'),
            new OA\Response(response: 403, description: 'لا تملك صلاحية العرض'),
            new OA\Response(response: 422, description: 'بيانات الفلترة غير صالحة'),
        ]
    )]
    public function index(
        FilterNotificationDeliveriesRequest $request
    ): AnonymousResourceCollection {
        Gate::authorize('viewAny', NotificationDelivery::class);

        $deliveries = $this->service->index(
            $request->user(),
            $request->validated()
        );

        return NotificationDeliveryResource::collection($deliveries);
    }

    #[OA\Get(
        path: '/v1/admin/notification-deliveries/{delivery}',
        tags: ['Admin - Notification Deliveries'],
        summary: 'عرض تفاصيل سجل إرسال إشعار',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'delivery',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'تم جلب تفاصيل سجل الإرسال بنجاح'),
            new OA\Response(response: 401, description: 'غير مصرح بالدخول'),
            new OA\Response(response: 403, description: 'لا تملك صلاحية العرض'),
            new OA\Response(response: 404, description: 'السجل غير موجود'),
        ]
    )]
    public function show(
        NotificationDelivery $delivery
    ): NotificationDeliveryResource {
        Gate::authorize('view', $delivery);

        $delivery->load(['type', 'channel']);

        return new NotificationDeliveryResource($delivery);
    }
}
