<?php

use App\Http\Middleware\ActivationGate;
use App\Http\Middleware\DemoGate;
use App\Http\Middleware\FeatureGate;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ShiftRequired;
use App\Support\ErrorPages;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            SetLocale::class,
            ActivationGate::class,
        ]);

        // The trial lock has to be decided before authentication is: a
        // signed-out visitor to /pos should land on the lock screen, not on a
        // login form that leads to it.
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
        // An expired token on a signed-out page goes back to the login page, not to a dead end; every
        // other failed page is the branded one with a Go home button (see App\Support\ErrorPages).
        // (The handler has already turned TokenMismatchException into an HttpException(419) by the time it asks.)
        $exceptions->render(fn (HttpException $e, Request $request) => $e->getStatusCode() === 419 ? ErrorPages::tokenMismatch($request) : null);
        $exceptions->respond(fn (Response $response, Throwable $e, Request $request) => ErrorPages::page($response, $e, $request));
    })->create();
