<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a suspended account out.
 *
 * Deliberately the same shape as EnforceSelfExclusion, which has been
 * doing this job correctly since launch: sign out first, invalidate the
 * session, regenerate the token, then redirect. The other order leaves a
 * live session behind for as long as the person declines to follow the
 * redirect, which is exactly as long as they want.
 *
 * Why this exists at all: legal/terms.blade.php has promised since launch
 * that an account may be suspended, and until now nothing in the code
 * could do it. A term that cannot be enforced is not a term.
 *
 * The distinction from self-exclusion is worth keeping straight, because
 * they end in the same locked door for different reasons. Self-exclusion
 * is the customer's own decision and no operator can shorten it - that is
 * the point of it. Suspension is the operator's decision about an account
 * and an operator can lift it. Two mechanisms, two doors, no shared
 * switch that could lift the wrong one.
 *
 * Runs in the web group alongside the exclusion check, for the same
 * reason: a lock applied page by page has as many holes as there are
 * pages somebody forgot.
 */
class EnforceSuspension
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isSuspended()) {
            return $next($request);
        }

        $reason = (string) ($user->suspended_reason ?? '');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        /*
         * Back to sign-in with a sentence rather than to a page of its
         * own. A suspended account is a conversation with support, not a
         * state to be browsed - and a dedicated page would be a public
         * URL that confirms which addresses are suspended.
         */
        return redirect()->route('login')->with('error', $reason !== ''
            ? 'This account is suspended: '.$reason
            : 'This account is suspended. Contact support.');
    }
}
