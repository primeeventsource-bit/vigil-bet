<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a sport+market has earned the right to produce recommendations.
 *
 * One tier, and only one. `Proven` means the model beats the no-vig
 * closing line, which is the strongest thing a sports model can say and
 * almost nothing ever says it.
 *
 * `Calibrated` used to pass here too, on the reasoning that an honest
 * probability is worth something even without an edge, and that holding
 * the line at Proven meant publishing nothing. Both halves of that were
 * true. It was still wrong: everything downstream of this method writes
 * a recommendation, and a recommendation drawn from a market that is
 * losing to the close reads as "we beat the book" however carefully the
 * page is worded. `claimsEdge` below says so in as many words, and the
 * live site was publishing a track record with a win rate and units
 * where no market had ever cleared the gate.
 *
 * Publishing nothing is the correct output of a gate nothing has passed.
 * It is not a dead end either: opinions are governed by calibration
 * rather than by this method, so they keep being published and keep
 * accumulating the closing-line measurements a market needs before it
 * can ever answer Proven here.
 *
 * Everything else is reported plainly - never quietly hidden.
 */
enum Verdict: string
{
    case Proven = 'proven';
    case Calibrated = 'calibrated';
    case Unproven = 'unproven';
    case Negative = 'negative';
    case Insufficient = 'insufficient_data';

    public function allowsRecommendation(): bool
    {
        return $this === self::Proven;
    }

    /**
     * Whether this tier may claim an edge over the market.
     *
     * Only the proven one. A calibrated market states honest
     * probabilities and is still losing to the close, so anything that
     * reads as "we beat the book" must not be shown for it.
     */
    public function claimsEdge(): bool
    {
        return $this === self::Proven;
    }

    public function label(): string
    {
        return match ($this) {
            self::Proven => 'Edge proven',
            self::Calibrated => 'Calibrated',
            self::Unproven => 'Unproven',
            self::Negative => 'Negative edge',
            self::Insufficient => 'Not enough data yet',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Proven => 'Beats the no-vig closing line on log loss with positive CLV.',
            self::Calibrated => 'Its stated probabilities have held up out of sample. An honest number, not an edge over the market.',
            self::Unproven => 'Has not beaten the closing line yet. No picks from this market.',
            self::Negative => 'Loses to the closing line. Do not bet this market.',
            self::Insufficient => 'Still collecting closed games before a verdict is possible.',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $v) => $v->value, self::cases());
    }
}
