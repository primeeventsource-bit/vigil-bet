<?php

declare(strict_types=1);

namespace App\Services\Modeling;

use App\Data\LoadedModel;
use App\Data\PreGameContext;
use App\Enums\EventStatus;
use App\Enums\Market;
use App\Enums\ModelType;
use App\Enums\SeasonType;
use App\Enums\Side;
use App\Enums\Sport;
use App\Modeling\Features\FeatureSchema;
use App\Modeling\Math\Normal;
use App\Modeling\Odds\OddsConverter;
use App\Models\Event;
use App\Models\FeatureSet;
use App\Models\Prediction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Prices upcoming games with the active model and writes the predictions
 * down before kickoff.
 *
 * This is the row that makes a track record possible. Everything else in the
 * app can be recomputed; a prediction cannot, because its whole value is
 * that it existed before the game was played. The table is append-only and
 * the database refuses any row stamped at or after kickoff.
 *
 * Only the moneyline is written, and that is a deliberate limit rather than
 * an unfinished one. The moneyline is the only market any model here has
 * been trained and measured on: the backtest's outcome is whether the home
 * side won, and the market option only chooses which closing line to
 * benchmark against. A spread probability would mean inverting a win
 * probability through a per-sport sigma that has never been validated as a
 * cover model, and a total would come out of a league baseline that is the
 * same number for every game - a constant dressed up as a forecast. The
 * projected margin, total and sigma are recorded on the row so the value
 * finder and the game detail page can show the model's shape, and so a
 * spread can be priced from stored parameters the day a cover model is
 * actually backtested. Until then no row claims one exists.
 *
 * One prediction per game, per market, per model version. Re-running this is
 * safe and changes nothing; activating a newly trained version produces a
 * fresh set attributed to that version.
 */
class Forecaster
{
    public function __construct(private readonly ModelLoader $loader) {}

    /**
     * Price everything starting inside the horizon.
     *
     * @return array<string, mixed>
     */
    public function forecast(Sport $sport, int $horizonHours = 72): array
    {
        $model = $this->loader->load($sport);

        if ($model === null) {
            return [
                'refusal' => sprintf(
                    'No active model for %s moneyline. Train one with edge:train %s.',
                    $sport->label(),
                    $sport->value,
                ),
            ];
        }

        $events = Event::query()
            ->forSport($sport)
            ->where('status', EventStatus::Scheduled)
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours($horizonHours))
            // Preseason is not priced, for the same reason it is not
            // trained on: the rating describes a team that intends to win,
            // and a preseason lineup is not that team. The model has no
            // opinion worth having here, so it should not offer one.
            ->where(fn ($q) => $q
                ->whereNull('season_type')
                ->orWhere('season_type', '!=', SeasonType::Preseason->value))
            ->orderBy('starts_at')
            ->get();

        $written = 0;
        $abstained = 0;
        $already = 0;

        foreach ($events as $event) {
            $outcome = $this->predict($event, $sport, $model);

            match ($outcome) {
                'written' => $written++,
                'exists' => $already++,
                default => $abstained++,
            };
        }

