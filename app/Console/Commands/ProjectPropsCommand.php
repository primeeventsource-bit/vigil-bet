<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VigMethod;
use App\Modeling\Odds\MarketConsensus;
use App\Services\Players\PropProjector;
use App\Services\Players\RatingSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What the box scores say about tonight's prop board.
 *
 * Read-only, and deliberately so at this stage. The projections are not
 * written anywhere, no pick is produced and nothing a customer can reach
 * changes, because a projection model that has never been graded has
 * earned none of that - the same rule the game markets live under, where
 * only a model that beats the closing line is allowed to claim an edge.
 *
 * What this is for is finding out whether the model is worth grading. It
 * reports three things, and the second two matter more than the first:
 *
 *   coverage  - how many priced props can be projected at all, and for
 *               the rest, which of the four reasons stopped it. A count
 *               of failures says the feature is broken without saying
 *               which part.
 *
 *   bias      - the average gap between the projection and the line,
 *               per market. This is the number that catches the failure
 *               mode the projector was built to avoid: a model shrunk
 *               toward a low pool reads as sober on every single row
 *               while saying "under" on all of them, and only the
 *               average over the board shows it.
 *
 *   agreement - how far the distribution's answer sits from the
 *               distribution-free one. Large means the family is
 *               fighting the data rather than describing it.
 */
class ProjectPropsCommand extends Command
{
    protected $signature = 'edge:props:project
                            {sport? : Sport key; omit for every sport with projectable props}
                            {--market= : One prop market key}
                            {--limit=20 : How many rows to print per market}
                            {--min-edge=0.03 : Only print rows at least this far from the fair price}
                            {--half-life= : Override the recency half-life, in games, for this run}';

    protected $description = 'Project tonight\'s player props from box scores and compare them to the board';

