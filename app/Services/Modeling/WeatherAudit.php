<?php

declare(strict_types=1);

namespace App\Services\Modeling;

use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Modeling\Math\Correlation;
use App\Models\Event;
use Illuminate\Support\Collection;

/**
 * Measures whether weather carries anything the market has not already
 * priced, before a single feature is built on it.
 *
 * This is the same discipline the live engine starts with - audit the data
 * before modelling it - and it exists because the alternative is seductive
 * and wrong. Weather obviously affects football. It does not follow that a
 * weather feature can make money, and the step everyone skips is the one
 * that decides it.
 *
 * Three questions, in the order that matters:
 *
 *  1. **Does weather move the game?** Wind against the points actually
 *     scored. If this is flat, nothing else matters.
 *  2. **Does the market already know?** Wind against the closing total. A
 *     book that shades a windy game down has priced it, and the remaining
 *     edge is whatever it got wrong rather than the whole effect.
 *  3. **Is anything left over?** Wind against the *residual* - points
 *     scored minus the closing total. This is the only question with money
 *     in it. A strong answer to (1) and an equally strong answer to (2)
 *     leaves nothing here, and that is the usual outcome.
 *
 * The third is reported as the headline for that reason. An audit that led
 * with "wind correlates with scoring" would be true, impressive and
 * useless.
 *
 * Weather is read at a fixed lead time, so the audit measures the forecast
 * a bettor could have acted on rather than a blend of runs.
 */
class WeatherAudit
{
    /**
     * @return array{
     *     sport: string,
     *     lead_days: int,
     *     games: int,
     *     variables: array<string, array<string, array{n: int, r: float, slope: float, t: float}>>,
     *     notes: array<int, string>,
     * }
     */
    public function run(Sport $sport = Sport::Nfl, int $leadDays = 1): array
    {
        $rows = $this->rows($sport, $leadDays);
        $notes = [];

        if ($rows->isEmpty()) {
            return [
                'sport' => $sport->value,
                'lead_days' => $leadDays,
                'games' => 0,
                'variables' => [],
                'notes' => ['No games with both a forecast at this lead and a final score.'],
            ];
        }

        $variables = [];

        foreach (['wind_mph' => 'Wind', 'temp_f' => 'Temperature', 'precip_in' => 'Precipitation'] as $field => $label) {
            // Each variable gets the games that actually carry it. Wind
            // thins out before 2024, and padding the gap with zeros would
            // dilute a real effect towards nothing.
            $usable = $rows->filter(fn (array $row) => $row[$field] !== null);

            if ($usable->count() < 30) {
                $notes[] = sprintf('%s: only %d games carry it, too few to measure.', $label, $usable->count());

                continue;
            }

            $x = $usable->pluck($field)->map(fn ($v) => (float) $v)->all();

            $measures = [
                // Did it move the game?
                'actual_total' => Correlation::summarise($x, $usable->pluck('actual_total')->all()),
            ];

            $priced = $usable->filter(fn (array $row) => $row['closing_total'] !== null);

            if ($priced->count() >= 30) {
                $px = $priced->pluck($field)->map(fn ($v) => (float) $v)->all();

                // Did the market already know?
                $measures['closing_total'] = Correlation::summarise(
                    $px,
                    $priced->pluck('closing_total')->map(fn ($v) => (float) $v)->all(),
                );

                // Is anything left over? The only one with money in it.
                $measures['residual'] = Correlation::summarise(
                    $px,
                    $priced->map(fn (array $row) => $row['actual_total'] - (float) $row['closing_total'])->all(),
                );
            } else {
                $notes[] = sprintf(
                    '%s: %d games have a closing total, too few to say whether it is priced in.',
                    $label,
                    $priced->count(),
                );
            }

            $variables[$field] = $measures;
        }

        return [
            'sport' => $sport->value,
            'lead_days' => $leadDays,
            'games' => $rows->count(),
            'variables' => $variables,
            'notes' => $notes,
        ];
    }

    /**
     * Finished games with a forecast at this lead, their scores, and the
     * closing total where one was recorded.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Sport $sport, int $leadDays): Collection
    {
        return Event::query()
            ->forSport($sport)
            ->whereNotNull('final_home_score')
            ->whereNotNull('final_away_score')
            ->whereHas('weatherReadings', fn ($query) => $query->where('lead_days', $leadDays))
            ->with([
                'weatherReadings' => fn ($query) => $query
                    ->where('lead_days', $leadDays)
                    ->orderBy('id'),
                // Eager loaded, or closingTotal() fires a query per game.
                'lineMarkers' => fn ($query) => $query
                    ->where('market', Market::Total->value)
                    ->where('side', Side::Over->value),
            ])
            ->get()
            ->map(function (Event $event) use ($leadDays): ?array {
                $reading = $event->weatherReadings
                    ->firstWhere('lead_days', $leadDays);

                if ($reading === null) {
                    return null;
                }

                return [
                    'event_id' => $event->id,
                    'actual_total' => (float) ((int) $event->final_home_score + (int) $event->final_away_score),
                    'closing_total' => $this->closingTotal($event),
                    'wind_mph' => $reading->wind_mph,
                    'temp_f' => $reading->temp_f,
                    'precip_in' => $reading->precip_in,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * The closing total for a game, taken as the median across books that
     * recorded one.
     *
     * A median rather than one book's number, for the same reason the fair
     * price is a median: one stale marker should not decide what the market
     * thought.
     */
    private function closingTotal(Event $event): ?float
    {
        $lines = $event->lineMarkers
            ->where('market', Market::Total)
            ->where('side', Side::Over)
            ->pluck('line_close')
            ->filter(fn ($line) => $line !== null)
            ->map(fn ($line) => (float) $line)
            ->sort()
            ->values();

        if ($lines->isEmpty()) {
            return null;
        }

        $middle = (int) floor($lines->count() / 2);

        return $lines->count() % 2 === 1
            ? $lines[$middle]
            : ($lines[$middle - 1] + $lines[$middle]) / 2.0;
    }
}
