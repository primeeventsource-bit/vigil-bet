<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SelfExclusion as SelfExclusionModel;
use App\Services\Safety\SelfExclusion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Responsible gambling: self-exclusion.
 *
 * There are two methods here and neither of them ends an exclusion. §9
 * says it cannot be undone early, so there is no route that could - not
 * for the customer, and not for support either. An operator who can be
 * talked into lifting it is the same hole with an extra step.
 */
class SafetyController extends Controller
{
    public function show(Request $request, SelfExclusion $exclusions): View
    {
        return view('app.safety', [
            'durations' => SelfExclusionModel::DURATIONS,
            'acknowledgements' => collect(SelfExclusionModel::DURATIONS)
                ->mapWithKeys(fn (int $days) => [$days => $exclusions->acknowledgement($days)]),
            'history' => $exclusions->history($request->user()),
        ]);
    }

    public function exclude(Request $request, SelfExclusion $exclusions): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', Rule::in(SelfExclusionModel::DURATIONS)],
            /*
             * The acknowledgement has to be ticked, and the exact
             * sentence is checked rather than just a boolean - so the
             * record says what the customer agreed to, not merely that
             * they agreed to something.
             */
            'acknowledged' => ['required', 'accepted'],
        ]);

        $exclusions->start($request->user(), (int) $data['days']);

        /*
         * No flash message and no redirect back. The next request hits
         * the middleware, which signs them out and sends them to the
         * page explaining when it lifts - which is the only thing they
         * should see from here.
         */
        return redirect()->route('today');
    }

    /**
     * The page a locked-out customer lands on.
     *
     * Public, because by the time they see it they have been signed out.
     * It says when the lock lifts and gives the helpline, and it
     * deliberately offers no way to shorten it and no link back into the
     * product.
     */
    public function excluded(): View
    {
        return view('safety.excluded', [
            'exclusion' => session('exclusion'),
        ]);
    }
}
