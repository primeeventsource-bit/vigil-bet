<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Admin Management pages, which only a Super Admin may see.
 *
 * 404 rather than 403, matching EnsureAdmin exactly. A 403 tells an
 * administrator that there is a page here they are not allowed to open,
 * which is information they do not need and the beginning of a question
 * they will go looking for an answer to. A 404 says nothing.
 *
 * That choice is enumerated route by route in the admin tests, so it has
 * to agree with EnsureAdmin rather than with Spatie's middleware, which
 * aborts 403.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isSuperAdmin() === true, 404);

        return $next($request);
    }
}
