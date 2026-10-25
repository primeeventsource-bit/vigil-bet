<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Players\PricedProps;
use App\Services\Players\PropProjectionWriter;
use App\Services\Players\PropProjector;
use Illuminate\Console\Command;

/**
 * What the box scores say about tonight's prop board.
 *
 * Reports by default and records only when asked. Nothing it writes is
 * shown to anybody: the `odds_snapshots` schema still forbids a prop
 * becoming a prediction or a pick, and a projection that has been graded
 * against nothing has earned none of that - the same rule the game
 * markets live under, where a market claims an edge only after beating a
 * closing line.
 *
 * Three things are reported, and the second two matter more than the
 * first:
 *
 *   coverage  - how much of the priced board can be projected, and for
 *               the rest which of the reasons stopped it. A count of
 *               failures says the feature is broken without saying
 *               which part is.
 *
 *   bias      - the average gap between projection and line, per
 *               market. This is the number that catches the failure the
 *               projector was built to avoid: a model wrong in one
 *               direction reads as sober on every single row while
 *               taking the same side of all of them, and only the
 *               average over the board shows it. It has already earned
 *               its place twice - it caught a recency weighting that
 *               was reading every player's career backwards, and a
 *               model that loved the over on anybody the market had
 *               written down.
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
                            {--half-life= : Override the recency half-life, in games, for this run}
                            {--record : Also write these projections down, to be graded after the games}';

    protected $description = 'Project tonight\'s player props from box scores and compare them to the board';

    public function handle(PropProjector $projector, PricedProps $board, PropProjectionWriter $writer): int
    {
        /*
         * Overridable for this run so the bias can be measured at
         * several half-lives without a deploy. Measured rather than
         * chosen: how fast a player's role actually changes is a
         * question about the data, and the answer is whichever setting
         * leaves the least bias against the sharpest price available.
         *
         * Swept at 20, 10 and 5 games on the live board; 20 was the
         * best of the three and shortening it made the bias worse.
         */
        if ($this->option('half-life') !== null) {
            config()->set('edge.props.recency_half_life_games', (float) $this->option('half-life'));

            $this->line(sprintf('Recency half-life overridden to %s games.', $this->option('half-life')));
        }

        $rows = $board->upcoming($this->argument('sport'), $this->option('market'));

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
        $this->bias($projected, $board);
        $this->board($projected, $board);

        if ($this->option('record')) {
            $this->record($writer);
        }

        return self::SUCCESS;
    }

    /**
     * Write the projections down so they can be marked later.
     *
     * The only route by which props could become publishable. A model
     * cannot be assessed retrospectively, because the thing being
     * tested is whether it knew beforehand - so the record starts while
     * the model is still not good enough to show anybody, and accrues.
     */
    private function record(PropProjectionWriter $writer): void
    {
        $this->newLine();

        if ($this->option('half-life') !== null) {
            // An overridden run records rows under a fingerprint the
            // scheduled runs do not use, which splits the sample rather
            // than corrupting it - worth saying out loud either way.
            $this->warn('Recording with an overridden half-life: these rows are filed under their own model fingerprint.');
        }

        $counts = $writer->record($this->argument('sport'), $this->option('market'));

        $this->info(sprintf(
            'Recorded %d projections (%d already on file, %d declined) under model %s.',
            $counts['recorded'],
            $counts['existing'],
            $counts['declined'],
            substr($writer->fingerprint(), 0, 12),
        ));

        $this->line('Nothing here is published. Grade them after the games with edge:props:settle.');
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
     * market whose mean gap is near zero is not thereby correct, but one
     * whose mean gap is a tenth of its line is wrong, and this says
     * which.
     *
     * @param  array<int, array<string, mixed>>  $projected
     */
    private function bias(array $projected, PricedProps $board): void
    {
        if ($projected === []) {
            return;
        }

        $byMarket = [];

        foreach ($projected as $row) {
            $projection = $row['projection'];

            $byMarket[$row['market_key']][] = [
                // A threshold market has no line to be above or below -
                // the question is a rate - so it is compared against
                // the market's own fair price instead.
                'gap' => $projection->family === 'threshold'
                    ? null
                    : $projection->projectedMean - $row['line'],
                'line' => $row['line'],
                'over' => $projection->probOver,
                'fair' => $board->fair($row, 'over')['fair'],
                'disagreement' => $projection->familyDisagreement(),
                'effective' => $projection->effectiveGames,
            ];
        }

        $table = [];

        foreach ($byMarket as $market => $entries) {
            $gaps = array_filter(array_column($entries, 'gap'), fn ($g) => $g !== null);
            $lines = array_column($entries, 'line');
            $disagreements = array_filter(array_column($entries, 'disagreement'), fn ($d) => $d !== null);

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
     * graded against a result, so a large number is a thing to go and
     * check rather than a thing to bet.
     *
     * @param  array<int, array<string, mixed>>  $projected
     */
    private function board(array $projected, PricedProps $prices): void
    {
        $minimum = (float) $this->option('min-edge');
        $rows = [];

        foreach ($projected as $row) {
            foreach (['over', 'under'] as $side) {
                $fair = $prices->fair($row, $side)['fair'];

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
}
