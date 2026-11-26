<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notifications\UpdateNotificationTypeRequest;
use App\Http\Resources\NotificationTypeResource;
use App\Models\NotificationType;
use App\Services\Notifications\NotificationAdminService;
use OpenApi\Attributes as OA;

class NotificationTypeController extends Controller
{
    public function __construct(private readonly NotificationAdminService $service) {}

    #[OA\Get(
        path: '/v1/admin/notification-types',
        tags: ['Admin - Notifications'],
        summary: 'عرض أنواع الإشعارات',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'قائمة أنواع الإشعارات مع عدد القوالب النشطة',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'غير مصرح بالدخول'),
            new OA\Response(response: 403, description: 'ليس لديك صلاحية عرض أنواع الإشعارات'),
        ]
    )]
    public function index()
    {
        $this->authorize('viewAny', NotificationType::class);

        return NotificationTypeResource::collection($this->service->listTypes());
    }

    #[OA\Patch(
        path: '/v1/admin/notification-types/{type}',
        tags: ['Admin - Notifications'],
        summary: 'تحديث إعدادات نوع إشعار',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'type',
                in: 'path',
                required: true,
                description: 'معرّف نوع الإشعار',
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'name', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
                    new OA\Property(property: 'description', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'string')),
                    new OA\Property(property: 'default_channels', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم تحديث نوع الإشعار',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'data', type: 'object')]
                )
            ),
            new OA\Response(response: 401, description: 'غير مصرح بالدخول'),
            new OA\Response(response: 403, description: 'ليس لديك صلاحية تحديث نوع الإشعار'),
            new OA\Response(response: 404, description: 'نوع الإشعار غير موجود'),
            new OA\Response(response: 422, description: 'فشل التحقق من المدخلات أو قيود حساسية القنوات'),
        ]
    )]
    public function update(UpdateNotificationTypeRequest $request, NotificationType $type): NotificationTypeResource
    {
        $this->authorize('update', $type);

        return new NotificationTypeResource(
            $this->service->updateType($type, $request->validated(), $request->user())
        );
    }
}
