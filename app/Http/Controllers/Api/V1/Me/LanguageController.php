<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\UserLanguage;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class LanguageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    #[OA\Get(
        path: '/v1/me/languages',
        tags: ['Profile - Languages'],
        summary: 'عرض لغات المستخدم',
        description: 'يعرض اللغات ومستويات إتقان المستخدم الحالي.',
        security: [['bearerAuth' => []]], // <--- أضفنا هذا السطر لإظهار القفل
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم جلب لغات المستخدم بنجاح'
            ),
            new OA\Response(response: 401, description: 'غير مصرح'),
        ]
    )]
    public function index(Request $request)
    {
        $languages = $request->user()
            ->userLanguages()
            ->with('language')
            ->get();

        return response()->json([
            'data' => $languages->map(fn ($item) => [
                'id' => $item->id,
                'language' => [
                    'id' => $item->language->id,
                    'code' => $item->language->code,
                    'name' => $item->language->name,
                    'native_name' => $item->language->native_name,
                ],
                'proficiency' => $item->proficiency,
                'is_native' => $item->is_native,
                'verified_at' => $item->verified_at,
            ]),
        ]);
    }


    /**
     * Store a newly created resource in storage.
     */
    #[OA\Post(
        path: '/v1/me/languages',
        tags: ['Profile - Languages'],
        summary: 'إضافة لغة للمستخدم',
        security: [['bearerAuth' => []]], // <--- أضفنا هذا السطر أيضاً
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['language_id', 'proficiency'],
                properties: [
                    new OA\Property(
                        property: 'language_id',
                        type: 'integer',
                        example: 1
                    ),
                    new OA\Property(
                        property: 'proficiency',
                        type: 'string',
                        enum: ['A1', 'A2', 'B1', 'B2', 'C1', 'C2', 'native'],
                        example: 'B2'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'تمت إضافة اللغة بنجاح'),
            new OA\Response(response: 401, description: 'غير مصرح'),
            new OA\Response(response: 422, description: 'فشل التحقق أو اللغة مضافة مسبقاً'),
        ]
    )]
    public function store(Request $request)
    {
        $validated = $request->validate([
            'language_id' => [
                'required',
                'integer',
                'exists:languages,id',
                \Illuminate\Validation\Rule::unique('user_languages', 'language_id')
                    ->where('user_id', $request->user()->id),
            ],
            'proficiency' => [
                'required',
                'string',
                \Illuminate\Validation\Rule::in([
                    'A1', 'A2', 'B1', 'B2', 'C1', 'C2', 'native',
                ]),
            ],
        ]);

        $userLanguage = $request->user()
            ->userLanguages()
            ->create($validated);

        return response()->json([
            'message' => 'تمت إضافة اللغة بنجاح.',
            'data' => $userLanguage->load('language'),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(UserLanguage $userLanguage)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    #[OA\Patch(
        path: '/v1/me/languages/{userLanguage}',
        tags: ['Profile - Languages'],
        summary: 'تعديل مستوى إتقان اللغة',
        security: [['bearerAuth' => []]], // <--- أضفنا هذا السطر
        parameters: [
            new OA\Parameter(
                name: 'userLanguage',
                in: 'path',
                required: true,
                description: 'معرّف سجل لغة المستخدم',
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'proficiency',
                        type: 'string',
                        enum: ['A1', 'A2', 'B1', 'B2', 'C1', 'C2', 'native'],
                        example: 'C1'
                    ),
                    new OA\Property(
                        property: 'is_native',
                        type: 'boolean',
                        example: false
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'تم تحديث اللغة بنجاح'),
            new OA\Response(response: 401, description: 'غير مصرح'),
            new OA\Response(response: 403, description: 'لا يمكن تعديل لغة تم التحقق منها'),
            new OA\Response(response: 404, description: 'سجل اللغة غير موجود'),
            new OA\Response(response: 422, description: 'فشل التحقق من المدخلات'),
        ]
    )]
    public function update(Request $request, UserLanguage $userLanguage)
    {
        abort_unless($userLanguage->user_id === $request->user()->id, 404);

        abort_if($userLanguage->verified_at !== null, 403, 'لا يمكن تعديل لغة تم التحقق منها.');

        $validated = $request->validate([
            'proficiency' => [
                'sometimes',
                'required',
                'string',
                \Illuminate\Validation\Rule::in([
                    'A1', 'A2', 'B1', 'B2', 'C1', 'C2', 'native',
                ]),
            ],
            'is_native' => ['sometimes', 'required', 'boolean'],
        ]);

        $userLanguage->update($validated);

        return response()->json([
            'message' => 'تم تحديث اللغة بنجاح.',
            'data' => $userLanguage->load('language'),
        ]);
    }


    /**
     * Remove the specified resource from storage.
     */
    #[OA\Delete(
        path: '/v1/me/languages/{userLanguage}',
        tags: ['Profile - Languages'],
        summary: 'حذف لغة من ملف المستخدم',
        security: [['bearerAuth' => []]], // <--- أضفنا هذا السطر
        parameters: [
            new OA\Parameter(
                name: 'userLanguage',
                in: 'path',
                required: true,
                description: 'معرّف سجل لغة المستخدم',
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'تم حذف اللغة بنجاح'),
            new OA\Response(response: 401, description: 'غير مصرح'),
            new OA\Response(response: 403, description: 'لا يمكن حذف لغة تم التحقق منها'),
            new OA\Response(response: 404, description: 'سجل اللغة غير موجود'),
        ]
    )]
    public function destroy(Request $request, UserLanguage $userLanguage)
    {
        abort_unless($userLanguage->user_id === $request->user()->id, 404);

        abort_if($userLanguage->verified_at !== null, 403, 'لا يمكن حذف لغة تم التحقق منها.');

        $userLanguage->delete();

        return response()->json([
            'message' => 'تم حذف اللغة بنجاح.',
        ]);
    }
}
