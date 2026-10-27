<?php

use App\Http\Middleware\Auth\AuthenticateCookie;
use App\Http\Middleware\Auth\EnsurePermission;
use App\Http\Middleware\Auth\EnsureRole;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // El JWT va en claro en la cookie: si Laravel la cifra, auth.cookie no puede decodificarlo.
        $middleware->encryptCookies(except: [JwtService::COOKIE]);

        $middleware->alias([
            'auth.cookie' => AuthenticateCookie::class,
            'role' => EnsureRole::class,
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
