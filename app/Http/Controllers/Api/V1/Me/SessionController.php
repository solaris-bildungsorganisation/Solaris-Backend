<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserSessionResource;
use App\Models\UserSession;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SessionController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', UserSession::class);

        $sessions = $request->user()->sessions()
            ->active()
            ->latest('last_activity_at')
            ->get();

        return UserSessionResource::collection($sessions);
    }

    public function destroy(Request $request, UserSession $session, SessionService $service)
    {
        Gate::authorize('revoke', $session);

        $service->revoke($session, $request->user(), 'logout');

        return response()->noContent();
    }

    public function destroyOthers(Request $request, SessionService $service)
    {
        Gate::authorize('revokeOthers', UserSession::class);

        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;
        $current = $user->sessions()
            ->where('personal_access_token_id', $currentTokenId)
            ->first();

        $service->revokeAllExcept($user, $current, $user, 'logout');

        return response()->noContent();
    }
}
