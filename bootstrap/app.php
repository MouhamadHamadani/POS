<?php

use App\Http\Middleware\ActivationGate;
use App\Http\Middleware\DemoGate;
use App\Http\Middleware\FeatureGate;
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
            ActivationGate::class,
        ]);

        // Setup has to be decided before authentication is. Otherwise `auth`
        // wins on protected routes and bounces an unprovisioned machine to a
        // login screen that has no account to accept — it recovers on the next
        // hop, but only by accident.
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            RequiresSetup::class,
        );

        // Same for the trial lock: a signed-out visitor to /pos should land on
        // the lock screen, not on a login form that leads to it. Listed after
        // RequiresSetup, so setup is still decided first.
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            ActivationGate::class,
        );

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'shift' => ShiftRequired::class,
            'demo' => DemoGate::class,
            'feature' => FeatureGate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
