<?php

use App\Http\Middleware\RequiresSetup;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ShiftRequired;
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
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            RequiresSetup::class,
            SetLocale::class,
        ]);

        // Setup has to be decided before authentication is. Otherwise `auth`
        // wins on protected routes and bounces an unprovisioned machine to a
        // login screen that has no account to accept — it recovers on the next
        // hop, but only by accident.
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            RequiresSetup::class,
        );

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'shift' => ShiftRequired::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
