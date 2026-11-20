<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationService $service)
    {
        Gate::authorize('viewAny', DatabaseNotification::class);

        $data = $request->validate([
            'unread' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $notifications = $service->list(
            $request->user(),
            (bool) ($data['unread'] ?? false),
            $data['category'] ?? null,
            $data['per_page'] ?? 15,
        );

        return NotificationResource::collection($notifications);
    }

    public function unreadCount(Request $request, NotificationService $service)
    {
        Gate::authorize('viewAny', DatabaseNotification::class);

        return response()->json([
            'data' => ['count' => $service->unreadCount($request->user())],
        ]);
    }

    public function markRead(Request $request, string $id, NotificationService $service)
    {
        Gate::authorize('viewAny', DatabaseNotification::class);

        $notification = $service->find($request->user(), $id);

        return new NotificationResource($service->markRead($notification));
    }

    public function readAll(Request $request, NotificationService $service)
    {
        Gate::authorize('viewAny', DatabaseNotification::class);

        $service->markAllRead($request->user());

        return response()->noContent();
    }

    public function destroy(Request $request, string $id, NotificationService $service)
    {
        Gate::authorize('viewAny', DatabaseNotification::class);

        $notification = $service->find($request->user(), $id);
        $service->delete($notification);

        return response()->noContent();
    }
}
