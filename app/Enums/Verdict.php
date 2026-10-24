<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a sport+market has earned the right to produce recommendations.
 * Only Proven markets may surface a pick. Everything else is reported
 * plainly as unproven or negative - never quietly hidden.
 */
enum Verdict: string
{
    case Proven = 'proven';
    case Unproven = 'unproven';
    case Negative = 'negative';
    case Insufficient = 'insufficient_data';

    public function allowsRecommendation(): bool
    {
        return $this === self::Proven;
    }

    public function label(): string
    {
        return match ($this) {
            self::Proven => 'Edge proven',
            self::Unproven => 'Unproven',
            self::Negative => 'Negative edge',
            self::Insufficient => 'Not enough data yet',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Proven => 'Beats the no-vig closing line on log loss with positive CLV.',
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
