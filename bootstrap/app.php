<?php

use App\Http\Middleware\EnforceSelfExclusion;
use App\Http\Middleware\EnsureAdmin;
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
        $middleware->alias([
            'entitled' => EnsureEntitled::class,
            'admin' => EnsureAdmin::class,
        ]);

        /*
         * Self-exclusion, on every authenticated request. §9 promises a
         * lock, and a lock enforced per page is a lock with as many
         * holes as there are pages somebody forgot - so it runs in the
         * web group rather than being applied route by route.
         */
        $middleware->appendToGroup('web', EnforceSelfExclusion::class);

        /*
         * Payment processors do not carry CSRF tokens, and cannot: a
         * webhook is a server talking to a server. What stands in for
         * the token is a signature the driver verifies over the raw
         * body before anything is parsed, and an event id that can only
         * be used once.
         */
        $middleware->validateCsrfTokens(except: [
            'webhooks/payments/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
