<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlayerRating;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Player IQ: the ratings the engine has been computing and never showing.
 *
 * The page is gated on `playeriq`, which only Elite grants, and it is
 * one of the two things that let Elite claim anything true - Pro already
 * held every other feature key in the product.
 *
 * What this page must not do is imply more than a percentile. The
 * calculator measures a player's production against others in the same
 * position from box scores on file; it is not a scouting grade and it is
 * not the number a video game would show. Every figure here travels with
 * its sample size and its date for that reason, and the view says so in
 * words as well.
 */
class PlayerIqController extends Controller
{
    /** How few games makes a percentile noise rather than a reading. */
    private const MINIMUM_GAMES = 5;

    public function index(Request $request): View
    {
        $sport = (string) $request->query('sport', '');
        $search = trim((string) $request->query('q', ''));

        $sports = PlayerRating::query()
            ->select('sport')
            ->distinct()
            ->orderBy('sport')
            ->pluck('sport')
            ->all();

        // An unrecognised sport in the query string shows everything
        // rather than nothing, so a stale bookmark is not a dead page.
        $sport = in_array($sport, $sports, true) ? $sport : '';

        $ratings = PlayerRating::query()
            ->with('team:id,display_name,abbreviation')
            ->ranked(self::MINIMUM_GAMES)
            ->when($sport !== '', fn ($q) => $q->forSport($sport))
            ->when($search !== '', fn ($q) => $q->where('player_name', 'like', '%'.$search.'%'))
            ->paginate(50)
            ->withQueryString();

        return view('app.player-iq', [
            'ratings' => $ratings,
            'sports' => $sports,
            'sport' => $sport,
            'search' => $search,
            'minimumGames' => self::MINIMUM_GAMES,
            /*
             * The newest rating anywhere, which dates the page. Taken
             * from the whole table rather than the current filter, so
             * narrowing to one sport cannot make the data look fresher
             * than it is.
             *
             * Parsed rather than passed through: an aggregate comes
             * back as a raw string, and Clock takes a date because
             * formatting a string would mean guessing its timezone.
             */
            'asOf' => ($newest = PlayerRating::query()->max('as_of'))
                ? Carbon::parse($newest)
                : null,
        ]);
    }

    public function show(string $sport, string $player): View
    {
        $rating = PlayerRating::query()
            ->with('team:id,display_name,abbreviation')
            ->forSport($sport)
            ->where('player_external_id', $player)
            ->firstOrFail();

        /*
         * Where they sit among their own position, which is the only
         * comparison the rating actually makes. Comparing a goaltender
         * to a winger would be a different claim and a meaningless one.
         */
        $peers = PlayerRating::query()
            ->with('team:id,display_name,abbreviation')
            ->forSport($sport)
            ->where('peer_group', $rating->peer_group)
            ->ranked(self::MINIMUM_GAMES)
            ->limit(10)
            ->get();

        $peerCount = PlayerRating::query()
            ->forSport($sport)
            ->where('peer_group', $rating->peer_group)
            ->where('games', '>=', self::MINIMUM_GAMES)
            ->count();

        return view('app.player-iq-detail', [
            'rating' => $rating,
            'peers' => $peers,
            'peerCount' => $peerCount,
            'minimumGames' => self::MINIMUM_GAMES,
        ]);
    }
}
