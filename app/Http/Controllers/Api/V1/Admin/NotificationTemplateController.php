<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notifications\PreviewNotificationTemplateRequest;
use App\Http\Requests\Admin\Notifications\StoreNotificationTemplateRequest;
use App\Http\Requests\Admin\Notifications\UpdateNotificationTemplateRequest;
use App\Http\Resources\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class NotificationTemplateController extends Controller
{
    public function __construct(private readonly NotificationAdminService $service) {}

    #[OA\Get(
        path: '/v1/admin/notification-templates',
        tags: ['Admin - Notifications'],
        summary: 'عرض قوالب الإشعارات',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'معرّف نوع الإشعار أو مفتاحه', schema: new OA\Schema(type: 'string', example: 'security.new_login')),
            new OA\Parameter(name: 'channel', in: 'query', required: false, description: 'معرّف القناة أو مفتاحها', schema: new OA\Schema(type: 'string', example: 'email')),
            new OA\Parameter(name: 'language', in: 'query', required: false, description: 'معرّف اللغة أو رمزها', schema: new OA\Schema(type: 'string', example: 'ar')),
            new OA\Parameter(name: 'active', in: 'query', required: false, description: 'تصفية حسب حالة التفعيل', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'قائمة قوالب الإشعارات مع العلاقات المحمّلة',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'غير مصرح بالدخول'),
            new OA\Response(response: 403, description: 'ليس لديك صلاحية عرض قوالب الإشعارات'),
        ]
    )]
    public function index(Request $request)
    {
        $this->authorize('viewAny', NotificationTemplate::class);

        return NotificationTemplateResource::collection(
            $this->service->listTemplates($request->only(['type', 'channel', 'language', 'active']))
        );
    }

    #[OA\Post(
        path: '/v1/admin/notification-templates',
        tags: ['Admin - Notifications'],
        summary: 'إنشاء إصدار جديد من قالب إشعار',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['notification_type_id', 'channel_id', 'language_id', 'body'],
                properties: [
                    new OA\Property(property: 'notification_type_id', type: 'integer', example: 1),
                    new OA\Property(property: 'channel_id', type: 'integer', example: 2),
                    new OA\Property(property: 'language_id', type: 'integer', example: 1),
                    new OA\Property(property: 'subject', type: 'string', nullable: true, example: 'مرحبًا {{ name }}'),
                    new OA\Property(property: 'body', type: 'string', example: 'تفاصيل الإشعار: {{ message }}'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'تم إنشاء إصدار القالب',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'data', type: 'object')]
                )
            ),
            new OA\Response(response: 401, description: 'غير مصرح بالدخول'),
            new OA\Response(response: 403, description: 'ليس لديك صلاحية إنشاء قالب إشعار'),
            new OA\Response(response: 422, description: 'فشل التحقق من المدخلات أو قيود حساسية القنوات'),
        ]
    )]
    public function store(StoreNotificationTemplateRequest $request): JsonResponse
    {
        $this->authorize('create', NotificationTemplate::class);

        $template = $this->service->createTemplateVersion($request->validated(), $request->user());

        return (new NotificationTemplateResource($template))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/v1/admin/notification-templates/{template}',
        tags: ['Admin - Notifications'],
        summary: 'تحديث حالة قالب إشعار',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'template',
                in: 'path',
                required: true,
                description: 'معرّف القالب',
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['is_active'],
                properties: [new OA\Property(property: 'is_active', type: 'boolean')]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم تحديث حالة القالب',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'data', type: 'object')]
                )
            ),
            new OA\Response(response: 401, description: 'غير مصرح بالدخول'),
            new OA\Response(response: 403, description: 'ليس لديك صلاحية تحديث القالب'),
            new OA\Response(response: 404, description: 'القالب غير موجود'),
            new OA\Response(response: 422, description: 'فشل التحقق من المدخلات'),
        ]
    )]
    public function update(
        UpdateNotificationTemplateRequest $request,
        NotificationTemplate $template
    ): NotificationTemplateResource {
        $this->authorize('update', $template);

        return new NotificationTemplateResource(
            $this->service->updateTemplateStatus(
                $template,
                (bool) $request->validated('is_active'),
                $request->user()
            )
        );
    }

    #[OA\Post(
        path: '/v1/admin/notification-templates/{template}/preview',
        tags: ['Admin - Notifications'],
        summary: 'معاينة قالب إشعار',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'template',
                in: 'path',
                required: true,
                description: 'معرّف القالب',
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                type: 'object',
                properties: [new OA\Property(property: 'data', type: 'object', nullable: true, additionalProperties: true)]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'نص الموضوع والمتن بعد المعاينة',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'subject', type: 'string', nullable: true),
                                new OA\Property(property: 'body', type: 'string'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'غير مصرح بالدخول'),
            new OA\Response(response: 403, description: 'ليس لديك صلاحية معاينة القالب'),
            new OA\Response(response: 404, description: 'القالب غير موجود'),
            new OA\Response(response: 422, description: 'فشل التحقق من بيانات المعاينة'),
        ]
    )]
    public function preview(
        PreviewNotificationTemplateRequest $request,
        NotificationTemplate $template
    ): JsonResponse {
        $this->authorize('view', $template);

        return response()->json([
            'data' => $this->service->previewTemplate($template, $request->validated('data') ?? []),
        ]);
    }
}
