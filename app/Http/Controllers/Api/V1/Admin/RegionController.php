<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Geo\StoreRegionRequest;
use App\Http\Requests\Admin\Geo\UpdateRegionRequest;
use App\Http\Resources\RegionResource;
use App\Models\Region;
use App\Services\GeoAdministrationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class RegionController extends Controller
{
    #[OA\Get(path: '/v1/admin/regions', operationId: 'adminRegionsIndex', tags: ['Admin Geo'], summary: 'قائمة المناطق', security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'صفحة المناطق'), new OA\Response(response: 401, description: 'غير مصادق'), new OA\Response(response: 403, description: 'لا توجد صلاحية')])]
    public function index(Request $request)
    {
        $this->authorize('viewAny', Region::class);
        $filters = $request->validate([
            'country_id' => ['sometimes', 'integer', 'exists:countries,id'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:regions,id'],
            'type' => ['sometimes', Rule::in(['governorate', 'district', 'city', 'state'])],
            'is_active' => ['sometimes', Rule::in(['0', '1', 0, 1, true, false])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Region::query()->with(['country', 'parent']);
        foreach (['country_id', 'parent_id', 'type'] as $field) {
            if (array_key_exists($field, $filters)) $query->where($field, $filters[$field]);
        }
        if (array_key_exists('is_active', $filters)) $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        return RegionResource::collection($query->orderBy('country_id')->orderBy('sort_order')->paginate($filters['per_page'] ?? 20));
    }

    #[OA\Post(path: '/v1/admin/regions', operationId: 'adminRegionsStore', tags: ['Admin Geo'], summary: 'إنشاء منطقة', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 201, description: 'تم إنشاء المنطقة'), new OA\Response(response: 403, description: 'لا توجد صلاحية'), new OA\Response(response: 422, description: 'مدخلات غير صحيحة')])]
    public function store(StoreRegionRequest $request, GeoAdministrationService $service)
    {
        return (new RegionResource($service->createRegion($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/v1/admin/regions/{region}', operationId: 'adminRegionsShow', tags: ['Admin Geo'], summary: 'عرض منطقة', security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'region', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'المنطقة'), new OA\Response(response: 404, description: 'غير موجودة')])]
    public function show(Region $region)
    {
        $this->authorize('view', $region);
        return new RegionResource($region->load(['country', 'parent']));
    }

    #[OA\Patch(path: '/v1/admin/regions/{region}', operationId: 'adminRegionsUpdate', tags: ['Admin Geo'], summary: 'تعديل منطقة', security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'region', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 200, description: 'تم التعديل'), new OA\Response(response: 403, description: 'لا توجد صلاحية'), new OA\Response(response: 422, description: 'مدخلات غير صحيحة')])]
    public function update(UpdateRegionRequest $request, Region $region, GeoAdministrationService $service)
    {
        return new RegionResource($service->updateRegion($request->user(), $region, $request->validated()));
    }

    #[OA\Delete(path: '/v1/admin/regions/{region}', operationId: 'adminRegionsDestroy', tags: ['Admin Geo'], summary: 'حذف أو تعطيل منطقة مستخدمة', security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'region', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'حُذفت المنطقة أو عُطلت'), new OA\Response(response: 403, description: 'لا توجد صلاحية')])]
    public function destroy(Region $region, GeoAdministrationService $service)
    {
        $this->authorize('delete', $region);
        $result = $service->deleteRegion(request()->user(), $region);
        return response()->json([
            'message' => $result['deleted'] ? 'تم حذف المنطقة.' : 'المنطقة مستخدمة؛ تم تعطيلها بدل حذفها.',
            'deleted' => $result['deleted'],
            'data' => $result['deleted'] ? null : new RegionResource($result['region']),
        ]);
    }
}
