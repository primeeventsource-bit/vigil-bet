<?php

declare(strict_types=1);

namespace App\Services\Record;

use App\Models\Event;
use App\Models\ModelVersion;
use App\Models\Opinion;
use App\Models\ValueBet;
use App\Services\Value\OpinionBar;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes the product's own opinions for the day.
 *
 * Two tests have to pass before anything goes out, and they are
 * different questions:
 *
 *  - The selection. `shadow_recommendation` is what the scanner decided
 *    on the merits alone - positive edge, over the threshold, over the
 *    minimum probability, with an actionable stake - ignoring whether
 *    the market is proven. That is the opinion.
 *  - The market. OpinionBar asks whether this model's probabilities have
 *    been measured to land close to the truth, because the confidence
 *    figure is the claim being made and it has to be approximately
 *    right.
 *
 * Neither asks whether the model beats the closing line. That is the
 * verdict gate's question and it governs PublishedPick, which this does
 * not touch.
 *
 * Nothing is published inside the playable lead. A row written four
 * minutes before kickoff is technically pre-kickoff and practically
 * unplayable, and an accuracy figure built from those would be true and
 * misleading at once.
 */
class OpinionPublisher
{
    /** How long before kickoff an opinion stops being publishable. */
    public const MINIMUM_LEAD_MINUTES = 15;

    public function __construct(private readonly OpinionBar $bar) {}

    /** @return array{published:int, skipped:array<string, int>} */
    public function run(): array
    {
        $candidates = ValueBet::query()
            ->where('shadow_recommendation', 'bet')
            ->whereNotNull('best_price_american')
            ->whereNotNull('model_prob')
            ->orderByDesc('flagged_at')
            ->limit(200)
            ->get();

        $published = 0;
        $skipped = [
            'already_published' => 0,
            'market_not_publishable' => 0,
            'no_event' => 0,
            'too_late' => 0,
        ];

        /*
         * Eligibility is a property of the model, not of the bet, so it
         * is answered once per market rather than once per candidate -
         * a busy NCAAF slate is a hundred rows against four models.
         *
         * @var array<string, bool> $eligible
         */
        $eligible = [];

        foreach ($candidates as $bet) {
            $key = $bet->sport?->value.'|'.$bet->market?->value;

            $eligible[$key] ??= $this->bar->allows($this->modelFor($bet));

            if (! $eligible[$key]) {
                $skipped['market_not_publishable']++;

                continue;
            }

            $outcome = $this->publish($bet);

            if ($outcome === null) {
                $published++;

                continue;
            }

            $skipped[$outcome] = ($skipped[$outcome] ?? 0) + 1;
        }

        return ['published' => $published, 'skipped' => $skipped];
    }

    /** Returns null on success, or the reason it was skipped. */
    public function publish(ValueBet $bet): ?string
    {
        $event = Event::query()
            ->with(['homeTeam:id,display_name', 'awayTeam:id,display_name'])
            ->find($bet->event_id);

        if ($event?->starts_at === null) {
            return 'no_event';
        }

        if ($event->starts_at->isBefore(now()->addMinutes(self::MINIMUM_LEAD_MINUTES))) {
            /*
             * Normal rather than exceptional: the board is rescanned
             * hourly and some games are always close to kicking off.
             */
            return 'too_late';
        }

        $model = $this->modelFor($bet);
        $metrics = $model?->holdout_metrics ?? [];

        try {
            Opinion::create([
                'board_entry_id' => null,
                'event_id' => $event->id,
                'sport' => $bet->sport?->value ?? $event->sport->value,
                'market' => $bet->market?->value,
                'side' => $bet->side?->value,
                'line' => $bet->line,
                'selection' => $bet->selectionLabel(),
                'matchup' => trim(($event->awayTeam?->display_name ?? '?').' at '.($event->homeTeam?->display_name ?? '?')),
                // The price as it was. Looking it up later would make the
                // record a claim about a price nobody was offered.
                'price_american' => (int) $bet->best_price_american,
                'book' => $bet->bestBook?->key,
                'book_title' => $bet->bestBook?->title,
                'model_prob' => $bet->model_prob,
                'fair_prob' => $bet->fair_prob,
                'edge_at_price' => $bet->edge_at_price,
                'stake_fraction' => $bet->kelly_fraction,
                'model_version' => $bet->model_version_id,
                // What was known about this model when it went out.
                'calibration_error_at_publish' => $metrics['calibration_error'] ?? null,
                'model_predictions_at_publish' => $metrics['predictions'] ?? null,
                'kickoff_at' => $event->starts_at,
                'published_at' => now(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            /*
             * The database refuses a second opinion on one selection,
             * and a rescan reaches here with the same game every hour.
             * Expected, so it is counted rather than thrown - but logged,
             * because any other refusal arriving here would otherwise be
             * silent.
             */
            Log::info('Opinion not published', [
                'value_bet_id' => $bet->id,
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            return 'already_published';
        }

        return null;
    }

    private function modelFor(ValueBet $bet): ?ModelVersion
    {
        if ($bet->model_version_id !== null) {
            return ModelVersion::find($bet->model_version_id);
        }

        return ModelVersion::query()
            ->where('sport', $bet->sport?->value)
            ->where('market', $bet->market?->value)
            ->where('status', 'active')
            ->first();
    }
}
