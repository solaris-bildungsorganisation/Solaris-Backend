<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Requests\Me\ChangePasswordRequest;
use App\Models\AuthEvent;
use App\Models\PasswordHistory;
use App\Models\UserSession;
use App\Services\SessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PasswordController extends Controller
{
    public function __invoke(ChangePasswordRequest $request, SessionService $sessionService)
    {
        $user = $request->user();
        $newPassword = $request->string('password')->toString();
        $currentToken = $user?->currentAccessToken();
        $currentSession = $currentToken
            ? UserSession::query()->where('personal_access_token_id', $currentToken->getKey())->first()
            : null;

        if (! $user || ! Hash::check($request->string('current_password')->toString(), $user->getRawOriginal('password'))) {
            return $this->invalidPasswordResponse();
        }

        if (Hash::check($newPassword, $user->getRawOriginal('password'))) {
            return $this->invalidPasswordResponse();
        }

        $recentHistories = PasswordHistory::query()
            ->where('user_id', $user->getKey())
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get();

        foreach ($recentHistories as $history) {
            if (Hash::check($newPassword, $history->password_hash)) {
                return $this->invalidPasswordResponse();
            }
        }

        DB::transaction(function () use ($request, $user, $newPassword): void {
            PasswordHistory::query()->create([
                'user_id' => $user->getKey(),
                'password_hash' => $user->getRawOriginal('password'),
            ]);

            $user->forceFill([
                'password' => $newPassword,
                'password_changed_at' => now(),
                'must_change_password' => false,
            ])->save();

            AuthEvent::query()->create([
                'user_id' => $user->getKey(),
                'event' => 'password_changed',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        $sessionService->revokeAllExcept($user, $currentSession, $user, 'password_changed');

        return response()->json([
            'message' => 'تم تغيير كلمة المرور بنجاح.',
        ]);
    }

    private function invalidPasswordResponse()
    {
        return response()->json([
            'message' => 'تعذر تغيير كلمة المرور بالبيانات المقدمة.',
        ], 422);
    }
}
