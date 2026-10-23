<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Safety\SelfExclusion;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a self-excluded customer out.
 *
 * Signs them out rather than showing a notice on the page, because §9's
 * promise is a lock and a notice is a nag. The session is invalidated too,
 * so an open tab in another window is not a way back in.
 *
 * Applied to the whole authenticated area rather than to the pages that
 * show odds. A customer who has locked themselves out should not be able
 * to read their own ROI either - the point is distance from the product,
 * not distance from one page of it.
 */
class EnforceSelfExclusion
{
    public function __construct(private readonly SelfExclusion $exclusions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $exclusion = $this->exclusions->active($request->user());

        if ($exclusion === null) {
            return $next($request);
        }

        /*
         * Logged out first, then redirected. The other order would leave
         * a valid session behind for as long as the customer did not
         * follow the redirect.
         */
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('excluded')->with('exclusion', [
            'ends_at' => $exclusion->ends_at->toIso8601String(),
            'remaining' => $exclusion->remaining(),
            'days' => $exclusion->days,
        ]);
    }
}
