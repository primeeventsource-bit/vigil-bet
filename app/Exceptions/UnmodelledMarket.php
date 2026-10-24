<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Market;
use RuntimeException;

/**
 * Raised when a backtest is asked for a market it cannot honestly score.
 *
 * The backtest asks one question of every prediction: did the home side
 * win? That is the right question for a moneyline and the wrong one for
 * anything else, and asking it anyway does not fail loudly - it produces a
 * number.
 *
 * It produces a flattering one. A spread or a total closes near even money
 * on both sides, so its implied probability is near a coin flip. Score a
 * moneyline-accurate model against that benchmark and it "beats the
 * market" by a wide margin every time, on a question the market was never
 * pricing. The first NFL spread run did exactly this: it reported beating
 * the close by 0.0741 of log loss, and the only thing standing between
 * that number and a recommended bet was the calibration tolerance.
 *
 * Scoring these properly needs two things this app does not have yet. The
 * outcome has to become "did the home side cover the closing line" or "did
 * the game go over it" - the data is there, event_line_markers stores
 * line_close. And the model has to predict the matching quantity, which
 * for a spread means emitting a margin distribution rather than a win
 * probability, the way Forecaster already does for the live board.
 *
 * Until both exist, refusing is the only honest answer. A missing verdict
 * costs nothing; a fabricated one is the single worst output this app
 * could produce, because every guard downstream is built to trust it.
 */
class UnmodelledMarket extends RuntimeException
{
    public static function for(Market $market): self
    {
        return new self(sprintf(
            '%s cannot be backtested yet. The walk-forward scores every prediction on whether the '
            .'home side won outright, which is the wrong question for this market and would report '
            .'a false edge against a benchmark that closes near even money. Needs a model that '
            .'predicts %s and scoring that reads the closing line.',
            $market->label(),
            $market === Market::Total ? 'total points' : 'margin of victory',
        ));
    }
}
