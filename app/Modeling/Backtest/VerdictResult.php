<?php

declare(strict_types=1);

namespace App\Modeling\Backtest;

use App\Enums\Verdict;

/**
 * A verdict and the sentence that justifies it. The reason travels with the
 * decision so the dashboard never has to invent an explanation.
 */
final readonly class VerdictResult
{
    public function __construct(
        public Verdict $verdict,
        public string $reason,
        public ?float $logLossImprovement = null,
        /** Mean home-side gap against the market. Diagnostic, never a gate. */
        public ?float $meanHomeBias = null,
        /** Points of calibration given up to the market. Negative is better. */
        public ?float $calibrationGap = null,
    ) {}

    public function allowsRecommendation(): bool
    {
        return $this->verdict->allowsRecommendation();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict->value,
            'reason' => $this->reason,
            'log_loss_improvement' => $this->logLossImprovement,
            'mean_home_bias' => $this->meanHomeBias,
            'calibration_gap' => $this->calibrationGap,
        ];
    }
}
