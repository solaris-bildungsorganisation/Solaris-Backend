<?php

use App\Http\Middleware\EnsureMfaConfirmed;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Http\Middleware\ResolveAccessScope;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias([
            'session.valid' => EnsureSessionIsValid::class,
            'mfa.confirmed' => EnsureMfaConfirmed::class,
            'scope.resolve' => ResolveAccessScope::class,
        ]);

        // B4: كل طلب API يضبط نطاق الصلاحيات (العام افتراضيًا أو active_scope_id من الجلسة).
        // الأولوية تضمن تشغيله بعد auth:sanctum حتى يكون المستخدم معروفًا.
        $middleware->api(append: [ResolveAccessScope::class]);
        $middleware->appendToPriorityList(AuthenticatesRequests::class, ResolveAccessScope::class);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
