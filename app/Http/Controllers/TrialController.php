<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Trial\TrialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Starting the 24 hours.
 *
 * Activation is an explicit act, not a side effect of registering or of
 * confirming an address. That is a product decision with a practical
 * reason behind it: somebody who signs up at midnight and comes back on
 * Saturday should get their day on Saturday, rather than having spent it
 * asleep.
 *
 * Both routes sit inside the `verified` group, so the spec's
 * "registration and verified email before accessing the trial" is
 * enforced by the same middleware that guards the rest of the product
 * rather than by a check in here that somebody could forget to copy.
 * The service checks verification again anyway, because it is the thing
 * writing the row.
 */
class TrialController extends Controller
{
    public function show(Request $request, TrialService $trials): View
    {
        $user = $request->user();

        return view('app.trial-start', [
            'eligible' => $trials->eligible($user),
            'active' => $trials->active($user),
            'expired' => $trials->expired($user),
            'hours' => $trials->hours(),
            'expiresAt' => $trials->remaining($user),

            /*
             * Why they are being refused, when they are. "You cannot
             * start a trial" with no reason is the kind of message that
             * generates a support ticket nobody can answer.
             */
            'reason' => match (true) {
                $user->onTrial() => 'running',
                $user->trialExpired() => 'spent',
                ! $user->hasVerifiedEmail() => 'unverified',
                $trials->redeemed($user->email) => 'redeemed',
                default => null,
            },
        ]);
    }

    public function activate(Request $request, TrialService $trials): RedirectResponse
    {
        try {
            $trials->activate($request->user(), $request);
        } catch (RuntimeException $e) {
            /*
             * The service's own words. Every message it throws is
             * written to be read by the person it happened to - "this
             * account has already used its trial" is an answer, where
             * "could not start trial" is the beginning of an argument.
             */
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('today')->with(
            'status',
            'Your '.$trials->hours().'-hour trial has started. No card was taken and nothing will be charged.'
        );
    }
}
