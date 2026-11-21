<?php

namespace App\Http\Middleware;

use App\Services\Rbac\AccessResolver;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * يقرأ active_scope_id من جلسة المستخدم (user_sessions) ويضبط setPermissionsTeamId().
 * - بدون نطاق فعّال → النطاق العام افتراضيًا (قاعدة المصفوفة 2).
 * - يفرّغ AccessResolver في بداية كل طلب حتى لا تُستخدم صلاحيات محسوبة لطلب سابق.
 *
 * مسجّل على مجموعة api ويعمل بعد auth:sanctum (أولوية في bootstrap/app.php).
 */
class ResolveAccessScope
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
        private readonly AccessResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->resolver->flush();

        $scopeId = config('solaris.global_scope_id');

        if ($user = $request->user()) {
            $scopeId = $user->currentSession()?->active_scope_id ?: $scopeId;
        }

        $this->registrar->setPermissionsTeamId($scopeId);

        return $next($request);
    }
}
