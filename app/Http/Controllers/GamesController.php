<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Odds\GameMarkets;
use App\Services\Parlay\ParlaySlip;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Today's games, and every market a customer can click.
 *
 * This page exists because the parlay builder had nothing to build from.
 * It took its legs from the engine's board, and the board only offered
 * picks from markets the engine had proven - of 24,610 candidates it had
 * evaluated, none. So the page rendered an empty list and every click was
 * refused, which looked like a broken front end and was a deliberate
 * restriction doing exactly what it was written to do.
 *
 * The restriction is right for VIGIL's own opinions and wrong as a gate on
 * somebody recording their own bet. So the two are separated: the board
 * stays a read-only record of what the engine decided and why, and this
 * is the betting surface. It is built from odds snapshots - what the books
 * are actually offering - which is why the engine's largest rejection
 * reason, having no price it would act on, does not apply here. A market
 * with no price does not appear at all.
 */
class GamesController extends Controller
{
    public function index(Request $request, GameMarkets $markets, ParlaySlip $slip): View
    {
        $sport = $request->query('sport');
        $sports = $markets->sportsWithGames();

        /*
         * Validated against what exists rather than against a pattern. A
         * sport nobody has a game in today would render an empty page
         * that looked like a fault.
         */
        if (! is_string($sport) || ! $sports->contains($sport)) {
            $sport = null;
        }

        return view('app.games', [
            'games' => $markets->upcoming($sport),
            'sports' => $sports,
            'sport' => $sport,
            // The keys already on the slip, so a selection the customer
            // has taken renders as taken rather than as available.
            'onSlip' => $slip->keys(),
            'slipCount' => $slip->count(),
            'maxLegs' => ParlaySlip::MAX_LEGS,
        ]);
    }
}
