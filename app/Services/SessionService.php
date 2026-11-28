<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

class SessionService
{
    public function start(User $user, NewAccessToken $token, Request $request): UserSession
    {
        return $user->sessions()->create([
            'personal_access_token_id' => $token->accessToken->id,
            'device_name' => $request->input('device_name'),
            'platform' => $request->input('platform'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_activity_at' => now(),
            'expires_at' => now()->addDays(config('solaris.session_lifetime_days')),
        ]);
    }

    public function revoke(UserSession $session, ?User $by, string $reason): void
    {
        DB::transaction(function () use ($session, $by, $reason) {
            $session->accessToken?->delete();
            $session->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $by?->id,
                'revoke_reason' => $reason,
            ])->save();
        });
    }
public function revokeAllExcept(User $user, ?UserSession $keep, ?User $by, string $reason): void
    {
        $user->sessions()->whereNull('revoked_at')
            ->when($keep, fn ($q) => $q->where('id', '!=', $keep->id))
            ->get()
            ->each(fn (UserSession $session) => $this->revoke($session, $by, $reason));
    }
}

