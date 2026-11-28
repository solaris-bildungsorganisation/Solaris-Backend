<?php

namespace App\Http\Controllers\Api\V1\Content;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicSuccessStoryResource;
use App\Models\SuccessStory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class SuccessStoryController extends Controller
{
    #[OA\Get(
        path: '/v1/success-stories',
        summary: 'List published success stories',
        tags: ['Success Stories'],
        responses: [
            new OA\Response(response: 200, description: 'Published stories in requested language'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $stories = SuccessStory::query()
            ->published()
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->paginate($perPage);

        return PublicSuccessStoryResource::collection($stories);
    }

    #[OA\Get(
        path: '/v1/success-stories/{story}',
        summary: 'Show a published success story',
        tags: ['Success Stories'],
        parameters: [new OA\Parameter(name: 'story', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Published story in requested language'),
            new OA\Response(response: 404, description: 'Story not found or not published'),
        ],
    )]
    public function show(string $story): PublicSuccessStoryResource
    {
        $publishedStory = SuccessStory::query()
            ->published()
            ->whereNotNull('published_at')
            ->where('uuid', $story)
            ->firstOrFail();

        return PublicSuccessStoryResource::make($publishedStory);
    }
}
