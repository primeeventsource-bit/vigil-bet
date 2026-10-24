<?php

declare(strict_types=1);

namespace App\Data;

use App\Modeling\Odds\OddsConverter;
use App\Models\Book;

/**
 * The prices on offer for one side, organised around the one that decides
 * anything: the price at the book the bet will actually be placed at.
 *
 * The rest of the board is still carried. It is not there to be bet; it is
 * there to say whether the wider market agrees, which is what makes a fair
 * price worth trusting.
 */
final readonly class ShoppedLine
{
    /**
     * @param  array<string, array{book_id:int, book:string, price:int, line:float|null, pulled_at:string, bettable:bool, pricing:bool}>  $quotes
     */
    public function __construct(
        public array $quotes,
        public ?int $pricingBookId,
        public ?int $pricingBookPriceAmerican,
        public ?string $pricingBookTitle,
        public ?int $bestMarketBookId,
        public ?int $bestMarketPriceAmerican,
        public ?string $bestMarketBookTitle,
        public ?float $line,
        public int $bookCount,
        public ?string $pricePulledAt = null,
    ) {}

    public function hasPrice(): bool
    {
        return $this->pricingBookPriceAmerican !== null;
    }

    /**
     * The win rate this price needs just to break even. A bet is only worth
     * making when the model beats this number.
     */
    public function breakEvenWinRate(): ?float
    {
        return $this->pricingBookPriceAmerican === null
            ? null
            : OddsConverter::americanToProbability($this->pricingBookPriceAmerican);
    }

    /** True when the board has a better number somewhere I cannot bet. */
    public function betterElsewhere(): bool
    {
        if ($this->pricingBookPriceAmerican === null || $this->bestMarketPriceAmerican === null) {
            return false;
        }

        return OddsConverter::americanToDecimal($this->bestMarketPriceAmerican)
            > OddsConverter::americanToDecimal($this->pricingBookPriceAmerican);
    }

    /** How much the pricing book costs against the best number on the board. */
    public function costOfPricingBook(): float
    {
        if (! $this->betterElsewhere()) {
            return 0.0;
        }

        return OddsConverter::americanToProbability($this->pricingBookPriceAmerican)
            - OddsConverter::americanToProbability($this->bestMarketPriceAmerican);
    }

    public function pricingBook(): ?Book
    {
        return $this->pricingBookId ? Book::find($this->pricingBookId) : null;
    }
}
