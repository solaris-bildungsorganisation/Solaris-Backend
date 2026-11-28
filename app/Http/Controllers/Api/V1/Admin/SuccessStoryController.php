<?php

namespace App\Http\Controllers\Api\V1\Admin;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\StoreSuccessStoryRequest;
use App\Http\Requests\Admin\Content\UpdateSuccessStoryRequest;
use App\Http\Resources\AdminSuccessStoryResource;
use App\Models\SuccessStory;
use App\Models\User;
use App\Policies\ScopeGuard;
use App\Services\Admin\SuccessStoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

class SuccessStoryController extends Controller
{
    public function __construct(private readonly SuccessStoryService $service)
    {
    }

    #[OA\Get(
        path: '/v1/admin/success-stories',
        summary: 'List success stories within the authorized scope',
        tags: ['Admin - Success Stories'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Success story list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Permission denied'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SuccessStory::class);

        /** @var User $actor */
        $actor = $request->user();
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $query = ScopeGuard::visibleTo(
            SuccessStory::query(),
            $actor,
            'success_stories.manage',
        );

        return AdminSuccessStoryResource::collection(
            $query->orderByDesc('created_at')->paginate($perPage),
        );
    }

    #[OA\Post(
        path: '/v1/admin/success-stories',
        summary: 'Create a success story draft',
        tags: ['Admin - Success Stories'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Draft created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Permission or scope denied'),
            new OA\Response(response: 422, description: 'Invalid input'),
        ],
    )]

public function store(StoreSuccessStoryRequest $request): JsonResponse
{
    /** @var User $actor */
    $actor = $request->user();

    $story = $this->service->create($request->validated(), $actor);

    return AdminSuccessStoryResource::make($story)
        ->response()
        ->setStatusCode(201);
}

    #[OA\Get(
        path: '/v1/admin/success-stories/{story}',
        summary: 'Show an administrative success story',
        tags: ['Admin - Success Stories'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'story', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Success story data'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 404, description: 'Story not found or outside scope'),
        ],
    )]
    public function show(SuccessStory $story): AdminSuccessStoryResource
    {
        Gate::authorize('view', $story);

        return AdminSuccessStoryResource::make($story);
    }

    #[OA\Put(
        path: '/v1/admin/success-stories/{story}',
        summary: 'Replace editable success story fields',
        tags: ['Admin - Success Stories'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'story', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Story updated'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 404, description: 'Story not found or outside scope'),
            new OA\Response(response: 422, description: 'Invalid input or protected fields'),
        ],
    )]
    #[OA\Patch(
        path: '/v1/admin/success-stories/{story}',
        summary: 'Partially update editable success story fields',
        tags: ['Admin - Success Stories'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'story', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Story updated'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 404, description: 'Story not found or outside scope'),
            new OA\Response(response: 422, description: 'Invalid input or protected fields'),
        ],
    )]
    public function update(UpdateSuccessStoryRequest $request, SuccessStory $story): AdminSuccessStoryResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->update($story, $request->validated(), $actor);

        return AdminSuccessStoryResource::make($updated);
    }

    #[OA\Delete(
        path: '/v1/admin/success-stories/{story}',
        summary: 'Soft-delete a success story',
        tags: ['Admin - Success Stories'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'story', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Story soft-deleted'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 404, description: 'Story not found or outside scope'),
        ],
    )]
    public function destroy(Request $request, SuccessStory $story): HttpResponse
    {
        Gate::authorize('delete', $story);

        /** @var User $actor */
        $actor = $request->user();
        $this->service->delete($story, $actor);

        return response()->noContent();
    }
}
