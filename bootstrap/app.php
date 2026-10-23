<?php

use App\Http\Middleware\EnsureEntitled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Feature gating on the route itself. The nav hides what a
         * customer cannot use; this is the part that actually holds when
         * somebody edits the URL.
         */
        $middleware->alias(['entitled' => EnsureEntitled::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
