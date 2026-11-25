<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Geo\StoreRegionAccessPolicyRequest;
use App\Http\Requests\Admin\Geo\UpdateRegionAccessPolicyRequest;
use App\Http\Resources\RegionAccessPolicyResource;
use App\Models\RegionAccessPolicy;
use App\Policies\ScopeGuard;
use App\Services\RegionPolicyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class RegionAccessPolicyController extends Controller
{
    #[OA\Get(path: '/v1/admin/region-policies', operationId: 'adminRegionPoliciesIndex', tags: ['Admin Geo'], summary: 'عرض سياسات المناطق ضمن النطاق', security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'صفحة السياسات'), new OA\Response(response: 401, description: 'غير مصادق'), new OA\Response(response: 403, description: 'لا توجد صلاحية')])]
    public function index(Request $request)
    {
        $this->authorize('viewAny', RegionAccessPolicy::class);
        $filters = $request->validate([
            'policy_key' => ['sometimes', 'string', 'max:80'],
            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'region_id' => ['sometimes', 'nullable', 'integer', 'exists:regions,id'],
            'is_active' => ['sometimes', Rule::in(['0', '1', 0, 1, true, false])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = ScopeGuard::visibleTo(RegionAccessPolicy::query()->with(['country', 'region']), $request->user(), 'region_policies.view');
        foreach (['policy_key', 'country_id', 'region_id'] as $field) {
            if (array_key_exists($field, $filters)) {
                $filters[$field] === null ? $query->whereNull($field) : $query->where($field, $filters[$field]);
            }
        }
        if (array_key_exists('is_active', $filters)) $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        return RegionAccessPolicyResource::collection($query->orderBy('policy_key')->paginate($filters['per_page'] ?? 20));
    }

    #[OA\Post(path: '/v1/admin/region-policies', operationId: 'adminRegionPoliciesStore', tags: ['Admin Geo'], summary: 'إنشاء سياسة منطقة', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 201, description: 'تم إنشاء السياسة'), new OA\Response(response: 403, description: 'الصلاحية أو MFA غير متحققين'), new OA\Response(response: 422, description: 'مدخلات غير صحيحة')])]
    public function store(StoreRegionAccessPolicyRequest $request, RegionPolicyService $service)
    {
        return (new RegionAccessPolicyResource($service->create($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    #[OA\Patch(path: '/v1/admin/region-policies/{policy}', operationId: 'adminRegionPoliciesUpdate', tags: ['Admin Geo'], summary: 'تعديل سياسة منطقة', security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'policy', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 200, description: 'تم التعديل'), new OA\Response(response: 403, description: 'الصلاحية أو MFA غير متحققين'), new OA\Response(response: 404, description: 'غير موجودة'), new OA\Response(response: 422, description: 'مدخلات غير صحيحة')])]
    public function update(UpdateRegionAccessPolicyRequest $request, RegionAccessPolicy $policy, RegionPolicyService $service)
    {
        return new RegionAccessPolicyResource($service->update($request->user(), $policy, $request->validated()));
    }

    #[OA\Delete(path: '/v1/admin/region-policies/{policy}', operationId: 'adminRegionPoliciesDestroy', tags: ['Admin Geo'], summary: 'حذف سياسة منطقة', security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'policy', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'تم الحذف'), new OA\Response(response: 403, description: 'الصلاحية أو MFA غير متحققين'), new OA\Response(response: 404, description: 'غير موجودة')])]
    public function destroy(RegionAccessPolicy $policy, RegionPolicyService $service)
    {
        $this->authorize('delete', $policy);
        $service->delete(request()->user(), $policy);
        return response()->json(['message' => 'تم حذف سياسة المنطقة.']);
    }
}
