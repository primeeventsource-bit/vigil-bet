<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a sport+market has earned the right to produce recommendations.
 *
 * Two tiers may surface a pick, and they are different claims.
 *
 * `Proven` means the model beats the no-vig closing line. That is the
 * strongest thing a sports model can say and almost nothing ever says it:
 * measured here across eleven markets, every one of them loses to the
 * close. Keeping it as the only gate meant VIGIL published nothing and
 * would have gone on publishing nothing.
 *
 * `Calibrated` means something weaker and still worth money: over a real
 * out-of-sample run, when this model says 60% the thing happens about 60%
 * of the time. That is an honest probability, not an edge over the
 * market, and it is labelled as such everywhere it appears. Four of the
 * eleven markets clear it and seven do not, which is what makes it a gate
 * rather than a rubber stamp.
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
        return $this === self::Proven || $this === self::Calibrated;
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
