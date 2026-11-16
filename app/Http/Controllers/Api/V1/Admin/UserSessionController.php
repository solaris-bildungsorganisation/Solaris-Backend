<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RevokeUserSessionsRequest;
use App\Http\Resources\UserSessionResource;
use App\Models\User;
use App\Models\UserSession;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserSessionController extends Controller
{
    public function index(Request $request, User $user)
    {
        Gate::authorize('viewForUser', [UserSession::class, $user]);

        return UserSessionResource::collection(
            $user->sessions()->active()->latest('last_activity_at')->get()
        );
    }

    public function destroy(RevokeUserSessionsRequest $request, User $user, SessionService $service)
    {
        Gate::authorize('revokeForUser', [UserSession::class, $user]);

        $service->revokeAllExcept($user, null, $request->user(), 'admin_terminated');

        activity('security')
            ->causedBy($request->user())
            ->performedOn($user)
            ->tap(function ($activity) use ($request) {
                $activity->context_id = null;
                $activity->sensitivity = 'sensitive';
                $activity->ip_address = $request->ip();
                $activity->session_id = null;
            })
            ->withProperties(['reason' => $request->validated('reason')])
            ->log('sessions_revoked_by_admin');

        return response()->noContent();
    }
}
