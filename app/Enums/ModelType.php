<?php

declare(strict_types=1);

namespace App\Enums;

enum ModelType: string
{
    case Elo = 'elo';
    case NormalMargin = 'normal_margin';
    case Poisson = 'poisson';
    case NegBinomial = 'neg_binomial';
    case Logistic = 'logistic';

    public function label(): string
    {
        return match ($this) {
            self::Elo => 'Elo / power rating',
            self::NormalMargin => 'Normal margin',
            self::Poisson => 'Poisson scoring',
            self::NegBinomial => 'Negative binomial scoring',
            self::Logistic => 'Regularized logistic regression',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $t) => $t->value, self::cases());
    }
}
