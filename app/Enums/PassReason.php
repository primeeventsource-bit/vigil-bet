<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a candidate is not a pick. Every non-pick carries one of these so the
 * Today screen can say Pass with a reason instead of staying silent.
 */
enum PassReason: string
{
    case BelowThreshold = 'below_threshold';
    case MarketUnproven = 'market_unproven';
    case NegativeEdge = 'negative_edge';
    case StaleQuote = 'stale_quote';
    case TooFewBooks = 'too_few_books';
    case LockoutActive = 'lockout_active';
    case ExposureCap = 'exposure_cap';
    case NoModel = 'no_model';
    case NoBankrollSettings = 'no_bankroll_settings';
    case NoPrice = 'no_price';
    case BelowMinStake = 'below_min_stake';
    case BelowMinWinProbability = 'below_min_win_probability';
    case PriceTooShortHere = 'price_too_short_here';
    // Parlays only.
    case CorrelatedLegs = 'correlated_legs';
    case LegUnmodelled = 'leg_unmodelled';
    case TooFewLegs = 'too_few_legs';
    case TooManyLegs = 'too_many_legs';
    case EdgeGoneWhenShaded = 'edge_gone_when_shaded';

    public function label(): string
    {
        return match ($this) {
            self::BelowThreshold => 'Edge too small',
            self::MarketUnproven => 'Model unproven here',
            self::NegativeEdge => 'Market is sharper than the model',
            self::StaleQuote => 'Price too old to trust',
            self::TooFewBooks => 'Not enough books quoting',
            self::LockoutActive => 'Loss limit reached',
            self::ExposureCap => 'Daily exposure cap reached',
            self::NoModel => 'No active model',
            self::NoBankrollSettings => 'No bankroll set up',
            self::NoPrice => 'No price available',
            self::BelowMinStake => 'Correct stake is below your minimum bet',
            self::BelowMinWinProbability => 'Win chance below your minimum',
            self::PriceTooShortHere => 'Price too short here',
            self::CorrelatedLegs => 'Legs from the same game',
            self::LegUnmodelled => 'A leg the model has not priced',
            self::TooFewLegs => 'Not enough legs',
            self::TooManyLegs => 'Too many legs',
            self::EdgeGoneWhenShaded => 'Edge does not survive the model being slightly wrong',
        };
    }

    /**
     * The longer line shown under a Pass. PriceTooShortHere is the one worth
     * spelling out: the bet is good, the number here is not.
     */
    public function detail(?string $bookTitle = null): string
    {
        $book = $bookTitle ?? 'your book';

        return match ($this) {
            self::PriceTooShortHere => "The model likes this bet, but {$book} is not paying enough for it.",
            self::BelowMinWinProbability => 'This can still be a profitable bet, it just wins less often than you asked to see.',
            self::BelowThreshold => "The edge is real but too small to be worth the risk at {$book}.",
            self::NegativeEdge => "{$book} is pricing this better than the model does.",
            self::MarketUnproven => 'The model has not beaten the closing line here yet, so it does not get to pick.',
            self::BelowMinStake => 'The correct stake is under your minimum bet, so this one is skipped rather than overbet.',
            self::StaleQuote => 'The last price seen is too old to act on.',
            self::TooFewBooks => 'Not enough books are quoting this to trust the fair price.',
            self::LockoutActive => 'A loss limit you set has been reached for this period.',
            self::ExposureCap => 'Today is already at the exposure cap you set.',
            self::NoModel => 'No trained model covers this market yet.',
            self::NoBankrollSettings => 'No bankroll settings have been saved, so there is no bankroll to size a stake against.',
            self::NoPrice => "{$book} is not quoting this right now.",
            self::CorrelatedLegs => 'Two of these legs are from the same game, so they are not independent. '
                .'Multiplying their probabilities would overstate the chance of both landing, and multiplying '
                .'their prices is not what a book pays for a same-game parlay anyway.',
            self::LegUnmodelled => 'No scan has priced one of these legs, so there is no model probability to '
                .'multiply and no joint chance to state.',
            self::TooFewLegs => 'A parlay needs at least two legs.',
            self::TooManyLegs => 'More legs than this app will price, because the error in each one compounds.',
            self::EdgeGoneWhenShaded => 'The edge disappears once every leg is shaded by the amount a model is '
                .'routinely wrong by. An edge that thin was confidence, not an edge.',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $r) => $r->value, self::cases());
    }
}
