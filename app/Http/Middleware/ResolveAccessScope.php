<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class ResolveAccessScope
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $scopeId = $user->currentSession()?->active_scope_id;
            if (!empty($scopeId)) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($scopeId);
            }
        }

        return $next($request);
    }
}
