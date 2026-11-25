<?php

namespace App\Http\Controllers\Api\V1\Meta;

use App\Http\Controllers\Controller;
use App\Services\GeoReferenceCache;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class LanguageController extends Controller
{
    #[OA\Get(path: '/v1/meta/languages', operationId: 'metaLanguagesIndex', tags: ['Meta'], summary: 'اللغات النشطة', responses: [new OA\Response(response: 200, description: 'قائمة اللغات النشطة')])]
    public function index(Request $request, GeoReferenceCache $cache)
    {
        return response()->json(['data' => $cache->languages($request->getLocale())]);
    }
}
