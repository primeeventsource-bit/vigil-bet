<?php

declare(strict_types=1);

namespace App\Services\Players;

use App\Models\PropProjection as PropProjectionRow;
use Illuminate\Support\Facades\DB;

/**
 * Writes down what the model said, before the game, so it can be marked
 * afterwards.
 *
 * This is the only route by which player props could ever become
 * publishable. The game markets earn the right to claim an edge by
 * beating a closing line; props have been measured against nothing, and
 * a model cannot be measured retrospectively because the thing being
 * tested is whether it knew beforehand. So the record starts now, while
 * the model is still not good enough to publish, and accrues.
 *
 * Nothing here is shown to anybody. No pick is produced, no probability
 * reaches a customer, and the `odds_snapshots` schema still forbids a
 * prop becoming a prediction.
 */
class PropProjectionWriter
{
    public function __construct(
        private readonly PropProjector $projector,
        private readonly PricedProps $board,
    ) {}

    /**
     * Record every projectable prop on the upcoming board.
     *
     * `$rows` is passed in when the caller already has the board, which
     * the reporting command does. Loading it twice is what exhausted the
     * memory limit in production: props are pulled every five minutes,
     * so one evening is a couple of hundred thousand quote rows and two
     * copies do not fit. Relying on the service to memoise would not
     * have helped, because the command and this class are handed
     * separate instances of it.
     *
     * @param  array<int, array<string, mixed>>|null  $rows
     * @return array{recorded: int, existing: int, declined: int, candidates: int}
     */
    public function record(?string $sport = null, ?string $market = null, ?array $rows = null): array
    {
        $rows ??= $this->board->upcoming($sport, $market);
        $fingerprint = $this->fingerprint();
        $params = $this->parameters();

        $recorded = 0;
        $existing = 0;
        $declined = 0;

        foreach ($rows as $row) {
            $projection = $this->projector->project(
                $row['sport'],
                $row['market_key'],
                $row['player_name'],
                $row['line'],
            );

            if ($projection === null) {
                $declined++;

                continue;
            }

            /*
             * One row per selection per model. Checked here as well as
             * in the database because the projector runs nightly over
             * the same board, and thirty copies of one opinion would
             * make a small sample look like a large one - which is
             * exactly the error a calibration figure cannot survive.
             */
            $alreadyThere = PropProjectionRow::query()
                ->where('event_id', $row['event_id'])
                ->where('market_key', $row['market_key'])
                ->where('player_key', $projection->playerKey)
                ->where('line', $row['line'])
                ->where('model_fingerprint', $fingerprint)
                ->exists();

            if ($alreadyThere) {
                $existing++;

                continue;
            }

            $fair = $this->board->fair($row, 'over');

            PropProjectionRow::create([
                'event_id' => $row['event_id'],
                'sport' => $projection->sport,
                'market_key' => $projection->marketKey,
                'player_key' => $projection->playerKey,
                'player_name' => $projection->playerName,
                'player_external_id' => $projection->playerExternalId,
                'line' => $projection->line,
                'projected_mean' => $projection->projectedMean,
                'projected_sd' => $projection->projectedSd,
                'prob_over' => $projection->probOver,
                'prob_under' => $projection->probUnder,
                'prob_push' => $projection->probPush,
                'empirical_over' => $projection->empiricalOver,
                'family' => $projection->family,
                'games' => $projection->games,
                'effective_games' => $projection->effectiveGames,
                'measured_from' => $projection->measuredFrom,
                'measured_to' => $projection->measuredTo,
                'fair_prob_over' => $fair['fair'],
                'book_count' => $fair['books'],
                'best_over_price_american' => $this->board->bestPrice($row, 'over'),
                'best_under_price_american' => $this->board->bestPrice($row, 'under'),
                'model_fingerprint' => $fingerprint,
                'model_params' => $params,
                'kickoff_at' => $row['kickoff_at'],
                'projected_at' => now(),
                'created_at' => now(),
            ]);

            $recorded++;
        }

        return [
            'recorded' => $recorded,
            'existing' => $existing,
            'declined' => $declined,
            'candidates' => count($rows),
        ];
    }

    /**
     * Which model produced a row.
     *
     * There is no `model_versions` row behind a prop projection - it
     * comes from a service and a handful of config values rather than
     * from fitted coefficients - so the parameters are the version.
     * Without this, lowering a half-life would pool the predictions of
     * two different models into one calibration figure that describes
     * neither, and the figure would look like more evidence rather than
     * less.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', json_encode($this->parameters())), 0, 32);
    }

    /**
     * Everything that changes what a projection says.
     *
     * The distribution families are in here as well as the numbers,
     * because changing which family prices a market changes the answer
     * more than any of the constants do.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'min_games' => (int) config('edge.props.min_games', 10),
            'recency_half_life_games' => (float) config('edge.props.recency_half_life_games', 20),
            'dispersion_pool_games' => (float) config('edge.props.dispersion_pool_games', 8),
            'threshold_prior_games' => (float) config('edge.props.threshold_prior_games', 8),
            'max_team_games_missed' => (int) config('edge.props.max_team_games_missed', 2),
            'families' => array_map(
                static fn (array $model): string => $model['family'],
                RatingSchema::PROP_MODELS,
            ),
        ];
    }

    /**
     * How many rows exist per model, newest first.
     *
     * Read before trusting a calibration figure: a fingerprint with
     * forty graded rows is a hint and one with four thousand is a
     * measurement, and nothing else in the table says which you have.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byModel(): array
    {
        return DB::table('prop_projections')
            ->selectRaw('model_fingerprint, count(*) as rows, min(projected_at) as first, max(projected_at) as last')
            ->groupBy('model_fingerprint')
            ->orderByDesc('last')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
