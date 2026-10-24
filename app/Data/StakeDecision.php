<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\PassReason;
use App\Support\Money;

/**
 * What to stake, and why that number and not a bigger one.
 *
 * Every pick card shows this, so the reasoning is part of the result rather
 * than something buried in a service.
 */
final readonly class StakeDecision
{
    public function __construct(
        public int $stakeCents,
        public float $units,
        public float $kellyFraction,
        public int $uncappedKellyCents,
        public ?string $cappedBy,
        public bool $isActionable,
        public ?PassReason $passReason = null,
        public ?string $explanation = null,
    ) {}

    public static function pass(PassReason $reason, string $explanation, int $uncappedKellyCents = 0, float $kellyFraction = 0.0): self
    {
        return new self(
            stakeCents: 0,
            units: 0.0,
            kellyFraction: $kellyFraction,
            uncappedKellyCents: $uncappedKellyCents,
            cappedBy: null,
            isActionable: false,
            passReason: $reason,
            explanation: $explanation,
        );
    }

    public function formatted(): string
    {
        return Money::format($this->stakeCents);
    }

    /** True when a cap, not the model, decided the number. */
    public function wasCapped(): bool
    {
        return $this->cappedBy !== null;
    }
}