    public function handle(PropProjector $projector): int
    {
        /*
         * Overridable for this run so the bias can be measured at
         * several half-lives without a deploy. Measured rather than
         * chosen: how fast a player's role actually changes is a
         * question about the data, and the answer is whichever setting
         * leaves the least bias against the sharpest price available.
         */
        if ($this->option('half-life') !== null) {
            config()->set('edge.props.recency_half_life_games', (float) $this->option('half-life'));

            $this->line(sprintf('Recency half-life overridden to %s games.', $this->option('half-life')));
        }

        $rows = $this->pricedProps($this->argument('sport'), $this->option('market'));

        if ($rows === []) {
            $this->warn('No priced player props on upcoming games. Pull props first: edge:odds:props');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d priced props on upcoming games.', count($rows)));

        $projected = [];
        $declined = [];

        foreach ($rows as $row) {
            $projection = $projector->project($row['sport'], $row['market_key'], $row['player_name'], $row['line']);

            if ($projection === null) {
                $reason = $projector->reasonFor($row['sport'], $row['market_key'], $row['player_name'])
                    ?? 'unknown';

                $declined[$row['sport'].'|'.$row['market_key']][$reason][$row['player_name']] = true;

                continue;
            }

            $projected[] = $row + ['projection' => $projection];
        }

        $this->coverage($rows, $projected, $declined);
        $this->bias($projected);
        $this->board($projected);

        return self::SUCCESS;
    }

    /**
     * Every priced prop on a game still to come, with both sides of each
     * line so the vig can be removed.
     *
     * The price is rebuilt from the newest quote per book, per side -
     * two stale quotes from one book are not two prices to shop
     * between, and a quote nobody is still offering is not a price.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pricedProps(?string $sport, ?string $market): array
    {
        $markets = $market !== null ? [$market] : RatingSchema::projectableProps();

        $quotes = DB::table('odds_snapshots as o')
            ->join('events as e', 'e.id', '=', 'o.event_id')
            ->join('books as b', 'b.id', '=', 'o.book_id')
            ->whereIn('o.market_key', $markets)
            ->whereNotNull('o.player_name')
            ->whereNotNull('o.price_american')
            ->where('e.starts_at', '>', now())
            ->when($sport, fn ($q) => $q->where('e.sport', $sport))
            ->orderBy('o.pulled_at')
            ->get([
                'e.sport', 'o.event_id', 'o.market_key', 'o.player_name',
                'o.line', 'o.side', 'o.price_american', 'b.key as book', 'o.pulled_at',
            ]);

        // Newest wins, so the last write per book and side is the price.
        $byLine = [];

        foreach ($quotes as $quote) {
            $key = implode('|', [
                $quote->event_id, $quote->market_key, $quote->player_name, (string) $quote->line,
            ]);

            $byLine[$key]['meta'] ??= [
                'sport' => (string) $quote->sport,
                'event_id' => (int) $quote->event_id,
                'market_key' => (string) $quote->market_key,
                'player_name' => (string) $quote->player_name,
                'line' => (float) $quote->line,
            ];

            $byLine[$key]['prices'][$quote->book][$quote->side] = (int) $quote->price_american;
        }

        $out = [];

        foreach ($byLine as $entry) {
            $out[] = $entry['meta'] + ['prices' => $entry['prices']];
        }

        return $out;
    }

    /**
     * How much of the board can be projected, and why the rest cannot.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $projected
     * @param  array<string, array<string, array<string, bool>>>  $declined
     */
    private function coverage(array $rows, array $projected, array $declined): void
    {
        $this->newLine();
        $this->info(sprintf(
            'Coverage: %d of %d priced props projected (%.0f%%).',
            count($projected),
            count($rows),
            count($rows) === 0 ? 0 : (count($projected) / count($rows)) * 100,
        ));

        if ($declined === []) {
            return;
        }

        $table = [];

        foreach ($declined as $market => $reasons) {
            foreach ($reasons as $reason => $players) {
                $names = array_keys($players);
                sort($names);

                $table[] = [
                    $market,
                    $reason,
                    count($names),
                    mb_substr(implode(', ', array_slice($names, 0, 4)), 0, 70),
                ];
            }
        }

        usort($table, fn (array $a, array $b) => $b[2] <=> $a[2]);

        $this->newLine();
        $this->table(['market', 'why not', 'players', 'for example'], $table);
    }

    /**
     * The average gap between the projection and the line, per market.
     *
     * The one number that catches a model biased in one direction. A
     * projector shrunk toward a low pool would read as sober on every
     * row while taking the under on all of them, and the per-row numbers
     * would never show it.
     *
     * A market whose mean gap is near zero is not thereby correct, but
     * one whose mean gap is a tenth of the line is wrong, and this says
     * which.
     *
     * @param  array<int, array<string, mixed>>  $projected
     */
    private function bias(array $projected): void
    {
        if ($projected === []) {
            return;
        }

        $byMarket = [];

        foreach ($projected as $row) {
            $projection = $row['projection'];

            // A threshold market has no line to be above or below - the
            // line is always 0.5 and the question is a rate - so the
            // comparison is made against the market's own fair price
            // instead.
            $byMarket[$row['market_key']][] = [
                'gap' => $projection->family === 'threshold'
                    ? null
                    : $projection->projectedMean - $row['line'],
                'line' => $row['line'],
                'over' => $projection->probOver,
                'fair' => $this->fairProbability($row, 'over'),
                'disagreement' => $projection->familyDisagreement(),
                'effective' => $projection->effectiveGames,
            ];
        }

        $table = [];

        foreach ($byMarket as $market => $entries) {
            $gaps = array_filter(array_column($entries, 'gap'), fn ($g) => $g !== null);
            $lines = array_column($entries, 'line');
            $disagreements = array_filter(array_column($entries, 'disagreement'), fn ($d) => $d !== null);

            // Against the market, where there is a two-sided price to
            // measure against.
            $vsMarket = [];

            foreach ($entries as $entry) {
                if ($entry['fair'] !== null) {
                    $vsMarket[] = $entry['over'] - $entry['fair'];
                }
            }

            $table[] = [
                $market,
                count($entries),
                $gaps === [] ? '-' : sprintf('%+.2f', array_sum($gaps) / count($gaps)),
                $lines === [] ? '-' : sprintf('%.1f', array_sum($lines) / count($lines)),
                $vsMarket === [] ? '-' : sprintf('%+.1f pts', (array_sum($vsMarket) / count($vsMarket)) * 100),
                $disagreements === [] ? '-' : sprintf('%.1f pts', (array_sum($disagreements) / count($disagreements)) * 100),
                sprintf('%.0f', array_sum(array_column($entries, 'effective')) / count($entries)),
            ];
        }

        $this->newLine();
        $this->info('Bias check - a market biased one way is wrong even when every row looks sober.');
        $this->table(
            ['market', 'rows', 'mean proj - line', 'mean line', 'mean over vs fair', 'family disagreement', 'eff. games'],
            $table,
        );
    }

    /**
     * The rows where the model disagrees with the board most.
     *
     * Printed as a disagreement, not as a pick. Nothing here has been
     * graded against a result yet, so a large number is a thing to go
     * and check rather than a thing to bet.
     *
     * @param  array<int, array<string, mixed>>  $projected
     */
    private function board(array $projected): void
    {
        $minimum = (float) $this->option('min-edge');
        $rows = [];

        foreach ($projected as $row) {
            foreach (['over', 'under'] as $side) {
                $fair = $this->fairProbability($row, $side);

                if ($fair === null) {
                    continue;
                }

                $projection = $row['projection'];
                $mine = $side === 'over' ? $projection->probOver : $projection->probUnder;
                $gap = $mine - $fair;

                if ($gap < $minimum) {
                    continue;
                }

                $rows[] = [
                    'sport' => $row['sport'],
                    'player' => $projection->playerName,
                    'market' => $row['market_key'],
                    'side' => $side,
                    'line' => $row['line'],
                    'projected' => $projection->projectedMean,
                    'mine' => $mine,
                    'fair' => $fair,
                    'gap' => $gap,
                    'games' => $projection->effectiveGames,
                    'disagreement' => $projection->familyDisagreement(),
                ];
            }
        }

        if ($rows === []) {
            $this->newLine();
            $this->line(sprintf('No projection sits %.0f points or more from the fair price.', $minimum * 100));

            return;
        }

        usort($rows, fn (array $a, array $b) => $b['gap'] <=> $a['gap']);

        $this->newLine();
        $this->info(sprintf(
            'Largest disagreements with the board (%d of %d over %.0f points). These are projections, not picks:',
            count($rows),
            count($projected) * 2,
            $minimum * 100,
        ));

        $this->table(
            ['sport', 'player', 'market', 'side', 'line', 'projected', 'model', 'fair', 'gap', 'eff. games', 'family gap'],
            array_map(fn (array $r) => [
                $r['sport'],
                mb_substr($r['player'], 0, 22),
                str_replace('player_', '', $r['market']),
                $r['side'],
                sprintf('%.1f', $r['line']),
                sprintf('%.1f', $r['projected']),
                sprintf('%.1f%%', $r['mine'] * 100),
                sprintf('%.1f%%', $r['fair'] * 100),
                sprintf('%+.1f pts', $r['gap'] * 100),
                sprintf('%.0f', $r['games']),
                $r['disagreement'] === null ? '-' : sprintf('%.0f pts', $r['disagreement'] * 100),
            ], array_slice($rows, 0, (int) $this->option('limit'))),
        );
    }

    /**
     * The market's fair probability for one side of a prop.
     *
     * Vig removed per book and only then combined, which is the order
     * that matters: averaging the raw implied probabilities first blends
     * the overrounds together and produces a market no book offered,
     * usually one that looks beatable before a model has been consulted.
     *
     * A book quoting one side of the prop does not get a vote, because a
     * one-sided price cannot have its vig removed.
     *
     * @param  array<string, mixed>  $row
     */
    private function fairProbability(array $row, string $side): ?float
    {
        $model = RatingSchema::modelForProp($row['market_key']);

        if ($model === null) {
            return null;
        }

        $sides = $model['sides'];

        // A yes/no market is asked about in over/under terms by the
        // caller, since that is what a projection produces.
        $wanted = match (true) {
            in_array($side, $sides, true) => $side,
            $side === 'over' => $sides[0],
            default => $sides[1],
        };

        return MarketConsensus::fairProbability(
            $row['prices'],
            $wanted,
            $sides,
            VigMethod::tryFrom((string) config('edge.value.vig_method')) ?? VigMethod::Multiplicative,
        )?->fairProbability;
    }
}
