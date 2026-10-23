<?php

declare(strict_types=1);

use App\Services\Ai\ReadValidator;

/*
|--------------------------------------------------------------------------
| §13: "Every number in every Vigil Read exists in its fact sheet (tested
| across a fixture set)."
|--------------------------------------------------------------------------
|
| A fixture set, not two examples. This is the component the whole AI
| feature rests on: a language model writing about probabilities will,
| occasionally and with total confidence, produce a number that is
| plausible, well-placed and wrong - and a wrong number in a betting
| product is not a typo, it is the thing the customer acted on.
|
| So the cases below are adversarial rather than representative. Each one
| is a way a model has actually been observed to go wrong, or a way a
| validator can be fooled.
|
*/

/** The sheet every case below is checked against. */
function sheet(): array
{
    return [
        'kind' => 'board_entry',
        'sport' => 'NFL',
        'market' => 'h2h',
        'selection' => 'Buffalo Bills',
        'market_verdict' => 'proven',
        'market_is_proven' => true,
        'recommendation' => 'bet',
        'model_probability' => 0.58,
        'model_probability_written' => '58%',
        'market_fair_probability' => 0.56,
        'market_fair_probability_written' => '56%',
        'edge_at_this_price' => 0.021,
        'edge_at_this_price_written' => '2.1%',
        'price_american' => -140,
        'price_written' => '-140',
        'book' => 'Hard Rock Bet',
        'model_predictions_measured' => 1284,
        'line_movement' => 'At Hard Rock Bet this price shortened from -130 to -140 across 4 stored quotes.',
        'injuries_modelled' => false,
    ];
}

function padding(): string
{
    return ' '.str_repeat('The engine measured this against the closing line and recorded what it found. ', 8);
}

function check(string $body): array
{
    return app(ReadValidator::class)->check($body.padding(), sheet());
}

/* ----------------------------------------------------------------------
 | Accepted: every figure is in the sheet
 ---------------------------------------------------------------------- */

it('accepts a read whose every figure came from the sheet', function (string $body) {
    expect(check($body)['ok'])->toBeTrue();
})->with([
    'the probability' => ['The model puts Buffalo Bills at 58%.'],
    'both probabilities' => ['The model says 58% against a vig-free market price of 56%.'],
    'the edge' => ['That leaves 2.1% over the break-even rate at this price.'],
    'the price, signed' => ['The price is -140 at Hard Rock Bet.'],
    'the sample size' => ['This verdict rests on 1284 measured predictions.'],
    'figures from the movement sentence' => ['The price shortened from -130 to -140.'],
    'a bare small integer' => ['One of the three markets in this sport is proven.'],
    'a percentage written without its decimal' => ['The model puts this at 58% and the market at 56%.'],
]);

/* ----------------------------------------------------------------------
 | Refused: a figure the sheet does not contain
 ---------------------------------------------------------------------- */

it('refuses a read that states a figure the sheet does not contain', function (string $body) {
    $result = check($body);

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toBe('unsupported_number');
})->with([
    // The classic: a plausible probability nobody calculated.
    'an invented probability' => ['The model puts Buffalo Bills at 61%.'],
    // Off by a decimal place, which is the hardest kind to spot by eye.
    'a mistyped edge' => ['That leaves 21% over the break-even rate.'],
    // Rounded "helpfully" into a number that was never given.
    'a rounded probability' => ['The model puts this at roughly 60%.'],
    // A price from a different book, or from thin air.
    'an invented price' => ['Hard Rock Bet has it at -155.'],
    // Arithmetic the model did itself. Correct arithmetic is still an
    // unsupported claim: 58 minus 56 is 2, and the sheet says 2.1%.
    'a difference it worked out' => ['The model is 2% clear of the market.'],
    // A sample size that sounds authoritative and is not in the sheet.
    'an invented sample' => ['Measured over 5000 predictions.'],
    // An implied return, which no field supports.
    'an invented payout' => ['A $100 stake returns $171.'],
    // A date. Not in the sheet, and a Read has no business asserting one.
    'a year' => ['Buffalo have been 58% favourites since 2019.'],
    /*
     * The price with its sign dropped. Refused, and rightly: +140 and
     * -140 are completely different prices, so an unsigned 140 is not a
     * sloppy way of writing one of them - it is a figure that could
     * mean either.
     */
    'the price with its sign dropped' => ['Hard Rock Bet has it at 140.'],
]);

/* ----------------------------------------------------------------------
 | Refused for other reasons
 ---------------------------------------------------------------------- */

it('refuses every banned word, however it is dressed up', function (string $body) {
    expect(check($body)['reason'])->toStartWith('banned_word:');
})->with([
    ['This one is a lock at 58%.'],
    ['Guaranteed value at 58%.'],
    ['Free money at -140.'],
    ['A sure thing on the Bills at 58%.'],
    ['This is a no-brainer at 2.1%.'],
    ['Hammer this at -140.'],
    ['Risk-free at these prices.'],
    ['Max bet. The model says 58%.'],
]);

it('catches a banned word at a word boundary and not inside another word', function () {
    // "locked in" is caught; "unlocked" is not. A validator that matched
    // substrings would reject correct English and train somebody to
    // stop trusting it.
    expect(check('The price is locked in at -140.')['reason'])->toStartWith('banned_word')
        ->and(check('Hard Rock Bet has unlocked this market at -140.')['ok'])->toBeTrue();
});

it('refuses a read that is nowhere near the length it was asked for', function () {
    // §6 asks for 60-120 words. Far outside that is not the thing that
    // was asked for, and the usual cause is the model having answered a
    // different question.
    expect(app(ReadValidator::class)->check('58%.', sheet())['reason'])->toStartWith('length:');

    $essay = str_repeat('The engine measured this against the closing line. ', 60);
    expect(app(ReadValidator::class)->check($essay, sheet())['reason'])->toStartWith('length:');
});

it('refuses an empty read', function () {
    expect(app(ReadValidator::class)->check('   ', sheet())['reason'])->toBe('empty');
});

it('names the offending figure, so a retry can be told what was wrong', function () {
    $result = check('The model puts this at 61% with an edge of 9.4%.');

    // The second attempt is given the failure verbatim. A retry told
    // only "rejected" produces the same output again.
    expect($result['offending'])->toContain('61%')
        ->and($result['offending'])->toContain('9.4%');
});

it('reads every number out of the sheet, including the ones inside strings', function () {
    $allowed = app(ReadValidator::class)->numbersIn(sheet());

    // The line-movement sentence is a string, and the figures in it are
    // quotable. A validator that only walked numeric fields would
    // reject a Read for quoting a sentence it was handed.
    expect($allowed)->toContain('-130')
        ->and($allowed)->toContain('-140')
        ->and($allowed)->toContain('58%')
        ->and($allowed)->toContain('1284');
});

it('fails safe when the sheet is empty', function () {
    // No sheet means no supported figures, so any claim is unsupported.
    // The direction of that failure is the one that matters.
    $result = app(ReadValidator::class)->check(
        'The model puts this at 58%.'.padding(),
        [],
    );

    expect($result['ok'])->toBeFalse();
});
