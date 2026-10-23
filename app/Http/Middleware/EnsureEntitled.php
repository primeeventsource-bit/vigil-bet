<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Entitlements\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side enforcement of a feature, on the route.
 *
 * §4 asks for this explicitly: checks enforced in policies and actions,
 * "not only hidden in the UI". Hiding a nav item is a courtesy to the
 * customer; this is the part that actually holds, and the acceptance
 * test points a Free user's browser at every Pro route to prove it.
 *
 * A refused HTML request is redirected to the upgrade page rather than
 * 403'd, because a customer on the wrong plan has not done anything
 * wrong. A refused JSON request gets a 403 and no data - an API caller
 * following a redirect into a marketing page is a worse experience than
 * an honest status code.
 */
class EnsureEntitled
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if ($this->entitlements->can($request->user(), $feature)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'upgrade_required',
                'feature' => $feature,
                'required_tier' => $this->entitlements->tierRequiredFor($feature),
            ], 403);
        }

        return redirect()->route('upgrade', ['feature' => $feature]);
    }
}
