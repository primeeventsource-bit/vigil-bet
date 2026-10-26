<?php

declare(strict_types=1);

namespace App\Services\Value;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Who is unavailable for a game, from the live injury reports.
 *
 * The engine's probability is a power rating and has never seen an injury
 * report. The market has: a book moves its number within minutes of a
 * quarterback being ruled out. So an absence is information the market
 * holds and the model does not, and the honest response is not to invent
 * a points-per-injury coefficient - it is to disagree with the market
 * less on exactly those games.
 *
 * What counts is the imbalance, not the count. Measured on 25 October
 * 2026, every upcoming NFL game had between two and six players out per
 * side; that is ordinary attrition and it roughly cancels in a
 * head-to-head price. Indianapolis at Minnesota was five against none,
 * and that does not cancel at all.
 *
 * Only reports filed before kickoff count, and only the latest one per
 * player. A feed restates a player's status every day, so counting rows
 * would make a long-term absence look like a crisis.
 */
class Availability
{
    /** Statuses that mean the player is not expected to take part. */
    private const ABSENT = ['out', 'doubtful'];

    /**
     * How stale a report may be and still describe this week.
     *
     * A fortnight covers a normal injury cycle without dragging in a
     * player who was ruled out two months ago and has long since
     * returned - a feed that stops mentioning somebody is how it says
     * they are back.
     */
    private const WINDOW_DAYS = 14;

    /** @var array<int, array{home: int, away: int}> */
    private array $memo = [];

    /** @var array<int, array{home: float, away: float}> */
    private array $weighted = [];

    /**
     * Players ruled out per side.
     *
     * @return array{home: int, away: int}
     */
    public function absences(Event $event): array
    {
        if (array_key_exists($event->id, $this->memo)) {
            return $this->memo[$event->id];
        }

        $counts = ['home' => 0, 'away' => 0];

        if ($event->home_team_id === null || $event->away_team_id === null) {
            return $this->memo[$event->id] = $counts;
        }

        /*
         * `reported_at < starts_at` is the leakage rule, and it is not
         * decoration: a scan re-run over a finished game would otherwise
         * count the injury report filed at half time and look
         * clairvoyant.
         */
        $rows = DB::table('injury_reports as i')
            ->whereIn('i.team_id', [$event->home_team_id, $event->away_team_id])
            ->where('i.sport', $event->sport->value)
            ->where('i.reported_at', '<', $event->starts_at)
            ->where('i.reported_at', '>', $event->starts_at->copy()->subDays(self::WINDOW_DAYS))
            ->whereNotExists(function ($query): void {
                // The latest report for this player wins, so a player
                // restated daily is counted once.
                $query->selectRaw('1')
                    ->from('injury_reports as later')
                    ->whereColumn('later.team_id', 'i.team_id')
                    ->whereColumn('later.player_name', 'i.player_name')
                    ->whereColumn('later.reported_at', '>', 'i.reported_at');
            })
            ->whereIn('i.status', self::ABSENT)
            ->get(['i.team_id', 'i.position']);

        $weighted = ['home' => 0.0, 'away' => 0.0];

        foreach ($rows as $row) {
            $side = (int) $row->team_id === (int) $event->home_team_id ? 'home' : 'away';
            $counts[$side]++;
            $weighted[$side] += self::weightFor($row->position);
        }

        $this->weighted[$event->id] = $weighted;

        return $this->memo[$event->id] = $counts;
    }

    /**
     * How lopsided the absences are.
     *
     * Six out against six is a hard week for both teams and close to no
     * information about who wins. Five against none is the whole story,
     * and the price already reflects it.
     */
    public function imbalance(Event $event): int
    {
        $counts = $this->absences($event);

        return abs($counts['home'] - $counts['away']);
    }

    /**
     * The same, counting what the position is worth rather than bodies.
     *
     * A head count says a quarterback and a third tight end are the
     * same event. The market does not price them the same way, and the
     * whole purpose of this number is to say how much the market knows
     * that the rating does not - so counting them alike understates the
     * one case that matters most and overstates the ordinary ones.
     *
     * The weights are a policy, not a measurement, and are named as one
     * in config. They never become a points-per-injury coefficient: the
     * only thing they do is pull the published number toward the book,
     * which is a statement about our own ignorance rather than a claim
     * about the game.
     */
    public function weightedImbalance(Event $event): float
    {
        $this->absences($event);

        $weighted = $this->weighted[$event->id] ?? ['home' => 0.0, 'away' => 0.0];

        return abs($weighted['home'] - $weighted['away']);
    }

    /**
     * What one absence at this position counts for.
     *
     * Unknown and unlisted positions fall back to the ordinary weight
     * rather than to zero: a feed that stops sending a position should
     * make this less certain, not more.
     */
    private static function weightFor(?string $position): float
    {
        $weights = (array) config('edge.value.absence_position_weights', []);
        $default = (float) ($weights['*'] ?? 1.0);

        if ($position === null || $position === '') {
            return $default;
        }

        return (float) ($weights[mb_strtoupper($position)] ?? $default);
    }
}
