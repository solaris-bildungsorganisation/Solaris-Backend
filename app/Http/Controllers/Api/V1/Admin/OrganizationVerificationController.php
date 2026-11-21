<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class OrganizationVerificationController extends Controller
{
#[OA\Post(
    path: '/v1/admin/organizations/{organization}/verify',
    operationId: 'verifyOrganization',
    tags: ['Admin Organizations'],
    summary: 'توثيق مؤسسة',
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'organization', in: 'path', required: true, description: 'معرّف المؤسسة وفق Route Model Binding المستخدم في المشروع.', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'تم توثيق المؤسسة؛ يعاد OrganizationResource'),
        new OA\Response(response: 401, description: 'التوكن مفقود أو غير صالح'),
        new OA\Response(response: 403, description: 'لا يملك المستخدم صلاحية توثيق المؤسسة'),
        new OA\Response(response: 404, description: 'المؤسسة غير موجودة'),
    ]
)]
    public function __invoke(Request $request, Organization $organization, OrganizationService $service)
    {
        $this->authorize('verify', $organization);

        return new OrganizationResource($service->verify($request->user(), $organization));
    }
}
