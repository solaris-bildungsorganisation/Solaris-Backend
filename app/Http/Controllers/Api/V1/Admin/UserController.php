<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\Admin\UserManagementService;
use App\Http\Resources\AdminUserResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;
class UserController extends Controller
{
    #[OA\Get(
        path: '/v1/admin/users',
        tags: ['Admin - Users'],
        summary: 'عرض قائمة المستخدمين مع إمكانية التصفية والترقيم',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'name', in: 'query', description: 'تصفية حسب الاسم', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'email', in: 'query', description: 'تصفية حسب البريد الإلكتروني', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'phone', in: 'query', description: 'تصفية حسب رقم الهاتف', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', description: 'تصفية حسب حالة الحساب', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'country_id', in: 'query', description: 'تصفية حسب معرف الدولة', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'region_id', in: 'query', description: 'تصفية حسب معرف المنطقة', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'role', in: 'query', description: 'تصفية حسب الدور', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'context', in: 'query', description: 'تصفية حسب السياق', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'organization', in: 'query', description: 'تصفية حسب المنظمة', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'عدد العناصر في كل صفحة', required: false, schema: new OA\Schema(type: 'integer', example: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'تم جلب قائمة المستخدمين بنجاح'),
            new OA\Response(response: 401, description: 'غير مصرح بالدخول'),
            new OA\Response(response: 403, description: 'ليس لديك صلاحية لعرض المستخدمين'),
        ]
    )]
    public function index(
        Request $request,
        UserManagementService $userManagementService
    ): AnonymousResourceCollection {
        $this->authorize('viewAny', User::class);

        $users = $userManagementService->listUsers(
            $request->user(),
            $request->only([
                'name',
                'email',
                'phone',
                'status',
                'country_id',
                'region_id',
                'per_page',
                'role',
                'context',
                'organization',
            ])
        );

        return AdminUserResource::collection($users);
    }

    #[OA\Get(
        path: '/v1/admin/users/{user}',
        tags: ['Admin - Users'],
        summary: 'عرض تفاصيل مستخدم محدد',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'user',
                in: 'path',
                description: 'معرف المستخدم (ID أو UUID حسب التوجيه)',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'تم جلب بيانات المستخدم بنجاح'),
            new OA\Response(response: 401, description: 'غير مصرح بالدخول'),
            new OA\Response(response: 403, description: 'ليس لديك صلاحية لعرض هذا المستخدم'),
            new OA\Response(response: 404, description: 'المستخدم غير موجود'),
        ]
    )]
    public function show(
        User $user,
        UserManagementService $userManagementService
    ) {
        $this->authorize('view', $user);

        $user = $userManagementService->getUserDetails(
            request()->user(),
            $user
        );

        return new AdminUserResource($user);
    }

    #[OA\Post(
        path: '/v1/admin/users',
        tags: ['Admin - Users'],
        summary: 'إنشاء مستخدم جديد',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        required: ['first_name', 'last_name'],
                        properties: [
                            new OA\Property(property: 'first_name', type: 'string', maxLength: 255, description: 'الاسم الأول', example: 'أحمد'),
                            new OA\Property(property: 'last_name', type: 'string', maxLength: 255, description: 'الاسم الأخير', example: 'محمد'),
                            new OA\Property(property: 'display_name', type: 'string', maxLength: 255, nullable: true, description: 'الاسم الظاهر', example: 'أحمد محمد'),
                            new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, description: 'البريد الإلكتروني (مطلوب في حال عدم إدخال رقم الهاتف)', example: 'ahmed@example.com'),
                            new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true, description: 'رقم الهاتف (مطلوب في حال عدم إدخال البريد الإلكتروني)', example: '+963912345678'),
                            new OA\Property(property: 'country_id', type: 'integer', nullable: true, description: 'معرف الدولة', example: 1),
                            new OA\Property(property: 'region_id', type: 'integer', nullable: true, description: 'معرف المنطقة', example: 5),
                            new OA\Property(property: 'interface_language_id', type: 'integer', nullable: true, description: 'معرف لغة الواجهة', example: 1),
                            new OA\Property(property: 'timezone', type: 'string', nullable: true, description: 'المنطقة الزمنية', example: 'Asia/Damascus'),
                            new OA\Property(property: 'organization_id', type: 'string', format: 'uuid', nullable: true, description: 'معرف المنظمة (UUID)', example: '550e8400-e29b-41d4-a716-446655440000'),
                            new OA\Property(
                                property: 'membership_type',
                                type: 'string',
                                nullable: true,
                                enum: ['member', 'staff', 'learner', 'instructor', 'employer_representative', 'partner_contact', 'volunteer'],
                                description: 'نوع العضوية (ممنوع إرساله بدون organization_id)',
                                example: 'member'
                            ),
                        ]
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(response: 201, description: 'تم إنشاء المستخدم بنجاح'),
            new OA\Response(response: 401, description: 'غير مصرح بالدخول'),
            new OA\Response(response: 403, description: 'ليس لديك صلاحية لإنشاء مستخدم'),
            new OA\Response(response: 422, description: 'فشل التحقق من المدخلات'),
        ]
    )]
    public function store(
        Request $request,
        UserManagementService $userManagementService
    ) {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],

            'email' => [
                'nullable',
                'email',
                'unique:users,email',
                'required_without:phone',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
                'unique:users,phone',
                'required_without:email',
            ],

            'country_id' => ['nullable', 'exists:countries,id'],
            'region_id' => ['nullable', 'exists:regions,id'],
            'interface_language_id' => [
                'nullable',
                'exists:languages,id',
            ],
            'timezone' => ['nullable', 'timezone'],

            'organization_id' => [
                'nullable',
                'uuid',
                'exists:organizations,uuid',
            ],
            'membership_type' => [
                'nullable',
                'in:member,staff,learner,instructor,employer_representative,partner_contact,volunteer',
                Rule::prohibitedIf(!$request->filled('organization_id')),
            ],
        ]);

        $user = $userManagementService->createUser(
            $request->user(),
            $data
        );

        return (new AdminUserResource($user))
            ->response()
            ->setStatusCode(201);
    }
    #[OA\Patch(
        path: '/v1/admin/users/{user}',
        tags: ['Admin - Users'],
        summary: 'تحديث بيانات مستخدم موجود',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'user',
                in: 'path',
                description: 'معرف المستخدم',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'first_name', type: 'string'),
                            new OA\Property(property: 'last_name', type: 'string'),
                            new OA\Property(property: 'display_name', type: 'string', nullable: true),
                            new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                            new OA\Property(property: 'phone', type: 'string', nullable: true),
                            new OA\Property(property: 'country_id', type: 'integer', nullable: true),
                            new OA\Property(property: 'region_id', type: 'integer', nullable: true),
                            new OA\Property(property: 'interface_language_id', type: 'integer', nullable: true),
                            new OA\Property(property: 'timezone', type: 'string', nullable: true),
                        ]
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(response: 200, description: 'تم تحديث بيانات المستخدم بنجاح'),
            new OA\Response(response: 401, description: 'غير مصرح بالدخول'),
            new OA\Response(response: 403, description: 'ليس لديك صلاحية لتعديل هذا المستخدم'),
            new OA\Response(response: 404, description: 'المستخدم غير موجود'),
            new OA\Response(response: 422, description: 'فشل التحقق من المدخلات'),
        ]
    )]
    public function update(
        Request $request,
        User $user,
        UserManagementService $userManagementService
    ) {
        $this->authorize('update', $user);

        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'unique:users,email,' . $user->id . ',id', 'required_without:phone'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'unique:users,phone,' . $user->id . ',id', 'required_without:email'],
            'country_id' => ['sometimes', 'nullable', 'exists:countries,id'],
            'region_id' => ['sometimes', 'nullable', 'exists:regions,id'],
            'interface_language_id' => ['sometimes', 'nullable', 'exists:languages,id'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
        ]);

        $user = $userManagementService->updateUser($user, $data);

        return new AdminUserResource($user);

    }
}
