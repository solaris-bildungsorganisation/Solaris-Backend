<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenId = $request->user()?->currentAccessToken()?->id;

        if (! $tokenId) {
            return $next($request);
        }

        $session = UserSession::where('personal_access_token_id', $tokenId)->first();

        // ما في سجل جلسة لهالتوكن: منكمل (توكنات قديمة قبل نظام الجلسات)
        if (! $session) {
            return $next($request);
        }

        if ($session->revoked_at || ($session->expires_at && $session->expires_at->isPast())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // تحديث مرة بالدقيقة كحد أقصى
        if (! $session->last_activity_at || $session->last_activity_at->diffInSeconds(now()) >= 60) {
            $session->forceFill(['last_activity_at' => now()])->save();
        }

        return $next($request);
    }
}
