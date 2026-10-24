<?php

declare(strict_types=1);

namespace App\Modeling\Math;

/**
 * Applies the calibration map that a model version stored at training time.
 *
 * ModelTrainer fits a map on part of the out-of-sample run and keeps it only
 * if it improved calibration on the part the fit never saw. When it did not,
 * the stored method is "none" and this passes the probability straight
 * through - which is the point of having a type for it. A caller should not
 * have to remember that an uncalibrated model exists.
 */
final class StoredCalibration
{
    private function __construct(
        public readonly string $method,
        private readonly ?IsotonicRegression $isotonic = null,
        private readonly ?PlattScaling $platt = null,
    ) {}

    /** @param  array<string, mixed>|null  $calibration */
    public static function fromParams(?array $calibration): self
    {
        $method = (string) ($calibration['method'] ?? 'none');

        return match ($method) {
            'isotonic' => new self($method, isotonic: IsotonicRegression::fromMap(
                (array) ($calibration['map'] ?? []),
            )),
            'platt' => new self($method, platt: PlattScaling::fromParams(
                (array) ($calibration['params'] ?? []),
            )),
            default => new self('none'),
        };
    }

    public static function none(): self
    {
        return new self('none');
    }

    public function isApplied(): bool
    {
        return $this->method !== 'none';
    }

    public function apply(float $probability): float
    {
        return match ($this->method) {
            'isotonic' => $this->isotonic->calibrate($probability),
            'platt' => $this->platt->calibrate($probability),
            default => $probability,
        };
    }
}
