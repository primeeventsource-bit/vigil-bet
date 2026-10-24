<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Side;
use Carbon\CarbonImmutable;

/**
 * One price, from one book, on one player prop, at one moment.
 *
 * Deliberately not an OddsQuote. An OddsQuote carries a Market, and Market
 * is the enum the rest of the app matches exhaustively on - it says which
 * sides exist, whether a line is meaningful, which pricer runs and how to
 * label the selection. A prop answers none of those, so it carries the raw
 * market key instead and travels its own path to the same table.
 */
final readonly class PropQuote
{
    public function __construct(
        public string $bookKey,
        public string $bookTitle,
        /** The Odds API market key, e.g. player_points. */
        public string $marketKey,
        /** Over/Under for a line, Yes/No for anytime touchdown scorer. */
        public Side $side,
        public ?float $line,
        public int $priceAmerican,
        /** The player. The feed calls this `description`. */
        public ?string $playerName = null,
        public ?CarbonImmutable $lastUpdateAt = null,
    ) {}
}
