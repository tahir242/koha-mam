<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureStaffSession;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? explode(',', env('TRUSTED_PROXIES')) : null);
        $middleware->prepend(AssignRequestId::class);
        $middleware->web(append: [SecurityHeaders::class]);
        $middleware->alias(['staff' => EnsureStaffSession::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->encryptCookies();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontFlash(['signature']);
    })->create();
