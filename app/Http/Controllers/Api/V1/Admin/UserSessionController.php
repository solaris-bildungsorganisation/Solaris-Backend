<?php

namespace App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RevokeUserSessionsRequest;
use App\Http\Resources\UserSessionResource;
use App\Models\User;
use App\Models\UserSession;
use App\Services\SessionService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;
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

   public function destroy(RevokeUserSessionsRequest $request, User $user, SessionService $service, NotificationService $notificationService)
    {
        Gate::authorize('revokeForUser', [UserSession::class, $user]);
$activeSessionsCount = $user->sessions()->active()->count();
        $service->revokeAllExcept($user, null, $request->user(), 'admin_terminated');
try {
    if ($activeSessionsCount > 0) {
        $notificationService->send($user, 'security.sessions_terminated');
    }
} catch (Throwable $e) {
    Log::warning('Failed to send sessions terminated notification.', [
        'user_id' => $user->id,
        'error' => $e->getMessage(),
    ]);
}
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
