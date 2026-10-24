<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Market;
use App\Enums\Side;
use Carbon\CarbonImmutable;

/**
 * One price, from one book, for one side of one market, at one moment.
 */
final readonly class OddsQuote
{
    public function __construct(
        public string $bookKey,
        public string $bookTitle,
        public Market $market,
        public Side $side,
        public ?float $line,
        public int $priceAmerican,
        public ?string $selectionName = null,
        public ?CarbonImmutable $lastUpdateAt = null,
    ) {}
}
