<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\I18n\UpdateTranslationRequest;
use App\Http\Resources\UiTranslationResource;
use App\Models\Language;
use App\Models\UiTranslation;
use App\Services\Admin\TranslationManagementService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
class TranslationController extends Controller
{
    #[OA\Get(
    path: '/v1/admin/translations',
    tags: ['Admin - I18N'],
    summary: 'عرض الترجمات',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'group', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'key', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'language', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'missing', in: 'query', schema: new OA\Schema(type: 'boolean')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'قائمة الترجمات'),
        new OA\Response(response: 403, description: 'لا تملك الصلاحية'),
        new OA\Response(response: 422, description: 'بيانات غير صالحة'),
    ]
)]
    public function index(
        Request $request,
        TranslationManagementService $service
    ) {
        $this->authorize('viewAny', UiTranslation::class);
        $filters = $request->validate([
            'group' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
            ],

            'key' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            'language' => [
                'sometimes',
                'nullable',
                'string',
                'size:2',
                'exists:languages,code',
            ],

            'missing' => [
                'sometimes',
                'nullable',
                'boolean',
            ],
        ]);

        $translations = $service->listTranslations($filters);

        return UiTranslationResource::collection($translations);
    }
#[OA\Put(
    path: '/v1/admin/translations',
    tags: ['Admin - I18N'],
    summary: 'إضافة أو تحديث الترجمات',
    security: [['bearerAuth' => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['group', 'key', 'translations'],
            properties: [
                new OA\Property(property: 'group', type: 'string', example: 'auth'),
                new OA\Property(property: 'key', type: 'string', example: 'login'),
                new OA\Property(
                    property: 'translations',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        required: ['language', 'value'],
                        properties: [
                            new OA\Property(property: 'language', type: 'string', example: 'ar'),
                            new OA\Property(property: 'value', type: 'string', example: 'تسجيل الدخول'),
                        ]
                    )
                ),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'تم حفظ الترجمات'),
        new OA\Response(response: 403, description: 'لا تملك الصلاحية'),
        new OA\Response(response: 422, description: 'بيانات غير صالحة'),
    ]
)]
    public function update(
        UpdateTranslationRequest $request,
        TranslationManagementService $service
    ) {
        $data = $request->validated();

        $savedCount = $service->saveTranslations(
            $data['group'],
            $data['key'],
            $data['translations']
        );

        $languageCodes = array_column($data['translations'], 'language');

        $languageIds = Language::query()
            ->whereIn('code', $languageCodes)
            ->pluck('id');

        $savedTranslations = UiTranslation::query()
            ->with('language')
            ->where('group', $data['group'])
            ->where('key', $data['key'])
            ->whereIn('language_id', $languageIds)
            ->orderBy('language_id')
            ->get();

        return UiTranslationResource::collection($savedTranslations)
            ->additional([
                'saved_count' => $savedCount,
            ]);
    }
    #[OA\Delete(
    path: '/v1/admin/translations/{translation}',
    tags: ['Admin - I18N'],
    summary: 'حذف ترجمة',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(
            name: 'translation',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'string', format: 'uuid')
        )
    ],
    responses: [
        new OA\Response(response: 204, description: 'تم حذف الترجمة'),
        new OA\Response(response: 403, description: 'لا تملك الصلاحية'),
        new OA\Response(response: 404, description: 'الترجمة غير موجودة'),
    ]
)]

    public function destroy(
        UiTranslation $translation,
        TranslationManagementService $service
    ) {
        $this->authorize('delete', $translation);

        $service->deleteTranslation($translation);

        return response()->noContent();
    }
}
