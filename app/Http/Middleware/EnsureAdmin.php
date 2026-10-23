<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the admin area to whoever runs the product.
 *
 * Returns **404**, not 403. A 403 confirms the page exists, and the admin
 * area is the one part of this application where that is worth hiding:
 * somebody probing for /admin should get the same answer as somebody
 * asking for /nonsense.
 *
 * It is also deliberately the last check rather than the first - a signed
 * out visitor hits the login first, because a stranger should not be able
 * to tell a non-admin account from a non-existent page either.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin() === true, 404);

        return $next($request);
    }
}
