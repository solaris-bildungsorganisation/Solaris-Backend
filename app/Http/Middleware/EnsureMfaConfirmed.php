<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureMfaConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        Log::warning('MFA check skipped (placeholder middleware)', [
            'user_id' => $request->user()?->id,
            'path' => $request->path(),
        ]);

        return $next($request);
    }
}
