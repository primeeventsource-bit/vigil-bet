<?php

use App\Http\Middleware\EnforceSelfExclusion;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureEntitled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        /*
         * A form that sat open longer than the session it was issued
         * against. Laravel's own answer is a black page reading "419
         * PAGE EXPIRED" - no heading, no link, nothing to press - and it
         * arrives at the moment somebody has just typed their password,
         * so what it most looks like is a site that is broken.
         *
         * Everywhere else this app treats an expiry as an ordinary thing
         * that happens to people: a half-finished second factor says the
         * attempt expired and sends them back to start again. A stale
         * token is the same event, and now reads the same way.
         *
         * Caught as the HttpException rather than as the thing that was
         * thrown, because the framework rewrites one into the other
         * before it asks us: a handler typed on TokenMismatchException
         * registers without complaint and is never called. The original
         * is kept as the previous exception, so that is what we read -
         * a bare `abort(419)` from somewhere else is somebody else's
         * meaning and is left alone.
         */
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $e->getPrevious() instanceof TokenMismatchException) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session expired. Reload the page and try again.',
                ], 419);
            }

            /*
             * Back to the page the form was on - but only on our own word
             * for where that is. `Referer` is set by the browser from
             * whatever page did the posting, so a form on somebody else's
             * site can name anywhere at all, and an expired token is a
             * bad moment to hand a stranger a redirect. Anything off-site
             * falls back to sign-in, which is where a lost session leads
             * anyway.
             */
            $referer = (string) $request->headers->get('referer', '');
            $ours = str_starts_with($referer, $request->schemeAndHttpHost().'/');

            return redirect($ours ? $referer : route('login'))
                ->withInput($request->except('password', 'password_confirmation', '_token'))
                ->with('error', 'That page had been open a while and your session expired. Please try again.');
        });
    })->create();
