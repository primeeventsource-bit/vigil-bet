<?php

declare(strict_types=1);

namespace App\Services\Players;

use App\Models\PropProjection as PropProjectionRow;
use App\Models\PropProjectionResult;

/**
 * Marks the prop model's homework.
 *
 * The half that makes the other half mean anything. A projection is an
 * assertion until it is graded, and props are published nowhere
 * precisely because they have never been graded - so this is the work
 * that either earns them a place or proves they do not deserve one.
 *
 * Either answer is a result. A model that grades out worse than the
 * market has told us something worth knowing cheaply, and the cost of
 * finding out is a few months of writing numbers down.
 */
class PropProjectionSettler
{
    public function __construct(private readonly PropProjector $projector) {}

    /** @return array{settled: int, unresolved: int, voided: int} */
    public function run(int $limit = 2000): array
    {
        $projections = PropProjectionRow::query()
            ->awaitingSettlement()
            ->with('event')
            ->orderBy('kickoff_at')
            ->limit($limit)
            ->get();

        $settled = 0;
        $unresolved = 0;
        $voided = 0;

        foreach ($projections as $projection) {
            /*
             * A game that has kicked off is not a game that has been
             * scored. Waiting is the right answer rather than reading a
             * half-finished box score, which would grade a prop against
             * a number still going up.
             */
            if ($projection->event?->status?->value !== 'final') {
                $unresolved++;

                continue;
            }

            $actual = $this->projector->actualFor(
                (int) $projection->event_id,
                (string) $projection->sport,
                (string) $projection->market_key,
                (string) $projection->player_key,
            );

            $outcome = $this->outcome($actual, (float) $projection->line);

            if ($outcome === 'void') {
                $voided++;
            }

            PropProjectionResult::create([
                'prop_projection_id' => $projection->id,
                'actual' => $actual,
                'outcome' => $outcome,
                'settled_at' => now(),
                'created_at' => now(),
            ]);

            $settled++;
        }

        return ['settled' => $settled, 'unresolved' => $unresolved, 'voided' => $voided];
    }

    /**
     * Over, under, push or void.
     *
     * The push is a real outcome on a whole-number line and is kept
     * apart from both sides, because a bet that was returned is not a
     * bet that was won or lost and counting it either way would move a
     * calibration figure by a few points on the low-count markets.
     */
    private function outcome(?float $actual, float $line): string
    {
        if ($actual === null) {
            return 'void';
        }

        return match (true) {
            $actual > $line => 'over',
            $actual < $line => 'under',
            default => 'push',
        };
    }
}
