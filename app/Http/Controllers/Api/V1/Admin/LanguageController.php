<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\I18n\StoreLanguageRequest;
use App\Http\Requests\Admin\I18n\UpdateLanguageRequest;
use App\Http\Resources\LanguageResource;
use App\Models\Language;
use App\Services\Admin\LanguageManagementService;
use OpenApi\Attributes as OA;
class LanguageController extends Controller
{
    #[OA\Get(
    path: '/v1/admin/languages',
    tags: ['Admin - I18N'],
    summary: 'عرض اللغات',
    security: [['bearerAuth' => []]],
    responses: [
        new OA\Response(response: 200, description: 'قائمة اللغات'),
        new OA\Response(response: 401, description: 'غير مصرح'),
        new OA\Response(response: 403, description: 'لا تملك الصلاحية'),
    ]
)]
    public function index(LanguageManagementService $service)
    {
        $this->authorize('viewAny', Language::class);

        return LanguageResource::collection(
            $service->listLanguages()
        );
    }
#[OA\Post(
    path: '/v1/admin/languages',
    tags: ['Admin - I18N'],
    summary: 'إضافة لغة جديدة',
    security: [['bearerAuth' => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['code', 'name', 'native_name', 'direction'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'fr'),
                new OA\Property(property: 'name', type: 'string', example: 'French'),
                new OA\Property(property: 'native_name', type: 'string', example: 'Français'),
                new OA\Property(property: 'direction', type: 'string', enum: ['ltr', 'rtl']),
                new OA\Property(property: 'is_interface', type: 'boolean'),
                new OA\Property(property: 'is_content', type: 'boolean'),
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'sort_order', type: 'integer'),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 201, description: 'تم إنشاء اللغة'),
        new OA\Response(response: 403, description: 'لا تملك الصلاحية'),
        new OA\Response(response: 422, description: 'بيانات غير صالحة'),
    ]
)]
    public function store(
        StoreLanguageRequest $request,
        LanguageManagementService $service
    ) {
        $language = $service->createLanguage(
            $request->validated()
        );

        return (new LanguageResource($language))
            ->response()
            ->setStatusCode(201);
    }
#[OA\Patch(
    path: '/v1/admin/languages/{language}',
    tags: ['Admin - I18N'],
    summary: 'تعديل إعدادات لغة',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(
            name: 'language',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'string', format: 'uuid')
        )
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'native_name', type: 'string'),
                new OA\Property(property: 'direction', type: 'string', enum: ['ltr', 'rtl']),
                new OA\Property(property: 'is_interface', type: 'boolean'),
                new OA\Property(property: 'is_content', type: 'boolean'),
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'sort_order', type: 'integer'),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'تم تحديث اللغة'),
        new OA\Response(response: 403, description: 'لا تملك الصلاحية'),
        new OA\Response(response: 404, description: 'اللغة غير موجودة'),
        new OA\Response(response: 422, description: 'بيانات غير صالحة'),
    ]
)]
    public function update(
        UpdateLanguageRequest $request,
        Language $language,
        LanguageManagementService $service
    ) {
        $updatedLanguage = $service->updateLanguage(
            $language,
            $request->validated()
        );

        return new LanguageResource($updatedLanguage);
    }
}
