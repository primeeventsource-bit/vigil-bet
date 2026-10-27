<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PropProjection;
use App\Services\Players\PropCalibration;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Projected player props: what the model expects, not what a book posted.
 *
 * Deliberately a separate page from Props, and not a column added to it.
 * That controller states an invariant in its own docblock - props are
 * collected and displayed, never modelled, and no probability or edge
 * may ever appear there - because the Edge Lab display mirror holds
 * quotes the model pipeline is structurally forbidden to read. This is
 * the other pipeline: PropProjector computes a distribution per player
 * per market from box scores, stores it, and settles it against the
 * actual number. Nine thousand of those have been produced and shown to
 * nobody.
 *
 * Gated on `props.projected`, which only Elite grants. It is the second
 * of the two things that let Elite claim anything Pro did not already
 * hold.
 *
 * The rule this page lives by: a model's probability is worth nothing
 * without its calibration beside it. Everywhere else in this product
 * that rule is enforced by the verdict gate, which refuses to publish
 * from a market whose confidence has not been measured. Props have the
 * same machinery - PropCalibration grades settled projections - so the
 * measurement is rendered on the page rather than left implied, and the
 * page says plainly when there is not enough graded yet to claim one.
 */
class PropProjectionController extends Controller
{
    /** Below this many games, a distribution is a guess with error bars. */
    private const MINIMUM_GAMES = 8;

    public function __invoke(Request $request, PropCalibration $calibration): View
    {
        $sport = (string) $request->query('sport', '');

        $sports = PropProjection::query()
            ->select('sport')->distinct()->orderBy('sport')->pluck('sport')->all();

        $sport = in_array($sport, $sports, true) ? $sport : '';

        $projections = PropProjection::query()
            ->with('event:id,sport,starts_at,home_team_id,away_team_id')
            ->awaitingSettlement()
            ->where('games', '>=', self::MINIMUM_GAMES)
            ->when($sport !== '', fn ($q) => $q->where('sport', $sport))
            ->orderByDesc('games')
            ->paginate(60)
            ->withQueryString();

        /*
         * How the model has actually done, per market. Rendered beside
         * the projections rather than on a page of its own, because a
         * reader who has to go and find the calibration will not.
         */
        $measured = $calibration->byMarket(null, $sport !== '' ? $sport : null);

        return view('app.prop-projections', [
            'projections' => $projections,
            'sports' => $sports,
            'sport' => $sport,
            'measured' => $measured,
            'overall' => $calibration->overall(null, $sport !== '' ? $sport : null),
            'minimumGames' => self::MINIMUM_GAMES,
        ]);
    }
}
