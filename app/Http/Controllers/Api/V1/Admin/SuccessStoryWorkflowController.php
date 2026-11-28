<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\UpdateSuccessStoryConsentRequest;
use App\Http\Resources\AdminSuccessStoryResource;
use App\Models\SuccessStory;
use App\Models\User;
use App\Services\Admin\SuccessStoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

class SuccessStoryWorkflowController extends Controller
{
    public function __construct(private readonly SuccessStoryService $service)
    {
    }

    #[OA\Patch(
        path: '/v1/admin/success-stories/{story}/consent',
        summary: 'Record or change success story consent',
        tags: ['Admin - Success Stories'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'story', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['consent_status'],
            properties: [new OA\Property(property: 'consent_status', type: 'string', enum: ['granted', 'not_required', 'withdrawn'])],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Consent recorded'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 404, description: 'Story not found or outside scope'),
            new OA\Response(response: 422, description: 'Invalid consent value or combination'),
        ],
    )]
    public function consent(UpdateSuccessStoryConsentRequest $request, SuccessStory $story): AdminSuccessStoryResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->recordConsent(
            $story,
            (string) $request->validated('consent_status'),
            $actor,
        );

        return AdminSuccessStoryResource::make($updated);
    }

    #[OA\Post(
        path: '/v1/admin/success-stories/{story}/submit',
        summary: 'Submit a draft for review',
        tags: ['Admin - Success Stories'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'story', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Story submitted for review'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 404, description: 'Story not found or outside scope'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ],
    )]
    public function submit(Request $request, SuccessStory $story): AdminSuccessStoryResource
    {
        Gate::authorize('submit', $story);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->submit($story, $actor);

        return AdminSuccessStoryResource::make($updated);
    }

    #[OA\Post(
        path: '/v1/admin/success-stories/{story}/publish',
        summary: 'Publish a story under review',
        tags: ['Admin - Success Stories'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'story', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Story published'),
            new OA\Response(response: 403, description: 'Permission denied or author attempted self-publication'),
            new OA\Response(response: 404, description: 'Story not found or outside scope'),
            new OA\Response(response: 422, description: 'Story not under review or consent invalid'),
        ],
    )]
    public function publish(Request $request, SuccessStory $story): AdminSuccessStoryResource
    {
        Gate::authorize('publish', $story);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->publish($story, $actor);

        return AdminSuccessStoryResource::make($updated);
    }

    #[OA\Post(
        path: '/v1/admin/success-stories/{story}/archive',
        summary: 'Archive a success story',
        tags: ['Admin - Success Stories'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'story', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Story archived'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 404, description: 'Story not found or outside scope'),
            new OA\Response(response: 422, description: 'Story already archived'),
        ],
    )]
    public function archive(Request $request, SuccessStory $story): AdminSuccessStoryResource
    {
        Gate::authorize('archive', $story);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->archive($story, $actor);

        return AdminSuccessStoryResource::make($updated);
    }
}
