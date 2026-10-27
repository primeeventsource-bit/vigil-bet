<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * No second factor, no admin pages.
 *
 * The spec requires MFA for administrative access, and the honest way to
 * require something is to refuse without it rather than to recommend it
 * in a banner nobody reads.
 *
 * Three things this deliberately does not do:
 *
 * - It does not touch customers. It is applied to the admin group only,
 *   so a member without 2FA uses the product exactly as before. Forcing
 *   it on everybody would be a different product decision, made here by
 *   accident.
 *
 * - It does not block the page that fixes it. Somebody sent here has to
 *   be able to reach two-factor.show, enrol, and come back, so the
 *   enrolment routes are excluded by name rather than by prefix.
 *
 * - It does not log anybody out. An administrator who enables 2FA
 *   mid-session continues in the same session; the check reads the
 *   column, which is already true by then.
 *
 * The practical consequence, and the reason this is safe to ship against
 * a live site where nobody has 2FA on: the first admin to hit /admin
 * after deploy is redirected to enrolment, not locked out. The console
 * remains a way back regardless.
 */
class RequireAdminTwoFactor
{
    /**
     * Routes an un-enrolled administrator must still be able to reach,
     * or the redirect is a loop.
     *
     * @var array<int, string>
     */
    private const ALLOWED = [
        'two-factor.show',
        'two-factor.store',
        'two-factor.confirm',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if (in_array((string) $request->route()?->getName(), self::ALLOWED, true)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'two_factor_required',
                'message' => 'Administrative access requires two-factor authentication.',
            ], 403);
        }

        return redirect()->route('two-factor.show')->with(
            'error',
            'Administrative access requires two-factor authentication. Set it up to continue.'
        );
    }
}
