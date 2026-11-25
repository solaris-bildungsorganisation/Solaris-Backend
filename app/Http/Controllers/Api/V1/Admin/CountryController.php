<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Geo\StoreCountryRequest;
use App\Http\Requests\Admin\Geo\UpdateCountryRequest;
use App\Http\Resources\CountryResource;
use App\Models\Country;
use App\Services\GeoAdministrationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class CountryController extends Controller
{
    #[OA\Get(path: '/v1/admin/countries', operationId: 'adminCountriesIndex', tags: ['Admin Geo'], summary: 'قائمة الدول', security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'صفحة الدول'), new OA\Response(response: 401, description: 'غير مصادق'), new OA\Response(response: 403, description: 'لا توجد صلاحية')])]
    public function index(Request $request)
    {
        $this->authorize('viewAny', Country::class);
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', Rule::in(['0', '1', 0, 1, true, false])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Country::query()->with('defaultLanguage');
        if (! empty($filters['q'])) {
            $query->where(fn ($q) => $q->where('iso2', 'like', '%'.$filters['q'].'%')->orWhere('iso3', 'like', '%'.$filters['q'].'%'));
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        return CountryResource::collection($query->orderBy('iso2')->paginate($filters['per_page'] ?? 20));
    }

    #[OA\Post(path: '/v1/admin/countries', operationId: 'adminCountriesStore', tags: ['Admin Geo'], summary: 'إنشاء دولة', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 201, description: 'تم إنشاء الدولة'), new OA\Response(response: 401, description: 'غير مصادق'), new OA\Response(response: 403, description: 'لا توجد صلاحية'), new OA\Response(response: 422, description: 'مدخلات غير صحيحة')])]
    public function store(StoreCountryRequest $request, GeoAdministrationService $service)
    {
        return (new CountryResource($service->createCountry($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/v1/admin/countries/{country}', operationId: 'adminCountriesShow', tags: ['Admin Geo'], summary: 'عرض دولة', security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'country', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'الدولة'), new OA\Response(response: 404, description: 'غير موجودة')])]
    public function show(Country $country)
    {
        $this->authorize('view', $country);
        return new CountryResource($country->load('defaultLanguage'));
    }

    #[OA\Patch(path: '/v1/admin/countries/{country}', operationId: 'adminCountriesUpdate', tags: ['Admin Geo'], summary: 'تعديل دولة', security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'country', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 200, description: 'تم التعديل'), new OA\Response(response: 403, description: 'لا توجد صلاحية'), new OA\Response(response: 422, description: 'مدخلات غير صحيحة')])]
    public function update(UpdateCountryRequest $request, Country $country, GeoAdministrationService $service)
    {
        return new CountryResource($service->updateCountry($request->user(), $country, $request->validated()));
    }

    #[OA\Delete(path: '/v1/admin/countries/{country}', operationId: 'adminCountriesDestroy', tags: ['Admin Geo'], summary: 'حذف أو تعطيل دولة مستخدمة', security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'country', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'حُذفت الدولة أو عُطلت إذا كانت مستخدمة'), new OA\Response(response: 403, description: 'لا توجد صلاحية')])]
    public function destroy(Country $country, GeoAdministrationService $service)
    {
        $this->authorize('delete', $country);
        $result = $service->deleteCountry(request()->user(), $country);
        return response()->json([
            'message' => $result['deleted'] ? 'تم حذف الدولة.' : 'الدولة مستخدمة؛ تم تعطيلها بدل حذفها.',
            'deleted' => $result['deleted'],
            'data' => $result['deleted'] ? null : new CountryResource($result['country']),
        ]);
    }
}
