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
            'active_scope_id'=>config('solaris.global_scope_id'),
            'expires_at' => now()->addDays(config('solaris.session_lifetime_days')),
        ]);
    }

    /** جلسة التوكن الحالي للطلب (null للتوكنات القديمة قبل نظام الجلسات). */
    public function current(User $user): ?UserSession
    {
        $tokenId = $user->currentAccessToken()?->id;

        return $tokenId
            ? $user->sessions()->where('personal_access_token_id', $tokenId)->first()
            : null;
    }

    public function revoke(UserSession $session, ?User $by, string $reason): void
    {
        DB::transaction(function () use ($session, $by, $reason) {
            $session->accessToken?->delete();
            $session->update([
                'revoked_at' => now(),
                'revoked_by' => $by?->id,
                'revoke_reason' => $reason,
            ]);
        });
    }

    public function revokeAllExcept(User $user, ?UserSession $keep, ?User $by, string $reason): void
    {
        $user->sessions()->active()
            ->when($keep, fn ($q) => $q->where('id', '!=', $keep->id))
            ->get()
            ->each(fn (UserSession $s) => $this->revoke($s, $by, $reason));
    }
}
