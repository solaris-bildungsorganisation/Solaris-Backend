<?php

namespace App\Http\Controllers\Api\V1\Meta;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Services\GeoReferenceCache;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CountryController extends Controller
{
    #[OA\Get(path: '/v1/meta/countries', operationId: 'metaCountriesIndex', tags: ['Meta'], summary: 'الدول النشطة', responses: [new OA\Response(response: 200, description: 'قائمة الدول النشطة')])]
    public function index(Request $request, GeoReferenceCache $cache)
    {
        return response()->json(['data' => $cache->countries($request->getLocale())]);
    }

    #[OA\Get(path: '/v1/meta/countries/{country}/regions', operationId: 'metaCountryRegions', tags: ['Meta'], summary: 'المناطق النشطة التابعة لدولة', parameters: [new OA\Parameter(name: 'country', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'قائمة المناطق النشطة'), new OA\Response(response: 404, description: 'الدولة غير موجودة')])]
    public function regions(Request $request, Country $country, GeoReferenceCache $cache)
    {
        abort_unless($country->is_active, 404);
        return response()->json(['data' => $cache->regions($country, $request->getLocale())]);
    }
}
