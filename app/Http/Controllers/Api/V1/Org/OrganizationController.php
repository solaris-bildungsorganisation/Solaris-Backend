<?php

namespace App\Http\Controllers\Api\V1\Org;

use App\Http\Controllers\Controller;
use App\Http\Requests\Org\StoreOrganizationRequest;
use App\Http\Requests\Org\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationMembershipResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\OrganizationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
class OrganizationController extends Controller
{
#[OA\Get(
    path: '/v1/organizations',
    operationId: 'listOrganizations',
    tags: ['Organizations'],
    summary: 'عرض المؤسسات مع البحث والفلاتر',
    description: 'يعيد قائمة مؤسسات مرقّمة. الفلاتر المتاحة: type وstatus وcountry وregion وq وper_page.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['educational', 'training_center', 'ngo', 'employer', 'donor', 'government', 'partner', 'other'])),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'active', 'suspended', 'archived'])),
        new OA\Parameter(name: 'country', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 1)),
        new OA\Parameter(name: 'region', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 3)),
        new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 150, example: 'تعليم')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, example: 15)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'قائمة المؤسسات مع روابط وبيانات الترقيم',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(type: 'object', properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', description: 'عنصر من OrganizationResource')),
                    new OA\Property(property: 'links', type: 'object', description: 'روابط الترقيم'),
                    new OA\Property(property: 'meta', type: 'object', description: 'بيانات الترقيم'),
                ])
            )
        ),
        new OA\Response(response: 401, description: 'التوكن مفقود أو غير صالح'),
        new OA\Response(response: 403, description: 'لا يملك المستخدم صلاحية عرض المؤسسات'),
        new OA\Response(response: 422, description: 'فشل التحقق من الفلاتر'),
    ]
)]
    public function index(Request $request)
    {
        $this->authorize('viewAny', Organization::class);

        $filters = $request->validate([
            'type' => ['sometimes', Rule::in(['educational', 'training_center', 'ngo', 'employer', 'donor', 'government', 'partner', 'other'])],
            'status' => ['sometimes', Rule::in(['pending', 'active', 'suspended', 'archived'])],
            'country' => ['sometimes', 'integer', 'exists:countries,id'],
            'region' => ['sometimes', 'integer', 'exists:regions,id'],
            'q' => ['sometimes', 'string', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Organization::query()->withCount('members');

        foreach ([
            'type' => 'type',
            'status' => 'status',
            'country' => 'country_id',
            'region' => 'region_id',
        ] as $input => $column) {
            if (isset($filters[$input])) {
                $query->where($column, $filters[$input]);
            }
        }

        if (! empty($filters['q'])) {
            $term = '%'.$filters['q'].'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('slug', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('name->ar', 'like', $term)
                    ->orWhere('name->en', 'like', $term);
            });
        }

        return OrganizationResource::collection(
            $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 15)
        );
    }

    #[OA\Post(
    path: '/v1/organizations',
    operationId: 'createOrganization',
    tags: ['Organizations'],
    summary: 'إنشاء مؤسسة',
    description: 'تنشأ المؤسسة بحالة pending. لا يعيّن هذا الإصدار admin_user؛ ربط RoleAssignmentService ينتظر دمج B4 ومعرفة توقيعه الفعلي.',
    security: [['bearerAuth' => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(
                type: 'object',
                required: ['name', 'type'],
                properties: [
                    new OA\Property(property: 'name', type: 'object', required: ['ar'], properties: [
                        new OA\Property(property: 'ar', type: 'string', maxLength: 255, example: 'مؤسسة النور'),
                        new OA\Property(property: 'en', type: 'string', nullable: true, maxLength: 255, example: 'Al Noor Foundation'),
                    ]),
                    new OA\Property(property: 'type', type: 'string', enum: ['educational', 'training_center', 'ngo', 'employer', 'donor', 'government', 'partner', 'other'], example: 'educational'),
                    new OA\Property(property: 'country_id', type: 'integer', nullable: true, example: 1),
                    new OA\Property(property: 'region_id', type: 'integer', nullable: true, example: 3),
                    new OA\Property(property: 'registration_number', type: 'string', nullable: true, maxLength: 100, example: 'REG-2026-01'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, maxLength: 255, example: 'info@example.org'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, maxLength: 20, example: '+963912345678'),
                    new OA\Property(property: 'website', type: 'string', format: 'uri', nullable: true, maxLength: 255, example: 'https://example.org' ),
                    new OA\Property(property: 'address', type: 'string', nullable: true, maxLength: 5000),
                    new OA\Property(property: 'logo_path', type: 'string', nullable: true, maxLength: 255),
                    new OA\Property(property: 'description', type: 'object', nullable: true, properties: [
                        new OA\Property(property: 'ar', type: 'string', nullable: true),
                        new OA\Property(property: 'en', type: 'string', nullable: true),
                    ]),
                    new OA\Property(property: 'settings', type: 'object', nullable: true, additionalProperties: true),
                ]
            )
        )
    ),
    responses: [
        new OA\Response(response: 201, description: 'تم إنشاء المؤسسة؛ تكون الحالة pending'),
        new OA\Response(response: 401, description: 'التوكن مفقود أو غير صالح'),
        new OA\Response(response: 403, description: 'لا يملك المستخدم صلاحية إنشاء مؤسسة'),
        new OA\Response(response: 422, description: 'فشل التحقق من المدخلات أو عدم تطابق الدولة والمنطقة'),
    ]
)]
    public function store(StoreOrganizationRequest $request, OrganizationService $service)
    {
        $this->authorize('create', Organization::class);
        $organization = $service->create($request->user(), $request->validated());

        return (new OrganizationResource($organization))->response()->setStatusCode(201);
    }

    #[OA\Get(
    path: '/v1/organizations/{organization}',
    operationId: 'showOrganization',
    tags: ['Organizations'],
    summary: 'عرض مؤسسة واحدة',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'organization', in: 'path', required: true, description: 'معرّف المؤسسة وفق Route Model Binding المستخدم في المشروع.', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'بيانات المؤسسة من OrganizationResource'),
        new OA\Response(response: 401, description: 'التوكن مفقود أو غير صالح'),
        new OA\Response(response: 403, description: 'لا يملك المستخدم صلاحية عرض هذه المؤسسة'),
        new OA\Response(response: 404, description: 'المؤسسة غير موجودة'),
    ]
)]
    public function show(Organization $organization)
    {
        $this->authorize('view', $organization);

        return new OrganizationResource($organization->loadCount('members'));
    }

    #[OA\Patch(
    path: '/v1/organizations/{organization}',
    operationId: 'updateOrganization',
    tags: ['Organizations'],
    summary: 'تعديل بيانات مؤسسة',
    description: 'جميع الحقول اختيارية للتعديل الجزئي. لا يقبل هذا المسار status أو slug أو verified_at أو verified_by.',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'organization', in: 'path', required: true, description: 'معرّف المؤسسة وفق Route Model Binding المستخدم في المشروع.', schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(
                type: 'object',
                properties: [
                    new OA\Property(property: 'name', type: 'object', properties: [
                        new OA\Property(property: 'ar', type: 'string', maxLength: 255),
                        new OA\Property(property: 'en', type: 'string', nullable: true, maxLength: 255),
                    ]),
                    new OA\Property(property: 'type', type: 'string', enum: ['educational', 'training_center', 'ngo', 'employer', 'donor', 'government', 'partner', 'other']),
                    new OA\Property(property: 'country_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'region_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'registration_number', type: 'string', nullable: true, maxLength: 100),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, maxLength: 255),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, maxLength: 20),
                    new OA\Property(property: 'website', type: 'string', format: 'uri', nullable: true, maxLength: 255),
                    new OA\Property(property: 'address', type: 'string', nullable: true, maxLength: 5000),
                    new OA\Property(property: 'logo_path', type: 'string', nullable: true, maxLength: 255),
                    new OA\Property(property: 'description', type: 'object', nullable: true, properties: [
                        new OA\Property(property: 'ar', type: 'string', nullable: true),
                        new OA\Property(property: 'en', type: 'string', nullable: true),
                    ]),
                    new OA\Property(property: 'settings', type: 'object', nullable: true, additionalProperties: true),
                ]
            )
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'تم تحديث المؤسسة؛ يعاد OrganizationResource'),
        new OA\Response(response: 401, description: 'التوكن مفقود أو غير صالح'),
        new OA\Response(response: 403, description: 'لا يملك المستخدم صلاحية تعديل هذه المؤسسة'),
        new OA\Response(response: 404, description: 'المؤسسة غير موجودة'),
        new OA\Response(response: 422, description: 'فشل التحقق من المدخلات أو عدم تطابق الدولة والمنطقة'),
    ]
)]
    public function update(
        UpdateOrganizationRequest $request,
        Organization $organization,
        OrganizationService $service
    ) {
        $this->authorize('update', $organization);
        $updated = $service->update($request->user(), $organization, $request->validated());

        return new OrganizationResource($updated);
    }
    #[OA\Get(
    path: '/v1/me/organizations',
    operationId: 'listMyOrganizations',
    tags: ['Organizations'],
    summary: 'عرض عضويات المؤسسات للمستخدم الحالي',
    description: 'يعيد العضويات ذات الحالة active مرتبة تنازليًا حسب joined_at مع الترقيم.',
    security: [['bearerAuth' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'قائمة عضويات المستخدم والمؤسسات المرتبطة بها',
            content: new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(type: 'object', properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', description: 'عنصر من OrganizationMembershipResource')),
                new OA\Property(property: 'links', type: 'object'),
                new OA\Property(property: 'meta', type: 'object'),
            ]))
        ),
        new OA\Response(response: 401, description: 'التوكن مفقود أو غير صالح'),
    ]
)]
    public function mine(Request $request)
    {
        $memberships = OrganizationMembership::query()
            ->where('user_id', $request->user()->getKey())
            ->where('status', 'active')
            ->with('organization')
            ->orderByDesc('joined_at')
            ->paginate(15);

        return OrganizationMembershipResource::collection($memberships);
    }
}
