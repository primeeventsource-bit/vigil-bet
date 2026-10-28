<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Trial\TrialReport;
use App\Services\Trial\TrialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trials, and what became of them.
 *
 * Follows the split the rest of the admin area already uses: this reads,
 * and the two writes at the bottom each take a typed reason of at least
 * eight characters and write an audit row, through the same component
 * and the same validation rule as every other administrative write.
 *
 * Granting a trial to somebody who has had one does not delete their
 * redemption - that table is append-only on purpose. It writes a new one
 * marked as an operator grant, so the history stays true and the
 * exception is visible as an exception rather than as an absence.
 */
class TrialController extends Controller
{
    public function index(Request $request, TrialReport $report): View
    {
        return view('admin.trials', [
            'summary' => $report->summary(),
            'running' => $report->running(),
            'ended' => $report->ended(),
        ]);
    }

    /**
     * Give somebody a trial, or more of one.
     *
     * Deliberately one action rather than "grant" and "extend". Both
     * end at the same place - a new expiry in the future - and two
     * routes doing one thing is two places for the audit entry to
     * differ.
     */
    public function grant(Request $request, User $user, TrialService $trials): RedirectResponse
    {
        // Managing a customer's access, which is what this is, sits
        // under the same permission as the rest of account management.
        $this->authorize('update', $user);

        $data = $request->validate([
            'hours' => ['required', 'integer', 'min:1', 'max:720'],
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $trials->grant($user, (int) $data['hours'], $request->user(), $data['reason']);

        return back()->with(
            'status',
            $data['hours'].' hours of trial granted to '.$user->email.'.'
        );
    }
}