        return [
            'version' => $model->version->describe(),
            'model_version_id' => $model->version->id,
            'caught_up_with' => $model->caughtUpWith,
            'events' => $events->count(),
            'written' => $written,
            'already_predicted' => $already,
            // A model that declines to price a game is reporting that it has
            // no opinion, which is a real answer and not a failure.
            'abstained' => $abstained,
        ];
    }

    private function predict(Event $event, Sport $sport, LoadedModel $model): string
    {
        if ($this->alreadyPredicted($event, $model)) {
            return 'exists';
        }

        // The constraint that makes the track record honest. Belt and braces
        // next to the database check: between selecting a slate and writing
        // it, a game can kick off.
        if (! $event->starts_at->isFuture()) {
            return 'abstained';
        }

        $context = $this->contextFor($event, $sport);
        $raw = $model->rawProbability($context);

        if ($raw === null) {
            return 'abstained';
        }

        $probability = $model->calibration->apply($raw);

        if ($probability <= 0.0 || $probability >= 1.0) {
            // The predictions table refuses a probability of nought or one,
            // and rightly: a model claiming certainty is broken, not sharp.
            return 'abstained';
        }

        $this->write($event, $sport, $model, $context, $raw, $probability);

        return 'written';
    }

    private function write(
        Event $event,
        Sport $sport,
        LoadedModel $model,
        PreGameContext $context,
        float $raw,
        float $probability,
    ): void {
        $sigma = (float) ($sport->config('margin_sigma') ?? 13.5);
        $baselineTotal = (float) ($sport->config('baseline_total') ?? 44.0);

        // The margin consistent with this win probability under the sport's
        // margin spread. It is a restatement of the probability, not a
        // second opinion, which is why the two can never disagree here.
        $margin = $sigma * Normal::inverseCdf($probability);

        $components = $model->ratings($context->homeTeamId, $context->awayTeamId) + [
            'model_type' => $model->version->model_type->value,
            'raw_probability' => $raw,
            'calibration' => $model->calibration->method,
            'margin_sigma' => $sigma,
            'implied_margin' => $margin,
            // How many finished games were replayed into the model after it
            // was trained. Enough to reproduce this number exactly.
            'caught_up_with' => $model->caughtUpWith,
            'trained_through' => $model->version->trained_through_at?->toIso8601String(),
            // The total is the league baseline, not a forecast: Elo and the
            // feature model only ever knew about margins.
            'total_is_baseline' => true,
        ];

        $contributions = $model->contributions($context);

        DB::transaction(function () use (
            $event, $model, $context, $probability, $margin, $baselineTotal, $sigma, $components, $contributions
        ): void {
            $featureSetId = $this->recordFeatureSet($event, $model, $context, $contributions);

            // A moneyline is a two-way market for betting purposes: a tie
            // pushes the bet rather than paying either side, so the pair of
            // probabilities splits the games that produce a winner. Ties are
            // not modelled separately and the grader already refunds them.
            foreach ([[Side::Home, $probability], [Side::Away, 1.0 - $probability]] as [$side, $prob]) {
                Prediction::create([
                    'event_id' => $event->id,
                    'model_version_id' => $model->version->id,
                    'market' => Market::Moneyline->value,
                    'side' => $side->value,
                    'line' => null,
                    'prob' => $prob,
                    'fair_price_american' => OddsConverter::probabilityToAmerican($prob),
                    'projected_home_score' => ($baselineTotal / 2) + ($margin / 2),
                    'projected_away_score' => ($baselineTotal / 2) - ($margin / 2),
                    'projected_margin' => $margin,
                    'projected_total' => $baselineTotal,
                    'sigma' => $sigma,
                    'components' => $components,
                    // The attribution explains the home probability, so it
                    // rides on the home row rather than being duplicated
                    // onto a side it does not describe.
                    'feature_contributions' => $side === Side::Home && $contributions !== [] ? $contributions : null,
                    'feature_set_id' => $featureSetId,
                    'predicted_at' => now(),
                    'event_starts_at' => $event->starts_at,
                    'created_at' => now(),
                ]);
            }
        });
    }

    /**
     * Store the feature vector behind a regression prediction.
     *
     * max_source_at is the newest thing that fed the vector: the kickoff of
     * the most recent finished game replayed into the model. Every game the
     * model learned from has already been played, and this event has not
     * started, so that timestamp is necessarily before kickoff - and the
     * FeatureSet guard refuses the row outright if it ever is not.
     *
     * @param  array<string, float>  $contributions
     */
    private function recordFeatureSet(
        Event $event,
        LoadedModel $model,
        PreGameContext $context,
        array $contributions,
    ): ?int {
        if ($model->version->model_type !== ModelType::Logistic || $model->predictor === null) {
            return null;
        }

        $vector = $model->predictor->vector($context);

        return FeatureSet::create([
            'event_id' => $event->id,
            'schema_version' => FeatureSchema::VERSION,
            'payload' => [
                'features' => FeatureSchema::label($vector),
                'contributions' => $contributions,
            ],
            'as_of' => now(),
            'max_source_at' => $this->newestLearnedGame($event, $model),
            'event_starts_at' => $event->starts_at,
            'built_at' => now(),
            'created_at' => now(),
        ])->id;
    }

    /**
     * The kickoff of the newest finished game this model has seen, bounded
     * below by nothing and above by this event's own start.
     */
    private function newestLearnedGame(Event $event, LoadedModel $model): CarbonImmutable
    {
        $newest = Event::query()
            ->forSport($event->sport)
            ->where('status', EventStatus::Final)
            ->where('starts_at', '<', $event->starts_at)
            ->max('starts_at');

        $trained = $model->version->trained_through_at;

        return CarbonImmutable::parse($newest ?? $trained ?? now());
    }

    private function alreadyPredicted(Event $event, LoadedModel $model): bool
    {
        return Prediction::query()
            ->where('event_id', $event->id)
            ->where('model_version_id', $model->version->id)
            ->where('market', Market::Moneyline->value)
            ->exists();
    }

    private function contextFor(Event $event, Sport $sport): PreGameContext
    {
        return new PreGameContext(
            eventId: $event->id,
            sport: $sport,
            homeTeamId: $event->home_team_id,
            awayTeamId: $event->away_team_id,
            startsAt: CarbonImmutable::parse($event->starts_at),
            neutralSite: (bool) $event->neutral_site,
            season: $event->season,
            homeRestDays: $event->home_rest_days !== null ? (int) $event->home_rest_days : null,
            awayRestDays: $event->away_rest_days !== null ? (int) $event->away_rest_days : null,
            roof: $event->roof,
            surface: $event->surface,
            divisional: $event->divisional !== null ? (bool) $event->divisional : null,
        );
    }
}
